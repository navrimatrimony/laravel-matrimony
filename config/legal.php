<?php

/*
|--------------------------------------------------------------------------
| Legal documents — the ONE place to fill in company facts
|--------------------------------------------------------------------------
| Every company-specific fact printed on the five public legal pages
| (terms, privacy, refund, disclaimer, grievance) is resolved from this file
| and injected into the translated text by App\Support\LegalDocument.
|
| A value still written as [[TOKEN]] is an UNFILLED placeholder. It renders
| literally on the public page, so a missing fact is impossible to overlook.
| Fill it here once and all five pages pick it up.
|
| Ownership note (no-duplicate rule, docs/FIELD-OWNERSHIP-MAP.md):
| brand identity — site name, logo, social links, support/sales/info email,
| public phone and public address — is already owned by
| App\Services\SiteIdentityService (DB-backed admin settings). This file does
| NOT create a second copy of those: App\Support\LegalDocument prefers the
| admin setting whenever it is filled and falls back to the values below.
| Facts that exist only for legal documents (legal entity name, LLPIN,
| registered address, grievance officer, jurisdiction, document versions)
| are owned here and nowhere else.
|
| Digits: every numeral written here must be Latin 0-9. Devanagari digits are
| forbidden product-wide.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Legal entity
    |--------------------------------------------------------------------------
    */
    'entity' => [
        // Verified facts — do not change without the product owner.
        'legal_name' => 'Navri Mile Navryala Matrimony LLP',
        'brand_name' => 'Navri Mile Navryala',
        'website' => 'https://navrimilenavryala.com',
        'domain' => 'navrimilenavryala.com',

        // Placeholders — the product owner must fill these in.
        'llpin' => '[[LLPIN]]',
        'registered_address' => '[[REGISTERED_ADDRESS]]',
        'gstin' => '[[GSTIN]]',
        'jurisdiction_city' => '[[JURISDICTION_CITY]]',
        'jurisdiction_state' => 'Maharashtra',
    ],

    /*
    |--------------------------------------------------------------------------
    | Public contact
    |--------------------------------------------------------------------------
    | `mobile` is the verified public contact number. `mobile_tel` is the same
    | number in tel: form. Overridden by SiteIdentityService when the admin has
    | set a primary phone / support email there.
    */
    'contact' => [
        'mobile' => '91284 92284',
        'mobile_tel' => '+919128492284',
        'support_email' => '[[SUPPORT_EMAIL]]',
    ],

    /*
    |--------------------------------------------------------------------------
    | Grievance Officer — IT (Intermediary Guidelines) Rules 2021, Rule 3(2)
    |--------------------------------------------------------------------------
    | The Rules require a NAMED officer with contact details published on the
    | platform, acknowledgement within 24 hours and disposal within 15 days.
    | Timelines below are the statutory maximums; do not raise them.
    */
    'grievance' => [
        'officer_name' => '[[GRIEVANCE_OFFICER_NAME]]',
        'officer_designation' => '[[GRIEVANCE_OFFICER_DESIGNATION]]',
        'officer_email' => '[[GRIEVANCE_OFFICER_EMAIL]]',
        'officer_phone' => '91284 92284',
        'officer_address' => '[[REGISTERED_ADDRESS]]',
        'officer_hours' => '10:00 - 18:00 IST, Monday to Saturday',

        'acknowledgement_hours' => 24,
        'resolution_days' => 15,
        'urgent_takedown_hours' => 24,
        'dpdp_response_days' => 30,
        'escalation_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Document versions and dates
    |--------------------------------------------------------------------------
    | `versions.terms` and `versions.privacy` MUST match the consent version
    | string the mobile apps send at signup, because that string is what lands
    | in `user_consents.version` (see App\Services\Api\MobileOtpService).
    |
    | Today both apps send the single literal '2026-06-24':
    |   flutter-apk/lib/core/app_consent.dart          -> AppConsent::version
    |   Suchak-apk/lib/core/auth/auth_repository.dart  -> consentVersion
    |
    | Changing the version here WITHOUT shipping both app builds would record
    | consent against a version no user was ever shown. Bump all three together.
    */
    'versions' => [
        'terms' => '2026-06-24',
        'privacy' => '2026-06-24',
        'refund' => '2026-06-24',
        'disclaimer' => '2026-06-24',
        'grievance' => '2026-06-24',
    ],

    'effective_from' => '2026-06-24',
    'last_updated' => '2026-08-05',

    /*
    |--------------------------------------------------------------------------
    | Refund / cancellation windows
    |--------------------------------------------------------------------------
    | Referenced by the refund policy page and by nothing else today. These are
    | policy numbers, not enforcement — no code reads them to allow or refuse a
    | refund. If a refund engine is ever built it must bind to these values
    | rather than restate them.
    */
    'refund' => [
        'cooling_off_hours' => 48,
        'processing_days' => 7,
        'bank_credit_days' => 10,
        'gateway' => 'PayU',
    ],

    /*
    |--------------------------------------------------------------------------
    | Data retention windows quoted in the privacy policy
    |--------------------------------------------------------------------------
    */
    'retention' => [
        'deletion_request_days' => 30,
        'financial_records_years' => 8,
        'inactive_account_months' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Document registry — slug => route name + lang key
    |--------------------------------------------------------------------------
    | The single list every legal surface reads: routes, the footer link block
    | and the page renderer. Adding a sixth document means adding one row here.
    */
    'documents' => [
        'terms' => ['uri' => 'terms', 'route' => 'legal.terms'],
        'privacy' => ['uri' => 'privacy', 'route' => 'legal.privacy'],
        'refund' => ['uri' => 'refund-policy', 'route' => 'legal.refund'],
        'disclaimer' => ['uri' => 'disclaimer', 'route' => 'legal.disclaimer'],
        'grievance' => ['uri' => 'grievance', 'route' => 'legal.grievance'],
    ],
];
