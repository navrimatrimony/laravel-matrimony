<?php

namespace App\Services\Location;

use App\Models\City;
use App\Models\CityAlias;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE-5 location normalization: {@see CityAlias} exact match, then hierarchy from {@see City} → taluka → district → state.
 * GPS is a later step.
 */
final class LocationNormalizationService
{
    private const CONFIDENCE_ALIAS_MATCH = 0.92;

    private const CONFIDENCE_NO_MATCH = 0.0;

    /**
     * Resolve free text via active `city_aliases.normalized_alias` exact match, then hierarchy from the city row.
     * Additive ambiguity policy: when multiple distinct cities match the same alias key, do not auto-resolve.
     *
     * @return array{matched: bool, city_id: int|null, district_id: int|null, state_id: int|null, taluka_id: int|null, country_id: int|null, confidence: float, raw_input: string, ambiguity: bool, possible_matches: array<int, array{city_id:int, city_name:string, taluka_name:string, district_name:string, state_name:string}>}
     */
    public function normalizeFromText(string $raw): array
    {
        $rawInput = trim($raw);
        if ($rawInput === '') {
            return $this->result(false, null, null, null, null, null, self::CONFIDENCE_NO_MATCH, '', false, []);
        }

        if (! Schema::hasTable('city_aliases')) {
            return $this->result(false, null, null, null, null, null, self::CONFIDENCE_NO_MATCH, $rawInput, false, []);
        }

        $normalizedKey = $this->normalizeForAliasLookup($rawInput);
        $compactKey = $this->compactLookupKey($normalizedKey);
        if ($normalizedKey === '' && $compactKey === '') {
            return $this->result(false, null, null, null, null, null, self::CONFIDENCE_NO_MATCH, $rawInput, false, []);
        }

        $aliases = CityAlias::query()
            ->where('is_active', true)
            ->where(function ($q) use ($normalizedKey, $compactKey) {
                $q->whereRaw('normalized_alias = ?', [$normalizedKey])
                    ->orWhereRaw('normalized_alias = ?', [$compactKey]);
            })
            ->orderByRaw('CASE WHEN normalized_alias = ? THEN 0 ELSE 1 END', [$normalizedKey])
            ->orderBy('id')
            ->get();

        if ($aliases->isEmpty()) {
            return $this->result(false, null, null, null, null, null, self::CONFIDENCE_NO_MATCH, $rawInput, false, []);
        }

        if (! Schema::hasTable('cities')) {
            return $this->result(false, null, null, null, null, null, self::CONFIDENCE_NO_MATCH, $rawInput, false, []);
        }

        $candidateCityIds = $aliases
            ->pluck('city_id')
            ->filter(static fn ($id) => $id !== null)
            ->map(static fn ($id) => (int) $id)
            ->unique()
            ->values();

        $cities = City::query()
            ->with(['taluka.district.state'])
            ->whereIn('id', $candidateCityIds)
            ->get();

        if ($cities->isEmpty()) {
            return $this->result(false, null, null, null, null, null, self::CONFIDENCE_NO_MATCH, $rawInput, false, []);
        }

        if ($cities->count() > 1) {
            return $this->result(
                false,
                null,
                null,
                null,
                null,
                null,
                self::CONFIDENCE_NO_MATCH,
                $rawInput,
                true,
                $this->formatPossibleMatches($cities)
            );
        }

        $city = $cities->first();

        $districtId = $city->taluka?->district_id !== null ? (int) $city->taluka->district_id : null;
        $stateId = $city->taluka?->district?->state_id !== null ? (int) $city->taluka->district->state_id : null;
        $talukaId = $city->taluka_id !== null ? (int) $city->taluka_id : null;
        $countryId = null;
        if ($city->taluka?->district?->state !== null && $city->taluka->district->state->country_id !== null) {
            $countryId = (int) $city->taluka->district->state->country_id;
        }

        return $this->result(
            true,
            (int) $city->id,
            $districtId,
            $stateId,
            $talukaId,
            $countryId,
            self::CONFIDENCE_ALIAS_MATCH,
            $rawInput,
            false,
            [],
        );
    }

    /**
     * @return array{matched: bool, city_id: int|null, district_id: int|null, state_id: int|null, taluka_id: int|null, country_id: int|null, confidence: float, raw_input: string, ambiguity: bool, possible_matches: array<int, array{city_id:int, city_name:string, taluka_name:string, district_name:string, state_name:string}>}
     */
    private function result(
        bool $matched,
        ?int $cityId,
        ?int $districtId,
        ?int $stateId,
        ?int $talukaId,
        ?int $countryId,
        float $confidence,
        string $rawInput,
        bool $ambiguity,
        array $possibleMatches
    ): array
    {
        return [
            'matched' => $matched,
            'city_id' => $cityId,
            'district_id' => $districtId,
            'state_id' => $stateId,
            'taluka_id' => $talukaId,
            'country_id' => $countryId,
            'confidence' => $confidence,
            'raw_input' => $rawInput,
            'ambiguity' => $ambiguity,
            'possible_matches' => $possibleMatches,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, City>  $cities
     * @return array<int, array{city_id:int, city_name:string, taluka_name:string, district_name:string, state_name:string}>
     */
    private function formatPossibleMatches($cities): array
    {
        return $cities->map(static function (City $city): array {
            return [
                'city_id' => (int) $city->id,
                'city_name' => (string) ($city->name ?? ''),
                'taluka_name' => (string) ($city->taluka?->name ?? ''),
                'district_name' => (string) ($city->taluka?->district?->name ?? ''),
                'state_name' => (string) ($city->taluka?->district?->state?->name ?? ''),
            ];
        })->values()->all();
    }

    /**
     * Public merge key for suggestion dedupe — same rules as {@see normalizeForAliasLookup} / `city_aliases.normalized_alias`.
     */
    public function mergeKeyFromRaw(string $raw): string
    {
        return $this->normalizeForAliasLookup(trim($raw));
    }

    /**
     * Lowercase, trim, remove ASCII dots, collapse whitespace (Unicode-safe).
     */
    private function normalizeForAliasLookup(string $s): string
    {
        $s = str_replace('.', ' ', $s);
        $s = mb_strtolower($s, 'UTF-8');
        // Strip zero-width/invisible codepoints that break exact Unicode compares.
        $s = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', '', $s) ?? $s;
        // Keep combining marks (\p{M}) so Marathi matras survive (e.g. "पु   णे" -> "पु णे", not "प ण").
        $s = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', $s) ?? $s;
        $s = trim((string) (preg_replace('/\s+/u', ' ', $s) ?? $s));

        return $s;
    }

    private function compactLookupKey(string $normalized): string
    {
        return preg_replace('/\s+/u', '', $normalized) ?? '';
    }
}
