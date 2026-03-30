<?php

namespace App\Services\Parsing;

/**
 * Canonical full parsed-intake skeleton.
 * Keeps parse output shape stable even for partial biodata extraction.
 */
class IntakeParsedSnapshotSkeleton
{
    /**
     * @return array<string,mixed>
     */
    public function defaults(): array
    {
        return [
            'core' => $this->coreDefaults(),
            'contacts' => [],
            'birth_place' => null,
            'native_place' => null,
            'children' => [],
            'marriages' => [],
            'education_history' => [],
            'career_history' => [],
            'addresses' => [],
            'siblings' => [],
            'relatives' => [],
            'relatives_parents_family' => [],
            'relatives_maternal_family' => [],
            'relatives_sectioned' => $this->relativeSectionDefaults(),
            'alliance_networks' => [],
            'property_summary' => [],
            'property_assets' => [],
            'horoscope' => [],
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
        $out = $snapshot;
        $defaults = $this->defaults();
        foreach ($defaults as $k => $v) {
            if (! array_key_exists($k, $out) || $out[$k] === null) {
                $out[$k] = $v;
            }
        }

        if (! is_array($out['core'] ?? null)) {
            $out['core'] = [];
        }
        $out['core'] = array_replace($this->coreDefaults(), $out['core']);

        foreach (['contacts', 'children', 'marriages', 'education_history', 'career_history', 'addresses', 'siblings', 'relatives', 'property_summary', 'property_assets', 'horoscope', 'preferences', 'alliance_networks'] as $arrKey) {
            if (! is_array($out[$arrKey] ?? null)) {
                $out[$arrKey] = [];
            }
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
    private function coreDefaults(): array
    {
        return [
            'full_name' => null,
            'date_of_birth' => null,
            'birth_time' => null,
            'birth_place' => null,
            'birth_city_id' => null,
            'birth_taluka_id' => null,
            'birth_district_id' => null,
            'birth_state_id' => null,
            'birth_place_text' => null,
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
            'mother_contact_1' => null,
            'mother_contact_2' => null,
            'mother_contact_3' => null,
            'brother_count' => null,
            'sister_count' => null,
            'other_relatives_text' => null,
            'highest_education' => null,
            'highest_education_other' => null,
            'specialization' => null,
            'college_id' => null,
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
            'profile_photo' => null,
            'photo_approved' => null,
            'photo_rejected_at' => null,
            'photo_rejection_reason' => null,
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
}

