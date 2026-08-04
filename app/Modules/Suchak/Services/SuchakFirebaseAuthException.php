<?php

namespace App\Modules\Suchak\Services;

use RuntimeException;

/**
 * The Firebase token was fine; the Suchak account it points at was not.
 *
 * Kept separate from FirebaseIdTokenException so "your token is bad" and
 * "your account is not eligible" never collapse into one client-visible code.
 */
class SuchakFirebaseAuthException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function suchakNotFound(): self
    {
        return new self(
            'suchak_not_found',
            'No Suchak account found for this verified mobile number. Register as a new Suchak.',
            404,
        );
    }

    /**
     * The app said it was verifying one number and Firebase signed another.
     *
     * Always a refusal, never a silent correction — see the reasoning on
     * SuchakFirebasePhoneAuthService::proveNumber().
     */
    public static function mobileMismatch(): self
    {
        return new self(
            'mobile_mismatch',
            'The verified number does not match the number this screen was verifying. Start again with one number.',
            409,
        );
    }
}
