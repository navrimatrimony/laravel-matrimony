<?php

namespace App\Models;

use App\Services\Location\LocationHierarchyValidator;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * District rows in {@code addresses} ({@code type = district}).
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
            $district->type = 'district';
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

        static::addGlobalScope('geo_district', fn ($q) => $q->where('type', 'district'));
    }

    /**
     * Stable URL segment per state (parent_id = state row id).
     */
    public static function uniqueSlugForState(int $stateId, string $englishName, ?int $exceptDistrictId = null): string
    {
        $base = Str::slug($englishName);
        if ($base === '') {
            $base = 'district';
        }
        $slug = $base;
        $n = 2;
        while (static::withoutGlobalScopes()
            ->where('type', 'district')
            ->where('parent_id', $stateId)
            ->where('slug', $slug)
            ->when($exceptDistrictId !== null, fn ($q) => $q->where('id', '!=', $exceptDistrictId))
            ->exists()) {
            $slug = $base.'-'.$n;
            $n++;
        }

        return $slug;
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
