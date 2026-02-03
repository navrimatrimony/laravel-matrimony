<?php

namespace Database\Seeders;

use App\Models\FieldRegistry;
use Illuminate\Database\Seeder;

/**
 * Phase-3 Day 1 — CORE Field Registry Seeder.
 * Seeds EXACTLY 9 CORE fields. Idempotent: upsert by field_key.
 * NEVER change field_key once created. No deletes. No Phase-2 tables touched.
 */
class FieldRegistryCoreSeeder extends Seeder
{
    /**
     * CORE fields list (EXACTLY 9 — from SSOT Phase-3):
     * full_name, gender, date_of_birth, marital_status, education, location, caste, height_cm, profile_photo
     */
    private const CORE_FIELDS = [
        [
            'field_key' => 'full_name',
            'field_type' => 'CORE',
            'data_type' => 'text',
            'is_mandatory' => false,
            'is_searchable' => false,
            'is_user_editable' => true,
            'is_system_overwritable' => true,
            'lock_after_user_edit' => true,
            'display_label' => 'Full Name',
            'display_order' => 100,
            'category' => 'basic',
            'is_archived' => false,
            'replaced_by_field' => null,
        ],
        [
            'field_key' => 'gender',
            'field_type' => 'CORE',
            'data_type' => 'select',
            'is_mandatory' => true,
            'is_searchable' => true,
            'is_user_editable' => true,
            'is_system_overwritable' => true,
            'lock_after_user_edit' => true,
            'display_label' => 'Gender',
            'display_order' => 110,
            'category' => 'basic',
            'is_archived' => false,
            'replaced_by_field' => null,
        ],
        [
            'field_key' => 'date_of_birth',
            'field_type' => 'CORE',
            'data_type' => 'date',
            'is_mandatory' => true,
            'is_searchable' => true,
            'is_user_editable' => true,
            'is_system_overwritable' => true,
            'lock_after_user_edit' => true,
            'display_label' => 'Date of Birth',
            'display_order' => 120,
            'category' => 'basic',
            'is_archived' => false,
            'replaced_by_field' => null,
        ],
        [
            'field_key' => 'marital_status',
            'field_type' => 'CORE',
            'data_type' => 'select',
            'is_mandatory' => true,
            'is_searchable' => true,
            'is_user_editable' => true,
            'is_system_overwritable' => true,
            'lock_after_user_edit' => true,
            'display_label' => 'Marital Status',
            'display_order' => 130,
            'category' => 'basic',
            'is_archived' => false,
            'replaced_by_field' => null,
        ],
        [
            'field_key' => 'education',
            'field_type' => 'CORE',
            'data_type' => 'text',
            'is_mandatory' => true,
            'is_searchable' => true,
            'is_user_editable' => true,
            'is_system_overwritable' => true,
            'lock_after_user_edit' => true,
            'display_label' => 'Education',
            'display_order' => 140,
            'category' => 'basic',
            'is_archived' => false,
            'replaced_by_field' => null,
        ],
        [
            'field_key' => 'location',
            'field_type' => 'CORE',
            'data_type' => 'text',
            'is_mandatory' => true,
            'is_searchable' => true,
            'is_user_editable' => true,
            'is_system_overwritable' => true,
            'lock_after_user_edit' => true,
            'display_label' => 'Location',
            'display_order' => 150,
            'category' => 'basic',
            'is_archived' => false,
            'replaced_by_field' => null,
        ],
        [
            'field_key' => 'caste',
            'field_type' => 'CORE',
            'data_type' => 'text',
            'is_mandatory' => true,
            'is_searchable' => true,
            'is_user_editable' => true,
            'is_system_overwritable' => true,
            'lock_after_user_edit' => true,
            'display_label' => 'Caste',
            'display_order' => 160,
            'category' => 'basic',
            'is_archived' => false,
            'replaced_by_field' => null,
        ],
        [
            'field_key' => 'height_cm',
            'field_type' => 'CORE',
            'data_type' => 'number',
            'is_mandatory' => false,
            'is_searchable' => true,
            'is_user_editable' => true,
            'is_system_overwritable' => true,
            'lock_after_user_edit' => true,
            'display_label' => 'Height (cm)',
            'display_order' => 170,
            'category' => 'basic',
            'is_archived' => false,
            'replaced_by_field' => null,
        ],
        [
            'field_key' => 'profile_photo',
            'field_type' => 'CORE',
            'data_type' => 'text',
            'is_mandatory' => true,
            'is_searchable' => false,
            'is_user_editable' => true,
            'is_system_overwritable' => true,
            'lock_after_user_edit' => true,
            'display_label' => 'Profile Photo',
            'display_order' => 180,
            'category' => 'basic',
            'is_archived' => false,
            'replaced_by_field' => null,
        ],
    ];

    /** Columns safe to update on existing row (never field_key, id, created_at). */
    private const SAFE_UPDATE_COLUMNS = [
        'field_type',
        'data_type',
        'is_mandatory',
        'is_searchable',
        'is_user_editable',
        'is_system_overwritable',
        'lock_after_user_edit',
        'display_label',
        'display_order',
        'category',
        'is_archived',
        'replaced_by_field',
        'updated_at',
    ];

    public function run(): void
    {
        $now = now();

        foreach (self::CORE_FIELDS as $row) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;

            $existing = FieldRegistry::where('field_key', $row['field_key'])->first();

            if ($existing) {
                $update = array_intersect_key($row, array_fill_keys(self::SAFE_UPDATE_COLUMNS, true));
                $update['updated_at'] = $now;
                FieldRegistry::where('id', $existing->id)->update($update);
            } else {
                FieldRegistry::create($row);
            }
        }
    }
}
