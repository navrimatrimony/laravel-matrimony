<?php

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\MutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin can filter bulk items by needs review status', function () {
    $admin = reviewQueueAdminUser();
    $batch = createReviewQueueBatch($admin);
    createReviewQueueItem($batch, ['item_status' => BulkIntakeBatchItem::STATUS_PENDING, 'original_filename' => 'pending-file.pdf']);
    createReviewQueueItem($batch, ['item_status' => BulkIntakeBatchItem::STATUS_PARSE_QUEUED, 'original_filename' => 'queued-file.pdf']);
    createReviewQueueItem($batch, ['item_status' => BulkIntakeBatchItem::STATUS_NEEDS_REVIEW, 'original_filename' => 'needs-review-file.pdf']);
    createReviewQueueItem($batch, ['item_status' => BulkIntakeBatchItem::STATUS_FAILED, 'original_filename' => 'failed-file.pdf']);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', ['bulkIntakeBatch' => $batch, 'status' => 'needs_review']))
        ->assertOk()
        ->assertSee('needs-review-file.pdf', false)
        ->assertDontSee('pending-file.pdf', false)
        ->assertDontSee('queued-file.pdf', false)
        ->assertDontSee('failed-file.pdf', false);
});

test('admin can filter unclaimed bulk items', function () {
    $admin = reviewQueueAdminUser();
    $claimedUser = reviewQueueMemberUser();
    $batch = createReviewQueueBatch($admin);

    createReviewQueueItem($batch, [
        'original_filename' => 'staged-only.pdf',
        'biodata_intake_id' => createReviewQueueIntake(null)->id,
    ]);
    createReviewQueueItem($batch, [
        'original_filename' => 'owned-only.pdf',
        'biodata_intake_id' => createReviewQueueIntake($claimedUser)->id,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', ['bulkIntakeBatch' => $batch, 'status' => 'unclaimed']))
        ->assertOk()
        ->assertSee('staged-only.pdf', false)
        ->assertSee('Unclaimed / consent pending', false)
        ->assertDontSee('owned-only.pdf', false);
});

test('admin can mark item needs review', function () {
    $admin = reviewQueueAdminUser();
    $batch = createReviewQueueBatch($admin);
    $item = createReviewQueueItem($batch, [
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
        'biodata_intake_id' => createReviewQueueIntake(null)->id,
    ]);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.mark-needs-review', [$batch, $item]), [
            'reason' => 'manual exception check',
        ])
        ->assertRedirect(route('admin.bulk-intakes.show', $batch));

    $item = $item->fresh();
    expect($item->item_status)->toBe(BulkIntakeBatchItem::STATUS_NEEDS_REVIEW)
        ->and($item->item_meta_json['previous_item_status'])->toBe(BulkIntakeBatchItem::STATUS_INTAKE_CREATED)
        ->and($item->item_meta_json['needs_review_marked_by_user_id'])->toBe($admin->id)
        ->and($item->item_meta_json['needs_review_marked_at'])->not->toBeNull()
        ->and($item->item_meta_json['needs_review_reason'])->toBe('manual exception check')
        ->and(MatrimonyProfile::count())->toBe(0);
});

test('admin can clear needs review', function () {
    $admin = reviewQueueAdminUser();
    $batch = createReviewQueueBatch($admin);
    $item = createReviewQueueItem($batch, [
        'item_status' => BulkIntakeBatchItem::STATUS_NEEDS_REVIEW,
        'item_meta_json' => ['previous_item_status' => BulkIntakeBatchItem::STATUS_PARSE_QUEUED],
        'biodata_intake_id' => createReviewQueueIntake(null)->id,
    ]);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.clear-needs-review', [$batch, $item]))
        ->assertRedirect(route('admin.bulk-intakes.show', $batch));

    $meta = $item->fresh()->item_meta_json;
    expect($item->fresh()->item_status)->toBe(BulkIntakeBatchItem::STATUS_PARSE_QUEUED)
        ->and($meta['needs_review_cleared_by_user_id'])->toBe($admin->id)
        ->and($meta['needs_review_cleared_at'])->not->toBeNull()
        ->and($meta['needs_review_restored_item_status'])->toBe(BulkIntakeBatchItem::STATUS_PARSE_QUEUED)
        ->and(array_key_exists('previous_item_status', $meta))->toBeFalse();
});

