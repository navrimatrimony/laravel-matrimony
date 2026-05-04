<?php

namespace App\Services\Showcase;

use App\Models\AdminSetting;
use App\Models\City;
use App\Models\Location;
use App\Models\MatrimonyProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fills {@see City::$population} only for cities in districts used by real (non-showcase) profiles, with null population.
 */
class ShowcaseEligibleCityPopulationService
{
    /**
     * District IDs implied by real members' `district_id` or `city_id` residence.
     *
     * @return Collection<int, int>
     */
    public function districtIdsFromRealProfiles(): Collection
    {
        $d1 = MatrimonyProfile::query()
            ->whereNotNull('district_id')
            ->whereNonShowcase()
            ->distinct()
            ->pluck('district_id');

        $geo = Location::geoTable();
        $d2 = DB::table('matrimony_profiles as mp')
            ->join($geo.' as city', function ($join): void {
                $join->on('city.id', '=', 'mp.city_id')->where('city.type', '=', 'city');
            })
            ->join($geo.' as taluka', function ($join): void {
                $join->on('taluka.id', '=', 'city.parent_id')->where('taluka.type', '=', 'taluka');
            })
            ->whereNotNull('mp.city_id')
            ->where(function ($q) {
                $q->where('mp.is_showcase', false)->orWhereNull('mp.is_showcase');
            })
            ->distinct()
            ->pluck('taluka.parent_id');

        return $d1->merge($d2)->map(fn ($v) => (int) $v)->unique()->filter(fn ($id) => $id > 0)->values();
    }

    /**
     * Districts where AI population fill has already run at least once (avoid repeat API cost).
     *
     * @return list<int>
     */
    public function aiLockedDistrictIds(): array
    {
        $raw = (string) AdminSetting::getValue('showcase_ai_population_district_ids_done', '[]');
        $decoded = json_decode($raw, true);

        return is_array($decoded)
            ? array_values(array_unique(array_filter(array_map('intval', $decoded))))
            : [];
    }

    /**
     * @param  list<int>  $districtIds
     */
    public function mergeAiLockedDistrictIds(array $districtIds): void
    {
        $districtIds = array_values(array_unique(array_filter(array_map('intval', $districtIds))));
        if ($districtIds === []) {
            return;
        }
        $merged = array_values(array_unique(array_merge($this->aiLockedDistrictIds(), $districtIds)));
        AdminSetting::setValue('showcase_ai_population_district_ids_done', json_encode($merged));
    }

    public function countEligible(bool $forAi = false): int
    {
        $districtIds = $this->districtIdsFromRealProfiles();
        if ($districtIds->isEmpty()) {
            return 0;
        }

        $geo = Location::geoTable();
        $q = City::query()
            ->join($geo.' as taluka', function ($join) use ($geo): void {
                $join->on('taluka.id', '=', $geo.'.parent_id')->where('taluka.type', '=', 'taluka');
            })
            ->whereIn('taluka.parent_id', $districtIds)
            ->whereNull($geo.'.population');
        if ($forAi) {
            $locked = $this->aiLockedDistrictIds();
            if ($locked !== []) {
                $q->whereNotIn('taluka.parent_id', $locked);
            }
        }

        return (int) $q->selectRaw('count(distinct '.$geo.'.id) as c')->value('c');
    }

    /**
     * @return Collection<int, int> city ids
     */
    public function eligibleCityIds(int $limit, bool $forAi = false): Collection
    {
        $districtIds = $this->districtIdsFromRealProfiles();
        if ($districtIds->isEmpty()) {
            return collect();
        }

        $geo = Location::geoTable();
        $q = City::query()
            ->join($geo.' as taluka', function ($join) use ($geo): void {
                $join->on('taluka.id', '=', $geo.'.parent_id')->where('taluka.type', '=', 'taluka');
            })
            ->whereIn('taluka.parent_id', $districtIds)
            ->whereNull($geo.'.population');
        if ($forAi) {
            $locked = $this->aiLockedDistrictIds();
            if ($locked !== []) {
                $q->whereNotIn('taluka.parent_id', $locked);
            }
        }

        return $q->orderBy($geo.'.id')
            ->limit(max(1, $limit))
            ->pluck($geo.'.id');
    }

