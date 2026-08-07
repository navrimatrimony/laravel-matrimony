<?php

/**
 * Site-wide footer labels.
 *
 * ONLY labels that had no owner before this file live here. Every label that
 * already existed keeps its original owner and is read from there by
 * resources/views/layouts/site-footer.blade.php, so no string is duplicated:
 *
 *   homepage.footer_contact / footer_legal / footer_disclaimer
 *   homepage.footer_partner_search / footer_suchak
 *   homepage.login / homepage.register
 *   nav.home
 *   legal.<document>.title      (via App\Support\LegalDocument::links())
 *
 * No company FACT belongs in this file — no name, phone, email, address or
 * LLPIN. Those are owned by config/legal.php and reach the footer through
 * App\Support\LegalDocument::replacements(). Labels here, facts there.
 *
 * Digits: Latin 0-9 only, in every locale.
 */
return [
    'landmark' => 'Site footer',

    // Column headings
    'company' => 'Company',

    // Company column links (routes are resolved by name in the footer view)
    'about_us' => 'About us',
    'contact_us' => 'Contact us',
    'pricing' => 'Pricing',
    'shipping' => 'Shipping & Delivery',

    // Identity / contact labels
    'registered_office' => 'Registered office',
    'llpin' => 'LLPIN',
    'grievance_officer' => 'Grievance Officer',
    'support_hours' => 'Support hours',
    'call_us' => 'Call us',
    'email_us' => 'Email us',
    'follow_us' => 'Follow us',
];
