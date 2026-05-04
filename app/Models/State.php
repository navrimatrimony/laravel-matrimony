<?php

namespace App\Models;

use App\Services\Location\LocationHierarchyValidator;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * State rows in {@code addresses} ({@code type = state}).
 *
 * @deprecated Prefer {@see Location}.
 */
class State extends Location
{
    /** @return list<string> */
    public function getFillable(): array
    {
        return array_merge(parent::getFillable(), ['country_id']);
    }

    protected static function booted(): void
    {
        static::saving(function (State $state): void {
            $state->type = 'state';
            if (isset($state->attributes['country_id'])) {
                $state->parent_id = $state->attributes['country_id'];
                unset($state->attributes['country_id']);
            }
            if (($state->slug ?? '') === '' && filled($state->name)) {
                $state->slug = Str::slug((string) $state->name).'-'.substr(md5((string) ($state->parent_id ?? '0').'-'.$state->name), 0, 8);
            }
            app(LocationHierarchyValidator::class)->validate($state);
        });

        parent::booted();

        static::addGlobalScope('geo_state', fn ($q) => $q->where('type', 'state'));
    }

    /**
     * Legacy column name: {@code country_id} on state rows is {@code parent_id} in {@code addresses}.
     */
    public function getCountryIdAttribute(): ?int
    {
        return isset($this->attributes['parent_id']) ? (int) $this->attributes['parent_id'] : null;
    }

    public function setCountryIdAttribute(mixed $value): void
    {
        $this->attributes['parent_id'] = $value;
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'parent_id');
    }

    public function districts(): HasMany
    {
        return $this->hasMany(District::class, 'parent_id');
    }
}
