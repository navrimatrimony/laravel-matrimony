<?php

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\IntakeSourceContext;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\MutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin can view assign owner form for unclaimed item', function () {
    $admin = ownerAssignmentAdminUser();
    $batch = ownerAssignmentBatch($admin);
    $intake = ownerAssignmentIntake(null);
    $item = ownerAssignmentItem($batch, ['biodata_intake_id' => $intake->id]);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.items.assign-owner', [$batch, $item]))
        ->assertOk()
        ->assertSee('This only assigns the owner of the intake', false)
        ->assertSee('I confirm this person has consented', false);
});

test('admin can assign existing non admin user after consent', function () {
    $admin = ownerAssignmentAdminUser();
    $member = ownerAssignmentMemberUser();
    $batch = ownerAssignmentBatch($admin);
    $intake = ownerAssignmentIntake(null, [
        'raw_ocr_text' => 'Immutable OCR owner assignment',
        'parsed_json' => ['core' => ['full_name' => 'Unclaimed Candidate']],
        'approval_snapshot_json' => ['core' => ['full_name' => 'Snapshot Candidate']],
    ]);
    $item = ownerAssignmentItem($batch, ['biodata_intake_id' => $intake->id]);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.assign-owner.store', [$batch, $item]), [
            'owner_user_id' => $member->id,
            'consent_confirmed' => '1',
            'consent_note' => 'phone consent confirmed',
        ])
        ->assertRedirect(route('admin.bulk-intakes.show', $batch));

    $intake = $intake->fresh();
    $context = IntakeSourceContext::query()->where('source_type', IntakeSourceContext::SOURCE_ADMIN_MANUAL)->sole();

    expect((int) $intake->uploaded_by)->toBe((int) $member->id)
        ->and($context->actor_type)->toBe(IntakeSourceContext::ACTOR_ADMIN)
        ->and((int) $context->actor_user_id)->toBe((int) $admin->id)
        ->and((int) $context->biodata_intake_id)->toBe((int) $intake->id)
        ->and((int) $context->bulk_intake_batch_id)->toBe((int) $batch->id)
        ->and((int) $context->bulk_intake_batch_item_id)->toBe((int) $item->id)
        ->and($context->source_meta_json['action'])->toBe('owner_assigned')
        ->and($context->source_meta_json['previous_uploaded_by'])->toBeNull()
        ->and($context->source_meta_json['new_uploaded_by'])->toBe($member->id)
        ->and($context->source_meta_json['consent_confirmed'])->toBeTrue()
        ->and($context->source_meta_json['consent_note'])->toBe('phone consent confirmed')
        ->and($intake->raw_ocr_text)->toBe('Immutable OCR owner assignment')
        ->and($intake->parsed_json)->toBe(['core' => ['full_name' => 'Unclaimed Candidate']])
        ->and($intake->approval_snapshot_json)->toBe(['core' => ['full_name' => 'Snapshot Candidate']])
        ->and(MatrimonyProfile::count())->toBe(0);
});

test('admin cannot assign without consent', function () {
    $admin = ownerAssignmentAdminUser();
    $member = ownerAssignmentMemberUser();
    $batch = ownerAssignmentBatch($admin);
    $intake = ownerAssignmentIntake(null);
    $item = ownerAssignmentItem($batch, ['biodata_intake_id' => $intake->id]);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.assign-owner.store', [$batch, $item]), [
            'owner_user_id' => $member->id,
        ])
        ->assertSessionHasErrors('consent_confirmed');

    expect($intake->fresh()->uploaded_by)->toBeNull();
});

test('admin cannot assign owner to admin user', function () {
    $admin = ownerAssignmentAdminUser();
    $adminOwner = ownerAssignmentAdminUser();
    $batch = ownerAssignmentBatch($admin);
    $intake = ownerAssignmentIntake(null);
    $item = ownerAssignmentItem($batch, ['biodata_intake_id' => $intake->id]);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.assign-owner.store', [$batch, $item]), [
            'owner_user_id' => $adminOwner->id,
            'consent_confirmed' => '1',
        ])
        ->assertSessionHasErrors('owner_user_id');

    expect($intake->fresh()->uploaded_by)->toBeNull();
});

