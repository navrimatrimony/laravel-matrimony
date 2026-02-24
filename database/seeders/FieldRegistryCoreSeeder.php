<?php

namespace Database\Seeders;

use App\Models\FieldRegistry;
use Illuminate\Database\Seeder;

/**
 * Phase-3 Day 1 — CORE Field Registry Seeder.
 * Seeds CORE fields (idempotent: upsert by field_key).
 * NEVER change field_key once created. No deletes. No Phase-2 tables touched.
 */
class FieldRegistryCoreSeeder extends Seeder
{
    /**
     * CORE fields list: full_name, gender, date_of_birth, marital_status, education, location, caste, height_cm, profile_photo, annual_income
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
            'replaced_by_field' => 'gender_id', // Phase-5: master lookup
        ],
        [
            'field_key' => 'gender_id',
            'field_type' => 'CORE',
            'data_type' => 'select',
            'is_mandatory' => true,
            'is_searchable' => true,
            'is_user_editable' => true,
            'is_system_overwritable' => true,
            'lock_after_user_edit' => true,
            'display_label' => 'Gender',
            'display_order' => 111,
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
            'replaced_by_field' => 'marital_status_id', // Phase-5: master lookup
        ],
        [
            'field_key' => 'marital_status_id',
            'field_type' => 'CORE',
            'data_type' => 'select',
            'is_mandatory' => true,
            'is_searchable' => true,
            'is_user_editable' => true,
            'is_system_overwritable' => true,
            'lock_after_user_edit' => true,
            'display_label' => 'Marital Status',
            'display_order' => 131,
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
        [
            'field_key' => 'annual_income',
            'field_type' => 'CORE',
            'data_type' => 'number',
            'is_mandatory' => false,
            'is_searchable' => true,
            'is_user_editable' => true,
            'is_system_overwritable' => true,
            'lock_after_user_edit' => true,
            'display_label' => 'Annual Income',
            'display_order' => 185,
            'category' => 'basic',
            'is_archived' => false,
            'replaced_by_field' => null,
        ],
        // ——— Location hierarchy (Phase-4) ———
        ['field_key' => 'country_id', 'field_type' => 'CORE', 'data_type' => 'select', 'is_mandatory' => false, 'is_searchable' => true, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Country', 'display_order' => 188, 'category' => 'basic', 'is_archived' => false, 'replaced_by_field' => null],
        ['field_key' => 'state_id', 'field_type' => 'CORE', 'data_type' => 'select', 'is_mandatory' => false, 'is_searchable' => true, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'State', 'display_order' => 189, 'category' => 'basic', 'is_archived' => false, 'replaced_by_field' => null],
        ['field_key' => 'district_id', 'field_type' => 'CORE', 'data_type' => 'select', 'is_mandatory' => false, 'is_searchable' => true, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'District', 'display_order' => 190, 'category' => 'basic', 'is_archived' => false, 'replaced_by_field' => null],
        ['field_key' => 'taluka_id', 'field_type' => 'CORE', 'data_type' => 'select', 'is_mandatory' => false, 'is_searchable' => true, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Taluka', 'display_order' => 191, 'category' => 'basic', 'is_archived' => false, 'replaced_by_field' => null],
        ['field_key' => 'city_id', 'field_type' => 'CORE', 'data_type' => 'select', 'is_mandatory' => false, 'is_searchable' => true, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'City', 'display_order' => 192, 'category' => 'basic', 'is_archived' => false, 'replaced_by_field' => null],
        // ——— Phase-5B core fields ———
        ['field_key' => 'religion', 'field_type' => 'CORE', 'data_type' => 'text', 'is_mandatory' => false, 'is_searchable' => true, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Religion', 'display_order' => 200, 'category' => 'basic', 'is_archived' => false, 'replaced_by_field' => null],
        ['field_key' => 'religion_id', 'field_type' => 'CORE', 'data_type' => 'select', 'is_mandatory' => false, 'is_searchable' => true, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Religion', 'display_order' => 2001, 'category' => 'basic', 'is_archived' => false, 'replaced_by_field' => null, 'is_enabled' => true],
        ['field_key' => 'caste_id', 'field_type' => 'CORE', 'data_type' => 'select', 'is_mandatory' => false, 'is_searchable' => true, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Caste', 'display_order' => 2002, 'category' => 'basic', 'is_archived' => false, 'replaced_by_field' => null, 'is_enabled' => true],
        ['field_key' => 'sub_caste_id', 'field_type' => 'CORE', 'data_type' => 'select', 'is_mandatory' => false, 'is_searchable' => true, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Sub Caste', 'display_order' => 2003, 'category' => 'basic', 'is_archived' => false, 'replaced_by_field' => null, 'is_enabled' => true],
        ['field_key' => 'sub_caste', 'field_type' => 'CORE', 'data_type' => 'text', 'is_mandatory' => false, 'is_searchable' => true, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Sub Caste', 'display_order' => 201, 'category' => 'basic', 'is_archived' => false, 'replaced_by_field' => null],
        ['field_key' => 'weight_kg', 'field_type' => 'CORE', 'data_type' => 'number', 'is_mandatory' => false, 'is_searchable' => false, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Weight (kg)', 'display_order' => 202, 'category' => 'basic', 'is_archived' => false, 'replaced_by_field' => null],
        ['field_key' => 'complexion', 'field_type' => 'CORE', 'data_type' => 'text', 'is_mandatory' => false, 'is_searchable' => true, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Complexion', 'display_order' => 203, 'category' => 'basic', 'is_archived' => false, 'replaced_by_field' => 'complexion_id'],
        ['field_key' => 'complexion_id', 'field_type' => 'CORE', 'data_type' => 'select', 'is_mandatory' => false, 'is_searchable' => true, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Complexion', 'display_order' => 2031, 'category' => 'basic', 'is_archived' => false, 'replaced_by_field' => null],
        ['field_key' => 'physical_build', 'field_type' => 'CORE', 'data_type' => 'text', 'is_mandatory' => false, 'is_searchable' => false, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Physical Build', 'display_order' => 204, 'category' => 'basic', 'is_archived' => false, 'replaced_by_field' => 'physical_build_id'],
        ['field_key' => 'physical_build_id', 'field_type' => 'CORE', 'data_type' => 'select', 'is_mandatory' => false, 'is_searchable' => false, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Physical Build', 'display_order' => 2041, 'category' => 'basic', 'is_archived' => false, 'replaced_by_field' => null],
        ['field_key' => 'blood_group', 'field_type' => 'CORE', 'data_type' => 'text', 'is_mandatory' => false, 'is_searchable' => false, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Blood Group', 'display_order' => 205, 'category' => 'basic', 'is_archived' => false, 'replaced_by_field' => 'blood_group_id'],
        ['field_key' => 'blood_group_id', 'field_type' => 'CORE', 'data_type' => 'select', 'is_mandatory' => false, 'is_searchable' => false, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Blood Group', 'display_order' => 2051, 'category' => 'basic', 'is_archived' => false, 'replaced_by_field' => null],
        ['field_key' => 'highest_education', 'field_type' => 'CORE', 'data_type' => 'text', 'is_mandatory' => false, 'is_searchable' => true, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Highest Education', 'display_order' => 210, 'category' => 'career', 'is_archived' => false, 'replaced_by_field' => null],
        ['field_key' => 'specialization', 'field_type' => 'CORE', 'data_type' => 'text', 'is_mandatory' => false, 'is_searchable' => true, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Specialization', 'display_order' => 211, 'category' => 'career', 'is_archived' => false, 'replaced_by_field' => null],
        ['field_key' => 'occupation_title', 'field_type' => 'CORE', 'data_type' => 'text', 'is_mandatory' => false, 'is_searchable' => true, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Occupation Title', 'display_order' => 212, 'category' => 'career', 'is_archived' => false, 'replaced_by_field' => null],
        ['field_key' => 'company_name', 'field_type' => 'CORE', 'data_type' => 'text', 'is_mandatory' => false, 'is_searchable' => true, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Company Name', 'display_order' => 213, 'category' => 'career', 'is_archived' => false, 'replaced_by_field' => null],
        ['field_key' => 'income_currency', 'field_type' => 'CORE', 'data_type' => 'text', 'is_mandatory' => false, 'is_searchable' => false, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Income Currency', 'display_order' => 214, 'category' => 'career', 'is_archived' => false, 'replaced_by_field' => 'income_currency_id'],
        ['field_key' => 'income_currency_id', 'field_type' => 'CORE', 'data_type' => 'select', 'is_mandatory' => false, 'is_searchable' => false, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Income Currency', 'display_order' => 2141, 'category' => 'career', 'is_archived' => false, 'replaced_by_field' => null],
        ['field_key' => 'family_income', 'field_type' => 'CORE', 'data_type' => 'number', 'is_mandatory' => false, 'is_searchable' => true, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Family Income', 'display_order' => 215, 'category' => 'career', 'is_archived' => false, 'replaced_by_field' => null],
        ['field_key' => 'father_name', 'field_type' => 'CORE', 'data_type' => 'text', 'is_mandatory' => false, 'is_searchable' => false, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Father Name', 'display_order' => 220, 'category' => 'family', 'is_archived' => false, 'replaced_by_field' => null],
        ['field_key' => 'father_occupation', 'field_type' => 'CORE', 'data_type' => 'text', 'is_mandatory' => false, 'is_searchable' => false, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Father Occupation', 'display_order' => 221, 'category' => 'family', 'is_archived' => false, 'replaced_by_field' => null],
        ['field_key' => 'mother_name', 'field_type' => 'CORE', 'data_type' => 'text', 'is_mandatory' => false, 'is_searchable' => false, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Mother Name', 'display_order' => 222, 'category' => 'family', 'is_archived' => false, 'replaced_by_field' => null],
        ['field_key' => 'mother_occupation', 'field_type' => 'CORE', 'data_type' => 'text', 'is_mandatory' => false, 'is_searchable' => false, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Mother Occupation', 'display_order' => 223, 'category' => 'family', 'is_archived' => false, 'replaced_by_field' => null],
        ['field_key' => 'brothers_count', 'field_type' => 'CORE', 'data_type' => 'number', 'is_mandatory' => false, 'is_searchable' => false, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Brothers Count', 'display_order' => 224, 'category' => 'family', 'is_archived' => false, 'replaced_by_field' => null],
        ['field_key' => 'sisters_count', 'field_type' => 'CORE', 'data_type' => 'number', 'is_mandatory' => false, 'is_searchable' => false, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Sisters Count', 'display_order' => 225, 'category' => 'family', 'is_archived' => false, 'replaced_by_field' => null],
        ['field_key' => 'family_type', 'field_type' => 'CORE', 'data_type' => 'text', 'is_mandatory' => false, 'is_searchable' => true, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Family Type', 'display_order' => 226, 'category' => 'family', 'is_archived' => false, 'replaced_by_field' => 'family_type_id'],
        ['field_key' => 'family_type_id', 'field_type' => 'CORE', 'data_type' => 'select', 'is_mandatory' => false, 'is_searchable' => true, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Family Type', 'display_order' => 2261, 'category' => 'family', 'is_archived' => false, 'replaced_by_field' => null],
        ['field_key' => 'work_city_id', 'field_type' => 'CORE', 'data_type' => 'select', 'is_mandatory' => false, 'is_searchable' => true, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Work City', 'display_order' => 230, 'category' => 'career', 'is_archived' => false, 'replaced_by_field' => null],
        ['field_key' => 'work_state_id', 'field_type' => 'CORE', 'data_type' => 'select', 'is_mandatory' => false, 'is_searchable' => true, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Work State', 'display_order' => 231, 'category' => 'career', 'is_archived' => false, 'replaced_by_field' => null],
        ['field_key' => 'serious_intent_id', 'field_type' => 'CORE', 'data_type' => 'select', 'is_mandatory' => false, 'is_searchable' => true, 'is_user_editable' => true, 'is_system_overwritable' => true, 'lock_after_user_edit' => true, 'display_label' => 'Serious Intent', 'display_order' => 232, 'category' => 'basic', 'is_archived' => false, 'replaced_by_field' => null],
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
