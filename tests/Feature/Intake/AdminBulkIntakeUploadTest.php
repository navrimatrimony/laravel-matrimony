<?php

use App\Jobs\ParseIntakeJob;
use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\IntakeSourceContext;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\AiVisionExtractionService;
use App\Services\Intake\BulkIntakeBatchService;
use App\Services\Intake\IntakeExtractionReuseResolver;
use App\Services\Intake\IntakeCreationService;
use App\Services\Intake\IntakeSourceContextRecorder;
use App\Services\OcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

test('admin bulk upload raw text auto queues free parse by default', function () {
    Queue::fake();
    $admin = adminBulkIntakeAdmin();

    $response = $this->actingAs($admin)->post(route('admin.bulk-intakes.store'), [
        'batch_name' => 'Two unclaimed text biodatas',
        'raw_text' => "Name: First Candidate\nMobile: 9000000001\n---INTAKE---\nName: Second Candidate\nMobile: 9000000002",
    ]);

    $batch = BulkIntakeBatch::query()->sole();

    $response->assertRedirect(route('admin.bulk-intakes.show', $batch));
    $response->assertSessionDoesntHaveErrors('owner_user_id');

    expect($batch->fresh()->batch_status)->toBe(BulkIntakeBatch::STATUS_COMPLETED)
        ->and($batch->fresh()->total_items)->toBe(2)
        ->and($batch->fresh()->total_texts)->toBe(2)
        ->and($batch->fresh()->total_intakes_created)->toBe(2)
        ->and($batch->fresh()->meta_json['owner_user_mode'])->toBe('unclaimed_bulk_staging')
        ->and($batch->fresh()->meta_json['consent_status'])->toBe('pending')
        ->and($batch->fresh()->meta_json['profile_creation_policy'])->toBe('after_candidate_consent')
        ->and($batch->fresh()->meta_json['parse_dispatch'])->toBe('auto_free_parse_after_upload')
        ->and(BulkIntakeBatchItem::count())->toBe(2)
        ->and(BiodataIntake::count())->toBe(2)
        ->and(IntakeSourceContext::count())->toBe(2)
        ->and(MatrimonyProfile::count())->toBe(0);

    $items = BulkIntakeBatchItem::query()->orderBy('item_sequence')->get();
    expect($items->pluck('item_status')->all())->toBe([
        BulkIntakeBatchItem::STATUS_PARSE_QUEUED,
        BulkIntakeBatchItem::STATUS_PARSE_QUEUED,
    ]);
    $items->each(function (BulkIntakeBatchItem $item): void {
        expect($item->item_meta_json['parse_queue_mode'])->toBe('auto_free_parse_after_upload')
            ->and($item->item_meta_json['parse_input_only'])->toBeTrue()
            ->and($item->item_meta_json['auto_queued_at'])->not->toBeNull();
    });

    BiodataIntake::query()->get()->each(function (BiodataIntake $intake): void {
        expect($intake->uploaded_by)->toBeNull()
            ->and($intake->parse_status)->toBe('pending')
            ->and(IntakeExtractionReuseResolver::peekParseInputOnlyFlag((int) $intake->id))->toBeTrue();
    });

    IntakeSourceContext::query()->get()->each(function (IntakeSourceContext $context) use ($admin, $batch): void {
        expect($context->source_type)->toBe(IntakeSourceContext::SOURCE_ADMIN_BULK)
            ->and($context->source_surface)->toBe(IntakeSourceContext::SURFACE_ADMIN_PANEL)
            ->and($context->actor_type)->toBe(IntakeSourceContext::ACTOR_ADMIN)
            ->and((int) $context->actor_user_id)->toBe((int) $admin->id)
            ->and((int) $context->bulk_intake_batch_id)->toBe((int) $batch->id)
            ->and($context->bulk_intake_batch_item_id)->not->toBeNull()
            ->and($context->biodata_intake_id)->not->toBeNull()
            ->and($context->source_meta_json['owner_user_id'])->toBeNull()
            ->and($context->source_meta_json['candidate_user_id'])->toBeNull()
            ->and($context->source_meta_json['owner_user_mode'])->toBe('unclaimed_bulk_staging')
            ->and($context->source_meta_json['consent_status'])->toBe('pending')
            ->and($context->source_meta_json['profile_creation_policy'])->toBe('after_candidate_consent');
    });

    Queue::assertPushed(ParseIntakeJob::class, 2);
    Queue::assertPushed(ParseIntakeJob::class, function (ParseIntakeJob $job): bool {
        return $job->forceRecompute === true;
    });
});

