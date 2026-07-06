<?php

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\IntakeSourceContext;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\MutationService;
use App\Support\MobileNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin can view create owner form for unclaimed item', function () {
    $admin = createOwnerAdminUser();
    $batch = createOwnerBatch($admin);
    $intake = createOwnerIntake(null);
    $item = createOwnerItem($batch, ['biodata_intake_id' => $intake->id]);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.items.create-owner', [$batch, $item]))
        ->assertOk()
        ->assertSee('This creates a login/member user and assigns this intake to that user. It does not create, approve, or apply a profile.', false)
        ->assertSee('name="new_name"', false)
        ->assertSee('name="new_mobile"', false)
        ->assertSee('name="registering_for"', false);
});

test('admin can create new member user and assign intake after consent', function () {
    $admin = createOwnerAdminUser();
    $batch = createOwnerBatch($admin);
    $intake = createOwnerIntake(null, [
        'raw_ocr_text' => 'Immutable OCR create owner',
        'parsed_json' => ['core' => ['full_name' => 'Unclaimed Candidate']],
        'approval_snapshot_json' => ['core' => ['full_name' => 'Snapshot Candidate']],
    ]);
    $item = createOwnerItem($batch, ['biodata_intake_id' => $intake->id]);
    $normalizedMobile = MobileNumber::normalize('+91 98765 43210');

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.create-owner.store', [$batch, $item]), [
            'new_name' => 'Created Bulk Owner',
            'new_mobile' => '+91 98765 43210',
            'new_email' => 'created-owner@example.test',
            'registering_for' => 'self',
            'consent_confirmed' => '1',
            'consent_note' => 'phone consent confirmed',
        ])
        ->assertRedirect(route('admin.bulk-intakes.show', $batch));

    $member = User::query()->where('mobile', $normalizedMobile)->sole();
    $intake = $intake->fresh();
    $context = IntakeSourceContext::query()->where('source_type', IntakeSourceContext::SOURCE_ADMIN_MANUAL)->sole();

    expect($member->name)->toBe('Created Bulk Owner')
        ->and($member->email)->toBe('created-owner@example.test')
        ->and($member->registering_for)->toBe('self')
        ->and((int) $intake->uploaded_by)->toBe((int) $member->id)
        ->and($context->actor_type)->toBe(IntakeSourceContext::ACTOR_ADMIN)
        ->and((int) $context->actor_user_id)->toBe((int) $admin->id)
        ->and((int) $context->biodata_intake_id)->toBe((int) $intake->id)
        ->and((int) $context->bulk_intake_batch_id)->toBe((int) $batch->id)
        ->and((int) $context->bulk_intake_batch_item_id)->toBe((int) $item->id)
        ->and($context->source_meta_json['action'])->toBe('owner_user_created_and_assigned')
        ->and($context->source_meta_json['new_uploaded_by'])->toBe($member->id)
        ->and($context->source_meta_json['consent_confirmed'])->toBeTrue()
        ->and($context->source_meta_json['consent_note'])->toBe('phone consent confirmed')
        ->and($context->source_meta_json['created_user_mobile'])->toBe($normalizedMobile)
        ->and($intake->raw_ocr_text)->toBe('Immutable OCR create owner')
        ->and($intake->parsed_json)->toBe(['core' => ['full_name' => 'Unclaimed Candidate']])
        ->and($intake->approval_snapshot_json)->toBe(['core' => ['full_name' => 'Snapshot Candidate']])
        ->and(MatrimonyProfile::count())->toBe(0);
});

test('admin cannot create owner without consent', function () {
    $admin = createOwnerAdminUser();
    $batch = createOwnerBatch($admin);
    $intake = createOwnerIntake(null);
    $item = createOwnerItem($batch, ['biodata_intake_id' => $intake->id]);
    $usersBefore = User::count();

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.create-owner.store', [$batch, $item]), [
            'new_name' => 'No Consent Owner',
            'new_mobile' => '9876543210',
            'registering_for' => 'self',
        ])
        ->assertSessionHasErrors('consent_confirmed');

    expect(User::count())->toBe($usersBefore)
        ->and($intake->fresh()->uploaded_by)->toBeNull();
});

test('duplicate mobile is rejected before owner creation', function () {
    $admin = createOwnerAdminUser();
    $existingMobile = MobileNumber::normalize('9876543210');
    User::factory()->create([
        'mobile' => $existingMobile,
        'is_admin' => false,
        'admin_role' => null,
    ]);
    $batch = createOwnerBatch($admin);
    $intake = createOwnerIntake(null);
    $item = createOwnerItem($batch, ['biodata_intake_id' => $intake->id]);
    $usersBefore = User::count();

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.create-owner.store', [$batch, $item]), [
            'new_name' => 'Duplicate Mobile Owner',
            'new_mobile' => '+91 98765 43210',
            'registering_for' => 'relative',
            'consent_confirmed' => '1',
        ])
        ->assertSessionHasErrors('new_mobile');

    expect(User::count())->toBe($usersBefore)
        ->and($intake->fresh()->uploaded_by)->toBeNull();
});

