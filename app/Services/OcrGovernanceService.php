<?php

namespace App\Services;

use App\Models\ConflictRecord;
use App\Models\MatrimonyProfile;

/**
 * Phase-3 Day-14: OCR Mode-Based Governance Service (Structure Only).
 * 
 * NO OCR ENGINE HERE — This is governance decision logic only.
 * 
 * Decides what action to take for OCR-proposed field values:
 * - ALLOW: Field can be populated (no conflict, no lock)
 * - SKIP: Field must be skipped (locked, cannot overwrite)
 * - CREATE_CONFLICT: Conflict detected, create ConflictRecord
 * 
 * Authority order enforced:
 *   Admin > User > Matchmaker > OCR/System
 * 
 * OCR/System (rank 99) is lowest authority.
 * Locked fields (edited by human) cannot be overwritten by OCR.
 * 
 * This service does NOT mutate profile data.
 * It only returns governance decisions.
 */
class OcrGovernanceService
{
    /** Decision: Field can be populated without conflict. */
    public const DECISION_ALLOW = 'ALLOW';

    /** Decision: Field must be skipped (locked, cannot overwrite). */
    public const DECISION_SKIP = 'SKIP';

    /** Decision: Conflict detected, create ConflictRecord. */
    public const DECISION_CREATE_CONFLICT = 'CREATE_CONFLICT';

    /**
     * Determine governance decision for a field based on OCR mode.
     * 
     * Mode behavior:
     * - MODE_1_FIRST_CREATION: ALLOW (no existing data, no conflicts)
     * - MODE_2_EXISTING_PROFILE: CREATE_CONFLICT if values differ, else ALLOW
     * - MODE_3_POST_HUMAN_EDIT_LOCK: SKIP (locked field, cannot overwrite)
     *
     * @param  string  $mode  OCR mode constant (OcrMode::MODE_*)
     * @param  MatrimonyProfile|null  $profile  Profile instance (null = new profile)
     * @param  string  $fieldKey  Field key
     * @param  mixed  $proposedValue  Proposed value from OCR
     * @param  string  $fieldType  'CORE' or 'EXTENDED'
     * @return string  Decision constant (DECISION_*)
     */
    public static function decide(
        string $mode,
        ?MatrimonyProfile $profile,
        string $fieldKey,
        mixed $proposedValue,
        string $fieldType
    ): string {
        // MODE_1: First creation — all fields allowed
        if ($mode === OcrMode::MODE_1_FIRST_CREATION) {
            return self::DECISION_ALLOW;
        }

        // MODE_3: Post-human-edit lock — skip locked fields
        if ($mode === OcrMode::MODE_3_POST_HUMAN_EDIT_LOCK) {
            return self::DECISION_SKIP;
        }

        // MODE_2: Existing profile — check for conflicts
        if ($mode === OcrMode::MODE_2_EXISTING_PROFILE) {
            if ($profile === null) {
                // Should not happen in MODE_2, but safety check
                return self::DECISION_ALLOW;
            }

            // Get current value and normalize both for consistent comparison
            $current = self::getCurrentValue($profile, $fieldKey, $fieldType);
            $current = self::normalize($current);
            $proposed = self::normalize($proposedValue);

            // Compare: if values differ, create conflict
            if (self::valuesDiffer($current, $proposed)) {
                return self::DECISION_CREATE_CONFLICT;
            }

            // Values match — allow (no conflict)
            return self::DECISION_ALLOW;
        }

        // Unknown mode — default to SKIP (safety)
        return self::DECISION_SKIP;
    }

    /**
     * Apply governance decisions for multiple fields (bulk decision).
     * 
     * Returns map: field_key => decision_constant
     * 
     * This method does NOT mutate profile data.
     * It only returns governance decisions.
     *
     * @param  MatrimonyProfile|null  $profile
     * @param  array<string, mixed>  $proposedCore  Proposed CORE field values
     * @param  array<string, mixed>  $proposedExtended  Proposed EXTENDED field values
     * @return array<string, string>  Map: field_key => decision_constant
     */
    public static function decideBulk(
        ?MatrimonyProfile $profile,
        array $proposedCore = [],
        array $proposedExtended = []
    ): array {
        $decisions = [];

        // CORE fields
        foreach ($proposedCore as $fieldKey => $proposedValue) {
            $mode = OcrModeDetectionService::detect($profile, $fieldKey);
            $decisions[$fieldKey] = self::decide($mode, $profile, $fieldKey, $proposedValue, 'CORE');
        }

        // EXTENDED fields
        foreach ($proposedExtended as $fieldKey => $proposedValue) {
            $mode = OcrModeDetectionService::detect($profile, $fieldKey);
            $decisions[$fieldKey] = self::decide($mode, $profile, $fieldKey, $proposedValue, 'EXTENDED');
        }

        return $decisions;
    }

