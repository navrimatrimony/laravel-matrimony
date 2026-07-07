<?php

use App\Jobs\ParseIntakeJob;
use App\Jobs\ProcessBulkIntakeBatchItemJob;
use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\IntakeSourceContext;
use App\Models\User;
use App\Services\Intake\BulkIntakeBatchService;
use App\Services\Intake\IntakeCreationService;
use App\Services\Intake\IntakeExtractionReuseResolver;
use App\Services\Intake\IntakeSourceContextRecorder;
use App\Services\OcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

test('bulk store creates pending items quickly and dispatches one processing job per item', function () {
    Queue::fake();
    $admin = asyncBulkIntakeAdmin();

    $response = $this->actingAs($admin)->post(route('admin.bulk-intakes.store'), [
        'batch_name' => 'Async raw text batch',
        'raw_text' => "Name: Async One\nMobile: 9000000001\n---INTAKE---\nName: Async Two\nMobile: 9000000002",
    ]);

    $batch = BulkIntakeBatch::query()->sole();

    $response->assertRedirect(route('admin.bulk-intakes.show', $batch));
    $response->assertSessionHas('success', 'Bulk intake queued. Items will process in background.');

    expect($batch->fresh()->batch_status)->toBe(BulkIntakeBatch::STATUS_PROCESSING)
        ->and($batch->fresh()->total_items)->toBe(2)
        ->and($batch->fresh()->total_intakes_created)->toBe(0)
        ->and(BulkIntakeBatchItem::query()->where('item_status', BulkIntakeBatchItem::STATUS_PENDING)->count())->toBe(2)
        ->and(BiodataIntake::count())->toBe(0)
        ->and(IntakeSourceContext::count())->toBe(0);

    Queue::assertPushed(ProcessBulkIntakeBatchItemJob::class, 2);
    Queue::assertNotPushed(ParseIntakeJob::class);
});

test('job processes one raw text item and queues parse input only parse', function () {
    Queue::fake();
    $admin = asyncBulkIntakeAdmin();
    $batch = asyncBulkIntakeBatch($admin);
    $item = app(BulkIntakeBatchService::class)->createPendingItemFromRawText(
        $batch,
        'Name: Async Text Candidate Mobile: 9000000101',
        1,
        true
    );

    asyncHandleBulkItemJob($item, $admin, true);

    $item->refresh();
    $intake = BiodataIntake::query()->sole();
    $context = IntakeSourceContext::query()->sole();

    expect($item->biodata_intake_id)->toBe($intake->id)
        ->and($item->item_status)->toBe(BulkIntakeBatchItem::STATUS_PARSE_QUEUED)
        ->and($intake->uploaded_by)->toBeNull()
        ->and($intake->raw_ocr_text)->toContain('Async Text Candidate')
        ->and($context->source_type)->toBe(IntakeSourceContext::SOURCE_ADMIN_BULK)
        ->and((int) $context->bulk_intake_batch_item_id)->toBe((int) $item->id)
        ->and(IntakeExtractionReuseResolver::peekParseInputOnlyFlag((int) $intake->id))->toBeTrue();

    Queue::assertPushed(ParseIntakeJob::class, function (ParseIntakeJob $job) use ($intake): bool {
        return (int) $job->intakeId === (int) $intake->id && $job->forceRecompute === true;
    });
});

test('job processes one file item and queues parse when OCR text is usable', function () {
    Queue::fake();
    $admin = asyncBulkIntakeAdmin();
    $batch = asyncBulkIntakeBatch($admin);

    $this->mock(OcrService::class, function ($mock): void {
        $mock->shouldReceive('extractTextFromPath')->once()->andReturn('नाव : Async File Candidate मोबाईल : 9000000201');
        $mock->shouldReceive('getLastExtractTextFromPathDebug')->once()->andReturn([
            'kind' => 'image',
            'ocr_pipeline' => 'test',
        ]);
    });

    $item = app(BulkIntakeBatchService::class)->createPendingItemFromUploadedFile(
        $batch,
        UploadedFile::fake()->createWithContent('async-file.jpg', 'file-bytes'),
        1,
        true
    );

    asyncHandleBulkItemJob($item, $admin, true);

    $item->refresh();
    $intake = BiodataIntake::query()->sole();

    expect($item->biodata_intake_id)->toBe($intake->id)
        ->and($item->item_status)->toBe(BulkIntakeBatchItem::STATUS_PARSE_QUEUED)
        ->and($intake->raw_ocr_text)->toBe('नाव : Async File Candidate मोबाईल : 9000000201');

    Queue::assertPushed(ParseIntakeJob::class, 1);
});

test('file item with empty OCR is marked needs review and does not queue parse', function () {
    Queue::fake();
    $admin = asyncBulkIntakeAdmin();
    $batch = asyncBulkIntakeBatch($admin);

    $this->mock(OcrService::class, function ($mock): void {
        $mock->shouldReceive('extractTextFromPath')->once()->andReturn('');
        $mock->shouldReceive('getLastExtractTextFromPathDebug')->once()->andReturn([
            'kind' => 'image',
            'ocr_pipeline' => 'test',
        ]);
    });

    $item = app(BulkIntakeBatchService::class)->createPendingItemFromUploadedFile(
        $batch,
        UploadedFile::fake()->createWithContent('empty-ocr.jpg', 'file-bytes'),
        1,
        true
    );

    asyncHandleBulkItemJob($item, $admin, true);

    $item->refresh();

    expect($item->item_status)->toBe(BulkIntakeBatchItem::STATUS_NEEDS_REVIEW)
        ->and($item->failure_code)->toBe('empty_ocr_text')
        ->and($item->item_meta_json['auto_parse_skipped_reason'])->toBe('empty_ocr_text')
        ->and(BiodataIntake::query()->sole()->raw_ocr_text)->toBe('');

    Queue::assertNotPushed(ParseIntakeJob::class);
});

