<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase-3: Field registry (CORE + EXTENDED). field_key is immutable after insert.
 */
class FieldRegistry extends Model
{
    protected $table = 'field_registry';

    protected $fillable = [
        'field_key',
        'field_type',
        'data_type',
        'is_mandatory',
        'is_searchable',
        'is_user_editable',
        'is_system_overwritable',
        'lock_after_user_edit',
        'locked_by',
        'locked_at',
        'display_label',
        'display_order',
        'category',
        'is_enabled',
        'is_archived',
        'replaced_by_field',
        'parent_field_key',
        'dependency_condition',
    ];

    protected $casts = [
        'is_mandatory' => 'boolean',
        'is_searchable' => 'boolean',
        'is_user_editable' => 'boolean',
        'is_system_overwritable' => 'boolean',
        'lock_after_user_edit' => 'boolean',
        'locked_at' => 'datetime',
        'is_enabled' => 'boolean',
        'is_archived' => 'boolean',
        'dependency_condition' => 'array',
    ];
}