    /**
     * Execute governance decisions (create conflicts where needed).
     * 
     * This method:
     * - Creates ConflictRecord entries for CREATE_CONFLICT decisions
     * - Does NOT mutate profile data
     * - Does NOT auto-populate fields
     * 
     * Returns array of created ConflictRecord instances.
     *
     * @param  MatrimonyProfile|null  $profile  Profile (null = new profile, conflicts not created)
     * @param  array<string, mixed>  $proposedCore  Proposed CORE values
     * @param  array<string, mixed>  $proposedExtended  Proposed EXTENDED values
     * @return ConflictRecord[]  Created conflict records
     */
    public static function executeDecisions(
        ?MatrimonyProfile $profile,
        array $proposedCore = [],
        array $proposedExtended = []
    ): array {
        if ($profile === null) {
            // New profile — no conflicts possible
            return [];
        }

        $decisions = self::decideBulk($profile, $proposedCore, $proposedExtended);
        $created = [];

        // Process CORE fields
        foreach ($proposedCore as $fieldKey => $proposedValue) {
            $decision = $decisions[$fieldKey] ?? self::DECISION_SKIP;
            
            if ($decision === self::DECISION_CREATE_CONFLICT) {
                // SSOT: Deduplication — skip if PENDING conflict already exists for this profile+field
                $existingPending = \App\Models\ConflictRecord::where('profile_id', $profile->id)
                    ->where('field_name', $fieldKey)
                    ->where('resolution_status', 'PENDING')
                    ->exists();
                
                if ($existingPending) {
                    continue; // Skip creating duplicate PENDING conflict
                }
                
                $current = self::getCurrentValue($profile, $fieldKey, 'CORE');
                $current = self::normalize($current);
                $proposed = self::normalize($proposedValue);
                
                // Double-check values actually differ after normalization (safety guard)
                if (self::valuesDiffer($current, $proposed)) {
                    $created[] = ConflictRecord::create([
                        'profile_id' => $profile->id,
                        'field_name' => $fieldKey,
                        'field_type' => 'CORE',
                        'old_value' => $current === null ? null : (string) $current,
                        'new_value' => $proposed === null ? null : (string) $proposed,
                        'source' => 'OCR',
                        'detected_at' => now(),
                        'resolution_status' => 'PENDING',
                    ]);
                }
            }
        }

        // Process EXTENDED fields
        $currentExtended = ExtendedFieldService::getValuesForProfile($profile);
        foreach ($proposedExtended as $fieldKey => $proposedValue) {
            $decision = $decisions[$fieldKey] ?? self::DECISION_SKIP;
            
            if ($decision === self::DECISION_CREATE_CONFLICT) {
                // SSOT: Deduplication — skip if PENDING conflict already exists for this profile+field
                $existingPending = \App\Models\ConflictRecord::where('profile_id', $profile->id)
                    ->where('field_name', $fieldKey)
                    ->where('resolution_status', 'PENDING')
                    ->exists();
                
                if ($existingPending) {
                    continue; // Skip creating duplicate PENDING conflict
                }
                
                $current = $currentExtended[$fieldKey] ?? null;
                $proposed = self::normalize($proposedValue);
                $current = self::normalize($current);
                
                // Double-check values actually differ after normalization (safety guard)
                if (self::valuesDiffer($current, $proposed)) {
                    $created[] = ConflictRecord::create([
                        'profile_id' => $profile->id,
                        'field_name' => $fieldKey,
                        'field_type' => 'EXTENDED',
                        'old_value' => $current === null ? null : (string) $current,
                        'new_value' => $proposed === null ? null : (string) $proposed,
                        'source' => 'OCR',
                        'detected_at' => now(),
                        'resolution_status' => 'PENDING',
                    ]);
                }
            }
        }

        return $created;
    }

    /**
     * Get current value for a field (CORE or EXTENDED).
     *
     * @param  MatrimonyProfile  $profile
     * @param  string  $fieldKey
     * @param  string  $fieldType
     * @return mixed
     */
    private static function getCurrentValue(MatrimonyProfile $profile, string $fieldKey, string $fieldType): mixed
    {
        if ($fieldType === 'CORE') {
            if ($fieldKey === 'gender_id') {
                return $profile->getAttribute('gender_id');
            }
            return $profile->getAttribute($fieldKey);
        }

        // EXTENDED
        $extended = ExtendedFieldService::getValuesForProfile($profile);
        return $extended[$fieldKey] ?? null;
    }

    /**
     * Normalize value (trim, empty string → null).
     *
     * @param  mixed  $value
     * @return ?string
     */
    private static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = is_string($value) ? trim($value) : (string) $value;
        return $s === '' ? null : $s;
    }

    /**
     * Check if two values differ.
     *
     * @param  ?string  $a
     * @param  ?string  $b
     * @return bool
     */
    private static function valuesDiffer(?string $a, ?string $b): bool
    {
        return (string) ($a ?? '') !== (string) ($b ?? '');
    }
}
