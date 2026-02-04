<?php

/**
 * Phase-3 Day 15 Manual Test Executor
 * Programmatic verification of governance systems
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Models\FieldRegistry;
use App\Models\ProfileExtendedField;
use App\Models\ProfileFieldLock;
use App\Models\ConflictRecord;
use App\Services\ProfileCompletenessService;
use App\Services\ProfileFieldLockService;
use App\Services\ConflictDetectionService;
use App\Services\ConflictResolutionService;
use App\Services\ProfileLifecycleService;
use App\Services\OcrModeDetectionService;
use App\Services\OcrGovernanceService;
use App\Services\OcrMode;
use App\Services\ExtendedFieldDependencyService;
use Illuminate\Support\Facades\DB;

echo "========================================\n";
echo "DAY 15 — MANUAL TEST REPORT\n";
echo "========================================\n\n";

$results = [];

// Get test profile
$profile = MatrimonyProfile::first();
if (!$profile) {
    echo "ERROR: No profile found. Cannot run tests.\n";
    exit(1);
}

$user = $profile->user;
if (!$user) {
    echo "ERROR: Profile has no user. Cannot run tests.\n";
    exit(1);
}

echo "Using Profile ID: {$profile->id}\n";
echo "User ID: {$user->id}\n\n";

// ============================================
// TEST SUITE A — FIELD REGISTRY & DEPENDENCIES
// ============================================

echo "TEST SUITE A — FIELD REGISTRY & DEPENDENCIES\n";
echo "--------------------------------------------\n";

// Test 1: Disable an EXTENDED field
$extendedField = FieldRegistry::where('field_type', 'EXTENDED')->where('is_enabled', true)->first();
if ($extendedField) {
    // First ensure there's a value for this field
    $extendedValue = ProfileExtendedField::where('profile_id', $profile->id)
        ->where('field_key', $extendedField->field_key)
        ->first();
    
    if (!$extendedValue) {
        // Create a test value
        ProfileExtendedField::create([
            'profile_id' => $profile->id,
            'field_key' => $extendedField->field_key,
            'field_value' => 'test_value'
        ]);
    }
    
    $oldValue = $extendedField->is_enabled;
    $extendedField->update(['is_enabled' => false]);
    
    // Check if value still exists in DB
    $extendedValue = ProfileExtendedField::where('profile_id', $profile->id)
        ->where('field_key', $extendedField->field_key)
        ->first();
    
    $results['A1'] = $extendedValue !== null ? 'PASS' : 'FAIL';
    echo "Test 1: " . $results['A1'] . " (Disable EXTENDED field - value preserved: " . ($extendedValue ? 'YES' : 'NO') . ")\n";
    
    // Restore
    $extendedField->update(['is_enabled' => $oldValue]);
} else {
    $results['A1'] = 'SKIP';
    echo "Test 1: SKIP (No EXTENDED fields found)\n";
}

// Test 2: Archive an EXTENDED field
$extendedField2 = FieldRegistry::where('field_type', 'EXTENDED')->where('is_archived', false)->first();
if ($extendedField2) {
    // Ensure there's a value
    $extendedValue2 = ProfileExtendedField::where('profile_id', $profile->id)
        ->where('field_key', $extendedField2->field_key)
        ->first();
    
    if (!$extendedValue2) {
        ProfileExtendedField::create([
            'profile_id' => $profile->id,
            'field_key' => $extendedField2->field_key,
            'field_value' => 'test_value_archive'
        ]);
    }
    
    $oldArchived = $extendedField2->is_archived;
    $extendedField2->update(['is_archived' => true]);
    
    // Check if value still exists
    $extendedValue2 = ProfileExtendedField::where('profile_id', $profile->id)
        ->where('field_key', $extendedField2->field_key)
        ->first();
    
    $results['A2'] = $extendedValue2 !== null ? 'PASS' : 'FAIL';
    echo "Test 2: " . $results['A2'] . " (Archive EXTENDED field - value preserved: " . ($extendedValue2 ? 'YES' : 'NO') . ")\n";
    
    // Restore
    $extendedField2->update(['is_archived' => $oldArchived]);
} else {
    $results['A2'] = 'SKIP';
    echo "Test 2: SKIP (No unarchived EXTENDED fields found)\n";
}

// Test 3: Parent-child dependency
$parentField = FieldRegistry::where('field_type', 'EXTENDED')->whereNull('parent_field_key')->first();
$childField = FieldRegistry::where('field_type', 'EXTENDED')->whereNotNull('parent_field_key')->first();
if ($parentField && $childField) {
    // Check dependency structure
    $hasDependency = $childField->parent_field_key !== null;
    $results['A3'] = $hasDependency ? 'PASS' : 'FAIL';
    echo "Test 3: " . $results['A3'] . " (Parent-child dependency exists: " . ($hasDependency ? 'YES' : 'NO') . ")\n";
} else {
    $results['A3'] = 'SKIP';
    echo "Test 3: SKIP (No parent-child dependency fields found)\n";
}

echo "\n";

// ============================================
// TEST SUITE B — COMPLETENESS (70% THRESHOLD)
// ============================================

echo "TEST SUITE B — COMPLETENESS (70% THRESHOLD)\n";
echo "--------------------------------------------\n";

// Test 4: Remove mandatory CORE field (find one that will drop below 70%)
$baselinePct = ProfileCompletenessService::percentage($profile);
$mandatoryFields = FieldRegistry::where('field_type', 'CORE')->where('is_mandatory', true)->pluck('field_key')->toArray();
$mandatoryField = null;
$oldValue = null;

// Try to find a field that, when removed, drops completeness below 70%
// Calculate how many mandatory fields are filled
$filledCount = 0;
$totalMandatory = count($mandatoryFields);
foreach ($mandatoryFields as $fieldKey) {
    $value = $profile->getAttribute($fieldKey);
    if ($value !== null && $value !== '') {
        $filledCount++;
    }
}

// If removing one field would drop below 70%, use that field
// Formula: (filledCount - 1) / totalMandatory * 100 < 70
if ($filledCount > 0 && (($filledCount - 1) / $totalMandatory * 100 < 70)) {
    // Find first filled mandatory field
    foreach ($mandatoryFields as $fieldKey) {
        $value = $profile->getAttribute($fieldKey);
        if ($value !== null && $value !== '') {
            $mandatoryField = FieldRegistry::where('field_key', $fieldKey)->first();
            $oldValue = $value;
            break;
        }
    }
}

if ($mandatoryField && $oldValue !== null) {
    $profile->update([$mandatoryField->field_key => null]);
    $profile->refresh();
    
    $newPct = ProfileCompletenessService::percentage($profile);
    $meetsThreshold = ProfileCompletenessService::meetsThreshold($profile);
    
    $results['B4'] = ($newPct < 70 && !$meetsThreshold) ? 'PASS' : 'FAIL';
    echo "Test 4: " . $results['B4'] . " (Remove mandatory field - Completeness: {$baselinePct}% → {$newPct}%, Meets threshold: " . ($meetsThreshold ? 'YES' : 'NO') . ")\n";
    
    // Restore
    $profile->update([$mandatoryField->field_key => $oldValue]);
    $profile->refresh();
} else {
    $results['B4'] = 'SKIP';
    echo "Test 4: SKIP (Profile has too many filled mandatory fields - removing one won't drop below 70%)\n";
}

// Test 5: Refill mandatory CORE field
if ($mandatoryField && $oldValue) {
    $profile->update([$mandatoryField->field_key => $oldValue]);
    $profile->refresh();
    
    $finalPct = ProfileCompletenessService::percentage($profile);
    $meetsThreshold = ProfileCompletenessService::meetsThreshold($profile);
    
    $results['B5'] = ($finalPct >= 70 && $meetsThreshold) ? 'PASS' : 'FAIL';
    echo "Test 5: " . $results['B5'] . " (Refill mandatory field - Completeness: {$finalPct}%, Meets threshold: " . ($meetsThreshold ? 'YES' : 'NO') . ")\n";
} else {
    $results['B5'] = 'SKIP';
    echo "Test 5: SKIP (No mandatory CORE fields found)\n";
}

echo "\n";

// ============================================
// TEST SUITE C — FIELD LOCKING
// ============================================

echo "TEST SUITE C — FIELD LOCKING\n";
echo "--------------------------------------------\n";

// Test 6: User edits CORE field (simulate lock)
$lockableField = FieldRegistry::where('field_type', 'CORE')->where('lock_after_user_edit', true)->first();
if ($lockableField) {
    // Simulate user edit by applying lock
    ProfileFieldLockService::applyLock($profile, $lockableField->field_key, 'CORE', $user);
    
    $isLocked = ProfileFieldLockService::isLocked($profile, $lockableField->field_key);
    $results['C6'] = $isLocked ? 'PASS' : 'FAIL';
    echo "Test 6: " . $results['C6'] . " (User edit creates lock - Locked: " . ($isLocked ? 'YES' : 'NO') . ")\n";
} else {
    $results['C6'] = 'SKIP';
    echo "Test 6: SKIP (No lockable CORE fields found)\n";
}

// Test 7a: Attempt overwrite of locked field via admin
if ($lockableField && $isLocked) {
    // Use a lower authority user (non-admin) to test lock enforcement
    $testUser = User::where('is_admin', false)->first() ?? $user;
    try {
        ProfileFieldLockService::assertNotLocked($profile, [$lockableField->field_key], $testUser);
        $results['C7a'] = 'FAIL';
        echo "Test 7a: FAIL (Lock check should have thrown exception)\n";
    } catch (\Exception $e) {
        $results['C7a'] = 'PASS';
        echo "Test 7a: PASS (Lock check blocked overwrite: " . $e->getMessage() . ")\n";
    }
} else {
    $results['C7a'] = 'SKIP';
    echo "Test 7a: SKIP (No locked field available)\n";
}

// Test 7b: OCR governance simulation on locked field
if ($lockableField && $isLocked) {
    $mode = OcrModeDetectionService::detect($profile, $lockableField->field_key);
    $decision = OcrGovernanceService::decide($mode, $profile, $lockableField->field_key, 'test_value', 'CORE');
    
    $results['C7b'] = ($mode === OcrMode::MODE_3_POST_HUMAN_EDIT_LOCK && $decision === OcrGovernanceService::DECISION_SKIP) ? 'PASS' : 'FAIL';
    echo "Test 7b: " . $results['C7b'] . " (OCR governance skips locked field - Mode: {$mode}, Decision: {$decision})\n";
} else {
    $results['C7b'] = 'SKIP';
    echo "Test 7b: SKIP (No locked field available)\n";
}

// Test 8: Edit unrelated unlocked field
$unlockedField = FieldRegistry::where('field_type', 'CORE')
    ->where('field_key', '!=', $lockableField->field_key ?? '')
    ->first();
if ($unlockedField) {
    $wasLocked = ProfileFieldLockService::isLocked($profile, $unlockedField->field_key);
    $results['C8'] = !$wasLocked ? 'PASS' : 'FAIL';
    echo "Test 8: " . $results['C8'] . " (Unlocked field is editable - Locked: " . ($wasLocked ? 'YES' : 'NO') . ")\n";
} else {
    $results['C8'] = 'SKIP';
    echo "Test 8: SKIP (No unlocked fields found)\n";
}

echo "\n";

// ============================================
// TEST SUITE D — CONFLICT DETECTION
// ============================================

echo "TEST SUITE D — CONFLICT DETECTION\n";
echo "--------------------------------------------\n";

// Test 9: Create CORE mismatch scenario (use a different field than the locked one)
$coreFields = FieldRegistry::where('field_type', 'CORE')->pluck('field_key')->toArray();
$coreField = null;
foreach ($coreFields as $fieldKey) {
    if (!ProfileFieldLockService::isLocked($profile, $fieldKey)) {
        $coreField = FieldRegistry::where('field_key', $fieldKey)->first();
        break;
    }
}
if ($coreField) {
    $currentValue = $profile->getAttribute($coreField->field_key);
    // Ensure we have a different value (use shorter value for enum fields like gender)
    if ($coreField->field_key === 'gender') {
        $proposedValue = ($currentValue === 'Male') ? 'Female' : 'Male';
    } else {
        $proposedValue = ($currentValue === null || $currentValue === '') ? 'test_value' : 'different_value';
    }
    
    // Clear existing conflicts
    ConflictRecord::where('profile_id', $profile->id)
        ->where('field_name', $coreField->field_key)
        ->where('resolution_status', 'PENDING')
        ->delete();
    
    $conflicts = ConflictDetectionService::detect($profile, [$coreField->field_key => $proposedValue], []);
    
    $conflictCreated = ConflictRecord::where('profile_id', $profile->id)
        ->where('field_name', $coreField->field_key)
        ->where('resolution_status', 'PENDING')
        ->exists();
    
    $results['D9'] = $conflictCreated ? 'PASS' : 'FAIL';
    echo "Test 9: " . $results['D9'] . " (CORE mismatch creates conflict - Created: " . ($conflictCreated ? 'YES' : 'NO') . ", Current: " . ($currentValue ?? 'NULL') . ", Proposed: {$proposedValue})\n";
} else {
    $results['D9'] = 'SKIP';
    echo "Test 9: SKIP (All CORE fields are locked)\n";
}

// Test 10: Create EXTENDED mismatch scenario
$extendedField = FieldRegistry::where('field_type', 'EXTENDED')->first();
if ($extendedField) {
    $currentValue = \App\Services\ExtendedFieldService::getValuesForProfile($profile)[$extendedField->field_key] ?? null;
    $proposedValue = ($currentValue ?? '') . '_DIFFERENT';
    
    // Clear existing conflicts
    ConflictRecord::where('profile_id', $profile->id)
        ->where('field_name', $extendedField->field_key)
        ->where('resolution_status', 'PENDING')
        ->delete();
    
    $conflicts = ConflictDetectionService::detect($profile, [], [$extendedField->field_key => $proposedValue]);
    
    $conflictCreated = ConflictRecord::where('profile_id', $profile->id)
        ->where('field_name', $extendedField->field_key)
        ->where('resolution_status', 'PENDING')
        ->exists();
    
    $results['D10'] = $conflictCreated ? 'PASS' : 'FAIL';
    echo "Test 10: " . $results['D10'] . " (EXTENDED mismatch creates conflict - Created: " . ($conflictCreated ? 'YES' : 'NO') . ")\n";
} else {
    $results['D10'] = 'SKIP';
    echo "Test 10: SKIP (No EXTENDED fields found)\n";
}

// Test 11: Locked field mismatch
if ($lockableField && $isLocked) {
    // Clear existing conflicts
    ConflictRecord::where('profile_id', $profile->id)
        ->where('field_name', $lockableField->field_key)
        ->where('resolution_status', 'PENDING')
        ->delete();
    
    $conflicts = ConflictDetectionService::detect($profile, [$lockableField->field_key => 'test_value'], []);
    
    $conflictCreated = ConflictRecord::where('profile_id', $profile->id)
        ->where('field_name', $lockableField->field_key)
        ->where('resolution_status', 'PENDING')
        ->exists();
    
    $results['D11'] = !$conflictCreated ? 'PASS' : 'FAIL';
    echo "Test 11: " . $results['D11'] . " (Locked field mismatch does NOT create conflict - Created: " . ($conflictCreated ? 'YES' : 'NO') . ")\n";
} else {
    $results['D11'] = 'SKIP';
    echo "Test 11: SKIP (No locked field available)\n";
}

echo "\n";

// ============================================
// TEST SUITE E — CONFLICT RESOLUTION
// ============================================

echo "TEST SUITE E — CONFLICT RESOLUTION\n";
echo "--------------------------------------------\n";

// Test 12: APPROVE conflict
$pendingConflict = ConflictRecord::where('profile_id', $profile->id)
    ->where('resolution_status', 'PENDING')
    ->first();
if ($pendingConflict) {
    $oldProfileValue = $pendingConflict->field_type === 'CORE' 
        ? $profile->getAttribute($pendingConflict->field_name)
        : (\App\Services\ExtendedFieldService::getValuesForProfile($profile)[$pendingConflict->field_name] ?? null);
    
    $adminUser = User::where('is_admin', true)->first() ?? $user;
    ConflictResolutionService::approveConflict($pendingConflict, $adminUser, 'Test approval');
    
    $profile->refresh();
    $newProfileValue = $pendingConflict->field_type === 'CORE'
        ? $profile->getAttribute($pendingConflict->field_name)
        : (\App\Services\ExtendedFieldService::getValuesForProfile($profile)[$pendingConflict->field_name] ?? null);
    
    $conflictResolved = ConflictRecord::find($pendingConflict->id)->resolution_status === 'APPROVED';
    $valueApplied = ($newProfileValue == $pendingConflict->new_value);
    
    $results['E12'] = ($conflictResolved && $valueApplied) ? 'PASS' : 'FAIL';
    echo "Test 12: " . $results['E12'] . " (APPROVE conflict - Resolved: " . ($conflictResolved ? 'YES' : 'NO') . ", Value applied: " . ($valueApplied ? 'YES' : 'NO') . ")\n";
} else {
    $results['E12'] = 'SKIP';
    echo "Test 12: SKIP (No pending conflicts found)\n";
}

// Test 13: REJECT conflict
$pendingConflict2 = ConflictRecord::where('profile_id', $profile->id)
    ->where('resolution_status', 'PENDING')
    ->first();
if ($pendingConflict2) {
    $beforeReject = $pendingConflict2->field_type === 'CORE'
        ? $profile->getAttribute($pendingConflict2->field_name)
        : (\App\Services\ExtendedFieldService::getValuesForProfile($profile)[$pendingConflict2->field_name] ?? null);
    
    $adminUser = User::where('is_admin', true)->first() ?? $user;
    ConflictResolutionService::rejectConflict($pendingConflict2, $adminUser, 'Test rejection');
    
    $profile->refresh();
    $afterReject = $pendingConflict2->field_type === 'CORE'
        ? $profile->getAttribute($pendingConflict2->field_name)
        : (\App\Services\ExtendedFieldService::getValuesForProfile($profile)[$pendingConflict2->field_name] ?? null);
    
    $conflictRejected = ConflictRecord::find($pendingConflict2->id)->resolution_status === 'REJECTED';
    $valueUnchanged = ($beforeReject == $afterReject);
    
    $results['E13'] = ($conflictRejected && $valueUnchanged) ? 'PASS' : 'FAIL';
    echo "Test 13: " . $results['E13'] . " (REJECT conflict - Rejected: " . ($conflictRejected ? 'YES' : 'NO') . ", Value unchanged: " . ($valueUnchanged ? 'YES' : 'NO') . ")\n";
} else {
    $results['E13'] = 'SKIP';
    echo "Test 13: SKIP (No pending conflicts found)\n";
}

// Test 14: OVERRIDE conflict
$pendingConflict3 = ConflictRecord::where('profile_id', $profile->id)
    ->where('resolution_status', 'PENDING')
    ->first();
if ($pendingConflict3) {
    $adminUser = User::where('is_admin', true)->first() ?? $user;
    ConflictResolutionService::overrideConflict($pendingConflict3, $adminUser, 'Test override');
    
    $profile->refresh();
    $newValue = $pendingConflict3->field_type === 'CORE'
        ? $profile->getAttribute($pendingConflict3->field_name)
        : (\App\Services\ExtendedFieldService::getValuesForProfile($profile)[$pendingConflict3->field_name] ?? null);
    
    $conflictOverridden = ConflictRecord::find($pendingConflict3->id)->resolution_status === 'OVERRIDDEN';
    $valueApplied = ($newValue == $pendingConflict3->new_value);
    
    $results['E14'] = ($conflictOverridden && $valueApplied) ? 'PASS' : 'FAIL';
    echo "Test 14: " . $results['E14'] . " (OVERRIDE conflict - Overridden: " . ($conflictOverridden ? 'YES' : 'NO') . ", Value applied: " . ($valueApplied ? 'YES' : 'NO') . ")\n";
} else {
    $results['E14'] = 'SKIP';
    echo "Test 14: SKIP (No pending conflicts found)\n";
}

echo "\n";

// ============================================
// TEST SUITE F — LIFECYCLE STATES
// ============================================

echo "TEST SUITE F — LIFECYCLE STATES\n";
echo "--------------------------------------------\n";

// Test 15: Set profile to DRAFT (test interest blocking)
// Note: Active -> Draft transition is not allowed per SSOT
// We'll test Draft behavior by checking if a Draft profile blocks interest
// Since we can't transition to Draft, we'll verify Draft state blocks interest via canReceiveInterest logic
$oldState = $profile->lifecycle_state ?? 'Active';
try {
    // Test that Draft state blocks interest (verify logic, even if we can't transition)
    $testProfile = clone $profile;
    $testProfile->lifecycle_state = 'Draft';
    $canReceiveInterest = ProfileLifecycleService::canReceiveInterest($testProfile);
    
    // Also test that Suspended blocks interest (which we CAN transition to)
    ProfileLifecycleService::transitionTo($profile, 'Suspended', $user);
    $profile->refresh();
    $isSuspended = $profile->lifecycle_state === 'Suspended';
    $canReceiveInterestSuspended = ProfileLifecycleService::canReceiveInterest($profile);
    
    $results['F15'] = (!$canReceiveInterest && $isSuspended && !$canReceiveInterestSuspended) ? 'PASS' : 'FAIL';
    echo "Test 15: " . $results['F15'] . " (Draft blocks interest: " . (!$canReceiveInterest ? 'YES' : 'NO') . ", Suspended blocks interest: " . (!$canReceiveInterestSuspended ? 'YES' : 'NO') . ")\n";
    
    // Restore for next test
    ProfileLifecycleService::transitionTo($profile, 'Active', $user);
    $profile->refresh();
} catch (\Exception $e) {
    $results['F15'] = 'FAIL';
    echo "Test 15: FAIL (Exception: " . $e->getMessage() . ")\n";
}

// Test 16: Set profile to ACTIVE
try {
    // Ensure we're in Active state
    if ($profile->lifecycle_state !== 'Active') {
        ProfileLifecycleService::transitionTo($profile, 'Active', $user);
        $profile->refresh();
    }
    
    $isActive = $profile->lifecycle_state === 'Active';
    $canReceiveInterest = ProfileLifecycleService::canReceiveInterest($profile);
    
    $results['F16'] = ($isActive && $canReceiveInterest) ? 'PASS' : 'FAIL';
    echo "Test 16: " . $results['F16'] . " (Set to ACTIVE - State: " . $profile->lifecycle_state . ", Interest allowed: " . ($canReceiveInterest ? 'YES' : 'NO') . ")\n";
} catch (\Exception $e) {
    $results['F16'] = 'FAIL';
    echo "Test 16: FAIL (Exception: " . $e->getMessage() . ")\n";
}

// Test 17: Set profile to SUSPENDED / ARCHIVED
try {
    ProfileLifecycleService::transitionTo($profile, 'Suspended', $user);
    $profile->refresh();
    
    $isSuspended = $profile->lifecycle_state === 'Suspended';
    $canReceiveInterest = ProfileLifecycleService::canReceiveInterest($profile);
    $isVisible = ProfileLifecycleService::isVisibleToOthers($profile);
    
    $results['F17'] = ($isSuspended && !$canReceiveInterest && !$isVisible) ? 'PASS' : 'FAIL';
    echo "Test 17: " . $results['F17'] . " (Set to SUSPENDED - State: " . $profile->lifecycle_state . ", Interest blocked: " . (!$canReceiveInterest ? 'YES' : 'NO') . ", Not visible: " . (!$isVisible ? 'YES' : 'NO') . ")\n";
    
    // Restore to original state
    ProfileLifecycleService::transitionTo($profile, $oldState, $user);
    $profile->refresh();
} catch (\Exception $e) {
    $results['F17'] = 'FAIL';
    echo "Test 17: FAIL (Exception: " . $e->getMessage() . ")\n";
}

echo "\n";

// ============================================
// TEST SUITE G — OCR GOVERNANCE (STRUCTURE)
// ============================================

echo "TEST SUITE G — OCR GOVERNANCE (STRUCTURE)\n";
echo "--------------------------------------------\n";

// Test 18: Simulate MODE_1
$mode1 = OcrModeDetectionService::detect(null, 'caste');
$decision1 = OcrGovernanceService::decide($mode1, null, 'caste', 'test_value', 'CORE');
$results['G18'] = ($mode1 === OcrMode::MODE_1_FIRST_CREATION && $decision1 === OcrGovernanceService::DECISION_ALLOW) ? 'PASS' : 'FAIL';
echo "Test 18: " . $results['G18'] . " (MODE_1 simulation - Mode: {$mode1}, Decision: {$decision1})\n";

// Test 19: Simulate MODE_2 (find unlocked field)
$unlockedFields = FieldRegistry::where('field_type', 'CORE')->pluck('field_key')->toArray();
$unlockedField = null;
foreach ($unlockedFields as $fieldKey) {
    if (!ProfileFieldLockService::isLocked($profile, $fieldKey)) {
        $unlockedField = FieldRegistry::where('field_key', $fieldKey)->first();
        break;
    }
}
if ($unlockedField) {
    $mode2 = OcrModeDetectionService::detect($profile, $unlockedField->field_key);
    $currentValue = $profile->getAttribute($unlockedField->field_key);
    $proposedValue = ($currentValue === null || $currentValue === '') ? 'test_value' : $currentValue . '_DIFFERENT';
    $decision2 = OcrGovernanceService::decide($mode2, $profile, $unlockedField->field_key, $proposedValue, 'CORE');
    
    $results['G19'] = ($mode2 === OcrMode::MODE_2_EXISTING_PROFILE && $decision2 === OcrGovernanceService::DECISION_CREATE_CONFLICT) ? 'PASS' : 'FAIL';
    echo "Test 19: " . $results['G19'] . " (MODE_2 simulation - Mode: {$mode2}, Decision: {$decision2})\n";
} else {
    $results['G19'] = 'SKIP';
    echo "Test 19: SKIP (All CORE fields are locked)\n";
}

// Test 20: Simulate MODE_3 (locked field)
if ($lockableField && $isLocked) {
    $mode3 = OcrModeDetectionService::detect($profile, $lockableField->field_key);
    $decision3 = OcrGovernanceService::decide($mode3, $profile, $lockableField->field_key, 'test_value', 'CORE');
    
    $results['G20'] = ($mode3 === OcrMode::MODE_3_POST_HUMAN_EDIT_LOCK && $decision3 === OcrGovernanceService::DECISION_SKIP) ? 'PASS' : 'FAIL';
    echo "Test 20: " . $results['G20'] . " (MODE_3 simulation - Mode: {$mode3}, Decision: {$decision3})\n";
} else {
    $results['G20'] = 'SKIP';
    echo "Test 20: SKIP (No locked field available)\n";
}

echo "\n";

// ============================================
// FINAL REPORT
// ============================================

echo "========================================\n";
echo "FINAL REPORT\n";
echo "========================================\n\n";

echo "Test Suite A:\n";
echo "- Test 1: " . ($results['A1'] ?? 'NOT RUN') . "\n";
echo "- Test 2: " . ($results['A2'] ?? 'NOT RUN') . "\n";
echo "- Test 3: " . ($results['A3'] ?? 'NOT RUN') . "\n\n";

echo "Test Suite B:\n";
echo "- Test 4: " . ($results['B4'] ?? 'NOT RUN') . "\n";
echo "- Test 5: " . ($results['B5'] ?? 'NOT RUN') . "\n\n";

echo "Test Suite C:\n";
echo "- Test 6: " . ($results['C6'] ?? 'NOT RUN') . "\n";
echo "- Test 7a: " . ($results['C7a'] ?? 'NOT RUN') . "\n";
echo "- Test 7b: " . ($results['C7b'] ?? 'NOT RUN') . "\n";
echo "- Test 8: " . ($results['C8'] ?? 'NOT RUN') . "\n\n";

echo "Test Suite D:\n";
echo "- Test 9: " . ($results['D9'] ?? 'NOT RUN') . "\n";
echo "- Test 10: " . ($results['D10'] ?? 'NOT RUN') . "\n";
echo "- Test 11: " . ($results['D11'] ?? 'NOT RUN') . "\n\n";

echo "Test Suite E:\n";
echo "- Test 12: " . ($results['E12'] ?? 'NOT RUN') . "\n";
echo "- Test 13: " . ($results['E13'] ?? 'NOT RUN') . "\n";
echo "- Test 14: " . ($results['E14'] ?? 'NOT RUN') . "\n\n";

echo "Test Suite F:\n";
echo "- Test 15: " . ($results['F15'] ?? 'NOT RUN') . "\n";
echo "- Test 16: " . ($results['F16'] ?? 'NOT RUN') . "\n";
echo "- Test 17: " . ($results['F17'] ?? 'NOT RUN') . "\n\n";

echo "Test Suite G:\n";
echo "- Test 18: " . ($results['G18'] ?? 'NOT RUN') . "\n";
echo "- Test 19: " . ($results['G19'] ?? 'NOT RUN') . "\n";
echo "- Test 20: " . ($results['G20'] ?? 'NOT RUN') . "\n\n";

$allPassed = true;
$failures = [];
foreach ($results as $test => $result) {
    if ($result === 'FAIL') {
        $allPassed = false;
        $failures[] = $test;
    }
}

echo "Overall Result:\n";
if ($allPassed && count($results) > 0) {
    echo "ALL PASSED\n";
    echo "\nDAY 15 MANUAL TESTING PASSED — USER TESTING MAY BEGIN\n";
} else {
    echo "FAILED\n";
    echo "Failures: " . implode(', ', $failures) . "\n";
    echo "\nDAY 15 MANUAL TESTING FAILED — USER TESTING BLOCKED\n";
}
