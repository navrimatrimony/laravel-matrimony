<?php

/*
|--------------------------------------------------------------------------
| Legal documents — the ONE place to fill in company facts
|--------------------------------------------------------------------------
| Every company-specific fact this product publishes about itself is resolved
| from this file. Two readers join it to the pages:
|
|   App\Support\LegalDocument     -> the six public legal documents, by
|                                    substituting :tokens into lang/{mr,en}/legal.php
|   App\Services\SiteIdentityService -> every other surface (homepage footer,
|                                    public Suchak pages, any new public page)
|
| Change a value here once and BOTH readers move — no page repeats it, no view
| hard-codes it, and there is no manual sweep to do afterwards.
|
| A value still written as [[TOKEN]] is an UNFILLED placeholder. It renders
| literally on the legal page (where an admin-only strip flags it), and the
| Site Identity reader treats it as "no fact yet" so it can never be published
| as though it were a phone number in a marketing footer.
|
| Ownership rule (no-duplicate rule, docs/FIELD-OWNERSHIP-MAP.md):
| this file owns the FACTS — legal entity name, LLPIN, statutory registered
| office, jurisdiction, the public phone and support email, the grievance
| officer, document versions and the policy windows.
| App\Services\SiteIdentityService owns BRAND PRESENTATION — site name, logo,
| tagline, socials, copyright line — and holds an admin OVERRIDE for the
| display-facing facts (company name, support email, primary phone, display
| address). An override applies only while it is non-blank; clear it and the
| value below is published again. `entity.legal_name` and
| `entity.registered_address` carry no override at all: they are statutory and
| are read-only through that service by construction.
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

        // From the MCA Form 16 Certificate of Incorporation, 18 March 2016,
        // Registrar of Companies, Pune. The address below is the LLP's own
        // mailing address on the Registrar's record — NOT the "Pune PMT
        // Building, Deccan Gymkhana, 411004" line that also appears on the
        // certificate, which is the Registrar's own office.
        'llpin' => 'AAF-9862',
        'incorporated_on' => '18 March 2016',
        'registered_address' => '473, Fugewadi, Panjabi Chawl, Opp. Mega Mart, Pune, Maharashtra 411012, India',
        // No `gstin` key on purpose: the LLP is not GST-registered (confirmed
        // 2026-08-05). Do not reintroduce it with a blank or invented value —
        // add it back only against a real registration certificate.
        'jurisdiction_city' => 'Pune',
        'jurisdiction_state' => 'Maharashtra',
    ],

    /*
    |--------------------------------------------------------------------------
    | Public contact
    |--------------------------------------------------------------------------
    | `mobile` is the verified public contact number and is what the homepage
    | footer, the legal pages and every other public surface print — they all
    | read it through App\Services\SiteIdentityService, which falls back here.
    | `mobile_tel` is the same number in tel: form, used for the href so the
    | link can never point somewhere other than the number beside it.
    | An admin may override the displayed phone / support email from
    | Admin -> App Settings -> Company & Contact; clearing that box restores
    | these values everywhere at once.
    */
    'contact' => [
        'mobile' => '91284 92284',
        'mobile_tel' => '+919128492284',
        'support_email' => 'navrimatrimony@gmail.com',
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
        // Rule 3(2) requires the officer's real name, not a role alone.
        'officer_name' => 'Shankar Pawar',
        'officer_designation' => 'Designated Partner',
        'officer_email' => 'navrimatrimony@gmail.com',
        'officer_phone' => '91284 92284',
        'officer_address' => '473, Fugewadi, Panjabi Chawl, Opp. Mega Mart, Pune, Maharashtra 411012, India',
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
        // Not a consent document — nothing in the apps records agreement to it,
        // so it carries its own date and does not need the signup version bump.
        'delete_account' => '2026-08-05',
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
        // Read from the engine rather than restated, so the number a member is
        // promised on the page can never drift from the number the sweep uses.
        'deletion_request_days' => \App\Services\Account\MemberAccountDeletionService::GRACE_DAYS,
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
        // Google Play requires a public, no-login URL describing account
        // deletion, separate from the in-app path. Same renderer as the rest.
        'delete_account' => ['uri' => 'delete-account', 'route' => 'legal.delete-account'],
    ],
];
