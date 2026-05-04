<?php

namespace App\Services\Location;

use App\Models\City;
use App\Models\Location;
use App\Models\LocationAlias;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE-5: alias hits live in {@code location_aliases} (FK → {@code addresses}.id).
 * Normalizes via active alias rows, then resolves hierarchy from the matched {@see Location} leaf.
 */
final class LocationNormalizationService
{
    private const CONFIDENCE_ALIAS_MATCH = 0.92;

    private const CONFIDENCE_NO_MATCH = 0.0;

    public function __construct(
        private readonly LocationService $locationService,
    ) {}

    /**
     * @return array{matched: bool, city_id: int|null, district_id: int|null, state_id: int|null, taluka_id: int|null, country_id: int|null, confidence: float, raw_input: string, ambiguity: bool, possible_matches: array<int, array<string, mixed>>, location_id: int|null}
     */
    public function normalizeFromText(string $raw): array
    {
        $rawInput = trim($raw);
        if ($rawInput === '') {
            return $this->result(false, null, null, null, null, null, self::CONFIDENCE_NO_MATCH, '', false, [], null);
        }

        if (! Schema::hasTable('location_aliases')) {
            return $this->result(false, null, null, null, null, null, self::CONFIDENCE_NO_MATCH, $rawInput, false, [], null);
        }

        $normalizedKey = $this->normalizeForAliasLookup($rawInput);
        $compactKey = $this->compactLookupKey($normalizedKey);
        if ($normalizedKey === '' && $compactKey === '') {
            return $this->result(false, null, null, null, null, null, self::CONFIDENCE_NO_MATCH, $rawInput, false, [], null);
        }

        $aliasQuery = LocationAlias::query();
        if (Schema::hasColumn((new LocationAlias)->getTable(), 'is_active')) {
            $aliasQuery->where('is_active', true);
        }
        $aliases = $aliasQuery
            ->where(function ($q) use ($normalizedKey, $compactKey): void {
                $q->whereRaw('normalized_alias = ?', [$normalizedKey])
                    ->orWhereRaw('normalized_alias = ?', [$compactKey]);
            })
            ->orderByRaw('CASE WHEN normalized_alias = ? THEN 0 ELSE 1 END', [$normalizedKey])
            ->orderBy('id')
            ->get();

        if ($aliases->isEmpty()) {
            return $this->result(false, null, null, null, null, null, self::CONFIDENCE_NO_MATCH, $rawInput, false, [], null);
        }

        if (! Schema::hasTable('addresses')) {
            return $this->result(false, null, null, null, null, null, self::CONFIDENCE_NO_MATCH, $rawInput, false, [], null);
        }

        $locationIds = $aliases->pluck('location_id')->filter()->map(static fn ($id) => (int) $id)->unique()->values();

        $locations = Location::query()->whereIn('id', $locationIds)->get();

        if ($locations->isEmpty()) {
            return $this->result(false, null, null, null, null, null, self::CONFIDENCE_NO_MATCH, $rawInput, false, [], null);
        }

        if ($locations->count() > 1) {
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
                $this->formatPossibleMatches($locations),
                null,
            );
        }

        $loc = $locations->first();

        return $this->buildMatchFromLocation($loc, $rawInput);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Location>  $locations
     * @return array<int, array<string, mixed>>
     */
    private function formatPossibleMatches($locations): array
    {
        return $locations->map(function (Location $loc): array {
            $h = $this->locationService->getFullHierarchy($loc);

            return [
                'city_id' => (int) $loc->id,
                'city_name' => $loc->localizedName(),
                'taluka_name' => $h['taluka'] ? $h['taluka']->localizedName() : '',
                'district_name' => $h['district'] ? $h['district']->localizedName() : '',
                'state_name' => $h['state'] ? $h['state']->localizedName() : '',
            ];
        })->values()->all();
    }

    /**
     * @return array{matched: bool, city_id: int|null, district_id: int|null, state_id: int|null, taluka_id: int|null, country_id: int|null, confidence: float, raw_input: string, ambiguity: bool, possible_matches: array<int, array<string, mixed>>, location_id: int|null}
     */
    private function buildMatchFromLocation(Location $loc, string $rawInput): array
    {
        $locationId = (int) $loc->id;

        if ($loc->type === 'city') {
            $city = City::query()->with(['taluka.district.state.country'])->find($loc->id);
            if ($city === null) {
                return $this->result(false, null, null, null, null, null, self::CONFIDENCE_NO_MATCH, $rawInput, false, [], null);
            }

            $taluka = $city->taluka;
            $district = $taluka?->district;
            $state = $district?->state;
            $districtId = $taluka?->parent_id !== null ? (int) $taluka->parent_id : null;
            $stateId = $district?->parent_id !== null ? (int) $district->parent_id : null;
            $talukaId = $city->parent_id !== null ? (int) $city->parent_id : null;
            $countryId = $state?->parent_id !== null ? (int) $state->parent_id : null;

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
                $locationId,
            );
        }

        $h = $this->locationService->getFullHierarchy($loc);
        $state = $h['state'];
        $district = $h['district'];
        $taluka = $h['taluka'];

        $districtId = $district?->id !== null ? (int) $district->id : null;
        $stateId = $state?->id !== null ? (int) $state->id : null;
        $talukaId = $taluka?->id !== null ? (int) $taluka->id : null;
        $countryId = null;
        if ($state !== null && isset($state->attributes['parent_id'])) {
            $countryId = (int) $state->parent_id;
        }

        return $this->result(
            true,
            null,
            $districtId,
            $stateId,
            $talukaId,
            $countryId,
            self::CONFIDENCE_ALIAS_MATCH,
            $rawInput,
            false,
            [],
            $locationId,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $possibleMatches
     * @return array{matched: bool, city_id: int|null, district_id: int|null, state_id: int|null, taluka_id: int|null, country_id: int|null, confidence: float, raw_input: string, ambiguity: bool, possible_matches: array<int, array<string, mixed>>, location_id: int|null}
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
        array $possibleMatches,
        ?int $locationId,
    ): array {
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
            'location_id' => $locationId,
        ];
    }

    /**
     * Public merge key for suggestion dedupe — same rules as {@see normalizeForAliasLookup} / {@code location_aliases.normalized_alias}.
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
        $s = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', '', $s) ?? $s;
        $s = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', $s) ?? $s;
        $s = trim((string) (preg_replace('/\s+/u', ' ', $s) ?? $s));

        return $s;
    }

    private function compactLookupKey(string $normalized): string
    {
        return preg_replace('/\s+/u', '', $normalized) ?? '';
    }
}
