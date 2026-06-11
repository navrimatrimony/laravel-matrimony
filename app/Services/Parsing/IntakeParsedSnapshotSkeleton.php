<?php

namespace App\Services\Parsing;

/**
 * Canonical full parsed-intake skeleton.
 * Keeps parse output shape stable even for partial biodata extraction.
 *
 * The {@code education_history} array is legacy parse shape only; matrimony profile education is
 * stored on {@code core.highest_education} / {@code highest_education_other} and {@see \App\Services\MutationService}
 * strips {@code education_history} before applying a snapshot to a profile.
 */
class IntakeParsedSnapshotSkeleton
{
    /**
     * @return array<string,mixed>
     */
    public function defaults(): array
    {
        return [
            'section_order' => $this->sectionOrder(),
            'sectioned' => $this->sectionedDefaults(),
            'missing_map' => [],
            'core' => $this->coreDefaults(),
            'contacts' => [],
            'birth_place' => null,
            'native_place' => null,
            'children' => [],
            'marriages' => [],
            'education_history' => [],
            'career_history' => [],
            'addresses' => [],
            'parents_addresses' => [],
            'siblings' => [],
            'relatives' => [],
            'relatives_parents_family' => [],
            'relatives_maternal_family' => [],
            'relatives_sectioned' => $this->relativeSectionDefaults(),
            'alliance_networks' => [],
            'property_summary' => $this->propertySummaryDefaults(),
            'property_assets' => [],
            'horoscope' => [],
            'legal_cases' => [],
            'preferences' => [],
            'extended_narrative' => [
                'narrative_about_me' => null,
                'narrative_expectations' => null,
                'additional_notes' => null,
            ],
            'confidence_map' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    public function ensure(array $snapshot): array
    {
        $defaults = $this->defaults();
        $out = array_replace($defaults, $snapshot);
        foreach ($defaults as $k => $v) {
            if ($out[$k] === null) {
                $out[$k] = $v;
            }
        }

        if (! is_array($out['section_order'] ?? null)) {
            $out['section_order'] = $this->sectionOrder();
        }
        if (! is_array($out['sectioned'] ?? null)) {
            $out['sectioned'] = $this->sectionedDefaults();
        } else {
            $out['sectioned'] = array_replace($this->sectionedDefaults(), $out['sectioned']);
        }
        if (! is_array($out['missing_map'] ?? null)) {
            $out['missing_map'] = [];
        }

        if (! is_array($out['core'] ?? null)) {
            $out['core'] = [];
        }
        $out['core'] = array_replace($this->coreDefaults(), $out['core']);
        if ($this->isEmptyValue($out['core']['brothers_count'] ?? null) && ! $this->isEmptyValue($out['core']['brother_count'] ?? null)) {
            $out['core']['brothers_count'] = $out['core']['brother_count'];
        }
        if ($this->isEmptyValue($out['core']['sisters_count'] ?? null) && ! $this->isEmptyValue($out['core']['sister_count'] ?? null)) {
            $out['core']['sisters_count'] = $out['core']['sister_count'];
        }
        if ($this->isEmptyValue($out['core']['brother_count'] ?? null) && ! $this->isEmptyValue($out['core']['brothers_count'] ?? null)) {
            $out['core']['brother_count'] = $out['core']['brothers_count'];
        }
        if ($this->isEmptyValue($out['core']['sister_count'] ?? null) && ! $this->isEmptyValue($out['core']['sisters_count'] ?? null)) {
            $out['core']['sister_count'] = $out['core']['sisters_count'];
        }

        foreach (['contacts', 'children', 'marriages', 'education_history', 'career_history', 'addresses', 'parents_addresses', 'siblings', 'relatives', 'relatives_parents_family', 'relatives_maternal_family', 'property_assets', 'horoscope', 'legal_cases', 'preferences', 'alliance_networks'] as $arrKey) {
            if (! is_array($out[$arrKey] ?? null)) {
                $out[$arrKey] = [];
            }
        }
        $out['contacts'] = $this->ensureRows($out['contacts'], $this->contactRowDefaults());
        $out['siblings'] = $this->ensureSiblingRows($out['siblings']);
        $out['relatives'] = $this->ensureRows($out['relatives'], $this->relativeRowDefaults());
        $out['relatives_parents_family'] = $this->ensureRows($out['relatives_parents_family'], $this->relativeRowDefaults());
        $out['relatives_maternal_family'] = $this->ensureRows($out['relatives_maternal_family'], $this->relativeRowDefaults());
        $out['property_assets'] = $this->ensureRows($out['property_assets'], $this->propertyAssetRowDefaults());
        $out['horoscope'] = $this->ensureRows($out['horoscope'], $this->horoscopeRowDefaults());
        $out['legal_cases'] = $this->ensureRows($out['legal_cases'], $this->legalCaseRowDefaults());

        // property_summary may be a scalar string from rules parser; preserve it for legacy compatibility.
        if (! array_key_exists('property_summary', $out) || $out['property_summary'] === null) {
            $out['property_summary'] = $this->propertySummaryDefaults();
        } elseif (is_string($out['property_summary'])) {
            // keep string for intake preview / scalar merge paths
        } elseif (is_array($out['property_summary'])) {
            $out['property_summary'] = array_replace($this->propertySummaryDefaults(), $out['property_summary']);
        } else {
            $out['property_summary'] = $this->propertySummaryDefaults();
        }
        foreach (['birth_place', 'native_place'] as $objKey) {
            if (isset($out[$objKey]) && $out[$objKey] !== null && ! is_array($out[$objKey])) {
                $out[$objKey] = null;
            }
        }
        if (! is_array($out['relatives_sectioned'] ?? null)) {
            $out['relatives_sectioned'] = $this->relativeSectionDefaults();
        } else {
            $out['relatives_sectioned'] = array_replace_recursive($this->relativeSectionDefaults(), $out['relatives_sectioned']);
        }
        $out['relatives_sectioned'] = $this->ensureRelativeSections($out['relatives_sectioned']);
        if (! is_array($out['extended_narrative'] ?? null)) {
            $out['extended_narrative'] = $defaults['extended_narrative'];
        } else {
            $out['extended_narrative'] = array_replace($defaults['extended_narrative'], $out['extended_narrative']);
        }
        if (! is_array($out['confidence_map'] ?? null)) {
            $out['confidence_map'] = [];
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    public function coreDefaults(): array
    {
        return [
            'full_name' => null,
            'date_of_birth' => null,
            'birth_time' => null,
            'birth_place' => null,
            'birth_country_id' => null,
            'birth_city_id' => null,
            'birth_taluka_id' => null,
            'birth_district_id' => null,
            'birth_state_id' => null,
            'birth_place_text' => null,
            'native_country_id' => null,
            'native_state_id' => null,
            'native_district_id' => null,
            'native_taluka_id' => null,
            'native_city_id' => null,
            'gender' => null,
            'gender_id' => null,
            'religion' => null,
            'religion_id' => null,
            'caste' => null,
            'caste_id' => null,
            'sub_caste' => null,
            'sub_caste_id' => null,
            'marital_status' => null,
            'marital_status_id' => null,
            'has_children' => null,
            'has_siblings' => null,
            'height' => null,
            'height_cm' => null,
            'weight_kg' => null,
            'complexion' => null,
            'complexion_id' => null,
            'blood_group' => null,
            'blood_group_id' => null,
            'physical_build' => null,
            'physical_build_id' => null,
            'spectacles_lens' => null,
            'physical_condition' => null,
            'mother_tongue' => null,
            'mother_tongue_id' => null,
            'diet' => null,
            'diet_id' => null,
            'smoking' => null,
            'smoking_status' => null,
            'smoking_status_id' => null,
            'drinking' => null,
            'drinking_status' => null,
            'drinking_status_id' => null,
            'family_type' => null,
            'family_type_id' => null,
            'income_currency' => null,
            'income_currency_id' => null,
            'annual_income' => null,
            'family_income' => null,
            'income_period' => null,
            'income_value_type' => null,
            'income_amount' => null,
            'income_min_amount' => null,
            'income_max_amount' => null,
            'income_normalized_annual_amount' => null,
            'family_income_period' => null,
            'family_income_value_type' => null,
            'family_income_amount' => null,
            'family_income_min_amount' => null,
            'family_income_max_amount' => null,
            'family_income_normalized_annual_amount' => null,
            'family_income_currency_id' => null,
            'family_income_private' => null,
            'income_range_id' => null,
            'income_private' => null,
            'primary_contact_number' => null,
            'father_name' => null,
            'father_occupation' => null,
            'father_extra_info' => null,
            'father_contact_1' => null,
            'father_contact_2' => null,
            'father_contact_3' => null,
            'mother_name' => null,
            'mother_occupation' => null,
            'mother_extra_info' => null,
            'mother_contact_1' => null,
            'mother_contact_2' => null,
            'mother_contact_3' => null,
            'brothers_count' => null,
            'sisters_count' => null,
            'brother_count' => null,
            'sister_count' => null,
            'other_relatives_text' => null,
            'property_details' => null,
            'highest_education' => null,
            'highest_education_other' => null,
            'specialization' => null,
            'working_with_type_id' => null,
            'profession_id' => null,
            'occupation_type' => null,
            'occupation_title' => null,
            'company_name' => null,
            'country_id' => null,
            'state_id' => null,
            'district_id' => null,
            'taluka_id' => null,
            'city_id' => null,
            'address_line' => null,
            'work_city_id' => null,
            'work_state_id' => null,
            'work_location_text' => null,
            'serious_intent_id' => null,
        ];
    }

    /**
     * Fixed section-wise relatives containers for stable preview/autofill orchestration.
     *
     * @return array<string,mixed>
     */
    private function relativeSectionDefaults(): array
    {
        return [
            'maternal' => [
                'ajol' => [],
                'mama' => [],
                'mavshi' => [],
                'other' => [],
            ],
            'paternal' => [
                'kaka' => [],
                'chulte' => [],
                'atya' => [],
                'other' => [],
            ],
            'other' => [],
        ];
    }

    /**
     * @return list<string>
     */
    public function sectionOrder(): array
    {
        $configured = config('field_catalog.section_order', []);
        $required = [
            'review_needed',
            'basic-info',
            'physical',
            'education-career',
            'family-details',
            'siblings',
            'relatives',
            'alliance',
            'property',
            'horoscope',
            'legal-cases',
            'about-me',
            'about-preferences',
            'photo',
        ];

        if (! is_array($configured) || $configured === []) {
            return $required;
        }

        $order = array_values(array_unique(array_filter(
            array_map('strval', $configured),
            static fn (string $section): bool => $section !== ''
        )));
        $order = array_values(array_filter(
            $order,
            static fn (string $section): bool => $section !== 'review_needed'
        ));

        foreach (array_slice($required, 1) as $index => $section) {
            if (in_array($section, $order, true)) {
                continue;
            }
            $nextRequired = array_slice($required, $index + 2);
            $insertAt = count($order);
            foreach ($nextRequired as $nextSection) {
                $position = array_search($nextSection, $order, true);
                if ($position !== false) {
                    $insertAt = $position;
                    break;
                }
            }
            array_splice($order, $insertAt, 0, [$section]);
        }

        array_unshift($order, 'review_needed');

        return $order;
    }

    /**
     * @return array<string,mixed>
     */
    public function sectionedDefaults(): array
    {
        return array_fill_keys($this->sectionOrder(), []);
    }

    /**
     * @return array<string,mixed>
     */
    public function contactRowDefaults(): array
    {
        return [
            'phone_number' => null,
            'number' => null,
            'type' => null,
            'label' => null,
            'relation_type' => null,
            'contact_name' => null,
            'is_primary' => false,
            'visibility_rule' => null,
            'verified_status' => null,
            'source_key' => null,
            'confidence' => 0.0,
            'status' => null,
            'missing_reason' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function siblingRowDefaults(): array
    {
        return [
            'relation_type' => null,
            'name' => null,
            'gender' => null,
            'marital_status' => null,
            'occupation' => null,
            'address_line' => null,
            'location_display' => null,
            'city_id' => null,
            'taluka_id' => null,
            'district_id' => null,
            'state_id' => null,
            'contact_number' => null,
            'contact_number_2' => null,
            'contact_number_3' => null,
            'notes' => null,
            'sort_order' => null,
            'spouse' => $this->siblingSpouseRowDefaults(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function siblingSpouseRowDefaults(): array
    {
        return [
            'name' => null,
            'occupation_title' => null,
            'address_line' => null,
            'location_display' => null,
            'city_id' => null,
            'taluka_id' => null,
            'district_id' => null,
            'state_id' => null,
            'contact_number' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function relativeRowDefaults(): array
    {
        return [
            'relation_type' => null,
            'name' => null,
            'occupation' => null,
            'marital_status' => null,
            'city_id' => null,
            'state_id' => null,
            'contact_number' => null,
            'notes' => null,
            'address_line' => null,
            'location' => null,
            'location_display' => null,
            'is_primary_contact' => false,
            'raw_note' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function propertySummaryDefaults(): array
    {
        return [
            'owns_house' => false,
            'owns_flat' => false,
            'owns_agriculture' => false,
            'total_land_acres' => null,
            'annual_agri_income' => null,
            'summary_notes' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function propertyAssetRowDefaults(): array
    {
        return [
            'asset_type' => null,
            'location' => null,
            'estimated_value' => null,
            'ownership_type' => null,
            'notes' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function horoscopeRowDefaults(): array
    {
        return [
            'mangal_dosh_type' => null,
            'mangal_dosh_type_id' => null,
            'rashi' => null,
            'rashi_id' => null,
            'nakshatra' => null,
            'nakshatra_id' => null,
            'charan' => null,
            'gan' => null,
            'gan_id' => null,
            'nadi' => null,
            'nadi_id' => null,
            'yoni' => null,
            'yoni_id' => null,
            'varna' => null,
            'vashya' => null,
            'rashi_lord' => null,
            'navras_name' => null,
            'devak' => null,
            'kuldaivat' => null,
            'gotra' => null,
            'birth_weekday' => null,
            'yog' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function legalCaseRowDefaults(): array
    {
        return [
            'case_type' => null,
            'court_name' => null,
            'case_number' => null,
            'case_stage' => null,
            'next_hearing_date' => null,
            'notes' => null,
            'active_status' => null,
        ];
    }

    /**
     * @param  array<int,mixed>  $rows
     * @param  array<string,mixed>  $defaults
     * @return list<array<string,mixed>>
     */
    private function ensureRows(array $rows, array $defaults): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = array_replace($defaults, $row);
            }
        }

        return $out;
    }

    /**
     * @param  array<int,mixed>  $rows
     * @return list<array<string,mixed>>
     */
    private function ensureSiblingRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $spouse = is_array($row['spouse'] ?? null) ? $row['spouse'] : [];
            $expanded = array_replace($this->siblingRowDefaults(), $row);
            $expanded['spouse'] = array_replace($this->siblingSpouseRowDefaults(), $spouse);
            $out[] = $expanded;
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $sections
     * @return array<string,mixed>
     */
    private function ensureRelativeSections(array $sections): array
    {
        foreach (['maternal', 'paternal'] as $family) {
            foreach ($sections[$family] ?? [] as $bucket => $rows) {
                $sections[$family][$bucket] = is_array($rows)
                    ? $this->ensureRows($rows, $this->relativeRowDefaults())
                    : [];
            }
        }
        $sections['other'] = is_array($sections['other'] ?? null)
            ? $this->ensureRows($sections['other'], $this->relativeRowDefaults())
            : [];

        return $sections;
    }

    private function isEmptyValue(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
