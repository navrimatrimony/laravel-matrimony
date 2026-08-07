<?php

/**
 * Public homepage copy — English.
 *
 * This file is the single owner of every word on the signed-out homepage. The
 * admin Homepage screen no longer carries a prose editor: marketing copy is
 * the surface a payment-gateway reviewer reads before anyone signs in, and a
 * textarea has no reviewer, no diff and no history. Per-deployment overrides
 * are still possible through Admin -> Translations, which writes the same keys.
 *
 * Rules that apply to every value below:
 *
 *  - Latin digits 0-9 only, never Devanagari (frozen workspace rule).
 *  - No contact fact is written here — no phone, email, address, office city
 *    or legal entity name. Those are owned by config/legal.php and reach the
 *    page through their own reader. Changing them in one place must change
 *    them everywhere.
 *  - No sentence may describe the admin panel, an internal tool, or a
 *    workflow. Everything here is addressed to a visitor deciding whether to
 *    trust us with their family's marriage.
 *  - No price is written here. Prices come from the plan catalog at render
 *    time; a number typed into this file would be a second owner and would go
 *    stale silently.
 *
 * lang/mr/homepage.php must carry every key in this file.
 */
return [
    // Header / navigation
    'language' => 'Language',
    'login' => 'Login',
    'register' => 'Register',
    'dashboard' => 'Dashboard',
    'profile_wizard' => 'Profile wizard',
    'complete_profile' => 'Complete profile',

    // Hero
    'hero_badge' => 'Marathi matrimony service for Maharashtra',
    'hero_title' => 'Find a trusted match for your family',
    'hero_subtitle' => 'We help Marathi families find, check and meet suitable matches — profiles our team has reviewed, contact details shared only with consent, and a matchmaker who stays with you from the first shortlist to the first meeting.',
    'hero_pricing_note' => 'Registering and browsing profiles is free. A paid membership is what lets you see a match\'s contact details, message them, and use our assisted matchmaking service.',
    'hero_pricing_link' => 'See plans and prices',
    'hero_primary_cta' => 'Register free',
    'hero_secondary_cta' => 'Browse profiles',
    // Shown in place of the hero photo if it fails to load. A visitor reads
    // this, so it must never be an instruction to staff.
    'hero_image_missing' => 'Trusted matchmaking for Marathi families',
    'trust_verified' => 'Checked profiles',
    'trust_privacy' => 'Privacy you control',
    'trust_family' => 'Family involved',

    // Hero search form
    'quick_search_title' => 'Find a match now',
    'quick_search_help' => 'Set what you are looking for and see matching profiles',
    'looking_for' => 'Looking for',
    'marital' => 'Marital status',
    'age_range' => 'Age range',
    'age_from' => 'Age from',
    'age_to' => 'Age to',
    'religion' => 'Religion',
    'caste' => 'Caste',
    'state' => 'State',
    'district' => 'District',
    'any' => 'Any',
    'search' => 'Search',

    // Trust strip
    'trust_safety_comm' => 'Safe, moderated contact',
    'trust_profiles' => 'Every profile is checked',
    'trust_family_flow' => 'Made for how families decide',
    'trust_intent' => 'Marriage-minded members only',

    // How it works
    'how_it_works_title' => 'How it works',
    'how_it_works_subtitle' => 'Four simple steps',
    'how_step_1' => 'Register free',
    'how_step_2' => 'Complete your profile',
    'how_step_3' => 'Search and send an interest',
    'how_step_4' => 'Take a membership and talk to the family',

    // Assisted service
    'assisted_kicker' => 'Assisted matchmaking',
    'assisted_title' => 'A matchmaker who works for your family',
    'assisted_body' => 'Our relationship manager speaks with your family, shortlists matches that fit what you are actually looking for, approaches the other family on your behalf, and helps you arrange the first meeting. Included with a paid membership.',

    // Success stories
    'success_title' => 'Success stories',
    'success_intro' => 'Couples who met through us and are now married, shared with their permission.',

    // Safety and verification
    'safety_title' => 'Safety and trust',
    'safety_subtitle' => 'The checks that happen before you speak to anyone',
    'safety_photo' => 'Photos are reviewed before they appear',
    'safety_contact' => 'Contact details are shared only under our policy',
    'safety_report' => 'You can report anything that looks wrong',
    'safety_admin' => 'Our team checks every new profile',

    // Plans and pricing
    'plans_title' => 'Membership plans and prices',
    'plans_subtitle' => 'Registering and browsing is free. A paid membership is what unlocks a match\'s contact details, messaging, and the assisted matchmaking service.',
    'plans_currency_note' => 'All prices are in Indian Rupees (INR).',
    'plans_gst_note' => 'GST is included in the price shown.',
    'plans_free' => 'Free',
    'plans_mrp_label' => 'MRP',
    'plans_save' => 'Save :percent%',
    'popular' => 'Most chosen',
    'view_plans' => 'See all plans and what each one includes',

    // Mobile app
    'app_title' => 'Our mobile app',
    'app_body' => 'Browse matches, send interests, and message safely from your Android or iOS phone.',
    'app_android_prefix' => 'Get it on',
    'app_android_cta' => 'Google Play',
    'app_ios_prefix' => 'Download on the',
    'app_ios_cta' => 'App Store',

    // Office / in-person help
    'retail_title' => 'Meet us in person',
    'retail_body' => 'You are welcome to visit our office, meet the team, and go through profiles with us before you decide anything.',

    // Closing call to action
    'final_cta_title' => 'Start your search today',
    'final_cta_body' => 'Register free, complete your profile, and see the matches that fit what your family is looking for.',
    'final_search' => 'Browse profiles',
    'final_register' => 'Register free',

    'featured' => 'Featured',

    // Footer (contact facts themselves are never written here)
    'footer_disclaimer' => 'This platform is intended solely for matrimonial matchmaking. Members are responsible for their own verification and decisions.',
    'footer_contact' => 'Contact',
    'footer_navigate' => 'Navigate',
    'footer_legal' => 'Legal',
    'footer_partner_search' => 'Partner search',
    'footer_suchak' => 'For Suchaks',
    'footer_plans' => 'Plans',
];
