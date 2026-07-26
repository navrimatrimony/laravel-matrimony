<?php

namespace App\Models;

use App\Services\ProfileFieldConfigurationService;
use Illuminate\Database\Eloquent\Model;

/*
|--------------------------------------------------------------------------
| ProfileFieldConfig Model (SSOT Day 5-6)
|--------------------------------------------------------------------------
|
| Database-backed configuration for profile fields.
| Foundation only — stores config, no business logic wiring.
|
*/
class ProfileFieldConfig extends Model
{
    protected $table = 'profile_field_configs';

    protected $fillable = [
        'field_key',
        'is_enabled',
        'is_visible',
        'is_searchable',
        'is_mandatory',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'is_visible' => 'boolean',
        'is_searchable' => 'boolean',
        'is_mandatory' => 'boolean',
    ];

    /**
     * The read side ({@see ProfileFieldConfigurationService}) memoises the flag lists for the life of
     * the process; any write here must invalidate that memo so the admin field-configuration screen
     * shows its own edit immediately.
     */
    protected static function booted(): void
    {
        static::saved(static fn () => ProfileFieldConfigurationService::flushRuntimeCache());
        static::deleted(static fn () => ProfileFieldConfigurationService::flushRuntimeCache());
    }
}
