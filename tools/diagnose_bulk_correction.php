<?php

declare(strict_types=1);

/**
 * Quick production diagnostics for bulk correct-candidate save failures.
 *
 * Usage:
 *   php tools/diagnose_bulk_correction.php 739
 *   php tools/diagnose_bulk_correction.php --batch=43 --item=175
 */
require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$intakeId = 0;
$batchId = null;
$itemId = null;
$trySave = false;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--batch=')) {
        $batchId = (int) substr($arg, 8);
    } elseif (str_starts_with($arg, '--item=')) {
        $itemId = (int) substr($arg, 7);
    } elseif ($arg === '--try-save') {
        $trySave = true;
    } elseif (ctype_digit($arg)) {
        $intakeId = (int) $arg;
    }
}

if ($batchId !== null && $itemId !== null) {
    $item = \App\Models\BulkIntakeBatchItem::query()
        ->where('bulk_intake_batch_id', $batchId)
        ->whereKey($itemId)
        ->first();
    if ($item?->biodata_intake_id) {
        $intakeId = (int) $item->biodata_intake_id;
    }
}

if ($intakeId <= 0) {
    fwrite(STDERR, "Usage: php tools/diagnose_bulk_correction.php <intake_id>\n");
    fwrite(STDERR, "   or: php tools/diagnose_bulk_correction.php --batch=43 --item=175\n");
    exit(2);
}

$requiredColumns = [
    'approval_snapshot_json',
    'reviewed_by_user_id',
    'review_actor_type',
    'review_surface',
    'reviewed_at',
    'approval_policy',
    'approval_status',
];

echo "=== bulk correction diagnostics ===\n";
echo 'intake_id='.$intakeId."\n";

$missing = [];
foreach ($requiredColumns as $column) {
    $ok = Illuminate\Support\Facades\Schema::hasColumn('biodata_intakes', $column);
    echo 'column '.$column.': '.($ok ? 'OK' : 'MISSING')."\n";
    if (! $ok) {
        $missing[] = $column;
    }
}

/** @var \App\Models\BiodataIntake|null $intake */
$intake = \App\Models\BiodataIntake::query()->find($intakeId);
if (! $intake) {
    echo "intake: NOT FOUND\n";
    exit(1);
}

echo 'parse_status='.(string) ($intake->parse_status ?? '')."\n";
echo 'intake_locked='.(($intake->intake_locked ?? false) ? 'yes' : 'no')."\n";
echo 'approved_by_user='.(($intake->approved_by_user ?? false) ? 'yes' : 'no')."\n";
echo 'has_parsed_json='.(is_array($intake->parsed_json) && $intake->parsed_json !== [] ? 'yes' : 'no')."\n";
echo 'has_approval_snapshot='.(is_array($intake->approval_snapshot_json) && $intake->approval_snapshot_json !== [] ? 'yes' : 'no')."\n";
echo 'raw_ocr_len='.strlen((string) ($intake->raw_ocr_text ?? ''))."\n";

if ($missing !== []) {
    echo "\nACTION: run pending migrations:\n";
    echo "  php artisan migrate --force\n";
    echo 'Missing columns: '.implode(', ', $missing)."\n";
    exit(3);
}

echo "\nSchema looks OK for human review save.\n";

if (! $trySave) {
    echo "Optional save test (rolls back, does not keep data):\n";
    echo "  php tools/diagnose_bulk_correction.php --batch={$batchId} --item={$itemId} --try-save\n";
    echo "If browser save fails, check log:\n";
    echo "  tail -n 40 storage/logs/laravel.log\n";
    exit(0);
}

$admin = \App\Models\User::query()
    ->where('is_admin', true)
    ->orderBy('id')
    ->first();

if (! $admin) {
    echo "admin_user=MISSING (no is_admin user found)\n";
    exit(4);
}

echo 'dry_run_admin_id='.$admin->id."\n";

$item = \App\Models\BulkIntakeBatchItem::query()
    ->where('biodata_intake_id', $intakeId)
    ->first();

if (! $item) {
    echo "batch_item=MISSING for intake {$intakeId}\n";
    exit(5);
}

$dryInput = [
    'name' => 'Dry Run Test',
    'mobile' => '9765771101',
    'date_of_birth' => '1994-03-11',
    'height' => "5'5\"",
    'gender' => 'male',
    'education' => 'B.A.',
    'location' => 'Gadhinglaj',
];

try {
    \Illuminate\Support\Facades\DB::beginTransaction();
    app(\App\Services\Intake\BulkIntakeCandidateCorrectionService::class)
        ->saveCorrection($item, $admin, $dryInput);
    \Illuminate\Support\Facades\DB::rollBack();
    echo "dry_run_save=OK (transaction rolled back; no data kept)\n";
} catch (Throwable $e) {
    \Illuminate\Support\Facades\DB::rollBack();
    echo 'dry_run_save=FAIL'."\n";
    echo 'exception='.$e::class."\n";
    echo 'message='.$e->getMessage()."\n";
    echo $e->getFile().':'.$e->getLine()."\n";
    exit(6);
}

echo "If browser save still fails, check latest log line:\n";
echo "  tail -n 40 storage/logs/laravel.log\n";
