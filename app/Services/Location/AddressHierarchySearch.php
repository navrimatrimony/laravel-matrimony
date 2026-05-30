<?php

namespace App\Services\Location;

use App\Models\City;
use App\Models\District;
use App\Models\Location;
use App\Models\Taluka;
use App\Models\Village;
use Illuminate\Database\Eloquent\Builder;

/**
 * Match leaf rows in {@code addresses} (village / suburban / city) with village + taluka + district (MR/EN).
 */
final class AddressHierarchySearch
{
    /** @var list<string> */
    private const LEAF_TYPES = ['village', 'suburb', 'city'];

    /**
     * @param  array{village: string, taluka: string, district: string}  $components
     * @return list<City>
     */
    public function findCities(array $components, int $limit = 20): array
    {
        $village = trim((string) ($components['village'] ?? ''));
        if ($village === '') {
            return [];
        }

        $taluka = trim((string) ($components['taluka'] ?? ''));
        $district = trim((string) ($components['district'] ?? ''));

        $strategies = [
            ['village' => $village, 'taluka' => $taluka, 'district' => $district],
            ['village' => $village, 'taluka' => $taluka, 'district' => ''],
            ['village' => $village, 'taluka' => '', 'district' => $district],
            ['village' => $village, 'taluka' => '', 'district' => ''],
        ];

        $seen = [];
        $out = [];

        foreach ($strategies as $strategy) {
            foreach ($this->queryLeaves($strategy, $limit) as $city) {
                $id = (int) $city->id;
                if ($id < 1 || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $out[] = $city;
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }

        return $out;
    }

    public function cityFromVillageLocation(Location $location): ?City
    {
        if ($location->type !== 'village') {
            return null;
        }

        $village = Village::query()
            ->with(['taluka.district.state'])
            ->find((int) $location->id);

        return $village !== null ? $this->leafAsCity($village) : null;
    }

    /**
     * @param  array{village: string, taluka: string, district: string}  $strategy
     * @return list<City>
     */
    private function queryLeaves(array $strategy, int $limit): array
    {
        $query = Village::query()
            ->with(['taluka.district.state', 'parent']);

        $this->applyVillageNameMatch($query, $strategy['village']);
        $this->applyAdminScope($query, $strategy['taluka'], $strategy['district']);

        $out = [];
        foreach ($query->orderBy('name')->limit(max(1, $limit) * 2)->get() as $leaf) {
            $city = $this->leafAsCity($leaf);
            if ($city !== null) {
                $out[] = $city;
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private function leafAsCity(Location $leaf): ?City
    {
        if (! in_array((string) $leaf->type, self::LEAF_TYPES, true)) {
            return null;
        }

        if ($leaf->type === 'city' || $leaf->type === 'suburb') {
            $city = City::query()
                ->withoutGlobalScope('geo_city')
                ->with(['taluka.district.state.country', 'parentCity', 'displayMeta'])
                ->find((int) $leaf->id);

            return $city ?? $this->hydrateCityFromLocation($leaf);
        }

        $village = $leaf instanceof Village
            ? $leaf
            : Village::query()->with(['taluka.district.state'])->find((int) $leaf->id);

        if ($village === null) {
            return null;
        }

        $mirrored = $this->mirrorCityForVillage($village);
        if ($mirrored !== null) {
            return $mirrored;
        }

        return $this->hydrateCityFromLocation($village);
    }

    private function hydrateCityFromLocation(Location $leaf): City
    {
        $city = new City;
        $city->forceFill($leaf->getAttributes());
        $city->exists = true;
        $city->syncOriginal();

        if ($leaf->relationLoaded('taluka') && $leaf->getRelation('taluka') !== null) {
            $city->setRelation('taluka', $leaf->getRelation('taluka'));
        } elseif ($leaf->parent_id) {
            $parent = $leaf->relationLoaded('parent')
                ? $leaf->getRelation('parent')
                : Location::query()->find((int) $leaf->parent_id);
            if ($parent !== null && $parent->type === 'taluka') {
                $city->setRelation('taluka', Taluka::query()->find((int) $parent->id));
            }
        }

        return $city;
    }

    /**
     * @param  Builder<\App\Models\Location>  $query
     */
    private function applyAdminScope(Builder $query, string $taluka, string $district): void
    {
        $talukaIds = $taluka !== '' ? $this->resolveTalukaIds($taluka, $district) : [];
        $districtIds = $district !== '' ? $this->resolveDistrictIds($district) : [];

        if ($talukaIds !== []) {
            $query->whereIn('parent_id', $talukaIds);

            return;
        }

        if ($districtIds !== []) {
            $query->where(function (Builder $scope) use ($districtIds): void {
                $scope->whereIn('parent_id', $districtIds)
                    ->orWhereIn('parent_id', function ($sub) use ($districtIds) {
                        $sub->select('id')
                            ->from(Location::geoTable())
                            ->where('type', 'taluka')
                            ->whereIn('parent_id', $districtIds);
                    });
            });
        } elseif ($taluka !== '') {
            $query->whereHas('parent', function (Builder $parentQuery) use ($taluka): void {
                $parentQuery->where('type', 'taluka');
                $this->applyGeoNameMatch($parentQuery, $taluka);
            });
        } elseif ($district !== '') {
            $fallbackDistrictIds = $this->resolveDistrictIds($district);
            if ($fallbackDistrictIds !== []) {
                $talukaUnder = Taluka::query()->whereIn('parent_id', $fallbackDistrictIds)->pluck('id')->all();
                $parentIds = array_values(array_unique(array_merge($fallbackDistrictIds, $talukaUnder)));
                $query->whereIn('parent_id', $parentIds);
            } else {
                $query->whereHas('parent', function (Builder $parentQuery) use ($district): void {
                    $parentQuery->where('type', 'district');
                    $this->applyGeoNameMatch($parentQuery, $district);
                });
            }
        }
    }

    /**
     * @return list<int>
     */
    private function resolveTalukaIds(string $taluka, string $district): array
    {
        $query = Taluka::query();
        $query->where(function (Builder $w) use ($taluka): void {
            $this->applyGeoNameMatch($w, $taluka);
        });

        if ($district !== '') {
            $districtIds = $this->resolveDistrictIds($district);
            if ($districtIds !== []) {
                $query->whereIn('parent_id', $districtIds);
            } else {
                $query->whereHas('district', function (Builder $districtQuery) use ($district): void {
                    $this->applyGeoNameMatch($districtQuery, $district);
                });
            }
        }

        return $query->limit(50)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return list<int>
     */
    private function resolveDistrictIds(string $district): array
    {
        return District::query()
            ->where(function (Builder $w) use ($district): void {
                $this->applyGeoNameMatch($w, $district);
            })
            ->limit(20)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function mirrorCityForVillage(Village $village): ?City
    {
        $parent = $village->relationLoaded('parent')
            ? $village->getRelation('parent')
            : Location::query()->find((int) ($village->parent_id ?? 0));

        if ($parent instanceof Location && in_array($parent->type, ['city', 'suburban'], true)) {
            return City::query()
                ->withoutGlobalScope('geo_city')
                ->with(['taluka.district.state.country', 'parentCity', 'displayMeta'])
                ->find((int) $parent->id);
        }

        $nameEnKey = mb_strtolower(trim((string) ($village->name_en ?: $village->name)), 'UTF-8');
        if ($nameEnKey === '' || ! $village->parent_id) {
            return null;
        }

        return City::query()
            ->withoutGlobalScope('geo_city')
            ->with(['taluka.district.state.country', 'parentCity', 'displayMeta'])
            ->whereIn('type', ['city', 'suburban'])
            ->where('parent_id', (int) $village->parent_id)
            ->whereRaw('LOWER(TRIM(COALESCE(name_en, name, ""))) = ?', [$nameEnKey])
            ->first();
    }

    /**
     * @param  Builder<\App\Models\Location>  $query
     */
    private function applyVillageNameMatch(Builder $query, string $village): void
    {
        $village = trim($village);
        $words = array_values(array_filter(preg_split('/\s+/u', $village) ?: []));
        $hyphenParts = array_values(array_filter(preg_split('/[-–—]+/u', $village) ?: []));

        $query->where(function (Builder $outer) use ($village, $words, $hyphenParts): void {
            $this->applyGeoNameMatch($outer, $village);
            $this->applyCompactNameMatch($outer, $village);

            if (count($words) >= 2) {
                $outer->orWhere(function (Builder $allWords) use ($words): void {
                    foreach ($words as $word) {
                        $allWords->where(function (Builder $one) use ($word): void {
                            $this->applyGeoNameMatch($one, $word);
                        });
                    }
                });

                if (mb_strlen($village) <= 48 && count($words) <= 4) {
                    $outer->orWhere(function (Builder $anyWord) use ($words): void {
                        foreach ($words as $word) {
                            if (mb_strlen(trim($word)) >= 3) {
                                $anyWord->orWhere(function (Builder $one) use ($word): void {
                                    $this->applyGeoNameMatch($one, $word);
                                });
                            }
                        }
                    });
                }
            }

            if (count($hyphenParts) >= 2 && $hyphenParts !== $words) {
                $outer->orWhere(function (Builder $parts) use ($hyphenParts): void {
                    foreach ($hyphenParts as $part) {
                        $parts->where(function (Builder $one) use ($part): void {
                            $this->applyGeoNameMatch($one, trim($part));
                        });
                    }
                });
            }
        });
    }

    /**
     * @param  Builder<\App\Models\Location>  $query
     */
    private function applyCompactNameMatch(Builder $query, string $value): void
    {
        $compact = $this->compactKey($value);
        if ($compact === '' || mb_strlen($compact) < 3) {
            return;
        }

        $like = '%'.$compact.'%';
        $query->orWhereRaw(
            'REPLACE(REPLACE(REPLACE(LOWER(COALESCE(name_en, name, "")), " ", ""), "-", ""), ".", "") LIKE ?',
            [$like]
        )->orWhereRaw(
            'REPLACE(REPLACE(REPLACE(LOWER(COALESCE(name_mr, "")), " ", ""), "-", ""), ".", "") LIKE ?',
            [$like]
        );
    }

    /**
     * @param  Builder<\App\Models\Location>  $query
     */
    private function applyGeoNameMatch(Builder $query, string $needle): void
    {
        $needle = trim($needle);
        if ($needle === '') {
            return;
        }

        $likeLower = '%'.mb_strtolower($needle, 'UTF-8').'%';
        $likeRaw = '%'.$needle.'%';
        $compact = $this->compactKey($needle);
        $compactLike = $compact !== '' ? '%'.$compact.'%' : null;

        $query->where(function (Builder $w) use ($likeLower, $likeRaw, $compactLike): void {
            $w->whereRaw('LOWER(COALESCE(name, "")) LIKE ?', [$likeLower])
                ->orWhereRaw('LOWER(COALESCE(name_en, "")) LIKE ?', [$likeLower])
                ->orWhereRaw('COALESCE(name_mr, "") LIKE ?', [$likeRaw]);
            if ($compactLike !== null) {
                $w->orWhereRaw(
                    'REPLACE(REPLACE(REPLACE(LOWER(COALESCE(name_mr, name, "")), " ", ""), "-", ""), ".", "") LIKE ?',
                    [$compactLike]
                )->orWhereRaw(
                    'REPLACE(REPLACE(REPLACE(LOWER(COALESCE(name_en, name, "")), " ", ""), "-", ""), ".", "") LIKE ?',
                    [$compactLike]
                );
            }
        });
    }

    private function compactKey(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/[\s\-–—\.]+/u', '', $value) ?? $value;

        return trim($value);
    }
}
