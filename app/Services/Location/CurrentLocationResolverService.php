<?php

namespace App\Services\Location;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Cache-first GPS resolution: user → rounded coords → (no DB lat/lng in schema) → Nominatim → canonical remap.
 */
class CurrentLocationResolverService
{
    public function __construct(
        private ReverseGeocodeService $reverseGeocodeService,
        private CanonicalLocationMatchService $canonicalLocationMatchService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(int $userId, float $lat, float $lon): array
    {
        $lat = round($lat, 6);
        $lon = round($lon, 6);
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            return ['success' => false, 'status' => 'invalid_coordinates'];
        }

        $rLat = round($lat, 3);
        $rLon = round($lon, 3);

        $userTtl = (int) config('location.resolve_user_cache_ttl', 3600);
        $geoTtl = (int) config('location.resolve_geo_cache_ttl', 604800);

        $userKey = 'loc_resolve:user:'.$userId.':'.$rLat.':'.$rLon;
        $geoKey = 'loc_resolve:geo:'.$rLat.':'.$rLon;

        $cached = Cache::get($userKey);
        if (is_array($cached) && ($cached['success'] ?? false) === true) {
            return array_merge($cached, ['cache_layer' => 'user', 'source' => 'gps']);
        }

        $cached = Cache::get($geoKey);
        if (is_array($cached) && ($cached['success'] ?? false) === true) {
            return array_merge($cached, ['cache_layer' => 'geo', 'source' => 'gps']);
        }

        $neg = Cache::get($geoKey.'_negative');
        if (is_array($neg)) {
            return $neg;
        }

        /*
         * Nearest-city from DB skipped: cities/talukas have no latitude/longitude in schema.
         * Pincode + name matching after reverse geocode covers India use cases.
         */

        $lockSeconds = max(1, (int) config('location.reverse_geocode_lock_seconds', 3));
        $waitSeconds = max(1, (int) config('location.reverse_geocode_lock_wait_seconds', 5));
        $lock = Cache::lock('location:nominatim_outbound', $lockSeconds);

        try {
            return $lock->block($waitSeconds, function () use ($lat, $lon, $userKey, $geoKey, $userTtl, $geoTtl) {
                $cached = Cache::get($userKey);
                if (is_array($cached) && ($cached['success'] ?? false) === true) {
                    return array_merge($cached, ['cache_layer' => 'user', 'source' => 'gps']);
                }

                $cached = Cache::get($geoKey);
                if (is_array($cached) && ($cached['success'] ?? false) === true) {
                    return array_merge($cached, ['cache_layer' => 'geo', 'source' => 'gps']);
                }

                $negInner = Cache::get($geoKey.'_negative');
                if (is_array($negInner)) {
                    return $negInner;
                }

                $json = $this->reverseGeocodeService->reverse($lat, $lon);
                if ($json === null) {
                    $fail = [
                        'success' => false,
                        'status' => 'reverse_geocode_failed',
                        'message' => 'Could not resolve this location. Try manual search.',
                    ];
                    Cache::put($geoKey.'_negative', $fail, min(600, $geoTtl));

                    return $fail;
                }

                $match = $this->canonicalLocationMatchService->matchFromNominatim($json);
                if (! ($match['success'] ?? false) || ($match['primary'] ?? null) === null) {
                    $fail = [
                        'success' => false,
                        'status' => 'no_canonical_match',
                        'reason' => $match['reason'] ?? 'unknown',
                        'message' => 'Could not match to a known place in our directory. Use manual search or suggest a new location.',
                    ];
                    Cache::put($geoKey.'_negative', $fail, min(600, $geoTtl));

                    return $fail;
                }

                $primary = $match['primary'];
                $confidence = (float) ($match['confidence'] ?? 0.5);
                $alternatives = array_values(array_filter($match['alternatives'] ?? []));

                $display = $this->buildDisplayLabel($primary);

                $payload = [
                    'success' => true,
                    'status' => 'resolved',
                    'cache_layer' => 'reverse',
                    'source' => 'gps',
                    'confidence' => $confidence,
                    'display_label' => $display,
                    'city_id' => (int) ($primary['city_id'] ?? 0),
                    'taluka_id' => (int) ($primary['taluka_id'] ?? 0),
                    'district_id' => (int) ($primary['district_id'] ?? 0),
                    'state_id' => (int) ($primary['state_id'] ?? 0),
                    'country_id' => (int) ($primary['country_id'] ?? 0),
                    'alternatives' => $alternatives,
                    'requires_explicit_choice' => $confidence < 0.62 || count($alternatives) > 0,
                ];

                Cache::put($userKey, $payload, $userTtl);
                Cache::put($geoKey, $payload, $geoTtl);
                Cache::forget($geoKey.'_negative');

                return $payload;
            });
        } catch (LockTimeoutException) {
            return [
                'success' => false,
                'status' => 'busy',
                'retry_after_seconds' => 2,
                'message' => 'Location service is busy. Please try again in a moment.',
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $primary
     */
    private function buildDisplayLabel(array $primary): string
    {
        $city = (string) ($primary['city_name'] ?? '');
        $taluka = (string) ($primary['taluka_name'] ?? '');
        $district = (string) ($primary['district_name'] ?? '');
        $state = (string) ($primary['state_name'] ?? '');

        return trim($city.', '.$taluka.', '.$district.', '.$state, ' ,');
    }
}