test('admin bulk upload file auto queues free parse by default', function () {
    Queue::fake();
    $admin = adminBulkIntakeAdmin();

    $this->mock(AiVisionExtractionService::class, function ($mock): void {
        $mock->shouldNotReceive('extractTextForIntake');
        $mock->shouldNotReceive('evaluateExtractedTextQuality');
    });

    $this->mock(OcrService::class, function ($mock): void {
        $mock->shouldReceive('extractTextFromPath')->twice()->andReturn(
            'Name: File Candidate One',
            'Name: File Candidate Two',
        );
        $mock->shouldReceive('getLastExtractTextFromPathDebug')->twice()->andReturn([
            'kind' => 'image',
            'ocr_pipeline' => 'test',
        ]);
    });

    $response = $this->actingAs($admin)->post(route('admin.bulk-intakes.store'), [
        'batch_name' => 'Two unclaimed file biodatas',
        'files' => [
            UploadedFile::fake()->createWithContent('candidate-one.jpg', 'file-one-bytes'),
            UploadedFile::fake()->createWithContent('candidate-two.jpg', 'file-two-bytes'),
        ],
    ]);

    $batch = BulkIntakeBatch::query()->sole();

    $response->assertRedirect(route('admin.bulk-intakes.show', $batch));
    expect($batch->fresh()->total_items)->toBe(2)
        ->and($batch->fresh()->total_files)->toBe(2)
        ->and($batch->fresh()->total_intakes_created)->toBe(2)
        ->and(BiodataIntake::count())->toBe(2)
        ->and(IntakeSourceContext::count())->toBe(2)
        ->and(MatrimonyProfile::count())->toBe(0);

    $items = BulkIntakeBatchItem::query()->orderBy('item_sequence')->get();
    expect($items->pluck('item_status')->all())->toBe([
        BulkIntakeBatchItem::STATUS_PARSE_QUEUED,
        BulkIntakeBatchItem::STATUS_PARSE_QUEUED,
    ]);
    $items->each(function (BulkIntakeBatchItem $item): void {
        expect($item->item_meta_json['parse_queue_mode'])->toBe('auto_free_parse_after_upload')
            ->and($item->item_meta_json['parse_input_only'])->toBeTrue()
            ->and($item->item_meta_json['auto_queued_at'])->not->toBeNull();
    });

    expect(BiodataIntake::query()->pluck('uploaded_by')->all())->toBe([null, null])
        ->and(BiodataIntake::query()->pluck('raw_ocr_text')->all())->toBe([
            'Name: File Candidate One',
            'Name: File Candidate Two',
        ]);

    BiodataIntake::query()->get()->each(function (BiodataIntake $intake): void {
        expect(IntakeExtractionReuseResolver::peekParseInputOnlyFlag((int) $intake->id))->toBeTrue();
    });

    Queue::assertPushed(ParseIntakeJob::class, 2);
    Queue::assertPushed(ParseIntakeJob::class, function (ParseIntakeJob $job): bool {
        return $job->forceRecompute === true;
    });
});

test('admin can disable auto free parse after upload', function () {
    Queue::fake();
    $admin = adminBulkIntakeAdmin();

    $response = $this->actingAs($admin)->post(route('admin.bulk-intakes.store'), [
        'batch_name' => 'Deferred parse text biodata',
        'raw_text' => 'Name: Deferred Candidate Mobile: 9000000401',
        'queue_free_parse_after_upload' => '0',
    ]);

    $batch = BulkIntakeBatch::query()->sole();

    $response->assertRedirect(route('admin.bulk-intakes.show', $batch));

    $item = BulkIntakeBatchItem::query()->sole();
    $intake = BiodataIntake::query()->sole();

    expect($item->item_status)->toBe(BulkIntakeBatchItem::STATUS_INTAKE_CREATED)
        ->and($intake->parse_status)->toBe('pending')
        ->and($batch->fresh()->meta_json['parse_dispatch'])->toBe('deferred')
        ->and(MatrimonyProfile::count())->toBe(0);

    Queue::assertNotPushed(ParseIntakeJob::class);
});

