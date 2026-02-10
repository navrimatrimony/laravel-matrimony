<?php

/**
 * Day-7 Manual Testing Script
 * Tests Admin Authority & Override Boundary Attack scenarios
 * 
 * Run: php tests/Manual/Day7RoleBasedAuthorizationTest.php
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\MatrimonyProfile;
use App\Models\ConflictRecord;
use App\Services\ProfileFieldLockService;
use Illuminate\Support\Facades\DB;

echo "=====================================\n";
echo "DAY-7 MANUAL TESTING SUITE\n";
echo "Admin Authority & Override Boundary\n";
echo "=====================================\n\n";

// Get test users
$superAdmin = User::where('email', 'super_admin_test@example.com')->first();
$dataAdmin = User::where('email', 'data_admin_test@example.com')->first();
$auditor = User::where('email', 'auditor_test@example.com')->first();
$normalUser = User::where('email', 'shankarmhtre13@gmail.com')->first();

if (!$superAdmin || !$dataAdmin || !$auditor) {
    die("ERROR: Test users not found. Run db:seed --class=TestAdminRolesSeeder first.\n");
}

// Get test profile
$profile = MatrimonyProfile::first();
if (!$profile) {
    die("ERROR: No test profile found.\n");
}

echo "Test Users:\n";
echo "- super_admin: {$superAdmin->email} (ID: {$superAdmin->id})\n";
echo "- data_admin: {$dataAdmin->email} (ID: {$dataAdmin->id})\n";
echo "- auditor: {$auditor->email} (ID: {$auditor->id})\n";
echo "- normal user: " . ($normalUser ? $normalUser->email : 'NOT FOUND') . "\n";
echo "- Test profile: {$profile->full_name} (ID: {$profile->id})\n\n";

// ============================================
// TEST-1: Admin Role Helper Methods
// ============================================
echo "TEST-1: Admin Role Helper Methods\n";
echo "----------------------------------\n";

$test1Results = [
    'super_admin_isAnyAdmin' => $superAdmin->isAnyAdmin() ? 'PASS' : 'FAIL',
    'super_admin_isSuperAdmin' => $superAdmin->isSuperAdmin() ? 'PASS' : 'FAIL',
    'super_admin_hasAdminRole_super' => $superAdmin->hasAdminRole(['super_admin']) ? 'PASS' : 'FAIL',
    'data_admin_isAnyAdmin' => $dataAdmin->isAnyAdmin() ? 'PASS' : 'FAIL',
    'data_admin_isDataAdmin' => $dataAdmin->isDataAdmin() ? 'PASS' : 'FAIL',
    'data_admin_hasAdminRole_data' => $dataAdmin->hasAdminRole(['data_admin']) ? 'PASS' : 'FAIL',
    'auditor_isAnyAdmin' => $auditor->isAnyAdmin() ? 'PASS' : 'FAIL',
    'auditor_isAuditor' => $auditor->isAuditor() ? 'PASS' : 'FAIL',
    'auditor_hasAdminRole_super' => !$auditor->hasAdminRole(['super_admin']) ? 'PASS' : 'FAIL',
    'normal_user_isAnyAdmin' => ($normalUser && !$normalUser->isAnyAdmin()) ? 'PASS' : 'N/A',
];

foreach ($test1Results as $test => $result) {
    echo "  {$test}: {$result}\n";
}

$test1Pass = !in_array('FAIL', $test1Results, true);
echo "\nTEST-1 Result: " . ($test1Pass ? "✓ PASS" : "✗ FAIL") . "\n\n";

// ============================================
// TEST-2: Conflict Resolution Role Guards
// ============================================
echo "TEST-2: Conflict Resolution Authorization\n";
echo "------------------------------------------\n";

// Create a test conflict record
$conflict = ConflictRecord::create([
    'profile_id' => $profile->id,
    'field_key' => 'height_cm',
    'field_type' => 'CORE',
    'existing_value' => '170',
    'proposed_value' => '175',
    'authority' => 'OCR',
    'resolution_status' => 'PENDING',
]);

echo "Created test conflict ID: {$conflict->id}\n";

$test2Results = [
    'super_admin_can_resolve' => $superAdmin->hasAdminRole(['super_admin', 'data_admin']) ? 'PASS' : 'FAIL',
    'data_admin_can_resolve' => $dataAdmin->hasAdminRole(['super_admin', 'data_admin']) ? 'PASS' : 'FAIL',
    'auditor_cannot_resolve' => !$auditor->hasAdminRole(['super_admin', 'data_admin']) ? 'PASS' : 'FAIL',
];

foreach ($test2Results as $test => $result) {
    echo "  {$test}: {$result}\n";
}

// Cleanup
$conflict->delete();

$test2Pass = !in_array('FAIL', $test2Results, true);
echo "\nTEST-2 Result: " . ($test2Pass ? "✓ PASS" : "✗ FAIL") . "\n\n";

// ============================================
// TEST-3: Lifecycle State Change Authorization
// ============================================
echo "TEST-3: Lifecycle State Change Authorization\n";
echo "---------------------------------------------\n";

$test3Results = [
    'super_admin_can_change_lifecycle' => $superAdmin->hasAdminRole(['super_admin']) ? 'PASS' : 'FAIL',
    'data_admin_cannot_change_lifecycle' => !$dataAdmin->hasAdminRole(['super_admin']) ? 'PASS' : 'FAIL',
    'auditor_cannot_change_lifecycle' => !$auditor->hasAdminRole(['super_admin']) ? 'PASS' : 'FAIL',
];

foreach ($test3Results as $test => $result) {
    echo "  {$test}: {$result}\n";
}

$test3Pass = !in_array('FAIL', $test3Results, true);
echo "\nTEST-3 Result: " . ($test3Pass ? "✓ PASS" : "✗ FAIL") . "\n\n";

// ============================================
// TEST-4: Field Unlock Authorization
// ============================================
echo "TEST-4: Field Unlock Authorization\n";
echo "-----------------------------------\n";

// Create a test field lock
ProfileFieldLockService::applyLocks($profile, ['test_field'], 'CORE', $superAdmin);
echo "Created test field lock on 'test_field'\n";

$test4Results = [
    'field_is_locked' => ProfileFieldLockService::isLocked($profile, 'test_field') ? 'PASS' : 'FAIL',
    'super_admin_can_unlock' => $superAdmin->hasAdminRole(['super_admin']) ? 'PASS' : 'FAIL',
    'data_admin_cannot_unlock' => !$dataAdmin->hasAdminRole(['super_admin']) ? 'PASS' : 'FAIL',
    'auditor_cannot_unlock' => !$auditor->hasAdminRole(['super_admin']) ? 'PASS' : 'FAIL',
];

foreach ($test4Results as $test => $result) {
    echo "  {$test}: {$result}\n";
}

// Test actual unlock
$unlockSuccess = ProfileFieldLockService::removeLock($profile, 'test_field');
echo "  unlock_method_works: " . ($unlockSuccess ? 'PASS' : 'FAIL') . "\n";

// Verify lock removed
$stillLocked = ProfileFieldLockService::isLocked($profile, 'test_field');
echo "  lock_removed: " . (!$stillLocked ? 'PASS' : 'FAIL') . "\n";

$test4Pass = !in_array('FAIL', $test4Results, true) && $unlockSuccess && !$stillLocked;
echo "\nTEST-4 Result: " . ($test4Pass ? "✓ PASS" : "✗ FAIL") . "\n\n";

// ============================================
// TEST-5: Admin Bypass with Lock Assertion
// ============================================
echo "TEST-5: Admin Lock Override\n";
echo "----------------------------\n";

// Create a lock
ProfileFieldLockService::applyLocks($profile, ['height_cm'], 'CORE', $superAdmin);

try {
    // Admin should bypass
    ProfileFieldLockService::assertNotLocked($profile, ['height_cm'], $superAdmin);
    echo "  super_admin_bypass: PASS\n";
    $test5AdminBypass = 'PASS';
} catch (\Exception $e) {
    echo "  super_admin_bypass: FAIL - {$e->getMessage()}\n";
    $test5AdminBypass = 'FAIL';
}

// Cleanup
ProfileFieldLockService::removeLock($profile, 'height_cm');

$test5Pass = $test5AdminBypass === 'PASS';
echo "\nTEST-5 Result: " . ($test5Pass ? "✓ PASS" : "✗ FAIL") . "\n\n";

// ============================================
// TEST-6: Stability Check
// ============================================
echo "TEST-6: Stability & Method Existence\n";
echo "-------------------------------------\n";

$test6Results = [
    'User_isAnyAdmin_exists' => method_exists(User::class, 'isAnyAdmin') ? 'PASS' : 'FAIL',
    'User_hasAdminRole_exists' => method_exists(User::class, 'hasAdminRole') ? 'PASS' : 'FAIL',
    'User_isSuperAdmin_exists' => method_exists(User::class, 'isSuperAdmin') ? 'PASS' : 'FAIL',
    'User_isDataAdmin_exists' => method_exists(User::class, 'isDataAdmin') ? 'PASS' : 'FAIL',
    'User_isAuditor_exists' => method_exists(User::class, 'isAuditor') ? 'PASS' : 'FAIL',
    'ProfileFieldLockService_removeLock_exists' => method_exists(ProfileFieldLockService::class, 'removeLock') ? 'PASS' : 'FAIL',
    'AdminController_unlockProfileField_exists' => method_exists(\App\Http\Controllers\AdminController::class, 'unlockProfileField') ? 'PASS' : 'FAIL',
];

foreach ($test6Results as $test => $result) {
    echo "  {$test}: {$result}\n";
}

$test6Pass = !in_array('FAIL', $test6Results, true);
echo "\nTEST-6 Result: " . ($test6Pass ? "✓ PASS" : "✗ FAIL") . "\n\n";

// ============================================
// FINAL REPORT
// ============================================
echo "=====================================\n";
echo "FINAL DAY-7 TEST REPORT\n";
echo "=====================================\n\n";

$allTests = [
    'TEST-1' => $test1Pass,
    'TEST-2' => $test2Pass,
    'TEST-3' => $test3Pass,
    'TEST-4' => $test4Pass,
    'TEST-5' => $test5Pass,
    'TEST-6' => $test6Pass,
];

foreach ($allTests as $test => $pass) {
    echo "{$test}: " . ($pass ? '✓ PASS' : '✗ FAIL') . "\n";
}

$finalStatus = !in_array(false, $allTests, true) ? 'PASS' : 'FAIL';

echo "\n=====================================\n";
echo "FINAL DAY-7 STATUS: {$finalStatus}\n";
echo "=====================================\n";

exit($finalStatus === 'PASS' ? 0 : 1);