test('already assigned intake cannot create new owner', function () {
    $admin = createOwnerAdminUser();
    $existingOwner = createOwnerMemberUser();
    $batch = createOwnerBatch($admin);
    $intake = createOwnerIntake($existingOwner);
    $item = createOwnerItem($batch, ['biodata_intake_id' => $intake->id]);
    $usersBefore = User::count();

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.create-owner.store', [$batch, $item]), [
            'new_name' => 'Blocked New Owner',
            'new_mobile' => '9876543210',
            'registering_for' => 'friend',
            'consent_confirmed' => '1',
        ])
        ->assertRedirect(route('admin.bulk-intakes.show', $batch))
        ->assertSessionHas('error', 'Owner already assigned. Reassignment is not available in this phase.');

    expect(User::count())->toBe($usersBefore)
        ->and((int) $intake->fresh()->uploaded_by)->toBe((int) $existingOwner->id);
});

test('non admin cannot access create owner routes', function () {
    $admin = createOwnerAdminUser();
    $member = createOwnerMemberUser();
    $batch = createOwnerBatch($admin);
    $intake = createOwnerIntake(null);
    $item = createOwnerItem($batch, ['biodata_intake_id' => $intake->id]);
    $usersBefore = User::count();

    $this->actingAs($member)
        ->get(route('admin.bulk-intakes.items.create-owner', [$batch, $item]))
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('admin.bulk-intakes.items.create-owner.store', [$batch, $item]), [
            'new_name' => 'Forbidden Owner',
            'new_mobile' => '9876543210',
            'registering_for' => 'self',
            'consent_confirmed' => '1',
        ])
        ->assertForbidden();

    expect(User::count())->toBe($usersBefore)
        ->and($intake->fresh()->uploaded_by)->toBeNull();
});

test('create owner does not trigger profile mutation or apply', function () {
    $this->mock(MutationService::class, function ($mock): void {
        $mock->shouldNotReceive('createDraftProfileForUser');
        $mock->shouldNotReceive('applyManualSnapshot');
        $mock->shouldNotReceive('applyFromIntake');
        $mock->shouldNotReceive('applyApprovedIntake');
    });

    $admin = createOwnerAdminUser();
    $batch = createOwnerBatch($admin);
    $intake = createOwnerIntake(null, [
        'approval_snapshot_json' => ['core' => ['full_name' => 'Original Snapshot']],
    ]);
    $item = createOwnerItem($batch, ['biodata_intake_id' => $intake->id]);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.create-owner.store', [$batch, $item]), [
            'new_name' => 'No Mutation Owner',
            'new_mobile' => '9876543210',
            'registering_for' => 'sibling',
            'consent_confirmed' => '1',
        ])
        ->assertRedirect(route('admin.bulk-intakes.show', $batch));

    $intake = $intake->fresh();
    expect(MatrimonyProfile::count())->toBe(0)
        ->and($intake->approved_by_user)->toBeFalse()
        ->and($intake->approval_snapshot_json)->toBe(['core' => ['full_name' => 'Original Snapshot']])
        ->and($intake->matrimony_profile_id)->toBeNull();
});

test('existing assign owner route still works after create owner route addition', function () {
    $admin = createOwnerAdminUser();
    $member = createOwnerMemberUser();
    $batch = createOwnerBatch($admin);
    $intake = createOwnerIntake(null);
    $item = createOwnerItem($batch, ['biodata_intake_id' => $intake->id]);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.assign-owner.store', [$batch, $item]), [
            'owner_user_id' => $member->id,
            'consent_confirmed' => '1',
        ])
        ->assertRedirect(route('admin.bulk-intakes.show', $batch));

    expect((int) $intake->fresh()->uploaded_by)->toBe((int) $member->id);
});

function createOwnerAdminUser(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function createOwnerMemberUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'is_admin' => false,
        'admin_role' => null,
    ], $overrides));
}

function createOwnerBatch(User $admin): BulkIntakeBatch
{
    return BulkIntakeBatch::create([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_name' => 'Create owner batch',
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);
}

function createOwnerItem(BulkIntakeBatch $batch, array $overrides = []): BulkIntakeBatchItem
{
    return BulkIntakeBatchItem::create(array_merge([
        'bulk_intake_batch_id' => $batch->id,
        'item_sequence' => ((int) BulkIntakeBatchItem::where('bulk_intake_batch_id', $batch->id)->max('item_sequence')) + 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'create-owner.pdf',
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ], $overrides));
}

function createOwnerIntake(?User $owner, array $overrides = []): BiodataIntake
{
    return BiodataIntake::create(array_merge([
        'uploaded_by' => $owner?->id,
        'raw_ocr_text' => 'Name: Create Owner Candidate',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}
