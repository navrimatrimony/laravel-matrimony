<?php

namespace App\Services\ControlledOptions;

/**
 * Phase-5 Day-36: Centralized registry for controlled-option fields.
 *
 * This is the SSOT for how each logical field is backed:
 * - master table name
 * - key / id / label columns
 * - active column
 * - strict allowlists
 * - translation namespace
 * - single vs multi-select.
 */
class ControlledOptionRegistry
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $config = [
        // -------------------------
        // Horoscope masters
        // -------------------------
        'horoscope.nadi' => [
            'source_type' => 'master_table',
            'table' => 'master_nadis',
            'key_column' => 'key',
            'id_column' => 'id',
            'label_column' => 'label',
            'active_column' => 'is_active',
            'strict_keys' => ['adi', 'madhya', 'antya'],
            'translation_namespace' => 'components.horoscope.options.nadi',
            'multi_select' => false,
        ],
        'horoscope.gan' => [
            'source_type' => 'master_table',
            'table' => 'master_gans',
            'key_column' => 'key',
            'id_column' => 'id',
            'label_column' => 'label',
            'active_column' => 'is_active',
            'strict_keys' => ['deva', 'manav', 'rakshasa'],
            'translation_namespace' => 'components.horoscope.options.gan',
            'multi_select' => false,
        ],
        'horoscope.rashi' => [
            'source_type' => 'master_table',
            'table' => 'master_rashis',
            'key_column' => 'key',
            'id_column' => 'id',
            'label_column' => 'label',
            'active_column' => 'is_active',
            'translation_namespace' => 'components.horoscope.options.rashi',
            'multi_select' => false,
        ],
        'horoscope.nakshatra' => [
            'source_type' => 'master_table',
            'table' => 'master_nakshatras',
            'key_column' => 'key',
            'id_column' => 'id',
            'label_column' => 'label',
            'active_column' => 'is_active',
            'translation_namespace' => 'components.horoscope.options.nakshatra',
            'multi_select' => false,
        ],
        'horoscope.yoni' => [
            'source_type' => 'master_table',
            'table' => 'master_yonis',
            'key_column' => 'key',
            'id_column' => 'id',
            'label_column' => 'label',
            'active_column' => 'is_active',
            'translation_namespace' => 'components.horoscope.options.yoni',
            'multi_select' => false,
        ],
        'horoscope.mangal_dosh_type' => [
            'source_type' => 'master_table',
            'table' => 'master_mangal_dosh_types',
            'key_column' => 'key',
            'id_column' => 'id',
            'label_column' => 'label',
            'active_column' => 'is_active',
            'translation_namespace' => 'components.horoscope.options.mangal_dosh_type',
            'multi_select' => false,
        ],

        // -------------------------
        // Core / basic info
        // -------------------------
        'basic.gender' => [
            'source_type' => 'master_table',
            'table' => 'master_genders',
            'key_column' => 'key',
            'id_column' => 'id',
            'label_column' => 'label',
            'active_column' => 'is_active',
            'multi_select' => false,
        ],
        'basic.marital_status' => [
            'source_type' => 'master_table',
            'table' => 'master_marital_statuses',
            'key_column' => 'key',
            'id_column' => 'id',
            'label_column' => 'label',
            'active_column' => 'is_active',
            'multi_select' => false,
        ],
        'core.religion' => [
            'source_type' => 'master_table',
            'table' => 'religions',
            'key_column' => 'key',
            'id_column' => 'id',
            'label_column' => 'label',
            'active_column' => 'is_active',
            'multi_select' => false,
        ],
        'core.caste' => [
            'source_type' => 'master_table',
            'table' => 'castes',
            'key_column' => 'key',
            'id_column' => 'id',
            'label_column' => 'label',
            'active_column' => 'is_active',
            'multi_select' => false,
        ],
        'core.sub_caste' => [
            'source_type' => 'master_table',
            'table' => 'sub_castes',
            'key_column' => 'key',
            'id_column' => 'id',
            'label_column' => 'label',
            'active_column' => 'is_active',
            'multi_select' => false,
        ],

        // -------------------------
        // Physical
        // -------------------------
        'physical.complexion' => [
            'source_type' => 'master_table',
            'table' => 'master_complexions',
            'key_column' => 'key',
            'id_column' => 'id',
            'label_column' => 'label',
            'active_column' => 'is_active',
            'multi_select' => false,
        ],
        'physical.blood_group' => [
            'source_type' => 'master_table',
            'table' => 'master_blood_groups',
            'key_column' => 'key',
            'id_column' => 'id',
            'label_column' => 'label',
            'active_column' => 'is_active',
            'multi_select' => false,
        ],
        'physical.physical_build' => [
            'source_type' => 'master_table',
            'table' => 'master_physical_builds',
            'key_column' => 'key',
            'id_column' => 'id',
            'label_column' => 'label',
            'active_column' => 'is_active',
            'multi_select' => false,
        ],

        // -------------------------
        // Education / career
        // -------------------------
        'education.income_currency' => [
            'source_type' => 'master_table',
            'table' => 'master_income_currencies',
            'key_column' => 'code',
            'id_column' => 'id',
            'label_column' => 'symbol',
            'active_column' => 'is_active',
            'multi_select' => false,
        ],
        'education.working_with' => [
            'source_type' => 'master_table',
            'table' => 'working_with_types',
            'key_column' => 'slug',
            'id_column' => 'id',
            'label_column' => 'name',
            'active_column' => 'is_active',
            'multi_select' => false,
        ],
        'education.profession' => [
            'source_type' => 'master_table',
            'table' => 'professions',
            'key_column' => 'slug',
            'id_column' => 'id',
            'label_column' => 'name',
            'active_column' => 'is_active',
            'multi_select' => false,
        ],

        // -------------------------
        // Preferences
        // -------------------------
        'preference.religion' => [
            'source_type' => 'master_table',
            'table' => 'religions',
            'key_column' => 'key',
            'id_column' => 'id',
            'label_column' => 'label',
            'active_column' => 'is_active',
            'multi_select' => true,
        ],
        'preference.caste' => [
            'source_type' => 'master_table',
            'table' => 'castes',
            'key_column' => 'key',
            'id_column' => 'id',
            'label_column' => 'label',
            'active_column' => 'is_active',
            'multi_select' => true,
        ],

        // -------------------------
        // Entity table lookups
        // -------------------------
        'entity.address_type' => [
            'source_type' => 'master_table',
            'table' => 'master_address_types',
            'key_column' => 'key',
            'id_column' => 'id',
            'label_column' => 'label',
            'active_column' => 'is_active',
            'multi_select' => false,
        ],
        'entity.contact_relation' => [
            'source_type' => 'master_table',
            'table' => 'master_contact_relations',
            'key_column' => 'key',
            'id_column' => 'id',
            'label_column' => 'label',
            'active_column' => 'is_active',
            'multi_select' => false,
        ],
        'entity.child_living_with' => [
            'source_type' => 'master_table',
            'table' => 'master_child_living_with',
            'key_column' => 'key',
            'id_column' => 'id',
            'label_column' => 'label',
            'active_column' => 'is_active',
            'multi_select' => false,
        ],
        'entity.asset_type' => [
            'source_type' => 'master_table',
            'table' => 'master_asset_types',
            'key_column' => 'key',
            'id_column' => 'id',
            'label_column' => 'label',
            'active_column' => 'is_active',
            'multi_select' => false,
        ],
        'entity.ownership_type' => [
            'source_type' => 'master_table',
            'table' => 'master_ownership_types',
            'key_column' => 'key',
            'id_column' => 'id',
            'label_column' => 'label',
            'active_column' => 'is_active',
            'multi_select' => false,
        ],
        'entity.legal_case_type' => [
            'source_type' => 'master_table',
            'table' => 'master_legal_case_types',
            'key_column' => 'key',
            'id_column' => 'id',
            'label_column' => 'label',
            'active_column' => 'is_active',
            'multi_select' => false,
        ],
    ];

    /**
     * Get configuration for a logical field key.
     *
     * @return array<string, mixed>
     */
    public function get(string $fieldKey): array
    {
        if (! isset($this->config[$fieldKey])) {
            throw new \InvalidArgumentException("Unknown controlled option field: {$fieldKey}");
        }

        return $this->config[$fieldKey];
    }
}

