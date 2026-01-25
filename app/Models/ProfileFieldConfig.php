<?php

namespace App\Models;

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
}