test('create page does not show existing member user field', function () {
    $admin = adminBulkIntakeAdmin();

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.create'))
        ->assertOk()
        ->assertSee('Bulk intake is staging only', false)
        ->assertSee('Queue free parse after upload', false)
        ->assertSee('paid Sarvam/OpenAI vision extraction is not called', false)
        ->assertDontSee('Existing member user ID', false)
        ->assertDontSee('Mode A only', false);
});

test('unclaimed bulk persistence stores nullable uploaded by', function () {
    $intake = app(IntakeCreationService::class)->persistPreparedForUnclaimedBulk([
        'file_path' => null,
        'original_filename' => null,
        'raw_ocr_text' => 'Name: Unclaimed Candidate',
    ]);

    expect($intake->uploaded_by)->toBeNull()
        ->and($intake->parse_status)->toBe('pending')
        ->and(MatrimonyProfile::count())->toBe(0);
});

test('queue free parse works for unclaimed bulk intakes', function () {
    Queue::fake();
    $admin = adminBulkIntakeAdmin();

    $this->actingAs($admin)->post(route('admin.bulk-intakes.store'), [
        'batch_name' => 'Queue unclaimed text biodatas',
        'raw_text' => "Name: Queue Candidate One\nMobile: 9000000301\n---INTAKE---\nName: Queue Candidate Two\nMobile: 9000000302",
        'queue_free_parse_after_upload' => '0',
    ]);
    $batch = BulkIntakeBatch::query()->sole();

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.queue-free-parse', $batch))
        ->assertRedirect(route('admin.bulk-intakes.show', $batch));

    Queue::assertPushed(ParseIntakeJob::class, 2);
    expect(BulkIntakeBatchItem::query()->where('item_status', BulkIntakeBatchItem::STATUS_PARSE_QUEUED)->count())->toBe(2)
        ->and(BiodataIntake::query()->whereNull('uploaded_by')->count())->toBe(2)
        ->and(MatrimonyProfile::count())->toBe(0);
});

test('bulk intake batch item does not store parsed profile data columns', function () {
    expect(Schema::hasColumn('bulk_intake_batch_items', 'parsed_json'))->toBeFalse()
        ->and(Schema::hasColumn('bulk_intake_batch_items', 'profile_data_json'))->toBeFalse()
        ->and(Schema::hasColumn('bulk_intake_batch_items', 'parsed_profile_json'))->toBeFalse()
        ->and(Schema::hasColumn('bulk_intake_batch_items', 'normalized_profile_json'))->toBeFalse();
});

test('unclaimed item retry idempotency does not duplicate biodata intake', function () {
    Queue::fake();
    $admin = adminBulkIntakeAdmin();
    $batchService = app(BulkIntakeBatchService::class);
    $batch = $batchService->createBatch([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
    ]);
    $item = $batchService->addItem($batch, [
        'item_sequence' => 1,
        'input_type' => BulkIntakeBatchItem::INPUT_TEXT,
        'raw_text_hash' => hash('sha256', 'Name: Retry Candidate'),
        'idempotency_key' => 'retry-bulk-item-one',
    ]);

    $first = $batchService->createUnclaimedIntakeForItem(
        $item,
        $admin,
        app(IntakeCreationService::class),
        app(IntakeSourceContextRecorder::class),
        null,
        'Name: Retry Candidate'
    );
    $second = $batchService->createUnclaimedIntakeForItem(
        $first,
        $admin,
        app(IntakeCreationService::class),
        app(IntakeSourceContextRecorder::class),
        null,
        'Name: Retry Candidate'
    );

    expect($second->id)->toBe($item->id)
        ->and($second->biodata_intake_id)->toBe($first->biodata_intake_id)
        ->and($second->item_status)->toBe(BulkIntakeBatchItem::STATUS_INTAKE_CREATED)
        ->and(BiodataIntake::count())->toBe(1)
        ->and(BiodataIntake::query()->first()->uploaded_by)->toBeNull()
        ->and(IntakeSourceContext::count())->toBe(1);
    Queue::assertNotPushed(ParseIntakeJob::class);
});

function adminBulkIntakeAdmin(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}
