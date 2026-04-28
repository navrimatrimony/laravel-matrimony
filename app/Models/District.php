<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Phase-4 Day-8: Location Hierarchy - District Model
 *
 * Unique per state: {@see $fillable name}, {@see $fillable slug} (URL-safe ASCII).
 *
 * Slug policy: generated once on {@see creating} from the English name. Renaming {@see $fillable name}
 * does **not** regenerate slug (stable URLs / FK-by-closure expectations). Change {@see $fillable slug}
 * explicitly only when an admin intentionally remaps identifiers.
 */
class District extends Model
{
    protected $fillable = ['state_id', 'name', 'name_mr', 'slug'];

    protected static function booted(): void
    {
        static::creating(function (District $district): void {
            if (($district->slug === null || $district->slug === '') && $district->name !== null && $district->name !== '') {
                $district->slug = static::uniqueSlugForState((int) $district->state_id, (string) $district->name);
            }
        });
    }

    /**
     * Stable URL segment per state (e.g. pune, mumbai-suburban). Handles collisions within the same state.
     */
    public static function uniqueSlugForState(int $stateId, string $englishName, ?int $exceptDistrictId = null): string
    {
        $base = Str::slug($englishName);
        if ($base === '') {
            $base = 'district';
        }
        $slug = $base;
        $n = 2;
        while (static::query()
            ->where('state_id', $stateId)
            ->where('slug', $slug)
            ->when($exceptDistrictId !== null, fn ($q) => $q->where('id', '!=', $exceptDistrictId))
            ->exists()) {
            $slug = $base.'-'.$n;
            $n++;
        }

        return $slug;
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function talukas(): HasMany
    {
        return $this->hasMany(Taluka::class);
    }
}
