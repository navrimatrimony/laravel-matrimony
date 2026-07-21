<?php

namespace App\Support;

/**
 * Canonical marital-status dependency rules (PO decision 2026-07-22).
 *
 * WHY THIS EXISTS — searched first, per the one-engine rule:
 * - `resources/views/matrimony/profile/wizard/sections/marital_engine.blade.php`
 *   calls itself "the single canonical UI for marital status + children", but it
 *   is Blade + Alpine — a VIEW. No PHP surface can call it.
 * - Year-sanity rules (divorce/separation/death >= marriage, no future years)
 *   existed in exactly ONE place: ProfileWizardController::buildMarriagesSnapshot,
 *   inline. The mobile PUT, ManualSnapshotBuilderService and the bulk-intake
 *   bridge had none, so the same edit was accepted on mobile and rejected on web.
 * - The status list ['divorced','annulled','separated','widowed'] was
 *   copy-pasted in 9+ places.
 *
 * So this class is NOT a second rule engine — the web rules were MOVED here
 * verbatim and ProfileWizardController now calls it. Every surface that needs
 * these rules must consume this class; do not re-implement them locally.
 */
final class MaritalDependencyRules
{
    /** Statuses whose marriage/children detail block applies. */
    public const DETAIL_STATUS_KEYS = ['divorced', 'annulled', 'separated', 'widowed'];

    public const NEVER_MARRIED = 'never_married';

    /** All supported marital status keys. */
    public const ALL_STATUS_KEYS = ['never_married', 'divorced', 'annulled', 'separated', 'widowed'];

    /** Year fields, mapped to the statuses they are meaningful for. */
    private const YEAR_FIELD_STATUSES = [
        'marriage_year' => self::DETAIL_STATUS_KEYS,
        'divorce_year' => ['divorced', 'annulled'],
        'separation_year' => ['separated'],
        'spouse_death_year' => ['widowed'],
    ];

    private const DIVORCE_STATUS_KEYS = ['divorced', 'annulled', 'separated'];

    public static function requiresMarriageDetails(?string $statusKey): bool
    {
        return $statusKey !== null && in_array($statusKey, self::DETAIL_STATUS_KEYS, true);
    }

    public static function isNeverMarried(?string $statusKey): bool
    {
        return $statusKey === self::NEVER_MARRIED;
    }

    public static function allowsDivorceStatus(?string $statusKey): bool
    {
        return $statusKey !== null && in_array($statusKey, self::DIVORCE_STATUS_KEYS, true);
    }

    /** Is this year field meaningful for the given status? */
    public static function allowsYearField(string $field, ?string $statusKey): bool
    {
        $statuses = self::YEAR_FIELD_STATUSES[$field] ?? null;

        return $statuses !== null && $statusKey !== null && in_array($statusKey, $statuses, true);
    }

    /**
     * Cross-field year sanity. Moved verbatim from
     * ProfileWizardController::buildMarriagesSnapshot so web behaviour is
     * unchanged; now also reachable from API surfaces.
     *
     * @param  array<string, mixed>  $row  one marriage row
     * @param  string  $keyPrefix  error-key prefix, e.g. 'marriages.0'
     * @return array<string, string> field error key => message (empty when valid)
     */
    public static function yearSanityErrors(array $row, string $keyPrefix = 'marriages.0'): array
    {
        $int = static function (mixed $value): ?int {
            if ($value === null || $value === '' || ! is_numeric($value)) {
                return null;
            }

            return (int) $value;
        };

        $marriageYear = $int($row['marriage_year'] ?? null);
        $divorceYear = $int($row['divorce_year'] ?? null);
        $separationYear = $int($row['separation_year'] ?? null);
        $spouseDeathYear = $int($row['spouse_death_year'] ?? null);
        $currentYear = (int) date('Y');

        $errors = [];

        if ($marriageYear !== null && $divorceYear !== null && $divorceYear < $marriageYear) {
            $errors[$keyPrefix.'.divorce_year'] = 'Divorce year must be greater than or equal to marriage year.';
        }
        if ($marriageYear !== null && $separationYear !== null && $separationYear < $marriageYear) {
            $errors[$keyPrefix.'.separation_year'] = 'Separation year must be greater than or equal to marriage year.';
        }
        if ($marriageYear !== null && $spouseDeathYear !== null && $spouseDeathYear < $marriageYear) {
            $errors[$keyPrefix.'.spouse_death_year'] = 'Spouse death year must be greater than or equal to marriage year.';
        }

        foreach ([
            'marriage_year' => ['value' => $marriageYear, 'label' => 'Marriage year'],
            'divorce_year' => ['value' => $divorceYear, 'label' => 'Divorce year'],
            'separation_year' => ['value' => $separationYear, 'label' => 'Separation year'],
            'spouse_death_year' => ['value' => $spouseDeathYear, 'label' => 'Spouse death year'],
        ] as $field => $meta) {
            if ($meta['value'] !== null && $meta['value'] > $currentYear) {
                $errors[$keyPrefix.'.'.$field] = $meta['label'].' cannot be in the future.';
            }
        }

        return $errors;
    }

    /** Per-field year rules shared by every validator. */
    public static function yearFieldRules(): array
    {
        return ['nullable', 'integer', 'min:1901', 'max:'.(int) date('Y')];
    }
}
