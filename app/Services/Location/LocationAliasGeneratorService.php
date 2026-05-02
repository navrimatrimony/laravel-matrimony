<?php

namespace App\Services\Location;

use App\Models\City;
use App\Models\Location;
use App\Models\LocationAlias;
use App\Models\LocationOpenPlaceSuggestion;
use Illuminate\Support\Facades\Schema;

/**
 * Generate searchable aliases for unified locations when suggestions are approved / mapped.
 */
class LocationAliasGeneratorService
{
    public function __construct(
        private readonly LocationNormalizationService $normalization,
        private readonly CityToLocationResolverService $cityResolver,
    ) {}

    /**
     * Called after open-place approval attaches a {@see City}; syncs aliases onto matching {@see Location} when found.
     */
    public function syncFromApprovedSuggestion(City $city, LocationOpenPlaceSuggestion $suggestion): void
    {
        if (! Schema::hasTable('location_aliases')) {
            return;
        }

        $location = $this->cityResolver->findLocationForCity($city);
        if ($location === null) {
            return;
        }

        $strings = $this->variantStrings($suggestion);
        $this->upsertAliases($location, $strings);
    }

    /**
     * @param  list<string>  $rawStrings
     */
    public function upsertAliases(Location $location, array $rawStrings): void
    {
        if (! Schema::hasTable('location_aliases')) {
            return;
        }

        foreach ($rawStrings as $raw) {
            $trim = trim($raw);
            if ($trim === '' || mb_strlen($trim) < 2) {
                continue;
            }

            $normalized = $this->normalization->mergeKeyFromRaw($trim);
            if ($normalized === '') {
                continue;
            }

            LocationAlias::query()->firstOrCreate(
                [
                    'location_id' => $location->id,
                    'normalized_alias' => $normalized,
                ],
                [
                    'alias' => mb_substr($trim, 0, 255),
                ]
            );
        }
    }

    /**
     * @return list<string>
     */
    private function variantStrings(LocationOpenPlaceSuggestion $suggestion): array
    {
        $raw = trim((string) $suggestion->raw_input);
        $norm = trim((string) $suggestion->normalized_input);
        $merged = $this->normalization->mergeKeyFromRaw($raw);

        $parts = array_filter([
            $raw,
            $norm,
            $merged,
        ]);

        if (mb_strlen($merged) >= 4) {
            $parts[] = $merged.'d';
        }

        return array_values(array_unique($parts));
    }
}
