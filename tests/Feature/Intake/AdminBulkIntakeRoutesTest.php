<?php

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin bulk intake route names exist', function () {
    expect(route('admin.bulk-intakes.index'))->toBe(url('/admin/bulk-intakes'))
        ->and(route('admin.bulk-intakes.create'))->toBe(url('/admin/bulk-intakes/create'))
        ->and(route('admin.bulk-intakes.store'))->toBe(url('/admin/bulk-intakes'))
        ->and(route('admin.bulk-intakes.show', ['bulkIntakeBatch' => 123]))->toBe(url('/admin/bulk-intakes/123'))
        ->and(route('admin.bulk-intakes.queue-free-parse', ['bulkIntakeBatch' => 123]))->toBe(url('/admin/bulk-intakes/123/queue-free-parse'))
        ->and(route('admin.bulk-intakes.items.queue-free-parse', ['bulkIntakeBatch' => 123, 'bulkIntakeBatchItem' => 456]))->toBe(url('/admin/bulk-intakes/123/items/456/queue-free-parse'))
        ->and(route('admin.bulk-intakes.items.mark-needs-review', ['bulkIntakeBatch' => 123, 'bulkIntakeBatchItem' => 456]))->toBe(url('/admin/bulk-intakes/123/items/456/mark-needs-review'))
        ->and(route('admin.bulk-intakes.items.clear-needs-review', ['bulkIntakeBatch' => 123, 'bulkIntakeBatchItem' => 456]))->toBe(url('/admin/bulk-intakes/123/items/456/clear-needs-review'))
        ->and(route('admin.bulk-intakes.items.mark-duplicate', ['bulkIntakeBatch' => 123, 'bulkIntakeBatchItem' => 456]))->toBe(url('/admin/bulk-intakes/123/items/456/mark-duplicate'))
        ->and(route('admin.bulk-intakes.items.clear-duplicate', ['bulkIntakeBatch' => 123, 'bulkIntakeBatchItem' => 456]))->toBe(url('/admin/bulk-intakes/123/items/456/clear-duplicate'))
        ->and(route('admin.bulk-intakes.items.correct-candidate', ['bulkIntakeBatch' => 123, 'bulkIntakeBatchItem' => 456]))->toBe(url('/admin/bulk-intakes/123/items/456/correct-candidate'))
        ->and(route('admin.bulk-intakes.items.correct-candidate.update', ['bulkIntakeBatch' => 123, 'bulkIntakeBatchItem' => 456]))->toBe(url('/admin/bulk-intakes/123/items/456/correct-candidate'))
        ->and(route('admin.bulk-intakes.items.assign-owner', ['bulkIntakeBatch' => 123, 'bulkIntakeBatchItem' => 456]))->toBe(url('/admin/bulk-intakes/123/items/456/assign-owner'))
        ->and(route('admin.bulk-intakes.items.assign-owner.store', ['bulkIntakeBatch' => 123, 'bulkIntakeBatchItem' => 456]))->toBe(url('/admin/bulk-intakes/123/items/456/assign-owner'))
        ->and(route('admin.bulk-intakes.items.create-owner', ['bulkIntakeBatch' => 123, 'bulkIntakeBatchItem' => 456]))->toBe(url('/admin/bulk-intakes/123/items/456/create-owner'))
        ->and(route('admin.bulk-intakes.items.create-owner.store', ['bulkIntakeBatch' => 123, 'bulkIntakeBatchItem' => 456]))->toBe(url('/admin/bulk-intakes/123/items/456/create-owner'))
        ->and(route('admin.bulk-intakes.items.readiness', ['bulkIntakeBatch' => 123, 'bulkIntakeBatchItem' => 456]))->toBe(url('/admin/bulk-intakes/123/items/456/readiness'))
        ->and(route('admin.bulk-intakes.items.bootstrap-draft-profile', ['bulkIntakeBatch' => 123, 'bulkIntakeBatchItem' => 456]))->toBe(url('/admin/bulk-intakes/123/items/456/bootstrap-draft-profile'))
        ->and(route('admin.bulk-intakes.items.apply-preview', ['bulkIntakeBatch' => 123, 'bulkIntakeBatchItem' => 456]))->toBe(url('/admin/bulk-intakes/123/items/456/apply-preview'))
        ->and(route('admin.bulk-intakes.items.manual-transcript', ['bulkIntakeBatch' => 123, 'bulkIntakeBatchItem' => 456]))->toBe(url('/admin/bulk-intakes/123/items/456/manual-transcript'))
        ->and(route('admin.bulk-intakes.items.manual-transcript.store', ['bulkIntakeBatch' => 123, 'bulkIntakeBatchItem' => 456]))->toBe(url('/admin/bulk-intakes/123/items/456/manual-transcript'));
});

