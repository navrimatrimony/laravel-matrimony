<?php

namespace App\Support;

/**
 * Normalizes Indian mobile numbers for storage and uniqueness checks.
 */
final class MobileNumber
{
    /**
     * Trim spaces, strip non-digits, normalize +91 / leading 0 → 10 digits when possible.
     */
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }
        $trimmed = trim($input);
        if ($trimmed === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $trimmed);
        if ($digits === null || $digits === '') {
            return null;
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, -10);
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, -10);
        }

        if (strlen($digits) !== 10) {
            return null;
        }

        return $digits;
    }

    /**
     * True when value is exactly 10 digits (canonical login/register key).
     */
    public static function isCanonicalTenDigit(?string $stored): bool
    {
        if ($stored === null || $stored === '') {
            return false;
        }

        return (bool) preg_match('/^\d{10}$/', $stored);
    }
}
