<?php

namespace App\Services;

use App\Models\MatrimonyProfile;

/**
 * Phase-3 Day-14: OCR Mode Detection (Structure Only).
 * 
 * NO OCR ENGINE HERE — This determines which governance mode applies.
 * 
 * Detects which OCR mode applies based on:
 * - Profile existence
 * - Existing data presence
 * - Field lock state
 * 
 * Returns mode constant only — NO data mutation, NO auto population.
 */
class OcrModeDetectionService
{
    /**
     * Detect which OCR mode applies for a profile and field.
     * 
     * Logic:
     * - If profile does not exist → MODE_1_FIRST_CREATION
     * - If profile exists AND field is locked → MODE_3_POST_HUMAN_EDIT_LOCK
     * - If profile exists AND field is not locked → MODE_2_EXISTING_PROFILE
     *
     * @param  MatrimonyProfile|null  $profile  Profile instance (null = new profile)
     * @param  string  $fieldKey  Field key to check
     * @return string  OCR mode constant (OcrMode::MODE_*)
     */
    public static function detect(?MatrimonyProfile $profile, string $fieldKey): string
    {
        // Profile does not exist → first creation
        if ($profile === null) {
            return OcrMode::MODE_1_FIRST_CREATION;
        }

        // Profile exists → check if field is locked
        if (ProfileFieldLockService::isLocked($profile, $fieldKey)) {
            return OcrMode::MODE_3_POST_HUMAN_EDIT_LOCK;
        }

        // Profile exists, field not locked → existing profile mode
        return OcrMode::MODE_2_EXISTING_PROFILE;
    }

    /**
     * Detect mode for entire profile (returns mode map per field).
     * 
     * Useful for bulk mode detection across multiple fields.
     *
     * @param  MatrimonyProfile|null  $profile
     * @param  array<string>  $fieldKeys  Field keys to check
     * @return array<string, string>  Map: field_key => mode_constant
     */
    public static function detectForFields(?MatrimonyProfile $profile, array $fieldKeys): array
    {
        $modes = [];
        foreach ($fieldKeys as $fieldKey) {
            $modes[$fieldKey] = self::detect($profile, $fieldKey);
        }
        return $modes;
    }
}
