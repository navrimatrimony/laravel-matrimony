<?php

namespace App\Services\Location;

use App\Models\Location;
use Illuminate\Support\Facades\Schema;

/**
 * Single SSOT formatter for {@see Location} rows in {@code addresses}.
 *
 * Hierarchy ({@code parent_id}) defines structure only; {@code tag} (alias {@see Location::$category}) drives UI copy.
 * Do not use legacy {@code taluka_id} / {@code district_id} columns — walk {@code parent_id} via {@see LocationService}.
 */
final class LocationFormatterService
{
    public function __construct(
        private readonly LocationService $locationService,
    ) {}

    /**
     * One-line display string for a canonical geo row (profile residence, search, nearby, listings).
     */
    public function formatLocation(?int $locationId): string
    {
        if ($locationId === null || $locationId < 1 || ! Schema::hasTable(Location::geoTable())) {
            return '';
        }

        $location = Location::query()->find($locationId);
        if ($location === null) {
            return '';
        }

        return $this->formatForLocation($location);
    }

    /**
     * Format when a {@see Location} model is already loaded (avoids an extra query).
     */
    public function formatForLocation(Location $location): string
    {
        if (! Schema::hasTable(Location::geoTable())) {
            return '';
        }

        $this->locationService->ensureAncestorsLoaded($location);

        if (($location->hierarchy ?? '') === 'country') {
            return $location->localizedName();
        }

        $tag = $this->effectiveTag($location);
        if ($tag === 'none') {
            return $location->localizedName();
        }

        $h = $this->enrichHierarchy($location, $this->locationService->getFullHierarchy($location));
        $h = $this->locationService->fillHierarchyGaps($location, $h);

        return match ($tag) {
            'rural', 'village' => $this->formatRural($location, $h),
            'suburban' => $this->formatSuburban($location, $h),
            'taluka' => $this->formatTalukaLine($location, $h),
            'metro', 'capital', 'city' => $this->formatMetroCapitalCity($location, $h),
            'town' => $this->formatTown($location, $h),
            default => $this->formatFallback($location, $h),
        };
    }

    private function effectiveTag(Location $location): string
    {
        $raw = strtolower(trim((string) ($location->category ?? '')));
        if ($raw === 'none') {
            return 'none';
        }
        if ($raw !== '') {
            return $raw;
        }

        return match ((string) ($location->hierarchy)) {
            'village' => 'rural',
            'taluka' => 'city',
            'district' => 'city',
            'state' => 'none',
            default => '',
        };
    }

    /**
     * @param  array<string, Location|null>  $h
     * @return array<string, Location|null>
     */
    private function enrichHierarchy(Location $leaf, array $h): array
    {
        if ($leaf->hierarchy === 'district' && ($h['district'] ?? null) === null) {
            $h['district'] = $leaf;
        }
        if ($leaf->hierarchy === 'taluka' && ($h['taluka'] ?? null) === null) {
            $h['taluka'] = $leaf;
        }
        if ($leaf->hierarchy === 'village' && ($leaf->category ?? '') === 'city' && ($h['city'] ?? null) === null) {
            $h['city'] = $leaf;
        }

        return $h;
    }

    /**
     * @param  array<string, Location|null>  $h
     */
    private function formatRural(Location $leaf, array $h): string
    {
        $body = $this->joinGeoChain([$leaf, $h['taluka'] ?? null, $h['district'] ?? null]);

        return $this->appendPin($body, $this->resolvePincode($leaf, $h));
    }

    /**
     * @param  array<string, Location|null>  $h
     */
    private function formatSuburban(Location $leaf, array $h): string
    {
        $sub = $leaf->localizedName();
        $cityLoc = $this->resolveCityLocation($leaf, $h);
        $cityName = $cityLoc?->localizedName() ?? '';

        if ($cityName === '') {
            $body = $this->joinSuburbThenGeoChain($sub, $h['taluka'] ?? null, $h['district'] ?? null);
        } else {
            $body = $this->joinDistinct([$sub, $cityName]);
        }

        return $this->appendPin($body, $this->resolvePincode($leaf, $h));
    }

    /**
     * @param  array<string, Location|null>  $h
     */
    private function formatTalukaLine(Location $leaf, array $h): string
    {
        $body = $this->joinGeoChain([$leaf, $h['district'] ?? null]);

        return $this->appendPin($body, $this->resolvePincode($leaf, $h));
    }

    /**
     * @param  array<string, Location|null>  $h
     */
    private function formatMetroCapitalCity(Location $leaf, array $h): string
    {
        return $this->joinDistinct([
            $this->resolveMetroCityName($leaf, $h),
            $h['state']?->localizedName() ?? '',
        ]);
    }

