<?php

/**
 * Phase-3 Day-15: Empty Field Conflict Approval Test
 * Tests whether approving a conflict with empty/null new_value deletes existing profile data
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Models\ConflictRecord;
use App\Services\ConflictDetectionService;
use App\Services\ConflictResolutionService;
use Illuminate\Support\Facades\DB;

echo "========================================\n";
echo "TEST CASE: Empty Field Conflict Approval\n";
echo "========================================\n\n";

// ============================================
// TEST SETUP
// ============================================

// Step 1-3: Create/Get profile with initial values
$user = User::first();
if (!$user) {
    echo "ERROR: No user found. Cannot run test.\n";
    exit(1);
}

// Create a fresh profile or use existing
$profile = MatrimonyProfile::where('user_id', $user->id)->first();
if (!$profile) {
    $profile = MatrimonyProfile::create([
        'user_id' => $user->id,
        'full_name' => 'Test Profile',
        'gender' => 'Male',
        'date_of_birth' => '1990-01-01',
        'marital_status' => 'Single',
        'caste' => 'Maratha',
        'education' => 'BE',
        'location' => 'Pune',
    ]);
} else {
    // Set initial values
    $profile->update([
        'caste' => 'Maratha',
        'education' => 'BE',
    ]);
}

$profile->refresh();

echo "Initial Profile State:\n";
echo "- caste = " . ($profile->caste ?? 'NULL') . "\n";
echo "- education = " . ($profile->education ?? 'NULL') . "\n\n";

$initialCaste = $profile->caste;
$initialEducation = $profile->education;

// ============================================
// TEST STEPS 4-7: Conflict Detection
// ============================================

echo "Running Conflict Detection...\n";
echo "- Proposed: caste = 'Brahmin'\n";
echo "- Proposed: education = (empty/untouched)\n\n";

// Clear any existing conflicts for this profile
ConflictRecord::where('profile_id', $profile->id)
    ->whereIn('field_name', ['caste', 'education'])
    ->delete();

// Simulate conflict detection:
// - caste changed to "Brahmin"
// - education left empty (not provided)
$proposedCore = [
    'caste' => 'Brahmin',
    // education is NOT in the array (simulating empty/untouched)
];

$conflicts = ConflictDetectionService::detect($profile, $proposedCore, []);

echo "Conflicts Created (from detection):\n";
$conflictRecords = ConflictRecord::where('profile_id', $profile->id)
    ->whereIn('field_name', ['caste', 'education'])
    ->where('resolution_status', 'PENDING')
    ->get();

foreach ($conflictRecords as $conflict) {
    echo "- Field: {$conflict->field_name} | old_value = " . ($conflict->old_value ?? 'NULL') . " | new_value = " . ($conflict->new_value ?? 'NULL') . "\n";
}

// MANUALLY CREATE education conflict with empty new_value to test approval behavior
if (!ConflictRecord::where('profile_id', $profile->id)->where('field_name', 'education')->where('resolution_status', 'PENDING')->exists()) {
    echo "\nManually creating EDUCATION conflict with empty new_value to test approval behavior...\n";
    $educationConflict = ConflictRecord::create([
        'profile_id' => $profile->id,
        'field_name' => 'education',
        'field_type' => 'CORE',
        'old_value' => $initialEducation,
        'new_value' => null, // Empty/null value
        'source' => 'SYSTEM',
        'detected_at' => now(),
        'resolution_status' => 'PENDING',
    ]);
    echo "- Created conflict: old_value = " . ($educationConflict->old_value ?? 'NULL') . " | new_value = " . ($educationConflict->new_value ?? 'NULL') . "\n";
}

echo "\n";

// Refresh conflict records
$conflictRecords = ConflictRecord::where('profile_id', $profile->id)
    ->whereIn('field_name', ['caste', 'education'])
    ->where('resolution_status', 'PENDING')
    ->get();

// ============================================
// TEST STEP 9-10: Approve EDUCATION Conflict
// ============================================

$educationConflict = ConflictRecord::where('profile_id', $profile->id)
    ->where('field_name', 'education')
    ->where('resolution_status', 'PENDING')
    ->first();

if ($educationConflict) {
    echo "Approving EDUCATION conflict...\n";
    echo "- old_value = " . ($educationConflict->old_value ?? 'NULL') . "\n";
    echo "- new_value = " . ($educationConflict->new_value ?? 'NULL') . "\n\n";
    
    $adminUser = User::where('is_admin', true)->first() ?? $user;
    ConflictResolutionService::approveConflict($educationConflict, $adminUser, 'Test approval of empty education conflict');
    
    $profile->refresh();
    
    echo "After Approving EDUCATION Conflict:\n";
    echo "- education value = " . ($profile->education ?? 'NULL') . "\n";
    echo "- Was old value preserved? " . (($profile->education === $initialEducation) ? 'YES' : 'NO') . "\n";
    echo "- Initial value was: " . ($initialEducation ?? 'NULL') . "\n";
    $dataLoss = ($initialEducation !== null && $profile->education === null);
    echo "- Data deleted (value became NULL)? " . ($dataLoss ? 'YES' : 'NO') . "\n\n";
} else {
    echo "No EDUCATION conflict found to approve.\n\n";
}

// ============================================
// TEST STEP 11-12: Approve CASTE Conflict
// ============================================

$casteConflict = ConflictRecord::where('profile_id', $profile->id)
    ->where('field_name', 'caste')
    ->where('resolution_status', 'PENDING')
    ->first();

if ($casteConflict) {
    echo "Approving CASTE conflict...\n";
    echo "- old_value = " . ($casteConflict->old_value ?? 'NULL') . "\n";
    echo "- new_value = " . ($casteConflict->new_value ?? 'NULL') . "\n\n";
    
    $adminUser = User::where('is_admin', true)->first() ?? $user;
    ConflictResolutionService::approveConflict($casteConflict, $adminUser, 'Test approval of caste conflict');
    
    $profile->refresh();
    
    echo "After Approving CASTE Conflict:\n";
    echo "- caste value = " . ($profile->caste ?? 'NULL') . "\n\n";
} else {
    echo "No CASTE conflict found to approve.\n\n";
}

// ============================================
// FINAL REPORT
// ============================================

echo "========================================\n";
echo "FACT REPORT\n";
echo "========================================\n\n";

echo "Initial Profile State:\n";
echo "- caste = " . ($initialCaste ?? 'NULL') . "\n";
echo "- education = " . ($initialEducation ?? 'NULL') . "\n\n";

echo "Conflicts Created:\n";
$allConflicts = ConflictRecord::where('profile_id', $profile->id)
    ->whereIn('field_name', ['caste', 'education'])
    ->get();
foreach ($allConflicts as $conflict) {
    echo "- Field: {$conflict->field_name} | old_value = " . ($conflict->old_value ?? 'NULL') . " | new_value = " . ($conflict->new_value ?? 'NULL') . " | status = {$conflict->resolution_status}\n";
}

echo "\nAfter Approving EDUCATION Conflict:\n";
$finalEducation = $profile->education ?? null;
echo "- education value = " . ($finalEducation ?? 'NULL') . "\n";
$educationPreserved = ($finalEducation === $initialEducation && $initialEducation !== null);
echo "- Was old value preserved? " . ($educationPreserved ? 'YES' : 'NO') . "\n";
$dataLoss = ($initialEducation !== null && $finalEducation === null);
echo "- Data deleted (value became NULL)? " . ($dataLoss ? 'YES' : 'NO') . "\n";

echo "\nAfter Approving CASTE Conflict:\n";
$finalCaste = $profile->caste ?? null;
echo "- caste value = " . ($finalCaste ?? 'NULL') . "\n";

echo "\nInterpretation (FACTUAL ONLY):\n";
echo "- Approving conflict with empty new_value causes profile data deletion: " . ($dataLoss ? 'YES' : 'NO') . "\n";

echo "\n";

if ($dataLoss) {
    echo "BEHAVIOR CONFIRMED — DATA LOSS BUG PRESENT\n";
} else {
    echo "BEHAVIOR CONFIRMED — EXPECTED BY CURRENT IMPLEMENTATION\n";
}
