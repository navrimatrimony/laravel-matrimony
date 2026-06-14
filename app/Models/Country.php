<?php

namespace App\Models;

use App\Services\Location\LocationHierarchyValidator;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Country rows in {@code addresses} ({@code hierarchy = country}).
 *
 * @deprecated Prefer {@see Location} directly.
 */
class Country extends Location
{
    protected static function booted(): void
    {
        static::saving(function (Country $country): void {
            $country->hierarchy = 'country';
            if (($country->slug ?? '') === '' && filled($country->name)) {
                $country->slug = static::uniqueSlugForHierarchy(null, 'country', (string) $country->name, $country->id ? (int) $country->id : null);
            }
            app(LocationHierarchyValidator::class)->validate($country);
        });

        parent::booted();

        static::addGlobalScope('geo_country', fn ($q) => $q->where('hierarchy', 'country'));
    }

    public function states(): HasMany
    {
        return $this->hasMany(State::class, 'parent_id');
    }
}
