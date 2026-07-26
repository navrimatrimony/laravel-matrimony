<?php

return [
    'nav_matches' => 'Matches',
    'profile_label' => 'Profile',
    'photo_placeholder' => 'Photo',
    'title' => 'Matches for you',
    'subtitle' => 'One compatibility engine — pick a lens below. Same scoring and safety rules; tabs only change who appears and in what order.',
    'lenses_label' => 'Lenses',
    'empty' => 'No matches found yet. Complete your profile and partner preferences, or check back later.',
    'empty_tab' => 'Nothing to show in this tab right now. Try another tab or broaden your partner preferences.',
    'score' => 'Match score',
    'score_percent' => ':n% match',
    'boost_note' => 'Includes :n boost from activity & premium signals',
    'view_profile' => 'View profile',
    'reasons_heading' => 'Why you match',
    'tab_perfect' => 'Perfect for you',
    'tab_daily' => 'Daily picks',
    'tab_near' => 'Near me',
    'tab_fresh' => 'Fresh arrivals',
    'tab_viewed' => 'Viewed you',
    'tab_interested' => 'Interested in you',
    'tab_second' => 'Second chance',
    'tab_curated' => 'Curated',
    'tab_hint_perfect' => 'Best overall fit from your preferences.',
    'tab_hint_daily' => 'A fresh mix each day — same rules, new order.',
    'tab_hint_near' => 'Same city & state matches ranked first.',
    'tab_hint_fresh' => 'Profiles updated in the last 14 days.',
    'tab_hint_viewed' => 'People who opened your profile (eligible matches only).',
    'tab_hint_interested' => 'Pending interests sent to you.',
    'tab_hint_second' => 'Profiles you viewed but have not sent interest to yet.',
    'tab_hint_curated' => 'Higher boost signals (activity, premium) surface first.',
    'skip' => 'Not for me',
    'skip_confirm' => 'Hide this profile from your match lists? After three skips they stay hidden.',
    'skip_recorded' => 'Preference saved. We will show you fewer similar suggestions.',
    'skip_invalid' => 'You cannot skip your own profile.',

    'reason_age_both_in_range' => 'Age fits both partner preference ranges',
    'reason_age_compatible' => 'Age compatible with preferences',
    'reason_age_flexible' => 'Age within flexible range',
    'reason_age_partial' => 'Partial age alignment',

    'reason_same_city' => 'Same city',
    'reason_same_taluka' => 'Same taluka',
    'reason_same_district' => 'Same district',
    'reason_nearby_taluka' => 'Nearby — about :km km',
    'reason_same_state' => 'Same state',
    'reason_same_country' => 'Same country',

    'reason_education_unknown' => 'Education partially scored (details missing)',
    'reason_education_match' => 'Education level matches closely',
    'reason_education_close' => 'Similar education level',
    'reason_education_similar' => 'Comparable education',

    'reason_same_occupation' => 'Same occupation',
    'reason_similar_work_sector' => 'Similar work sector',

    'reason_same_subcaste' => 'Same sub-caste',
    'reason_same_caste' => 'Same caste',
    'reason_same_religion' => 'Same religion',

    'reason_prefs_open' => 'Open partner preferences',
    'reason_strong_pref_alignment' => 'Strong preference alignment both ways',
    'reason_good_pref_alignment' => 'Good preference alignment',

    // Scored field labels — used when reporting a weak signal back to a Suchak.
    'field_age' => 'Age alignment',
    'field_location' => 'Location proximity',
    'field_education' => 'Education level',
    'field_occupation' => 'Occupation / sector',
    'field_community' => 'Community',
    'field_preferences' => 'Partner preference fit',
    'field_marital_status' => 'Marital status fit',
    'field_height' => 'Height fit',
    'field_diet' => 'Diet fit',
    'field_gunamilan' => 'Gunamilan',

    // Location has two ways of scoring zero. "Far apart" is a real weak signal;
    // "no village entered" is a data gap. Never word the gap as a mismatch.
    'location_missing_seeker' => 'The customer has no village recorded — location could not be compared',
    'location_missing_candidate' => 'This match has no village recorded — location could not be compared',

    // ------------------------------------------------------------------
    // गुणमिलन / Gunamilan. Terminology is always "Gunamilan", never "kundali" (owner decision).
    // The three verdicts must stay three: compatible / not compatible / not available. "Not
    // available" is the normal state for most profiles and must never be worded as a rejection.
    // Digits are always Latin — `26/36`, `18` — frozen workspace rule.
    // ------------------------------------------------------------------
    'gunamilan_label' => 'Gunamilan',
    'gunamilan_verdict_compatible' => 'Gunamilan matches',
    'gunamilan_verdict_not_compatible' => 'Gunamilan does not match',
    'gunamilan_verdict_unknown' => 'Patrika details not available',
    'gunamilan_summary' => ':points · :verdict',
    'gunamilan_review_note' => 'Gunamilan :points — below the required 18, worth discussing',
    'gunamilan_mangal_verdict_compatible' => 'Mangal matches',
    'gunamilan_mangal_verdict_not_compatible' => 'Mangal does not match',
    'gunamilan_mangal_verdict_unknown' => 'Mangal status not known',
    'reason_gunamilan_compatible' => 'Gunamilan :points/:max — compatible',

    // Suchak-facing fit presentation (same engine score, operator wording).
    'suchak_fit_strong' => 'Strong preliminary fit',
    'suchak_fit_possible' => 'Possible preliminary fit',
    'suchak_fit_review' => 'Review carefully',
    'suchak_weak_signal' => ':field needs review',
    'suchak_fit_signals' => '{1} :n matched signal|[2,*] :n matched signals',
    'suchak_fit_notes' => '{1} :n review note|[2,*] :n review notes',

    // ------------------------------------------------------------------
    // Tiered relaxation ladder ({@see \App\Services\Matching\MatchRelaxationLadder},
    // reported by MatchingService::lastRelaxationSummary()). `relaxation_tier_N` is the note for
    // the highest tier the run had to climb; `relaxation_field_*` labels the `relaxed_fields` list.
    // ------------------------------------------------------------------
    'relaxation_heading' => 'How this list was widened',
    'relaxation_tier_0' => 'Strict match — nothing was relaxed.',
    'relaxation_tier_1' => 'Income / height conditions eased.',
    'relaxation_tier_2' => 'Widened to nearby districts.',
    'relaxation_tier_3' => 'Caste eased (religion unchanged).',
    'relaxation_tier_4' => 'Gunamilan eased — a computed score below 18 was allowed through.',
    'relaxation_notice' => 'To find enough options we eased this: :note',
    'relaxation_field_income' => 'Income',
    'relaxation_field_height' => 'Height',
    'relaxation_field_location' => 'Location',
    'relaxation_field_caste' => 'Caste',
    'relaxation_field_gunamilan' => 'Gunamilan',
    'relaxation_fields_label' => 'Eased: :fields',
    'relaxation_row_strict' => 'Fits every stated condition',
    'relaxation_row_relaxed' => 'Shown after easing a condition',
    'relaxation_floor_not_reached' => 'Even after easing everything we could, only a few options were found.',
    'relaxation_never_relaxed' => 'Religion, gender and the legal marriage age are never relaxed.',
];
