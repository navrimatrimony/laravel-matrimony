<?php

declare(strict_types=1);

use App\Jobs\ParseIntakeJob;
use App\Models\BiodataIntake;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$intakeId = (int) ($argv[1] ?? 4);
$intake = BiodataIntake::find($intakeId);
if (! $intake) {
    fwrite(STDERR, "Intake {$intakeId} not found\n");
    exit(1);
}

$runParse = ($argv[2] ?? '') === '--parse';
if ($runParse) {
    $intake->update(['parse_status' => 'pending']);
    (new ParseIntakeJob($intakeId, true))->handle();
    $intake->refresh();
}

$core = is_array($intake->parsed_json['core'] ?? null) ? $intake->parsed_json['core'] : [];
$apCore = is_array($intake->approval_snapshot_json['core'] ?? null) ? $intake->approval_snapshot_json['core'] : [];

echo "intake_id={$intakeId}\n";
echo 'parsed_json.core.date_of_birth='.json_encode($core['date_of_birth'] ?? null)."\n";
echo 'approval_snapshot_json.core.date_of_birth='.json_encode($apCore['date_of_birth'] ?? 'KEY_ABSENT')."\n";
echo 'parse_status='.json_encode($intake->parse_status)."\n";
$raw = (string) ($intake->raw_ocr_text ?? '');
echo 'raw_has_prajakta='.(mb_strpos($raw, 'प्राजक्ता') !== false ? 'yes' : 'no')."\n";
echo 'raw_has_janma_taarikh='.(preg_match('/जन्म\s*तारीख/u', $raw) === 1 ? 'yes' : 'no')."\n";

if (($argv[2] ?? '') === '--preview') {
    $uid = (int) ($intake->uploaded_by ?? 0);
    if ($uid <= 0) {
        fwrite(STDERR, "No uploaded_by on intake\n");
        exit(1);
    }
    \Illuminate\Support\Facades\Auth::loginUsingId($uid);
    /** @var \App\Http\Controllers\IntakeController $ctrl */
    $ctrl = app(\App\Http\Controllers\IntakeController::class);
    $ctrl->preview($intake);
    echo "preview_invoked_ok\n";
}
