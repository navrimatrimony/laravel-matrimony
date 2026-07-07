<?php

use App\Jobs\ParseIntakeJob;
use App\Jobs\ProcessBulkIntakeBatchItemJob;
use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\User;
use App\Services\Intake\BulkIntakeBatchService;
use App\Services\Intake\BulkIntakeProgressPresenter;
use App\Services\Intake\IntakeCreationService;
use App\Services\Intake\IntakeSourceContextRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

test('show page displays progress summary and eta label for pending processing and parsed items', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-07 12:00:00'));

    try {
        $admin = progressBulkIntakeAdminUser();
        $batch = progressBulkIntakeBatch($admin);
        $batch->forceFill([
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ])->save();

        progressBulkIntakeItem($batch, null, [
            'item_sequence' => 1,
            'item_status' => BulkIntakeBatchItem::STATUS_PENDING,
        ]);
        progressBulkIntakeItem($batch, null, [
            'item_sequence' => 2,
            'item_status' => BulkIntakeBatchItem::STATUS_PROCESSING,
        ]);

        $queuedIntake = progressBulkIntakeIntake([
            'parse_status' => 'pending',
            'parsed_json' => [],
        ]);
        progressBulkIntakeItem($batch, $queuedIntake, [
            'item_sequence' => 3,
            'item_status' => BulkIntakeBatchItem::STATUS_PARSE_QUEUED,
        ]);

        $parsedIntake = progressBulkIntakeIntake([
            'parse_status' => 'parsed',
            'parsed_json' => [
                'core' => ['full_name' => 'Progress Parsed Candidate'],
            ],
        ]);
        progressBulkIntakeItem($batch, $parsedIntake, [
            'item_sequence' => 4,
            'item_status' => BulkIntakeBatchItem::STATUS_PARSE_QUEUED,
        ]);

        DB::table('jobs')->insert([
            'queue' => ProcessBulkIntakeBatchItemJob::QUEUE_NAME,
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.bulk-intakes.show', $batch))
            ->assertOk()
            ->assertSee('Background processing', false)
            ->assertSee('Bulk processing runs in background. You can leave this page open and refresh later. Website and app requests are not blocked by this batch.', false)
            ->assertSee('Total items', false)
            ->assertSee('Pending', false)
            ->assertSee('Processing', false)
            ->assertSee('Parse queued', false)
            ->assertSee('Parsed', false)
            ->assertSee('Percent done', false)
            ->assertSee('25%', false)
            ->assertSee('Approx ETA', false)
            ->assertSee('30 min', false)
            ->assertSee('Queue backlog', false)
            ->assertSee('Progress Parsed Candidate', false);
    } finally {
        Carbon::setTestNow();
    }
});

test('worker warning appears when active items have no recent activity', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-07 12:00:00'));

    try {
        $admin = progressBulkIntakeAdminUser();
        $batch = progressBulkIntakeBatch($admin);
        $batch->forceFill([
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ])->save();

        $item = progressBulkIntakeItem($batch, null, [
            'item_sequence' => 1,
            'item_status' => BulkIntakeBatchItem::STATUS_PENDING,
        ]);
        $item->forceFill([
            'created_at' => now()->subMinutes(25),
            'updated_at' => now()->subMinutes(25),
        ])->save();

        $this->actingAs($admin)
            ->get(route('admin.bulk-intakes.show', $batch))
            ->assertOk()
            ->assertSee('No recent progress detected. Queue worker may be stopped or busy.', false);
    } finally {
        Carbon::setTestNow();
    }
});

test('progress and index stay lightweight and do not expose parsed json payloads', function () {
    $admin = progressBulkIntakeAdminUser();
    $heavyMarker = 'progress-heavy-parsed-json-marker';
    $firstBatch = null;

    foreach (range(1, 25) as $index) {
        $batch = progressBulkIntakeBatch($admin, ['batch_name' => 'Progress index batch '.$index]);
        $intake = progressBulkIntakeIntake([
            'raw_ocr_text' => 'Name: Progress Index Candidate '.$index,
            'parse_status' => 'parsed',
            'parsed_json' => [
                'core' => ['full_name' => 'Progress Index Candidate '.$index],
                'debug_blob' => str_repeat($heavyMarker, 20),
            ],
        ]);
        progressBulkIntakeItem($batch, $intake, [
            'item_sequence' => 1,
            'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
        ]);
        $firstBatch ??= $batch;
    }

    $progress = app(BulkIntakeProgressPresenter::class)->progressForBatch($firstBatch);

    expect($progress['parsed'])->toBe(1)
        ->and(json_encode($progress))->not->toContain($heavyMarker);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.index'))
        ->assertOk()
        ->assertSee('Bulk Intakes', false)
        ->assertDontSee($heavyMarker, false);
});

