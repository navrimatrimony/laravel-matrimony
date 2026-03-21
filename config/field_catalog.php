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
        'education-career',
        'family-details',
        'siblings',
        'relatives',
        'alliance',
        'property',
        'horoscope',
        'about-me',
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
    | Values are translation keys so locale (en/mr) shows correct language.
    */
    'section_labels' => [
        'basic-info' => 'wizard.basic_info',
        'physical' => 'wizard.physical',
        'education-career' => 'wizard.education_career',
        'family-details' => 'wizard.family_details',
        'siblings' => 'wizard.siblings',
        'relatives' => 'wizard.extended_family',
        'alliance' => 'wizard.alliance',
        'property' => 'wizard.property',
        'horoscope' => 'wizard.horoscope',
        'about-me' => 'wizard.about_me',
        'about-preferences' => 'wizard.partner_preferences',
        'contacts' => 'wizard.contacts',
        'photo' => 'wizard.photo',
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
        'highest_education' => 'education-career',
        'specialization' => 'education-career',
        'occupation_title' => 'education-career',
        'company_name' => 'education-career',
        'annual_income' => 'education-career',
        'family_income' => 'family-details',
        'family_type_id' => 'family-details',
        'family_status' => 'family-details',
        'family_values' => 'family-details',
        'family_annual_income' => 'family-details',
        'father_name' => 'family-details',
        'mother_name' => 'family-details',
        'brothers_count' => 'family-details',
        'sisters_count' => 'family-details',
        // Residence hierarchy lives on Basic info (centralized typeahead); not a separate wizard tab.
        'city_id' => 'basic-info',
        'work_city_id' => 'education-career',
        'country_id' => 'basic-info',
        'state_id' => 'basic-info',
        'district_id' => 'basic-info',
        'taluka_id' => 'basic-info',
    ],

];
