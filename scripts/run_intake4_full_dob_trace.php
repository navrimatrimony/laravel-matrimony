<?php

declare(strict_types=1);

use App\Http\Controllers\IntakeController;
use App\Jobs\ParseIntakeJob;
use App\Models\BiodataIntake;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$intakeId = 4;
$intake = BiodataIntake::find($intakeId);
if (! $intake) {
    fwrite(STDERR, "Intake {$intakeId} not found\n");
    exit(1);
}

$backup = [
    'approved_by_user' => $intake->approved_by_user,
    'approved_at' => $intake->approved_at,
    'parse_status' => $intake->parse_status,
];

// Allow ParseIntakeJob + preview for this diagnostic run only.
$intake->update([
    'approved_by_user' => false,
    'parse_status' => 'parsed',
]);

$intake->refresh();
$intake->update(['parse_status' => 'pending']);
(new ParseIntakeJob($intakeId, true))->handle();
$intake = BiodataIntake::find($intakeId);

$uid = (int) $intake->uploaded_by;
if ($uid <= 0) {
    fwrite(STDERR, "uploaded_by missing\n");
    exit(1);
}
Auth::loginUsingId($uid);
app(IntakeController::class)->preview($intake);

$intake = BiodataIntake::find($intakeId);
$core = is_array($intake->parsed_json['core'] ?? null) ? $intake->parsed_json['core'] : [];
$ap = is_array($intake->approval_snapshot_json['core'] ?? null) ? $intake->approval_snapshot_json['core'] : [];

echo "--- AFTER RUN ---\n";
echo 'parse_status='.json_encode($intake->parse_status)."\n";
echo 'parsed_json.core.date_of_birth='.json_encode($core['date_of_birth'] ?? null)."\n";
echo 'approval_snapshot_json.core.date_of_birth='.json_encode($ap['date_of_birth'] ?? 'KEY_ABSENT')."\n";

// Restore approval flags (do not wipe approval_snapshot_json).
$restore = [
    'approved_by_user' => (bool) $backup['approved_by_user'],
    'parse_status' => 'parsed',
];
if (! empty($backup['approved_at'])) {
    $restore['approved_at'] = $backup['approved_at'];
}
$intake->update($restore);

echo "restored approved_by_user=".json_encode($backup['approved_by_user'])."\n";
