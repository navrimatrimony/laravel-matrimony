<?php

use App\Jobs\ParseIntakeJob;
use App\Jobs\ProcessBulkIntakeBatchItemJob;
use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\User;
use App\Services\Intake\IntakeExtractionReuseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

test('dry run lists eligible items but dispatches no jobs and writes nothing', function () {
    Queue::fake();

    $admin = queueReparseCommandAdminUser();
    $batch = queueReparseCommandBatch($admin);
    $intake = queueReparseCommandIntake([
        'raw_ocr_text' => queueReparseCommandUsableText('Dry Run Candidate'),
        'parse_status' => 'parsed',
        'last_error' => 'old_error',
        'parsed_json' => ['core' => ['full_name' => 'Old Dry Run Candidate']],
    ]);
    $item = queueReparseCommandItem($batch, $intake, [
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
        'item_meta_json' => ['keep' => 'same'],
    ]);

    $exitCode = Artisan::call('bulk-intake:queue-reparse', [
        'batchId' => $batch->id,
        '--dry-run' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)
        ->toContain('DRY RUN')
        ->toContain('Queued count: 1')
        ->toContain('Queued intake ids: '.$intake->id);

    Queue::assertNotPushed(ParseIntakeJob::class);
    expect(IntakeExtractionReuseResolver::peekParseInputOnlyFlag((int) $intake->id))->toBeFalse();

    $freshItem = $item->fresh();
    $freshIntake = $intake->fresh();
    expect($freshItem->item_status)->toBe(BulkIntakeBatchItem::STATUS_INTAKE_CREATED)
        ->and($freshItem->item_meta_json)->toEqual(['keep' => 'same'])
        ->and($freshIntake->parse_status)->toBe('parsed')
        ->and($freshIntake->last_error)->toBe('old_error')
        ->and($freshIntake->raw_ocr_text)->toBe($intake->raw_ocr_text)
        ->and($freshIntake->parsed_json)->toEqual(['core' => ['full_name' => 'Old Dry Run Candidate']]);
});

test('command queues ParseIntakeJob on bulk-intake queue for parsed linked intakes with raw OCR text', function () {
    Queue::fake();

    $admin = queueReparseCommandAdminUser();
    $batch = queueReparseCommandBatch($admin);
    $intake = queueReparseCommandIntake([
        'raw_ocr_text' => queueReparseCommandUsableText('Parsed Reparse Candidate'),
        'parse_status' => 'parsed',
        'last_error' => 'stale_error',
        'parsed_json' => ['core' => ['full_name' => 'Old Parsed Candidate']],
        'parser_version' => 'rules_only',
    ]);
    $item = queueReparseCommandItem($batch, $intake, [
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
        'item_meta_json' => ['existing' => 'meta'],
        'failure_code' => 'old_failure',
        'failure_message' => 'Old failure',
    ]);

    $exitCode = Artisan::call('bulk-intake:queue-reparse', [
        'batchId' => $batch->id,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)
        ->toContain('Queued count: 1')
        ->toContain('Skipped count: 0')
        ->toContain('Queued intake ids: '.$intake->id);

    Queue::assertPushedOn(ProcessBulkIntakeBatchItemJob::QUEUE_NAME, ParseIntakeJob::class);
    Queue::assertPushed(ParseIntakeJob::class, function (ParseIntakeJob $job) use ($intake): bool {
        return (int) $job->intakeId === (int) $intake->id
            && $job->forceRecompute === true
            && $job->queue === ProcessBulkIntakeBatchItemJob::QUEUE_NAME;
    });
    expect(IntakeExtractionReuseResolver::peekParseInputOnlyFlag((int) $intake->id))->toBeTrue();

    $freshItem = $item->fresh();
    $freshIntake = $intake->fresh();
    expect($freshItem->item_status)->toBe(BulkIntakeBatchItem::STATUS_PARSE_QUEUED)
        ->and($freshItem->failure_code)->toBeNull()
        ->and($freshItem->failure_message)->toBeNull()
        ->and($freshItem->item_meta_json['existing'] ?? null)->toBe('meta')
        ->and($freshItem->item_meta_json['reparse_queued_at'] ?? null)->not->toBeNull()
        ->and($freshItem->item_meta_json['reparse_reason'] ?? null)->toBe('latest_parser')
        ->and($freshItem->item_meta_json['reparse_parser_version'] ?? null)->toBe('rules_only')
        ->and($freshIntake->parse_status)->toBe('pending')
        ->and($freshIntake->last_error)->toBeNull()
        ->and($freshIntake->raw_ocr_text)->toBe($intake->raw_ocr_text)
        ->and($freshIntake->parsed_json)->toEqual(['core' => ['full_name' => 'Old Parsed Candidate']]);
});

test('items without linked intake are skipped safely', function () {
    Queue::fake();

    $admin = queueReparseCommandAdminUser();
    $batch = queueReparseCommandBatch($admin);
    $item = queueReparseCommandItem($batch, null, [
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
        'item_meta_json' => ['unchanged' => true],
    ]);

    $exitCode = Artisan::call('bulk-intake:queue-reparse', [
        'batchId' => $batch->id,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)
        ->toContain('Queued count: 0')
        ->toContain('Skipped count: 1')
        ->toContain('missing_linked_intake_id=1');
    Queue::assertNotPushed(ParseIntakeJob::class);
    expect($item->fresh()->item_meta_json)->toEqual(['unchanged' => true]);
});

test('items without usable parse input are skipped and not dispatched', function () {
    Queue::fake();

    $admin = queueReparseCommandAdminUser();
    $batch = queueReparseCommandBatch($admin);
    $intake = queueReparseCommandIntake([
        'raw_ocr_text' => '',
        'last_parse_input_text' => '',
        'parse_status' => 'parsed',
        'parsed_json' => ['core' => ['full_name' => 'Empty Input Candidate']],
    ]);
    $item = queueReparseCommandItem($batch, $intake);

    $exitCode = Artisan::call('bulk-intake:queue-reparse', [
        'batchId' => $batch->id,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)
        ->toContain('Queued count: 0')
        ->toContain('no_usable_parse_input=1');
    Queue::assertNotPushed(ParseIntakeJob::class);
    expect($item->fresh()->item_status)->toBe(BulkIntakeBatchItem::STATUS_INTAKE_CREATED)
        ->and($intake->fresh()->parse_status)->toBe('parsed');
});

test('already reparse queued items are not queued again', function () {
    Queue::fake();

    $admin = queueReparseCommandAdminUser();
    $batch = queueReparseCommandBatch($admin);
    $intake = queueReparseCommandIntake([
        'raw_ocr_text' => queueReparseCommandUsableText('Already Queued Candidate'),
        'parse_status' => 'pending',
    ]);
    $item = queueReparseCommandItem($batch, $intake, [
        'item_status' => BulkIntakeBatchItem::STATUS_PARSE_QUEUED,
        'item_meta_json' => [
            'reparse_queued_at' => now()->subMinute()->toIso8601String(),
            'reparse_reason' => 'latest_parser',
        ],
    ]);

    $exitCode = Artisan::call('bulk-intake:queue-reparse', [
        'batchId' => $batch->id,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)
        ->toContain('Queued count: 0')
        ->toContain('already_reparse_queued=1');
    Queue::assertNotPushed(ParseIntakeJob::class);
    expect($item->fresh()->item_meta_json['reparse_reason'] ?? null)->toBe('latest_parser');
});

test('approved and locked intakes are skipped safely', function () {
    Queue::fake();

    $admin = queueReparseCommandAdminUser();
    $batch = queueReparseCommandBatch($admin);
    $approved = queueReparseCommandIntake([
        'raw_ocr_text' => queueReparseCommandUsableText('Approved Candidate'),
        'parse_status' => 'parsed',
        'approved_by_user' => true,
        'parsed_json' => ['core' => ['full_name' => 'Approved Candidate']],
    ]);
    $locked = queueReparseCommandIntake([
        'raw_ocr_text' => queueReparseCommandUsableText('Locked Candidate'),
        'parse_status' => 'parsed',
        'intake_locked' => true,
        'parsed_json' => ['core' => ['full_name' => 'Locked Candidate']],
    ]);
    queueReparseCommandItem($batch, $approved, ['item_sequence' => 1]);
    queueReparseCommandItem($batch, $locked, ['item_sequence' => 2]);

    $exitCode = Artisan::call('bulk-intake:queue-reparse', [
        'batchId' => $batch->id,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)
        ->toContain('Queued count: 0')
        ->toContain('approved_or_locked=2');
    Queue::assertNotPushed(ParseIntakeJob::class);
    expect($approved->fresh()->parse_status)->toBe('parsed')
        ->and($locked->fresh()->parse_status)->toBe('parsed');
});

test('only parse error queues error intakes only', function () {
    Queue::fake();

    $admin = queueReparseCommandAdminUser();
    $batch = queueReparseCommandBatch($admin);
    $errorIntake = queueReparseCommandIntake([
        'raw_ocr_text' => queueReparseCommandUsableText('Error Candidate'),
        'parse_status' => 'error',
        'last_error' => 'parse_failed',
    ]);
    $parsedIntake = queueReparseCommandIntake([
        'raw_ocr_text' => queueReparseCommandUsableText('Parsed Candidate'),
        'parse_status' => 'parsed',
        'parsed_json' => ['core' => ['full_name' => 'Parsed Candidate']],
    ]);
    $errorItem = queueReparseCommandItem($batch, $errorIntake, ['item_sequence' => 1]);
    $parsedItem = queueReparseCommandItem($batch, $parsedIntake, ['item_sequence' => 2]);

    $exitCode = Artisan::call('bulk-intake:queue-reparse', [
        'batchId' => $batch->id,
        '--only' => 'parse_error',
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)
        ->toContain('Only filter: parse_error')
        ->toContain('Queued count: 1')
        ->toContain('Skipped count: 1')
        ->toContain('filter_not_matched=1')
        ->toContain('Queued intake ids: '.$errorIntake->id);

    Queue::assertPushed(ParseIntakeJob::class, 1);
    Queue::assertPushed(ParseIntakeJob::class, function (ParseIntakeJob $job) use ($errorIntake): bool {
        return (int) $job->intakeId === (int) $errorIntake->id
            && $job->forceRecompute === true
            && $job->queue === ProcessBulkIntakeBatchItemJob::QUEUE_NAME;
    });

    expect($errorItem->fresh()->item_status)->toBe(BulkIntakeBatchItem::STATUS_PARSE_QUEUED)
        ->and($errorIntake->fresh()->parse_status)->toBe('pending')
        ->and($errorIntake->fresh()->last_error)->toBeNull()
        ->and($parsedItem->fresh()->item_status)->toBe(BulkIntakeBatchItem::STATUS_INTAKE_CREATED)
        ->and($parsedIntake->fresh()->parse_status)->toBe('parsed');
});

function queueReparseCommandAdminUser(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function queueReparseCommandBatch(User $admin, array $overrides = []): BulkIntakeBatch
{
    return BulkIntakeBatch::create(array_merge([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_name' => 'Queue reparse command batch',
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ], $overrides));
}

function queueReparseCommandIntake(array $overrides = []): BiodataIntake
{
    return BiodataIntake::create(array_merge([
        'uploaded_by' => null,
        'raw_ocr_text' => queueReparseCommandUsableText('Default Candidate'),
        'last_parse_input_text' => null,
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}

function queueReparseCommandItem(BulkIntakeBatch $batch, ?BiodataIntake $intake, array $overrides = []): BulkIntakeBatchItem
{
    return BulkIntakeBatchItem::create(array_merge([
        'bulk_intake_batch_id' => $batch->id,
        'biodata_intake_id' => $intake?->id,
        'item_sequence' => 1,
        'input_type' => BulkIntakeBatchItem::INPUT_TEXT,
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
        'summary_text' => 'Queue reparse item',
    ], $overrides));
}

function queueReparseCommandUsableText(string $name): string
{
    return 'Name: '.$name.' Mobile: 9876543210 Education: BE City: Pune Occupation: Engineer';
}
