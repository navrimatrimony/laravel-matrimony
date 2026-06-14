<?php

namespace App\Models;

use App\Services\Location\LocationFormatterService;
use App\Services\Location\LocationHierarchyValidator;
use InvalidArgumentException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Canonical geographic hierarchy (country → state → district → taluka → village).
 *
 * **Only** the {@code addresses} table stores hierarchy rows (single source of truth). Do not query
 * non-existent parallel tables such as {@code countries}, {@code states}, etc.; typed subclasses
 * ({@see Country}, {@see State}, …) are scoped views over {@code addresses}.
 *
 * This is not {@see ProfileAddress} (profile_addresses — member postal rows).
 */
class Location extends Model
{
    /**
     * SSOT table for geo hierarchy (not the legacy country/state/district/taluka/village master tables).
     */
    protected $table = 'addresses';

    public static function geoTable(): string
    {
        return (new static)->getTable();
    }

    public static function defaultLevelForHierarchy(string $hierarchy): int
    {
        return match ($hierarchy) {
            'country' => 0,
            'state' => 1,
            'district' => 2,
            'taluka' => 3,
            'village' => 4,
            default => throw new InvalidArgumentException("Unsupported address hierarchy [{$hierarchy}]."),
        };
    }

    public static function cleanSlugBase(string $name, string $fallback = 'address'): string
    {
        $base = \Illuminate\Support\Str::slug($name);

        return $base !== '' ? $base : $fallback;
    }

    public static function uniqueSlugForHierarchy(?int $parentId, string $hierarchy, string $name, ?int $exceptLocationId = null): string
    {
        $base = self::cleanSlugBase($name);
        $slug = $base;
        $suffix = 2;

        while (self::query()
            ->withoutGlobalScopes()
            ->where('hierarchy', $hierarchy)
            ->when($parentId === null, fn ($q) => $q->whereNull('parent_id'), fn ($q) => $q->where('parent_id', $parentId))
            ->where('slug', $slug)
            ->when($exceptLocationId !== null, fn ($q) => $q->where('id', '!=', $exceptLocationId))
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    protected $fillable = [
        'name',
        'name_mr',
        'name_en',
        'slug',
        'hierarchy',
        'tag',
        'category',
        'parent_id',
        'level',
        'is_active',
        'pincode',
        'lat',
        'lng',
        'lgd_code',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'level' => 'integer',
        'lat' => 'float',
        'lng' => 'float',
    ];

    /**
     * UI / rules use "category" (city/suburban/rural); DB column is {@code tag}.
     */
    protected function category(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value, array $attributes) => $attributes['tag'] ?? $attributes['category'] ?? null,
            set: fn (?string $value) => ['tag' => $value],
        );
    }

    protected static function booted(): void
    {
        static::saving(function (Location $location): void {
            $location->level = self::defaultLevelForHierarchy((string) $location->hierarchy);
            // Only validate base Location rows here; subclasses normalize hierarchy/parent in their own saving hooks first.
            if ($location::class !== self::class) {
                return;
            }
            app(LocationHierarchyValidator::class)->validate($location);
        });
    }

    /**
     * Display name following app locale (Marathi when {@code name_mr} is set and locale is {@code mr}).
     */
    public function localizedName(): string
    {
        if (app()->getLocale() === 'mr' && filled($this->name_mr)) {
            return trim((string) $this->name_mr);
        }

        return trim((string) $this->name);
    }

    /**
     * Computed label only — not stored (see {@see LocationFormatterService::formatForLocation}).
     */
    public function getDisplayLabelAttribute(): string
    {
        if (! $this->id) {
            return $this->localizedName();
        }

        return app(LocationFormatterService::class)->formatForLocation($this);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(LocationAlias::class, 'location_id');
    }

    public function usageStat(): HasOne
    {
        return $this->hasOne(LocationUsageStat::class, 'location_id');
    }
}