test('already assigned intake cannot be reassigned', function () {
    $admin = ownerAssignmentAdminUser();
    $memberA = ownerAssignmentMemberUser();
    $memberB = ownerAssignmentMemberUser();
    $batch = ownerAssignmentBatch($admin);
    $intake = ownerAssignmentIntake($memberA);
    $item = ownerAssignmentItem($batch, ['biodata_intake_id' => $intake->id]);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.assign-owner.store', [$batch, $item]), [
            'owner_user_id' => $memberB->id,
            'consent_confirmed' => '1',
        ])
        ->assertRedirect(route('admin.bulk-intakes.show', $batch))
        ->assertSessionHas('error', 'Owner already assigned. Reassignment is not available in this phase.');

    expect((int) $intake->fresh()->uploaded_by)->toBe((int) $memberA->id);
});

test('non admin cannot access assign owner routes', function () {
    $admin = ownerAssignmentAdminUser();
    $member = ownerAssignmentMemberUser();
    $batch = ownerAssignmentBatch($admin);
    $intake = ownerAssignmentIntake(null);
    $item = ownerAssignmentItem($batch, ['biodata_intake_id' => $intake->id]);

    $this->actingAs($member)
        ->get(route('admin.bulk-intakes.items.assign-owner', [$batch, $item]))
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('admin.bulk-intakes.items.assign-owner.store', [$batch, $item]), [
            'owner_user_id' => $member->id,
            'consent_confirmed' => '1',
        ])
        ->assertForbidden();

    expect($intake->fresh()->uploaded_by)->toBeNull();
});

test('assignment does not trigger profile mutation or apply', function () {
    $this->mock(MutationService::class, function ($mock): void {
        $mock->shouldNotReceive('createDraftProfileForUser');
        $mock->shouldNotReceive('applyManualSnapshot');
        $mock->shouldNotReceive('applyFromIntake');
        $mock->shouldNotReceive('applyApprovedIntake');
    });

    $admin = ownerAssignmentAdminUser();
    $member = ownerAssignmentMemberUser();
    $batch = ownerAssignmentBatch($admin);
    $intake = ownerAssignmentIntake(null, [
        'approval_snapshot_json' => ['core' => ['full_name' => 'Original Snapshot']],
    ]);
    $item = ownerAssignmentItem($batch, ['biodata_intake_id' => $intake->id]);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.assign-owner.store', [$batch, $item]), [
            'owner_user_id' => $member->id,
            'consent_confirmed' => '1',
        ])
        ->assertRedirect(route('admin.bulk-intakes.show', $batch));

    $intake = $intake->fresh();
    expect(MatrimonyProfile::count())->toBe(0)
        ->and($intake->approved_by_user)->toBeFalse()
        ->and($intake->approval_snapshot_json)->toBe(['core' => ['full_name' => 'Original Snapshot']])
        ->and($intake->matrimony_profile_id)->toBeNull();
});

function ownerAssignmentAdminUser(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function ownerAssignmentMemberUser(): User
{
    return User::factory()->create([
        'is_admin' => false,
        'admin_role' => null,
    ]);
}

function ownerAssignmentBatch(User $admin): BulkIntakeBatch
{
    return BulkIntakeBatch::create([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);
}

function ownerAssignmentItem(BulkIntakeBatch $batch, array $overrides = []): BulkIntakeBatchItem
{
    return BulkIntakeBatchItem::create(array_merge([
        'bulk_intake_batch_id' => $batch->id,
        'item_sequence' => ((int) BulkIntakeBatchItem::where('bulk_intake_batch_id', $batch->id)->max('item_sequence')) + 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'owner-assignment.pdf',
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ], $overrides));
}

function ownerAssignmentIntake(?User $owner, array $overrides = []): BiodataIntake
{
    return BiodataIntake::create(array_merge([
        'uploaded_by' => $owner?->id,
        'raw_ocr_text' => 'Name: Owner Assignment Candidate',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}
