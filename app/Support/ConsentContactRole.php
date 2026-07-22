<?php

namespace App\Support;

/**
 * Canonical vocabulary for "whose number is this" when routing a consent
 * request, and the order in which numbers should be tried.
 *
 * WHY THIS EXISTS — searched first, per the one-engine rule:
 * BulkIntakeCandidateContactPlanService already implemented exactly this
 * ordering (self → father → mother → other family → other) plus display
 * labels, but as private constants/methods bound to a BulkIntakeBatchItem,
 * so nothing outside the admin OCR pipeline could reuse it. The vocabulary
 * was EXTRACTED here and that service now consumes it — this is one fewer
 * copy, not a parallel engine.
 *
 * What legitimately differs per surface is only where the numbers are READ
 * from: the intake pipeline reads an OCR snapshot array, the Suchak side
 * reads a saved MatrimonyProfile. The ordering, labels and priority live
 * here once.
 */
final class ConsentContactRole
{
    public const SELF = 'self';

    public const FATHER = 'father';

    public const MOTHER = 'mother';

    public const SIBLING = 'sibling';

    public const OTHER_FAMILY = 'other_family';

    public const OTHER = 'ocr_other';

    /**
     * Try-order for consent delivery. The candidate's own number first; family
     * numbers only as fallback when the candidate does not respond.
     *
     * @return array<int, string>
     */
    public static function priorityOrder(): array
    {
        return [self::SELF, self::FATHER, self::MOTHER, self::SIBLING, self::OTHER_FAMILY, self::OTHER];
    }

    public static function priority(string $role): int
    {
        $index = array_search($role, self::priorityOrder(), true);

        return $index === false ? count(self::priorityOrder()) : (int) $index;
    }

    public static function label(string $role): ?string
    {
        return match ($role) {
            self::SELF => 'Candidate',
            self::FATHER => 'Father',
            self::MOTHER => 'Mother',
            self::SIBLING => 'Sibling',
            self::OTHER_FAMILY => 'Family',
            self::OTHER => 'Other',
            default => $role !== '' ? str_replace('_', ' ', $role) : null,
        };
    }

    /** Marathi label for Suchak-facing surfaces. */
    public static function labelMarathi(string $role): string
    {
        return match ($role) {
            self::SELF => 'उमेदवार स्वतः',
            self::FATHER => 'वडील',
            self::MOTHER => 'आई',
            self::SIBLING => 'भाऊ / बहीण',
            self::OTHER_FAMILY => 'कुटुंबातील',
            default => 'इतर',
        };
    }

    /** Masks a 10-digit number for display: 9822012345 → 98220•••45 */
    public static function maskMobile(string $mobile): string
    {
        $digits = preg_replace('/\D+/', '', $mobile) ?? $mobile;
        if (strlen($digits) < 7) {
            return str_repeat('•', max(0, strlen($digits)));
        }

        return substr($digits, 0, 5).'•••'.substr($digits, -2);
    }
}