test('bulk processing and bulk parse jobs dispatch to the dedicated bulk queue', function () {
    Queue::fake();
    $admin = progressBulkIntakeAdminUser();

    $this->actingAs($admin)->post(route('admin.bulk-intakes.store'), [
        'batch_name' => 'Dedicated bulk queue',
        'raw_text' => 'Name: Bulk Queue Candidate Mobile: 9000000101',
    ]);

    Queue::assertPushed(ProcessBulkIntakeBatchItemJob::class, function (ProcessBulkIntakeBatchItemJob $job): bool {
        return $job->queue === ProcessBulkIntakeBatchItemJob::QUEUE_NAME;
    });
    Queue::assertNotPushed(ParseIntakeJob::class);

    Queue::fake();
    $batch = progressBulkIntakeBatch($admin);
    $item = app(BulkIntakeBatchService::class)->createPendingItemFromRawText(
        $batch,
        'Name: Bulk Parse Queue Candidate Mobile: 9000000202',
        1,
        true
    );

    progressHandleBulkItemJob($item, $admin, true);

    Queue::assertPushed(ParseIntakeJob::class, function (ParseIntakeJob $job): bool {
        return $job->queue === ProcessBulkIntakeBatchItemJob::QUEUE_NAME;
    });
});

test('mobile biodata intake keeps normal parse job queue behavior unchanged', function () {
    Queue::fake();

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/biodata-intakes', [
        'raw_text' => 'Name: Mobile Queue Candidate Mobile: 9000000303',
        'parse_now' => false,
    ]);

    $response->assertCreated();

    Queue::assertPushed(ParseIntakeJob::class, function (ParseIntakeJob $job): bool {
        return $job->queue !== ProcessBulkIntakeBatchItemJob::QUEUE_NAME;
    });
});

function progressBulkIntakeAdminUser(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function progressBulkIntakeBatch(User $admin, array $overrides = []): BulkIntakeBatch
{
    return BulkIntakeBatch::create(array_merge([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_name' => 'Progress batch',
        'batch_status' => BulkIntakeBatch::STATUS_PROCESSING,
        'intake_creation_policy' => BulkIntakeBatch::POLICY_EXISTING_CHAIN,
        'ocr_policy' => BulkIntakeBatch::OCR_POLICY_FREE_OCR_FIRST,
    ], $overrides));
}

function progressBulkIntakeIntake(array $overrides = []): BiodataIntake
{
    return BiodataIntake::create(array_merge([
        'uploaded_by' => null,
        'raw_ocr_text' => 'Name: Progress Candidate',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}

function progressBulkIntakeItem(BulkIntakeBatch $batch, ?BiodataIntake $intake, array $overrides = []): BulkIntakeBatchItem
{
    return BulkIntakeBatchItem::create(array_merge([
        'bulk_intake_batch_id' => $batch->id,
        'biodata_intake_id' => $intake?->id,
        'item_sequence' => 1,
        'input_type' => BulkIntakeBatchItem::INPUT_TEXT,
        'item_status' => BulkIntakeBatchItem::STATUS_PENDING,
        'summary_text' => 'Name: Progress Candidate',
    ], $overrides));
}

function progressHandleBulkItemJob(BulkIntakeBatchItem $item, User $admin, bool $queueFreeParseAfterUpload): void
{
    (new ProcessBulkIntakeBatchItemJob((int) $item->id, (int) $admin->id, $queueFreeParseAfterUpload))
        ->handle(
            app(BulkIntakeBatchService::class),
            app(IntakeCreationService::class),
            app(IntakeSourceContextRecorder::class)
        );
}