    /**
     * Uses average population of other cities in the same district, or a safe default.
     */
    public function fillHeuristic(int $limit): int
    {
        $ids = $this->eligibleCityIds($limit);
        $updated = 0;
        foreach ($ids as $cityId) {
            $city = City::query()->with('taluka')->find((int) $cityId);
            if (! $city || $city->population !== null || ! $city->taluka) {
                continue;
            }
            $districtId = (int) $city->taluka->district_id;
            $geo = Location::geoTable();
            $avg = City::query()
                ->join($geo.' as taluka', function ($join) use ($geo): void {
                    $join->on('taluka.id', '=', $geo.'.parent_id')->where('taluka.type', '=', 'taluka');
                })
                ->where('taluka.parent_id', $districtId)
                ->whereNotNull($geo.'.population')
                ->avg($geo.'.population');
            $pop = $avg ? (int) round((float) $avg) : 175_000;
            $pop = max(50_000, min(8_000_000, $pop));
            $city->population = $pop;
            $city->save();
            $updated++;
        }

        return $updated;
    }

    /**
     * OpenAI JSON fill for up to $limit eligible cities (requires OPENAI_API_KEY).
     */
    public function fillWithAi(int $limit): int
    {
        $key = (string) config('services.openai.key', '');
        if ($key === '') {
            return 0;
        }

        $ids = $this->eligibleCityIds($limit, true);
        if ($ids->isEmpty()) {
            return 0;
        }

        $geo = Location::geoTable();
        $rows = City::query()
            ->select([$geo.'.id', $geo.'.name', 'district.id as district_id', 'district.name as district_name'])
            ->join($geo.' as taluka', function ($join) use ($geo): void {
                $join->on('taluka.id', '=', $geo.'.parent_id')->where('taluka.type', '=', 'taluka');
            })
            ->join($geo.' as district', function ($join): void {
                $join->on('district.id', '=', 'taluka.parent_id')->where('district.type', '=', 'district');
            })
            ->whereIn($geo.'.id', $ids->all())
            ->get();

        $cityToDistrict = [];
        foreach ($rows as $r) {
            $cityToDistrict[(int) $r->id] = (int) $r->district_id;
        }

        $allowed = $ids->flip()->all();
        $payload = $rows->map(fn ($r) => [
            'id' => (int) $r->id,
            'city' => (string) $r->name,
            'district' => (string) $r->district_name,
        ])->values()->all();

        $model = (string) config('services.openai.model', 'gpt-4o-mini');
        $url = (string) config('services.openai.url', 'https://api.openai.com/v1/chat/completions');

        try {
            $response = Http::timeout(90)
                ->withToken($key)
                ->acceptJson()
                ->post($url, [
                    'model' => $model,
                    'temperature' => 0.2,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You estimate Indian city populations for matrimony location ranking. Reply with JSON only: {"estimates":[{"id":number,"population":number}]} — population is approximate resident count (integer). No prose.',
                        ],
                        [
                            'role' => 'user',
                            'content' => json_encode(['cities' => $payload], JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::warning('showcase_city_population_ai', ['error' => $e->getMessage()]);

            return 0;
        }

        if (! $response->successful()) {
            Log::warning('showcase_city_population_ai', ['status' => $response->status(), 'body' => $response->body()]);

            return 0;
        }

        $content = data_get($response->json(), 'choices.0.message.content', '');
        $decoded = json_decode((string) $content, true);
        if (! is_array($decoded) || ! isset($decoded['estimates']) || ! is_array($decoded['estimates'])) {
            Log::warning('showcase_city_population_ai_parse', ['content' => $content]);

            return 0;
        }

        $updated = 0;
        $districtsLocked = [];
        foreach ($decoded['estimates'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            $pop = (int) ($row['population'] ?? 0);
            if ($id < 1 || ! isset($allowed[$id]) || $pop < 10_000) {
                continue;
            }
            $pop = min(12_000_000, $pop);
            $affected = City::query()->whereKey($id)->whereNull('population')->update(['population' => $pop]);
            if ($affected > 0) {
                $updated++;
                if (isset($cityToDistrict[$id])) {
                    $districtsLocked[] = $cityToDistrict[$id];
                }
            }
        }

        if ($districtsLocked !== []) {
            $this->mergeAiLockedDistrictIds($districtsLocked);
        }

        return $updated;
    }
}
