<?php

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\Intake\BulkIntakeBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('bulk intake batch foundation records items and refreshes counters without creating profiles', function () {
    $user = User::factory()->create();
    $service = app(BulkIntakeBatchService::class);
    $profilesBefore = MatrimonyProfile::count();

    $batch = $service->createBatch([
        'uploaded_by_user_id' => $user->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_name' => 'July office uploads',
        'meta_json' => ['source' => 'test'],
    ]);

    $fileItem = $service->addItem($batch, [
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'candidate-a.pdf',
        'source_file_path' => 'bulk/candidate-a.pdf',
        'file_hash' => hash('sha256', 'candidate-a-file'),
        'idempotency_key' => 'bulk-item-file-a',
        'summary_text' => 'Candidate A upload preview only',
    ]);
    $service->addItem($batch, [
        'input_type' => BulkIntakeBatchItem::INPUT_TEXT,
        'raw_text_hash' => hash('sha256', 'candidate-b-text'),
        'item_status' => BulkIntakeBatchItem::STATUS_NEEDS_REVIEW,
        'summary_text' => 'Candidate B text preview only',
    ]);
    $service->addItem($batch, [
        'input_type' => BulkIntakeBatchItem::INPUT_UNKNOWN,
        'item_status' => BulkIntakeBatchItem::STATUS_FAILED,
        'failure_code' => 'unsupported_input',
    ]);

    $batch = $service->refreshCounters($batch);

    expect($batch->batch_status)->toBe(BulkIntakeBatch::STATUS_PENDING)
        ->and($batch->total_items)->toBe(3)
        ->and($batch->total_files)->toBe(1)
        ->and($batch->total_texts)->toBe(1)
        ->and($batch->total_intakes_created)->toBe(0)
        ->and($batch->total_profiles_created)->toBe(0)
        ->and($batch->total_conflicts_generated)->toBe(0)
        ->and($batch->total_needs_review)->toBe(1)
        ->and($batch->total_failed)->toBe(1)
        ->and($batch->items)->toHaveCount(3)
        ->and($fileItem->batch->is($batch))->toBeTrue()
        ->and(MatrimonyProfile::count())->toBe($profilesBefore);

    expect(Schema::hasColumn('bulk_intake_batch_items', 'parsed_json'))->toBeFalse()
        ->and(Schema::hasColumn('bulk_intake_batch_items', 'profile_data_json'))->toBeFalse()
        ->and(Schema::hasColumn('bulk_intake_batch_items', 'parsed_profile_json'))->toBeFalse()
        ->and(Schema::hasColumn('bulk_intake_batch_items', 'normalized_profile_json'))->toBeFalse();
});

test('bulk intake item can link to an existing biodata intake without copying parsed payload', function () {
    $user = User::factory()->create();
    $intake = createBulkFoundationIntake($user, [
        'raw_ocr_text' => 'Original immutable OCR text',
        'parsed_json' => ['core' => ['full_name' => 'Linked Candidate']],
    ]);
    $service = app(BulkIntakeBatchService::class);
    $batch = $service->createBatch([
        'uploaded_by_user_id' => $user->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
    ]);

    $item = $service->addItem($batch, [
        'biodata_intake_id' => $intake->id,
        'input_type' => BulkIntakeBatchItem::INPUT_TEXT,
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
        'summary_text' => 'Linked Candidate preview only',
    ]);

    $batch = $service->refreshCounters($batch);

    expect($item->biodataIntake->is($intake))->toBeTrue()
        ->and($batch->total_intakes_created)->toBe(1)
        ->and($item->summary_text)->toBe('Linked Candidate preview only')
        ->and($intake->fresh()->parsed_json)->toBe(['core' => ['full_name' => 'Linked Candidate']])
        ->and($intake->fresh()->raw_ocr_text)->toBe('Original immutable OCR text');
});

test('bulk intake item idempotency returns the existing row', function () {
    $service = app(BulkIntakeBatchService::class);
    $batch = $service->createBatch([
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
    ]);

    $first = $service->addItem($batch, [
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'idempotency_key' => 'same-bulk-file',
    ]);
    $second = $service->addItem($batch, [
        'input_type' => BulkIntakeBatchItem::INPUT_TEXT,
        'idempotency_key' => 'same-bulk-file',
    ]);

    expect($second->id)->toBe($first->id)
        ->and(BulkIntakeBatchItem::count())->toBe(1);
});

function createBulkFoundationIntake(User $user, array $overrides = []): BiodataIntake
{
    return BiodataIntake::create(array_merge([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'Original OCR text',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}
