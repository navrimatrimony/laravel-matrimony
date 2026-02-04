<?php

/**
 * Verification test for NULL value guard in ConflictResolutionService
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Models\ConflictRecord;
use App\Services\ConflictResolutionService;
use Illuminate\Validation\ValidationException;

echo "========================================\n";
echo "NULL VALUE GUARD VERIFICATION\n";
echo "========================================\n\n";

$user = User::first();
$profile = MatrimonyProfile::where('user_id', $user->id)->first();

if (!$profile) {
    echo "ERROR: No profile found.\n";
    exit(1);
}

$adminUser = User::where('is_admin', true)->first() ?? $user;

// Set initial value
$profile->update(['education' => 'BE']);
$profile->refresh();
$initialValue = $profile->education;
echo "Initial education value: " . ($initialValue ?? 'NULL') . "\n\n";

// Test 1: Approving conflict with NULL new_value
echo "Test 1: Approving conflict with NULL new_value\n";
echo "--------------------------------------------\n";
ConflictRecord::where('profile_id', $profile->id)->where('field_name', 'education')->delete();
$conflict1 = ConflictRecord::create([
    'profile_id' => $profile->id,
    'field_name' => 'education',
    'field_type' => 'CORE',
    'old_value' => $initialValue,
    'new_value' => null,
    'source' => 'SYSTEM',
    'detected_at' => now(),
    'resolution_status' => 'PENDING',
]);

try {
    ConflictResolutionService::approveConflict($conflict1, $adminUser, 'Test approval');
    echo "❌ FAIL: Exception should have been thrown\n";
    $profile->refresh();
    echo "Profile value after approval: " . ($profile->education ?? 'NULL') . "\n";
} catch (ValidationException $e) {
    echo "✅ PASS: Exception thrown as expected\n";
    echo "Message: " . $e->getMessage() . "\n";
    $profile->refresh();
    echo "Profile value preserved: " . ($profile->education ?? 'NULL') . "\n";
    echo "Data preserved: " . (($profile->education === $initialValue) ? 'YES' : 'NO') . "\n";
}
echo "\n";

// Test 2: Approving conflict with empty string new_value
echo "Test 2: Approving conflict with empty string new_value\n";
echo "--------------------------------------------\n";
ConflictRecord::where('profile_id', $profile->id)->where('field_name', 'education')->delete();
$conflict2 = ConflictRecord::create([
    'profile_id' => $profile->id,
    'field_name' => 'education',
    'field_type' => 'CORE',
    'old_value' => $initialValue,
    'new_value' => '',
    'source' => 'SYSTEM',
    'detected_at' => now(),
    'resolution_status' => 'PENDING',
]);

try {
    ConflictResolutionService::approveConflict($conflict2, $adminUser, 'Test approval');
    echo "❌ FAIL: Exception should have been thrown\n";
    $profile->refresh();
    echo "Profile value after approval: " . ($profile->education ?? 'NULL') . "\n";
} catch (ValidationException $e) {
    echo "✅ PASS: Exception thrown as expected\n";
    $profile->refresh();
    echo "Profile value preserved: " . ($profile->education ?? 'NULL') . "\n";
    echo "Data preserved: " . (($profile->education === $initialValue) ? 'YES' : 'NO') . "\n";
}
echo "\n";

// Test 3: Approving conflict with whitespace-only new_value
echo "Test 3: Approving conflict with whitespace-only new_value\n";
echo "--------------------------------------------\n";
ConflictRecord::where('profile_id', $profile->id)->where('field_name', 'education')->delete();
$conflict3 = ConflictRecord::create([
    'profile_id' => $profile->id,
    'field_name' => 'education',
    'field_type' => 'CORE',
    'old_value' => $initialValue,
    'new_value' => '   ',
    'source' => 'SYSTEM',
    'detected_at' => now(),
    'resolution_status' => 'PENDING',
]);

try {
    ConflictResolutionService::approveConflict($conflict3, $adminUser, 'Test approval');
    echo "❌ FAIL: Exception should have been thrown\n";
    $profile->refresh();
    echo "Profile value after approval: " . ($profile->education ?? 'NULL') . "\n";
} catch (ValidationException $e) {
    echo "✅ PASS: Exception thrown as expected\n";
    $profile->refresh();
    echo "Profile value preserved: " . ($profile->education ?? 'NULL') . "\n";
    echo "Data preserved: " . (($profile->education === $initialValue) ? 'YES' : 'NO') . "\n";
}
echo "\n";

// Test 4: Approving conflict with valid new_value (should work)
echo "Test 4: Approving conflict with valid new_value\n";
echo "--------------------------------------------\n";
ConflictRecord::where('profile_id', $profile->id)->where('field_name', 'education')->delete();
$conflict4 = ConflictRecord::create([
    'profile_id' => $profile->id,
    'field_name' => 'education',
    'field_type' => 'CORE',
    'old_value' => $initialValue,
    'new_value' => 'ME',
    'source' => 'SYSTEM',
    'detected_at' => now(),
    'resolution_status' => 'PENDING',
]);

try {
    ConflictResolutionService::approveConflict($conflict4, $adminUser, 'Test approval');
    echo "✅ PASS: Approval succeeded\n";
    $profile->refresh();
    echo "Profile value after approval: " . ($profile->education ?? 'NULL') . "\n";
    echo "Value updated correctly: " . (($profile->education === 'ME') ? 'YES' : 'NO') . "\n";
} catch (\Exception $e) {
    echo "❌ FAIL: Unexpected exception: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 5: Reject conflict (should work regardless of new_value)
echo "Test 5: Reject conflict with NULL new_value\n";
echo "--------------------------------------------\n";
$profile->update(['education' => $initialValue]);
$profile->refresh();
ConflictRecord::where('profile_id', $profile->id)->where('field_name', 'education')->delete();
$conflict5 = ConflictRecord::create([
    'profile_id' => $profile->id,
    'field_name' => 'education',
    'field_type' => 'CORE',
    'old_value' => $initialValue,
    'new_value' => null,
    'source' => 'SYSTEM',
    'detected_at' => now(),
    'resolution_status' => 'PENDING',
]);

try {
    ConflictResolutionService::rejectConflict($conflict5, $adminUser, 'Test rejection');
    echo "✅ PASS: Rejection succeeded\n";
    $profile->refresh();
    echo "Profile value after rejection: " . ($profile->education ?? 'NULL') . "\n";
    echo "Data preserved: " . (($profile->education === $initialValue) ? 'YES' : 'NO') . "\n";
} catch (\Exception $e) {
    echo "❌ FAIL: Unexpected exception: " . $e->getMessage() . "\n";
}
echo "\n";

echo "========================================\n";
echo "VERIFICATION COMPLETE\n";
echo "========================================\n";
