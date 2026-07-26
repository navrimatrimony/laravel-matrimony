<?php

namespace App\Support\Location;

use Illuminate\Support\Facades\Http;

/**
 * Minimal forward-geocoder against OpenStreetMap / Nominatim, used ONLY as an outside referee when
 * scoring our own coordinate sources — never in a request path.
 *
 * Nominatim is a free public service with a strict usage policy: at most one request per second and a
 * real User-Agent. The sleep is inside {@see lookup()} rather than left to each caller, so no caller
 * can accidentally hammer it. Spot-check panels are kept to a few dozen calls.
 *
 * Shared by {@see \App\Console\Commands\AnalyzePincodeGeoSourceCommand} (taluka centres) and
 * {@see \App\Console\Commands\RepairVillageCoordinatesCommand} (village points).
 */
final class NominatimClient
{
    /** @return array{0:float,1:float}|null [lat, lng] */
    public static function lookup(string $query): ?array
    {
        usleep(1_100_000); // usage policy: <= 1 req/s

        try {
            $r = Http::withHeaders([
                'User-Agent' => (string) config('location.nominatim_user_agent', 'LaravelMatrimony-GeoAudit/1.0'),
                'Accept' => 'application/json',
                'Accept-Language' => 'en',
            ])->timeout(20)->get('https://nominatim.openstreetmap.org/search', [
                'q' => $query, 'format' => 'json', 'limit' => 1, 'countrycodes' => 'in',
            ]);
            $j = $r->json();

            return isset($j[0]['lat']) ? [(float) $j[0]['lat'], (float) $j[0]['lon']] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * First query that returns a hit wins. Lets a caller fall back from a fully-qualified place string
     * to a looser one without duplicating the rate-limit handling.
     *
     * @param  list<string>  $queries
     * @return array{0:float,1:float}|null
     */
    public static function first(array $queries): ?array
    {
        foreach ($queries as $q) {
            $hit = self::lookup($q);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }
}