    /**
     * @param  array<string, Location|null>  $h
     */
    private function resolveMetroCityName(Location $leaf, array $h): string
    {
        if (($leaf->hierarchy ?? '') === 'district' || (($leaf->hierarchy ?? '') === 'village' && ($leaf->category ?? '') === 'city')) {
            return $leaf->localizedName();
        }

        if (($h['city'] ?? null) !== null) {
            return $h['city']->localizedName();
        }

        $cityLoc = $this->resolveCityLocation($leaf, $h);
        if ($cityLoc !== null) {
            return $cityLoc->localizedName();
        }

        return $leaf->localizedName();
    }

    /**
     * @param  array<string, Location|null>  $h
     */
    private function formatTown(Location $leaf, array $h): string
    {
        $body = $this->joinGeoChain([$leaf, $h['district'] ?? null]);

        return $this->appendPin($body, $this->resolvePincode($leaf, $h));
    }

    /**
     * @param  array<string, Location|null>  $h
     */
    private function formatFallback(Location $leaf, array $h): string
    {
        return $this->joinDistinct([
            $leaf->localizedName(),
            $h['district']?->localizedName() ?? '',
            $h['state']?->localizedName() ?? '',
        ]);
    }

    /**
     * @param  array<string, Location|null>  $h
     */
    private function resolveCityLocation(Location $leaf, array $h): ?Location
    {
        if (($h['city'] ?? null) !== null) {
            return $h['city'];
        }

        $current = $leaf->parent;
        $guard = 0;
        while ($current !== null && $guard++ < 24) {
            if ((string) ($current->hierarchy ?? '') === 'village' && ($current->category ?? '') === 'city') {
                return $current;
            }
            if (! $current->relationLoaded('parent')) {
                $current->load('parent');
            }
            $current = $current->parent;
        }

        return null;
    }

    /**
     * First non-empty pincode on leaf or ancestors (city → taluka → district).
     *
     * @param  array<string, Location|null>  $h
     */
    private function resolvePincode(Location $leaf, array $h): string
    {
        foreach ([$leaf, $h['city'] ?? null, $h['taluka'] ?? null, $h['district'] ?? null] as $node) {
            if ($node === null) {
                continue;
            }
            $pin = $this->normalizePin((string) ($node->pincode ?? ''));
            if ($pin !== '') {
                return $pin;
            }
        }

        return '';
    }

    private function normalizePin(string $pin): string
    {
        return preg_replace('/\s+/u', '', trim($pin)) ?? '';
    }

    private function appendPin(string $body, string $pin): string
    {
        $body = trim($body);
        if ($pin === '') {
            return $body;
        }
        if ($body === '') {
            return $pin;
        }

        return $body.' '.$pin;
    }

    /**
     * Ordered labels for {@see Location} rows; dedupe by row id only so same spelling
     * (e.g. village and taluka both "Tasgaon") still shows both — SSOT hierarchy is not dropped.
     *
     * @param  array<int, Location|null>  $chain
     */
    private function joinGeoChain(array $chain): string
    {
        $labels = [];
        $seenIds = [];
        foreach ($chain as $node) {
            if (! $node instanceof Location) {
                continue;
            }
            $id = (int) $node->id;
            if ($id > 0) {
                if (isset($seenIds[$id])) {
                    continue;
                }
                $seenIds[$id] = true;
            }
            $label = trim($node->localizedName());
            if ($label === '') {
                continue;
            }
            $labels[] = $label;
        }

        return implode(', ', $labels);
    }

    /**
     * Suburb name (plain string) then taluka/district {@see Location} labels — do not drop taluka/district
     * when their text equals the suburb name (unlike {@see joinDistinct} on strings).
     */
    private function joinSuburbThenGeoChain(string $suburbName, ?Location $taluka, ?Location $district): string
    {
        $parts = [];
        $sub = trim($suburbName);
        if ($sub !== '') {
            $parts[] = $sub;
        }
        $tail = $this->joinGeoChain([$taluka, $district]);
        if ($tail !== '') {
            $parts[] = $tail;
        }

        return implode(', ', $parts);
    }

    /**
     * @param  array<int, string|null>  $parts
     */
    private function joinDistinct(array $parts): string
    {
        $clean = [];
        foreach ($parts as $part) {
            $value = trim((string) ($part ?? ''));
            if ($value === '') {
                continue;
            }
            $last = $clean[count($clean) - 1] ?? null;
            if ($last !== null && mb_strtolower($last, 'UTF-8') === mb_strtolower($value, 'UTF-8')) {
                continue;
            }
            $clean[] = $value;
        }

        return implode(', ', $clean);
    }
}