test('admin can access bulk intake index create and show pages', function () {
    $admin = adminBulkIntakeRoutesAdmin();
    $batch = BulkIntakeBatch::create([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.index'))
        ->assertOk()
        ->assertSee('Bulk Intakes', false);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.create'))
        ->assertOk()
        ->assertSee('New Bulk Intake', false);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('Bulk Intake #'.$batch->id, false);
});

test('bulk intake show keeps extraction actions and hides readiness owner profile actions', function () {
    $admin = adminBulkIntakeRoutesAdmin();
    $batch = BulkIntakeBatch::create([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);
    $intake = BiodataIntake::create([
        'uploaded_by' => null,
        'raw_ocr_text' => 'Name: Extraction Stage Candidate',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
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
    $fallbackIntake = BiodataIntake::create([
        'uploaded_by' => null,
        'raw_ocr_text' => '',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'error',
        'last_error' => 'empty_text',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);
    BulkIntakeBatchItem::create([
        'bulk_intake_batch_id' => $batch->id,
        'biodata_intake_id' => $fallbackIntake->id,
        'item_sequence' => 2,
        'input_type' => BulkIntakeBatchItem::INPUT_TEXT,
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('Current stage: candidate extraction and review. Owner assignment and profile creation are later steps.', false)
        ->assertSee('Open intake review', false)
        ->assertSee('Add manual transcript (OCR failed fallback)', false)
        ->assertSee('Queue free parse item', false)
        ->assertSee('Correct candidate', false)
        ->assertSee('Mark needs review', false)
        ->assertDontSee('Profile Readiness', false)
        ->assertDontSee('Profile Readiness details', false)
        ->assertDontSee('Ready for Profile Review', false)
        ->assertDontSee('Owner Missing', false)
        ->assertDontSee('Not ready', false)
        ->assertDontSee('Assign owner', false)
        ->assertDontSee('Create owner', false)
        ->assertDontSee('Create draft profile', false)
        ->assertDontSee('Preview parsed fields', false);
});

test('non admin cannot access admin bulk intake routes', function () {
    $member = User::factory()->create(['is_admin' => false, 'admin_role' => null]);
    $batch = BulkIntakeBatch::create([
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);
    $item = BulkIntakeBatchItem::create([
        'bulk_intake_batch_id' => $batch->id,
        'item_sequence' => 1,
        'input_type' => BulkIntakeBatchItem::INPUT_TEXT,
        'item_status' => BulkIntakeBatchItem::STATUS_NEEDS_REVIEW,
    ]);

    $this->actingAs($member)
        ->get(route('admin.bulk-intakes.index'))
        ->assertForbidden();

    $this->actingAs($member)
        ->get(route('admin.bulk-intakes.create'))
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('admin.bulk-intakes.store'), [
            'owner_user_id' => $member->id,
            'raw_text' => 'Name: Forbidden',
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('admin.bulk-intakes.queue-free-parse', $batch))
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('admin.bulk-intakes.items.mark-needs-review', [$batch, $item]))
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('admin.bulk-intakes.items.clear-needs-review', [$batch, $item]))
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('admin.bulk-intakes.items.mark-duplicate', [$batch, $item]), [
            'reason' => 'Forbidden duplicate mark',
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('admin.bulk-intakes.items.clear-duplicate', [$batch, $item]))
        ->assertForbidden();

    $this->actingAs($member)
        ->get(route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]))
        ->assertForbidden();

    $this->actingAs($member)
        ->patch(route('admin.bulk-intakes.items.correct-candidate.update', [$batch, $item]), [
            'name' => 'Forbidden Candidate',
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->get(route('admin.bulk-intakes.items.assign-owner', [$batch, $item]))
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('admin.bulk-intakes.items.assign-owner.store', [$batch, $item]))
        ->assertForbidden();

    $this->actingAs($member)
        ->get(route('admin.bulk-intakes.items.create-owner', [$batch, $item]))
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('admin.bulk-intakes.items.create-owner.store', [$batch, $item]))
        ->assertForbidden();

    $this->actingAs($member)
        ->get(route('admin.bulk-intakes.items.readiness', [$batch, $item]))
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('admin.bulk-intakes.items.bootstrap-draft-profile', [$batch, $item]))
        ->assertForbidden();

    $this->actingAs($member)
        ->get(route('admin.bulk-intakes.items.apply-preview', [$batch, $item]))
        ->assertForbidden();

    $this->actingAs($member)
        ->get(route('admin.bulk-intakes.items.manual-transcript', [$batch, $item]))
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('admin.bulk-intakes.items.manual-transcript.store', [$batch, $item]), [
            'transcript' => str_repeat('Manual transcript text. ', 2),
        ])
        ->assertForbidden();
});

function adminBulkIntakeRoutesAdmin(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}
