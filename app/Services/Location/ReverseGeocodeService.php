<?php

namespace App\Services\Location;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Guarded outbound reverse geocode (Nominatim). Never used for autocomplete keystrokes.
 */
class ReverseGeocodeService
{
    public function reverse(float $lat, float $lon): ?array
    {
        $base = rtrim((string) config('location.nominatim_base_url', 'https://nominatim.openstreetmap.org'), '/');
        $ua = (string) config('location.nominatim_user_agent', 'LaravelMatrimony/1.0');
        $timeout = max(5, (int) config('location.nominatim_timeout_seconds', 12));

        try {
            $response = Http::withHeaders([
                'User-Agent' => $ua,
                'Accept' => 'application/json',
                'Accept-Language' => 'en',
            ])
                ->timeout($timeout)
                ->get($base.'/reverse', [
                    'lat' => $lat,
                    'lon' => $lon,
                    'format' => 'json',
                    'addressdetails' => 1,
                    'zoom' => 18,
                ]);

            if (! $response->successful()) {
                Log::warning('location.reverse_geocode.http_error', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $json = $response->json();

            return is_array($json) ? $json : null;
        } catch (\Throwable $e) {
            Log::warning('location.reverse_geocode.exception', ['message' => $e->getMessage()]);

            return null;
        }
    }
}
