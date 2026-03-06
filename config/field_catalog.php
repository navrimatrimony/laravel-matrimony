<?php

/**
 * Phase-5 Point 5: Canonical field catalog — single definition for section order,
 * section labels, and which sections appear in "minimal" (post-registration) wizard.
 * Metadata only; no structured entities as JSON blob.
 *
 * Wizard (post-registration): fewer sections (minimal_wizard_sections).
 * Edit-full & intake preview: same catalog, all sections.
 */

return [

    /*
    | Section keys in display order (full wizard / full edit).
    | Must match ProfileCompletionService::SECTIONS keys order and ProfileWizardController flow.
    */
    'section_order' => [
        'basic-info',
        'physical',
        'marriages',
        'personal-family',
        'siblings',
        'relatives',
        'alliance',
        'location',
        'property',
        'horoscope',
        'legal',
        'about-preferences',
        'contacts',
        'photo',
    ],

    /*
    | Sections shown in minimal wizard (e.g. right after registration).
    | User sees only these; then "Complete profile" / full edit for the rest.
    */
    'minimal_wizard_sections' => [
        'basic-info',
        'contacts',
    ],

    /*
    | Section display labels (for progress bar, headings). Key = section key.
    */
    'section_labels' => [
        'basic-info' => 'Basic info',
        'physical' => 'Physical',
        'marriages' => 'Marriages',
        'personal-family' => 'Personal & family',
        'siblings' => 'Siblings',
        'relatives' => 'Relatives',
        'alliance' => 'Alliance',
        'location' => 'Location',
        'property' => 'Property',
        'horoscope' => 'Horoscope',
        'legal' => 'Legal',
        'about-preferences' => 'About & preferences',
        'contacts' => 'Contacts',
        'photo' => 'Photo',
    ],

    /*
    | CORE field_key => section_key mapping for catalog-driven placement.
    | Fields not listed fall back to category or existing blade placement.
    */
    'field_to_section' => [
        'full_name' => 'basic-info',
        'date_of_birth' => 'basic-info',
        'gender_id' => 'basic-info',
        'marital_status_id' => 'basic-info',
        'religion_id' => 'basic-info',
        'caste_id' => 'basic-info',
        'sub_caste_id' => 'basic-info',
        'height_cm' => 'physical',
        'complexion_id' => 'physical',
        'blood_group_id' => 'physical',
        'physical_build_id' => 'physical',
        'weight_kg' => 'physical',
        'spectacles_lens' => 'physical',
        'physical_condition' => 'physical',
        'primary_contact_number' => 'contacts',
        'highest_education' => 'personal-family',
        'specialization' => 'personal-family',
        'occupation_title' => 'personal-family',
        'company_name' => 'personal-family',
        'annual_income' => 'personal-family',
        'family_income' => 'personal-family',
        'family_type_id' => 'personal-family',
        'family_status' => 'personal-family',
        'family_values' => 'personal-family',
        'family_annual_income' => 'personal-family',
        'father_name' => 'personal-family',
        'mother_name' => 'personal-family',
        'brothers_count' => 'personal-family',
        'sisters_count' => 'personal-family',
        'city_id' => 'location',
        'work_city_id' => 'location',
        'country_id' => 'location',
        'state_id' => 'location',
        'district_id' => 'location',
        'taluka_id' => 'location',
    ],

];
