<?php

namespace App\Services\Location;

/**
 * Human-facing settlement category (metro/city/town/village/suburban), separate from strict {@code type} hierarchy.
 */
final class LocationCategoryResolver
{
    /**
     * @return 'metro'|'city'|'town'|'village'|'suburban'|null
     */
    public function resolve(string $name, string $type): ?string
    {
        $nameKey = mb_strtolower(trim($name), 'UTF-8');
        if (in_array($nameKey, ['pune', 'mumbai'], true)) {
            return 'metro';
        }

        return match ($type) {
            'district' => 'city',
            'taluka' => 'town',
            'village' => 'village',
            'suburb' => 'suburban',
            'city' => 'city',
            default => null,
        };
    }
}
