<?php

namespace App\Models;

use App\Services\Location\LocationHierarchyValidator;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * District rows in {@code addresses} ({@code hierarchy = district}).
 *
 * @deprecated Prefer {@see Location}.
 */
class District extends Location
{
    /** @return list<string> */
    public function getFillable(): array
    {
        return array_merge(parent::getFillable(), ['state_id']);
    }

    protected static function booted(): void
    {
        static::saving(function (District $district): void {
            $district->hierarchy = 'district';
            if (isset($district->attributes['state_id'])) {
                $district->parent_id = $district->attributes['state_id'];
                unset($district->attributes['state_id']);
            }
            if (($district->slug === null || $district->slug === '') && filled($district->name)) {
                $pid = (int) ($district->parent_id ?? 0);
                if ($pid > 0) {
                    $district->slug = static::uniqueSlugForState($pid, (string) $district->name);
                }
            }
            app(LocationHierarchyValidator::class)->validate($district);
        });

        parent::booted();

        static::addGlobalScope('geo_district', fn ($q) => $q->where('hierarchy', 'district'));
    }

    /**
     * Stable URL segment per state (parent_id = state row id).
     */
    public static function uniqueSlugForState(int $stateId, string $englishName, ?int $exceptDistrictId = null): string
    {
        return static::uniqueSlugForHierarchy($stateId, 'district', $englishName, $exceptDistrictId);
    }

    public function getStateIdAttribute(): ?int
    {
        return isset($this->attributes['parent_id']) ? (int) $this->attributes['parent_id'] : null;
    }

    public function setStateIdAttribute(mixed $value): void
    {
        $this->attributes['parent_id'] = $value;
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class, 'parent_id');
    }

    public function talukas(): HasMany
    {
        return $this->hasMany(Taluka::class, 'parent_id');
    }
}
