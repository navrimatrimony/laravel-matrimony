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

        if (($location->type ?? '') === 'country') {
            return $location->localizedName();
        }

        $tag = $this->effectiveTag($location);
        if ($tag === 'none') {
            return $location->localizedName();
        }

        $h = $this->enrichHierarchy($location, $this->locationService->getFullHierarchy($location));

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

        return match ((string) ($location->type)) {
            'village' => 'rural',
            'suburb' => 'suburban',
            'taluka' => 'taluka',
            'city' => 'city',
            'district' => 'town',
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
        if ($leaf->type === 'district' && ($h['district'] ?? null) === null) {
            $h['district'] = $leaf;
        }
        if ($leaf->type === 'taluka' && ($h['taluka'] ?? null) === null) {
            $h['taluka'] = $leaf;
        }
        if ($leaf->type === 'city' && ($h['city'] ?? null) === null) {
            $h['city'] = $leaf;
        }

        return $h;
    }

    /**
     * @param  array<string, Location|null>  $h
     */
    private function formatRural(Location $leaf, array $h): string
    {
        $body = $this->joinDistinct([
            $leaf->localizedName(),
            $h['taluka']?->localizedName() ?? '',
            $h['district']?->localizedName() ?? '',
        ]);

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
            $body = $this->joinDistinct([
                $sub,
                $h['taluka']?->localizedName() ?? '',
                $h['district']?->localizedName() ?? '',
            ]);
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
        $body = $this->joinDistinct([
            $leaf->localizedName(),
            $h['district']?->localizedName() ?? '',
        ]);

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
        if (in_array((string) ($leaf->type ?? ''), ['city', 'district'], true)) {
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
        $body = $this->joinDistinct([
            $leaf->localizedName(),
            $h['district']?->localizedName() ?? '',
        ]);

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
            if ((string) ($current->type ?? '') === 'city') {
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
