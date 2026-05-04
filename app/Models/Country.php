<?php

namespace App\Models;

use App\Services\Location\LocationHierarchyValidator;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Country rows in {@code addresses} ({@code type = country}).
 *
 * @deprecated Prefer {@see Location} directly.
 */
class Country extends Location
{
    protected static function booted(): void
    {
        static::saving(function (Country $country): void {
            $country->type = 'country';
            if (($country->slug ?? '') === '' && filled($country->name)) {
                $suffix = filled($country->iso_alpha2) ? strtolower((string) $country->iso_alpha2) : Str::slug((string) $country->name);
                $country->slug = Str::slug((string) $country->name).'-'.$suffix;
            }
            app(LocationHierarchyValidator::class)->validate($country);
        });

        parent::booted();

        static::addGlobalScope('geo_country', fn ($q) => $q->where('type', 'country'));
    }

    public function states(): HasMany
    {
        return $this->hasMany(State::class, 'parent_id');
    }
}
