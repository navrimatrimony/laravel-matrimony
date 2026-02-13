<?php

use App\Models\MatrimonyProfile;
use App\Models\ProfileFieldLock;
use App\Models\AdminAuditLog;
use App\Models\ConflictRecord;
use App\Services\ProfileLifecycleService;

require __DIR__ . "/vendor/autoload.php";
$app = require_once __DIR__ . "/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "================ TEST A : CONFLICT ACTIVATION ================\n";

$profile = MatrimonyProfile::first();

if (!$profile) {
    echo "No profile found.\n";
    exit;
}

echo "Profile ID: {$profile->id}\n";
echo "Current lifecycle_state: " . ($profile->lifecycle_state ?? "NULL") . "\n";

try {
    ProfileLifecycleService::transition($profile, "INVALID_STATE", null);
    echo "Transition executed.\n";
} catch (Throwable $e) {
    echo "Exception caught: " . $e->getMessage() . "\n";
}

echo "Conflict records count: " . ConflictRecord::count() . "\n";

echo "\n================ TEST B : FULL GOVERNANCE REGRESSION ================\n";

try {
    ProfileLifecycleService::transition($profile, "Suspended", null);
    echo "Lifecycle changed to Suspended\n";
} catch (Throwable $e) {
    echo "Lifecycle Exception: " . $e->getMessage() . "\n";
}

echo "Current lifecycle_state: " . $profile->fresh()->lifecycle_state . "\n";

echo "--- Applying Field Lock ---\n";

ProfileFieldLock::create([
    "profile_id" => $profile->id,
    "field_name" => "education",
    "locked_by" => 1,
]);

echo "Field locks count: " . ProfileFieldLock::where("profile_id", $profile->id)->count() . "\n";

echo "--- Audit Log Count ---\n";
echo "AdminAuditLog count: " . AdminAuditLog::count() . "\n";
