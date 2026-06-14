<?php

namespace App\Services\Location;

/**
 * Human-facing settlement category, separate from strict {@code hierarchy}.
 */
final class LocationCategoryResolver
{
    /**
     * @return 'city'|'suburban'|'rural'|null
     */
    public function resolve(string $name, string $hierarchy): ?string
    {
        $nameKey = mb_strtolower(trim($name), 'UTF-8');
        return match ($hierarchy) {
            'district' => 'city',
            'taluka' => 'suburban',
            'village' => in_array($nameKey, ['pune', 'mumbai'], true) ? 'city' : 'rural',
            default => null,
        };
    }
}
