<?php

namespace App\Models;

use App\Services\Location\LocationHierarchyValidator;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * City-classified leaf rows in {@code addresses} ({@code hierarchy = village}, {@code tag = city}).
 * Legacy attribute {@code taluka_id} maps to {@code parent_id}.
 *
 * @deprecated Prefer {@see Location}.
 */
class City extends Location
{
    /** @return list<string> */
    public function getFillable(): array
    {
        return array_merge(parent::getFillable(), ['taluka_id']);
    }

    protected static function booted(): void
    {
        static::saving(function (City $city): void {
            if (isset($city->attributes['taluka_id'])) {
                $city->parent_id = $city->attributes['taluka_id'];
                unset($city->attributes['taluka_id']);
            }
            if (isset($city->attributes['parent_city_id'])) {
                unset($city->attributes['parent_city_id']);
            }
            $city->hierarchy = 'village';
            $city->tag = 'city';
            if (($city->slug ?? '') === '' && filled($city->name)) {
                $city->slug = static::uniqueSlugForHierarchy($city->parent_id ? (int) $city->parent_id : null, 'village', (string) $city->name, $city->id ? (int) $city->id : null);
            }
            app(LocationHierarchyValidator::class)->validate($city);
        });

        parent::booted();

        static::addGlobalScope('geo_city', fn ($q) => $q->where('hierarchy', 'village')->where('tag', 'city'));
    }

    public function getTalukaIdAttribute(): ?int
    {
        return isset($this->attributes['parent_id']) ? (int) $this->attributes['parent_id'] : null;
    }

    public function setTalukaIdAttribute(mixed $value): void
    {
        $this->attributes['parent_id'] = $value;
    }

    public function taluka(): BelongsTo
    {
        return $this->belongsTo(Taluka::class, 'parent_id');
    }

    public function parentCity(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_city_id');
    }

    public function displayMeta(): HasOne
    {
        return $this->hasOne(LocationDisplayMeta::class, 'location_id');
    }
}
