<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reverse geocode (Nominatim) — user-triggered only, never for typed search
    |--------------------------------------------------------------------------
    */
    'nominatim_base_url' => env('LOCATION_NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),

    'nominatim_user_agent' => env('LOCATION_NOMINATIM_USER_AGENT', 'LaravelMatrimony/1.0 (profile location assist; contact: admin@localhost)'),

    'nominatim_timeout_seconds' => (int) env('LOCATION_NOMINATIM_TIMEOUT', 12),

    /*
    |--------------------------------------------------------------------------
    | Outbound guard: serialized short lock so concurrent users share one flight
    |--------------------------------------------------------------------------
    */
    'reverse_geocode_lock_seconds' => (int) env('LOCATION_REVERSE_GEOCODE_LOCK_SECONDS', 3),

    'reverse_geocode_lock_wait_seconds' => (int) env('LOCATION_REVERSE_GEOCODE_LOCK_WAIT_SECONDS', 5),

    /*
    |--------------------------------------------------------------------------
    | Resolver cache TTLs (seconds)
    |--------------------------------------------------------------------------
    */
    'resolve_user_cache_ttl' => (int) env('LOCATION_RESOLVE_USER_CACHE_TTL', 3600),

    'resolve_geo_cache_ttl' => (int) env('LOCATION_RESOLVE_GEO_CACHE_TTL', 604800),

];
