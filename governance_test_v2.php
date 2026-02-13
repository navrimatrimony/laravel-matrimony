<?php

use App\Models\MatrimonyProfile;
use App\Models\ProfileFieldLock;
use App\Models\AdminAuditLog;
use App\Models\ConflictRecord;
use App\Models\User;
use App\Services\ProfileLifecycleService;

require __DIR__ . "/vendor/autoload.php";
$app = require_once __DIR__ . "/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "================ TEST A : LIFECYCLE VALIDATION ================\n";

$profile = MatrimonyProfile::first();
$actor   = User::first();

if (!$profile || !$actor) {
    echo "Profile or User not found.\n";
    exit;
}

echo "Profile ID: {$profile->id}\n";
echo "Current lifecycle_state: " . ($profile->lifecycle_state ?? "NULL") . "\n";

try {
    ProfileLifecycleService::transitionTo($profile, "INVALID_STATE", $actor);
    echo "Transition executed.\n";
} catch (Throwable $e) {
    echo "Exception caught: " . $e->getMessage() . "\n";
}

echo "Conflict records count: " . ConflictRecord::count() . "\n";

echo "\n================ TEST B : FIELD LOCK CORRECT INSERT ================\n";

try {
    ProfileFieldLock::create([
        "profile_id" => $profile->id,
        "field_key"  => "education",
        "field_type" => "CORE",
        "locked_by"  => $actor->id,
        "locked_at"  => now(),
    ]);
    echo "Field lock inserted.\n";
} catch (Throwable $e) {
    echo "Field lock exception: " . $e->getMessage() . "\n";
}

echo "Field locks count: " . ProfileFieldLock::where("profile_id", $profile->id)->count() . "\n";

echo "\n================ TEST C : AUDIT IMMUTABILITY ================\n";

$log = AdminAuditLog::first();

if ($log) {
    try {
        $log->update(["reason" => "Test update"]);
    } catch (Throwable $e) {
        echo "Audit immutability working: " . $e->getMessage() . "\n";
    }
} else {
    echo "No audit log found.\n";
}
