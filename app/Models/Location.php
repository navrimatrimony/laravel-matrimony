<?php

namespace App\Models;

use App\Services\Location\LocationHierarchyValidator;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Canonical geographic hierarchy (country → … → city/suburb/village).
 *
 * Rows are stored in the {@code addresses} table (single source of truth). The {@see Location}
 * name is kept for code compatibility; this is not {@see ProfileAddress} (profile_addresses).
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

    public static function defaultLevelForType(string $type): int
    {
        return match ($type) {
            'country' => 0,
            'state' => 1,
            'district' => 2,
            'taluka' => 3,
            'city' => 4,
            'suburb', 'village' => 5,
            default => 4,
        };
    }

    protected $fillable = [
        'name',
        'name_mr',
        'slug',
        'type',
        'category',
        'parent_id',
        'level',
        'state_code',
        'district_code',
        'is_active',
        'pincode',
        'lgd_code',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'level' => 'integer',
    ];

    /**
     * UI / rules use "category" (metro, town, suburban…); DB column is {@code tag}.
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
            $location->level = self::defaultLevelForType((string) $location->type);
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
     * Computed label only — not stored (see also {@see \App\Services\Location\LocationService::getDisplayLabel}).
     */
    public function getDisplayLabelAttribute(): string
    {
        $this->loadMissing('parent');
        $parent = $this->parent;
        $parentName = $parent ? $parent->localizedName() : '';
        $suffix = $parentName !== '' ? ', '.$parentName : '';
        $typeLabel = $this->type !== null && $this->type !== '' ? ucfirst((string) $this->type) : '';

        return trim($this->localizedName().$suffix.' ('.$typeLabel.')');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function pincodes(): HasMany
    {
        return $this->hasMany(Pincode::class, 'place_id');
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
