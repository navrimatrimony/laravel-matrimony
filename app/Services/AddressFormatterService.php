<?php

namespace App\Services;

use App\Services\Location\LocationFormatterService;

/**
 * Back-compat facade for profile residence lines — delegates to {@see LocationFormatterService}.
 *
 * @deprecated Prefer {@see LocationFormatterService::formatLocation()} directly.
 */
final class AddressFormatterService
{
    /**
     * @param  int|null  $addressId  Primary key of {@see Location} ({@code addresses}.id)
     */
    public function format(?int $addressId): string
    {
        return app(LocationFormatterService::class)->formatLocation($addressId);
    }
}