test('one item failure does not stop another item from processing', function () {
    Queue::fake();
    $admin = asyncBulkIntakeAdmin();
    $batch = asyncBulkIntakeBatch($admin);
    $service = app(BulkIntakeBatchService::class);
    $failedItem = $service->addItem($batch, [
        'item_sequence' => 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'missing-file.jpg',
        'item_status' => BulkIntakeBatchItem::STATUS_PENDING,
        'item_meta_json' => [
            'source_file_path' => 'bulk-intake-sources/missing-file.jpg',
            'source_original_filename' => 'missing-file.jpg',
        ],
    ]);
    $successItem = $service->createPendingItemFromRawText(
        $batch,
        'Name: Surviving Async Candidate Mobile: 9000000301',
        2,
        false
    );

    asyncHandleBulkItemJob($failedItem, $admin, true);
    asyncHandleBulkItemJob($successItem, $admin, false);

    expect($failedItem->fresh()->item_status)->toBe(BulkIntakeBatchItem::STATUS_FAILED)
        ->and($failedItem->fresh()->failure_code)->toBe('bulk_item_processing_failed')
        ->and($successItem->fresh()->item_status)->toBe(BulkIntakeBatchItem::STATUS_INTAKE_CREATED)
        ->and(BiodataIntake::count())->toBe(1)
        ->and($batch->fresh()->total_failed)->toBe(1)
        ->and($batch->fresh()->total_intakes_created)->toBe(1);
});

test('store supports one hundred text items without synchronous intake processing', function () {
    Queue::fake();
    $admin = asyncBulkIntakeAdmin();
    $items = collect(range(1, 100))
        ->map(fn (int $index): string => 'Name: Bulk Candidate '.$index."\nMobile: 9".str_pad((string) $index, 9, '0', STR_PAD_LEFT))
        ->implode("\n---INTAKE---\n");

    $response = $this->actingAs($admin)->post(route('admin.bulk-intakes.store'), [
        'batch_name' => 'Hundred item async batch',
        'raw_text' => $items,
    ]);

    $batch = BulkIntakeBatch::query()->sole();

    $response->assertRedirect(route('admin.bulk-intakes.show', $batch));

    expect($batch->fresh()->total_items)->toBe(100)
        ->and($batch->fresh()->total_texts)->toBe(100)
        ->and($batch->fresh()->total_intakes_created)->toBe(0)
        ->and(BulkIntakeBatchItem::count())->toBe(100)
        ->and(BiodataIntake::count())->toBe(0);

    Queue::assertPushed(ProcessBulkIntakeBatchItemJob::class, 100);
    Queue::assertNotPushed(ParseIntakeJob::class);
});

test('bulk index stays paginated and does not render heavy parsed json', function () {
    $admin = asyncBulkIntakeAdmin();
    $heavyMarker = 'heavy-parsed-json-marker';

    foreach (range(1, 25) as $index) {
        $batch = asyncBulkIntakeBatch($admin, ['batch_name' => 'Index batch '.$index]);
        $intake = BiodataIntake::create([
            'uploaded_by' => null,
            'raw_ocr_text' => 'Name: Index Candidate '.$index,
            'parsed_json' => [
                'core' => ['full_name' => 'Index Candidate '.$index],
                'debug_blob' => str_repeat($heavyMarker, 20),
            ],
            'intake_status' => 'uploaded',
            'parse_status' => 'parsed',
            'parser_version' => 'rules_only',
            'snapshot_schema_version' => 1,
            'approved_by_user' => false,
            'intake_locked' => false,
        ]);
        BulkIntakeBatchItem::create([
            'bulk_intake_batch_id' => $batch->id,
            'biodata_intake_id' => $intake->id,
            'item_sequence' => 1,
            'input_type' => BulkIntakeBatchItem::INPUT_TEXT,
            'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
        ]);
    }

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.index'))
        ->assertOk()
        ->assertSee('Bulk Intakes', false)
        ->assertDontSee($heavyMarker, false);
});

function asyncBulkIntakeAdmin(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function asyncBulkIntakeBatch(User $admin, array $overrides = []): BulkIntakeBatch
{
    return BulkIntakeBatch::create(array_merge([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_name' => 'Async batch',
        'batch_status' => BulkIntakeBatch::STATUS_PENDING,
        'intake_creation_policy' => BulkIntakeBatch::POLICY_EXISTING_CHAIN,
        'ocr_policy' => BulkIntakeBatch::OCR_POLICY_FREE_OCR_FIRST,
    ], $overrides));
}

function asyncHandleBulkItemJob(BulkIntakeBatchItem $item, User $admin, bool $queueFreeParseAfterUpload): void
{
    (new ProcessBulkIntakeBatchItemJob((int) $item->id, (int) $admin->id, $queueFreeParseAfterUpload))
        ->handle(
            app(BulkIntakeBatchService::class),
            app(IntakeCreationService::class),
            app(IntakeSourceContextRecorder::class)
        );
}
