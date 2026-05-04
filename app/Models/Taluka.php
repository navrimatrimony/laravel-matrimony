<?php

namespace App\Models;

use App\Services\Location\LocationHierarchyValidator;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Taluka rows in {@code addresses} ({@code type = taluka}).
 *
 * @deprecated Prefer {@see Location}.
 */
class Taluka extends Location
{
    /** @return list<string> */
    public function getFillable(): array
    {
        return array_merge(parent::getFillable(), ['district_id']);
    }

    protected static function booted(): void
    {
        static::saving(function (Taluka $taluka): void {
            $taluka->type = 'taluka';
            if (isset($taluka->attributes['district_id'])) {
                $taluka->parent_id = $taluka->attributes['district_id'];
                unset($taluka->attributes['district_id']);
            }
            if (($taluka->slug ?? '') === '' && filled($taluka->name)) {
                $taluka->slug = Str::slug((string) $taluka->name).'-t'.substr(md5((string) ($taluka->parent_id ?? '0')), 0, 6);
            }
            app(LocationHierarchyValidator::class)->validate($taluka);
        });

        parent::booted();

        static::addGlobalScope('geo_taluka', fn ($q) => $q->where('type', 'taluka'));
    }

    public function getDistrictIdAttribute(): ?int
    {
        return isset($this->attributes['parent_id']) ? (int) $this->attributes['parent_id'] : null;
    }

    public function setDistrictIdAttribute(mixed $value): void
    {
        $this->attributes['parent_id'] = $value;
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'parent_id');
    }

    public function cities(): HasMany
    {
        return $this->hasMany(City::class, 'parent_id');
    }
}
