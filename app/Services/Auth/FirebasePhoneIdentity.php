<?php

namespace App\Services\Auth;

use Illuminate\Support\Carbon;

/**
 * What a verified Firebase ID token actually proves.
 *
 * Nothing in here comes from the request body. Every field was read out of a
 * token whose RS256 signature was checked against Google's published keys, so
 * this object — and only this object — may be treated as the truth about which
 * phone number the caller holds.
 */
final class FirebasePhoneIdentity
{
    public function __construct(
        /** Firebase uid (`sub`). Stable per project per phone number. */
        public readonly string $uid,
        /** Exactly as Firebase issued it, E.164, e.g. +919876543210. */
        public readonly string $phoneNumberE164,
        /** The same number in this product's canonical 10-digit storage form. */
        public readonly string $mobile,
        /** When the phone actually completed the sign-in challenge. */
        public readonly Carbon $authenticatedAt,
        /** When the token stops being acceptable. */
        public readonly Carbon $expiresAt,
        /** Firebase sign-in provider — always `phone` on this path. */
        public readonly string $signInProvider,
    ) {}
}
