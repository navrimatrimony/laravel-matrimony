<?php

namespace App\Services;

/**
 * Phase-3 Day-14: OCR Mode-Based Governance Foundation (Structure Only).
 * 
 * NO OCR ENGINE HERE — This is governance structure only.
 * 
 * Defines three OCR modes for field population governance:
 * 
 * MODE_1_FIRST_CREATION:
 *   - Profile does not exist yet (new profile creation)
 *   - All fields are empty/null
 *   - OCR can populate ALL fields without conflicts
 *   - Behavior: ALLOW all fields (no conflicts, no locks)
 * 
 * MODE_2_EXISTING_PROFILE:
 *   - Profile exists and has some data
 *   - Fields may have existing values
 *   - OCR proposes new values that may differ
 *   - Behavior: CREATE_CONFLICT for mismatches (conflict detection applies)
 * 
 * MODE_3_POST_HUMAN_EDIT_LOCK:
 *   - Profile exists
 *   - Field has been edited by human (User/Admin/Matchmaker)
 *   - Field is LOCKED (lock_after_user_edit = true)
 *   - Behavior: SKIP locked fields (no overwrite, no conflict creation)
 * 
 * Authority order (enforced in governance):
 *   Admin > User > Matchmaker > OCR/System
 * 
 * OCR/System (authority rank 99) is lowest authority.
 * Locked fields cannot be overwritten by OCR.
 */
class OcrMode
{
    /** Mode 1: First profile creation — no existing data, all fields allowed. */
    public const MODE_1_FIRST_CREATION = 'MODE_1_FIRST_CREATION';

    /** Mode 2: Existing profile — conflicts detected for mismatches. */
    public const MODE_2_EXISTING_PROFILE = 'MODE_2_EXISTING_PROFILE';

    /** Mode 3: Post-human-edit lock — locked fields skipped. */
    public const MODE_3_POST_HUMAN_EDIT_LOCK = 'MODE_3_POST_HUMAN_EDIT_LOCK';

    /**
     * Get all valid OCR modes.
     *
     * @return array<string>
     */
    public static function all(): array
    {
        return [
            self::MODE_1_FIRST_CREATION,
            self::MODE_2_EXISTING_PROFILE,
            self::MODE_3_POST_HUMAN_EDIT_LOCK,
        ];
    }

    /**
     * Check if a mode string is valid.
     *
     * @param  string  $mode
     * @return bool
     */
    public static function isValid(string $mode): bool
    {
        return in_array($mode, self::all(), true);
    }
}
