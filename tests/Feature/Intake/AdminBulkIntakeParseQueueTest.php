<?php

use App\Jobs\ParseIntakeJob;
use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\AiVisionExtractionService;
use App\Services\Intake\IntakeExtractionReuseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

test('admin can queue free parse for bulk-created pending intakes', function () {
    Queue::fake();

    $admin = parseQueueAdminUser();
    $batch = createParseQueueTextBatch($this, $admin);

    Queue::assertNotPushed(ParseIntakeJob::class);

    $response = $this->actingAs($admin)->post(route('admin.bulk-intakes.queue-free-parse', $batch));

    $response->assertRedirect(route('admin.bulk-intakes.show', $batch));
    $response->assertSessionHas('success', 'Free parse queued: 2; skipped: 0; failed: 0.');

    $items = BulkIntakeBatchItem::query()
        ->where('bulk_intake_batch_id', $batch->id)
        ->with('biodataIntake')
        ->orderBy('item_sequence')
        ->get();
    $intakeIds = $items->pluck('biodata_intake_id')->map(fn ($id): int => (int) $id)->all();

    expect($items)->toHaveCount(2)
        ->and($items->pluck('item_status')->all())->toBe([
            BulkIntakeBatchItem::STATUS_PARSE_QUEUED,
            BulkIntakeBatchItem::STATUS_PARSE_QUEUED,
        ])
        ->and(MatrimonyProfile::count())->toBe(0);

    Queue::assertPushed(ParseIntakeJob::class, 2);
    Queue::assertPushed(ParseIntakeJob::class, function (ParseIntakeJob $job) use ($intakeIds): bool {
        return $job->forceRecompute === true && in_array((int) $job->intakeId, $intakeIds, true);
    });

    foreach ($intakeIds as $intakeId) {
        expect(IntakeExtractionReuseResolver::peekParseInputOnlyFlag($intakeId))->toBeTrue();
    }
});

test('queue free parse skips already parsed non pending intakes', function () {
    Queue::fake();

    $admin = parseQueueAdminUser();
    $batch = createParseQueueTextBatch($this, $admin);
    $items = BulkIntakeBatchItem::query()
        ->where('bulk_intake_batch_id', $batch->id)
        ->with('biodataIntake')
        ->orderBy('item_sequence')
        ->get();

    $parsedItem = $items->first();
    $pendingItem = $items->last();
    $parsedItem->biodataIntake->forceFill([
        'parse_status' => 'parsed',
        'parsed_json' => ['core' => ['full_name' => 'Already Parsed']],
        'parsed_at' => now(),
    ])->save();

    $response = $this->actingAs($admin)->post(route('admin.bulk-intakes.queue-free-parse', $batch));

    $response->assertRedirect(route('admin.bulk-intakes.show', $batch));
    $response->assertSessionHas('success', 'Free parse queued: 1; skipped: 1; failed: 0. Skipped reasons: parse_status_not_pending=1.');

    Queue::assertPushed(ParseIntakeJob::class, 1);
    Queue::assertPushed(ParseIntakeJob::class, function (ParseIntakeJob $job) use ($pendingItem): bool {
        return $job->forceRecompute === true && (int) $job->intakeId === (int) $pendingItem->biodata_intake_id;
    });

    expect($parsedItem->fresh()->item_status)->toBe(BulkIntakeBatchItem::STATUS_INTAKE_CREATED)
        ->and($pendingItem->fresh()->item_status)->toBe(BulkIntakeBatchItem::STATUS_PARSE_QUEUED);
});

test('queue free parse is idempotent for already queued items', function () {
    Queue::fake();

    $admin = parseQueueAdminUser();
    $batch = createParseQueueTextBatch($this, $admin);

    $this->actingAs($admin)->post(route('admin.bulk-intakes.queue-free-parse', $batch))
        ->assertRedirect(route('admin.bulk-intakes.show', $batch));

    Queue::assertPushed(ParseIntakeJob::class, 2);

    Queue::fake();

    $response = $this->actingAs($admin)->post(route('admin.bulk-intakes.queue-free-parse', $batch));

    $response->assertRedirect(route('admin.bulk-intakes.show', $batch));
    $response->assertSessionHas('success', 'Free parse queued: 0; skipped: 2; failed: 0. Skipped reasons: already_parse_queued=2.');

    Queue::assertNotPushed(ParseIntakeJob::class);
    expect(BulkIntakeBatchItem::query()->where('item_status', BulkIntakeBatchItem::STATUS_PARSE_QUEUED)->count())->toBe(2);
});

