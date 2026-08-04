<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Firebase Phone Auth — server-side ID token verification
    |--------------------------------------------------------------------------
    |
    | The phone proves itself to Firebase on the device; Firebase returns an ID
    | token; the app sends that token here. The server NEVER believes a client
    | flag such as "I verified this number" — it verifies the token's RS256
    | signature against Google's published JWKS and reads the number out of the
    | `phone_number` claim.
    |
    | `project_id` is the ONLY value that must be set for this to work. It is
    | not a secret: verification needs the project id and Google's public keys,
    | nothing else. No service-account JSON is required, which is deliberate —
    | see App\Services\Auth\FirebaseIdTokenVerifier for the reasoning.
    |
    | Resolution order for the project id (first non-empty wins):
    |   1. FIREBASE_AUTH_PROJECT_ID
    |   2. FCM_PROJECT_ID / engagement.push.project_id (the existing owner of
    |      "which Firebase project is this?" — see config/engagement.php)
    |   3. the `project_id` inside the FCM service-account JSON, when present
    |
    | Step 2 and 3 exist so a correctly configured push install needs no new
    | env at all. Step 1 exists because Phone Auth must be able to work on a
    | host that has no service-account file.
    |
    */
    'project_id' => env('FIREBASE_AUTH_PROJECT_ID'),

    /*
    | Master switch for the Suchak Firebase Phone Auth endpoints.
    |
    | OFF means the endpoints answer 503 with a "not available" code. It does
    | NOT mean the app quietly falls back to a demo OTP — a silent fallback is
    | an auth bypass. Every failure on this path fails closed.
    */
    'enabled' => (bool) env('SUCHAK_FIREBASE_PHONE_AUTH_ENABLED', true),

    /*
    | The legacy demo/WhatsApp OTP endpoints for the Suchak app
    | (/suchak/login/otp/*, /suchak/register/otp/*).
    |
    | null (unset) => enabled everywhere EXCEPT production.
    | true / false => explicit override.
    |
    | Production default is OFF because those endpoints can still hand back a
    | plaintext OTP (MOBILE_OTP_DELIVERY / AdminSetting mobile_verification_mode
    | = 'dev_show'), which is blueprint item §10 S1. The switch stays so the
    | owner can re-open them deliberately while testing, never by accident.
    |
    | This switch covers the SUCHAK endpoints only. The member app's own OTP
    | routes are untouched: turning them off here would break member login,
    | which is out of scope for this phase.
    */
    'legacy_suchak_otp' => env('SUCHAK_LEGACY_OTP_ENABLED'),

    /*
    | Google's published key set for Firebase ID tokens (RFC 7517 JWK). The
    | equivalent x509 PEM endpoint is also understood if configured instead:
    | https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com
    */
    'jwks_url' => env(
        'FIREBASE_AUTH_JWKS_URL',
        'https://www.googleapis.com/service_accounts/v1/jwk/securetoken@system.gserviceaccount.com'
    ),

    /** Seconds to cache the fetched key set. Google rotates roughly daily. */
    'jwks_ttl' => (int) env('FIREBASE_AUTH_JWKS_TTL', 3600),

    /** Seconds of clock skew tolerated on exp / iat / auth_time. */
    'leeway' => (int) env('FIREBASE_AUTH_LEEWAY', 60),

    /** Seconds to wait on the JWKS fetch. */
    'http_timeout' => (int) env('FIREBASE_AUTH_HTTP_TIMEOUT', 5),

];