test('show page displays linked intake parse error', function () {
    $admin = reviewQueueAdminUser();
    $batch = createReviewQueueBatch($admin);
    createReviewQueueItem($batch, [
        'original_filename' => 'parse-error-candidate.pdf',
        'biodata_intake_id' => createReviewQueueIntake(null, [
            'parse_status' => 'error',
            'last_error' => 'parser failed',
        ])->id,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('parse-error-candidate.pdf', false)
        ->assertSee('Parse error', false)
        ->assertSee('parser failed', false);
});

test('show page displays unclaimed consent pending badge', function () {
    $admin = reviewQueueAdminUser();
    $batch = createReviewQueueBatch($admin);
    createReviewQueueItem($batch, [
        'original_filename' => 'unclaimed-visible.pdf',
        'biodata_intake_id' => createReviewQueueIntake(null)->id,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('unclaimed-visible.pdf', false)
        ->assertSee('Unclaimed / consent pending', false);
});

test('non admin cannot mark or clear review', function () {
    $admin = reviewQueueAdminUser();
    $member = reviewQueueMemberUser();
    $batch = createReviewQueueBatch($admin);
    $markItem = createReviewQueueItem($batch, ['item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED]);
    $clearItem = createReviewQueueItem($batch, ['item_status' => BulkIntakeBatchItem::STATUS_NEEDS_REVIEW]);

    $this->actingAs($member)
        ->post(route('admin.bulk-intakes.items.mark-needs-review', [$batch, $markItem]))
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('admin.bulk-intakes.items.clear-needs-review', [$batch, $clearItem]))
        ->assertForbidden();

    expect($markItem->fresh()->item_status)->toBe(BulkIntakeBatchItem::STATUS_INTAKE_CREATED)
        ->and($clearItem->fresh()->item_status)->toBe(BulkIntakeBatchItem::STATUS_NEEDS_REVIEW);
});

test('review queue does not call profile mutation or apply', function () {
    $this->mock(MutationService::class, function ($mock): void {
        $mock->shouldNotReceive('createDraftProfileForUser');
        $mock->shouldNotReceive('applyManualSnapshot');
        $mock->shouldNotReceive('applyFromIntake');
        $mock->shouldNotReceive('applyApprovedIntake');
    });

    $admin = reviewQueueAdminUser();
    $batch = createReviewQueueBatch($admin);
    $intake = createReviewQueueIntake(null);
    $item = createReviewQueueItem($batch, [
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
        'biodata_intake_id' => $intake->id,
    ]);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.mark-needs-review', [$batch, $item]))
        ->assertRedirect(route('admin.bulk-intakes.show', $batch));
    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.clear-needs-review', [$batch, $item->fresh()]))
        ->assertRedirect(route('admin.bulk-intakes.show', $batch));

    $intake = $intake->fresh();
    expect(MatrimonyProfile::count())->toBe(0)
        ->and($intake->approved_by_user)->toBeFalse()
        ->and($intake->intake_locked)->toBeFalse()
        ->and($intake->matrimony_profile_id)->toBeNull();
});

function reviewQueueAdminUser(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function reviewQueueMemberUser(): User
{
    return User::factory()->create([
        'is_admin' => false,
        'admin_role' => null,
    ]);
}

function createReviewQueueBatch(User $admin): BulkIntakeBatch
{
    return BulkIntakeBatch::create([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);
}

function createReviewQueueItem(BulkIntakeBatch $batch, array $overrides = []): BulkIntakeBatchItem
{
    return BulkIntakeBatchItem::create(array_merge([
        'bulk_intake_batch_id' => $batch->id,
        'item_sequence' => ((int) BulkIntakeBatchItem::where('bulk_intake_batch_id', $batch->id)->max('item_sequence')) + 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'review-item.pdf',
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ], $overrides));
}

function createReviewQueueIntake(?User $owner, array $overrides = []): BiodataIntake
{
    return BiodataIntake::create(array_merge([
        'uploaded_by' => $owner?->id,
        'raw_ocr_text' => 'Name: Review Queue Candidate',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}