test('manual queue free parse item refuses empty OCR text', function () {
    Queue::fake();

    $admin = parseQueueAdminUser();
    $batch = BulkIntakeBatch::create([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);
    $intake = BiodataIntake::create([
        'uploaded_by' => null,
        'raw_ocr_text' => '',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);
    $item = BulkIntakeBatchItem::create([
        'bulk_intake_batch_id' => $batch->id,
        'biodata_intake_id' => $intake->id,
        'item_sequence' => 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'empty-ocr.jpg',
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ]);

    $response = $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.queue-free-parse', [$batch, $item]));

    $response->assertRedirect(route('admin.bulk-intakes.show', $batch));
    $response->assertSessionHas('error', 'Cannot queue free parse because OCR text is empty. Add manual transcript or re-upload clearer file.');

    $item->refresh();

    expect($item->item_status)->toBe(BulkIntakeBatchItem::STATUS_NEEDS_REVIEW)
        ->and($item->failure_code)->toBe('empty_ocr_text')
        ->and($item->failure_message)->toBe('OCR did not extract usable text from this file.')
        ->and($item->item_meta_json['ocr_failure_code'])->toBe('empty_ocr_text')
        ->and($item->item_meta_json['parse_skipped_reason'])->toBe('empty_ocr_text');

    Queue::assertNotPushed(ParseIntakeJob::class);
});

test('approved or locked intake is skipped', function () {
    Queue::fake();

    $admin = parseQueueAdminUser();
    $batch = createParseQueueTextBatch($this, $admin);
    $items = BulkIntakeBatchItem::query()
        ->where('bulk_intake_batch_id', $batch->id)
        ->with('biodataIntake')
        ->orderBy('item_sequence')
        ->get();

    $items->first()->biodataIntake->forceFill(['approved_by_user' => true])->save();
    $items->last()->biodataIntake->forceFill(['intake_locked' => true])->save();

    $response = $this->actingAs($admin)->post(route('admin.bulk-intakes.queue-free-parse', $batch));

    $response->assertRedirect(route('admin.bulk-intakes.show', $batch));
    $response->assertSessionHas('success', 'Free parse queued: 0; skipped: 2; failed: 0. Skipped reasons: approved_or_locked=2.');

    Queue::assertNotPushed(ParseIntakeJob::class);
    expect(BulkIntakeBatchItem::query()->where('item_status', BulkIntakeBatchItem::STATUS_PARSE_QUEUED)->count())->toBe(0);
});

test('non admin cannot queue bulk parse', function () {
    Queue::fake();

    $admin = parseQueueAdminUser();
    $member = parseQueueMemberUser();
    $batch = createParseQueueTextBatch($this, $admin);

    $this->actingAs($member)
        ->post(route('admin.bulk-intakes.queue-free-parse', $batch))
        ->assertForbidden();

    Queue::assertNotPushed(ParseIntakeJob::class);
});

test('paid vision service is not invoked by controller service queue path', function () {
    Queue::fake();

    $this->mock(AiVisionExtractionService::class, function ($mock): void {
        $mock->shouldNotReceive('extractTextForIntake');
        $mock->shouldNotReceive('evaluateExtractedTextQuality');
    });

    $admin = parseQueueAdminUser();
    $batch = createParseQueueTextBatch($this, $admin);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.queue-free-parse', $batch))
        ->assertRedirect(route('admin.bulk-intakes.show', $batch));

    Queue::assertPushed(ParseIntakeJob::class, 2);
});

function createParseQueueTextBatch(object $testCase, User $admin): BulkIntakeBatch
{
    $response = $testCase->actingAs($admin)->post(route('admin.bulk-intakes.store'), [
        'batch_name' => 'Parse queue test batch',
        'raw_text' => "Name: Parse Queue Candidate One\nMobile: 9000000101\n---INTAKE---\nName: Parse Queue Candidate Two\nMobile: 9000000102",
        'queue_free_parse_after_upload' => '0',
    ]);

    $batch = BulkIntakeBatch::query()->sole();
    $response->assertRedirect(route('admin.bulk-intakes.show', $batch));

    expect(BiodataIntake::query()->where('parse_status', 'pending')->count())->toBe(2);
    expect(BiodataIntake::query()->whereNull('uploaded_by')->count())->toBe(2);

    return $batch;
}

function parseQueueAdminUser(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function parseQueueMemberUser(): User
{
    return User::factory()->create([
        'is_admin' => false,
        'admin_role' => null,
    ]);
}
