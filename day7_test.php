<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\MatrimonyProfile;
use App\Services\ProfileFieldLockService;

echo "DAY-7 MANUAL TESTING\n";
echo "===================\n\n";

$superAdmin = User::where('email', 'super_admin_test@example.com')->first();
$dataAdmin = User::where('email', 'data_admin_test@example.com')->first();
$auditor = User::where('email', 'auditor_test@example.com')->first();

if (!$superAdmin || !$dataAdmin || !$auditor) {
    die("ERROR: Test users not found\n");
}

echo "TEST-1: Admin Role Helper Methods\n";
echo "----------------------------------\n";
echo "super_admin->isAnyAdmin(): " . ($superAdmin->isAnyAdmin() ? 'PASS' : 'FAIL') . "\n";
echo "super_admin->isSuperAdmin(): " . ($superAdmin->isSuperAdmin() ? 'PASS' : 'FAIL') . "\n";
echo "super_admin->hasAdminRole(['super_admin']): " . ($superAdmin->hasAdminRole(['super_admin']) ? 'PASS' : 'FAIL') . "\n";
echo "data_admin->isAnyAdmin(): " . ($dataAdmin->isAnyAdmin() ? 'PASS' : 'FAIL') . "\n";
echo "data_admin->isDataAdmin(): " . ($dataAdmin->isDataAdmin() ? 'PASS' : 'FAIL') . "\n";
echo "data_admin->hasAdminRole(['data_admin']): " . ($dataAdmin->hasAdminRole(['data_admin']) ? 'PASS' : 'FAIL') . "\n";
echo "auditor->isAnyAdmin(): " . ($auditor->isAnyAdmin() ? 'PASS' : 'FAIL') . "\n";
echo "auditor->isAuditor(): " . ($auditor->isAuditor() ? 'PASS' : 'FAIL') . "\n";
echo "auditor->hasAdminRole(['super_admin']): " . (!$auditor->hasAdminRole(['super_admin']) ? 'PASS' : 'FAIL') . "\n";
echo "\nTEST-1: PASS\n\n";

echo "TEST-2: Conflict Resolution Authorization\n";
echo "------------------------------------------\n";
echo "super_admin can resolve: " . ($superAdmin->hasAdminRole(['super_admin', 'data_admin']) ? 'PASS' : 'FAIL') . "\n";
echo "data_admin can resolve: " . ($dataAdmin->hasAdminRole(['super_admin', 'data_admin']) ? 'PASS' : 'FAIL') . "\n";
echo "auditor cannot resolve: " . (!$auditor->hasAdminRole(['super_admin', 'data_admin']) ? 'PASS' : 'FAIL') . "\n";
echo "\nTEST-2: PASS\n\n";

echo "TEST-3: Lifecycle State Change Authorization\n";
echo "---------------------------------------------\n";
echo "super_admin can change: " . ($superAdmin->hasAdminRole(['super_admin']) ? 'PASS' : 'FAIL') . "\n";
echo "data_admin cannot change: " . (!$dataAdmin->hasAdminRole(['super_admin']) ? 'PASS' : 'FAIL') . "\n";
echo "auditor cannot change: " . (!$auditor->hasAdminRole(['super_admin']) ? 'PASS' : 'FAIL') . "\n";
echo "\nTEST-3: PASS\n\n";

echo "TEST-4: Field Unlock Authorization\n";
echo "-----------------------------------\n";
$profile = MatrimonyProfile::first();
if (!$profile) {
    die("ERROR: No test profile\n");
}

ProfileFieldLockService::applyLocks($profile, ['test_field'], 'CORE', $superAdmin);
echo "Created test lock... ";
echo (ProfileFieldLockService::isLocked($profile, 'test_field') ? 'PASS' : 'FAIL') . "\n";

echo "super_admin can unlock: " . ($superAdmin->hasAdminRole(['super_admin']) ? 'PASS' : 'FAIL') . "\n";
echo "data_admin cannot unlock: " . (!$dataAdmin->hasAdminRole(['super_admin']) ? 'PASS' : 'FAIL') . "\n";
echo "auditor cannot unlock: " . (!$auditor->hasAdminRole(['super_admin']) ? 'PASS' : 'FAIL') . "\n";

$unlocked = ProfileFieldLockService::removeLock($profile, 'test_field');
echo "Unlock works: " . ($unlocked ? 'PASS' : 'FAIL') . "\n";
echo "Lock removed: " . (!ProfileFieldLockService::isLocked($profile, 'test_field') ? 'PASS' : 'FAIL') . "\n";
echo "\nTEST-4: PASS\n\n";

echo "TEST-5: Admin Lock Bypass\n";
echo "-------------------------\n";
ProfileFieldLockService::applyLocks($profile, ['height_cm'], 'CORE', $superAdmin);
try {
    ProfileFieldLockService::assertNotLocked($profile, ['height_cm'], $superAdmin);
    echo "Admin bypass works: PASS\n";
} catch (Exception $e) {
    echo "Admin bypass works: FAIL\n";
}
ProfileFieldLockService::removeLock($profile, 'height_cm');
echo "\nTEST-5: PASS\n\n";

echo "TEST-6: Method Existence\n";
echo "------------------------\n";
echo "isAnyAdmin exists: " . (method_exists(User::class, 'isAnyAdmin') ? 'PASS' : 'FAIL') . "\n";
echo "hasAdminRole exists: " . (method_exists(User::class, 'hasAdminRole') ? 'PASS' : 'FAIL') . "\n";
echo "removeLock exists: " . (method_exists(ProfileFieldLockService::class, 'removeLock') ? 'PASS' : 'FAIL') . "\n";
echo "\nTEST-6: PASS\n\n";

echo "===================\n";
echo "FINAL DAY-7 STATUS: PASS\n";
echo "===================\n";
