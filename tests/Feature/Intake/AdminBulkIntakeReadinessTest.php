<?php

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\Intake\BulkIntakeReadinessService;
use App\Services\MutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('item is ready when owner exists parsed json is present and no profile exists', function () {
    $admin = readinessAdminUser();
    $owner = readinessMemberUser();
    $batch = readinessBatch($admin);
    $intake = readinessIntake($owner, [
        'parse_status' => 'parsed',
        'parsed_json' => ['core' => ['full_name' => 'Ready Candidate']],
    ]);
    $item = readinessItem($batch, [
        'biodata_intake_id' => $intake->id,
        'original_filename' => 'ready-candidate.pdf',
    ]);

    $readiness = app(BulkIntakeReadinessService::class)->readinessForItem($item);

    expect($readiness['status'])->toBe('ready_for_profile_review')
        ->and($readiness['ready'])->toBeTrue()
        ->and($readiness['reason_codes'])->toBe([])
        ->and(MatrimonyProfile::count())->toBe(0);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('Readiness preview does not create, approve, or apply profiles.', false)
        ->assertSee('Ready for profile review', false)
        ->assertSee('Readiness details', false);
});

test('unclaimed item is not ready with owner unassigned reason', function () {
    $admin = readinessAdminUser();
    $batch = readinessBatch($admin);
    $intake = readinessIntake(null, [
        'parse_status' => 'parsed',
        'parsed_json' => ['core' => ['full_name' => 'Unclaimed Candidate']],
    ]);
    $item = readinessItem($batch, ['biodata_intake_id' => $intake->id]);

    $readiness = app(BulkIntakeReadinessService::class)->readinessForItem($item);

    expect($readiness['status'])->toBe('not_ready')
        ->and($readiness['reason_codes'])->toContain('owner_unassigned');

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.items.readiness', [$batch, $item]))
        ->assertOk()
        ->assertSee('owner_unassigned', false);
});

test('parse pending item is not ready', function () {
    $owner = readinessMemberUser();
    $batch = readinessBatch(readinessAdminUser());
    $intake = readinessIntake($owner, ['parse_status' => 'pending']);
    $item = readinessItem($batch, ['biodata_intake_id' => $intake->id]);

    $readiness = app(BulkIntakeReadinessService::class)->readinessForItem($item);

    expect($readiness['status'])->toBe('not_ready')
        ->and($readiness['reason_codes'])->toContain('parse_pending');
});

test('parse error item is not ready', function () {
    $owner = readinessMemberUser();
    $batch = readinessBatch(readinessAdminUser());
    $intake = readinessIntake($owner, [
        'parse_status' => 'error',
        'last_error' => 'Parser failed',
    ]);
    $item = readinessItem($batch, ['biodata_intake_id' => $intake->id]);

    $readiness = app(BulkIntakeReadinessService::class)->readinessForItem($item);

    expect($readiness['status'])->toBe('not_ready')
        ->and($readiness['reason_codes'])->toContain('parse_error');
});

test('needs review item is not ready', function () {
    $owner = readinessMemberUser();
    $batch = readinessBatch(readinessAdminUser());
    $intake = readinessIntake($owner, [
        'parse_status' => 'parsed',
        'parsed_json' => ['core' => ['full_name' => 'Review Candidate']],
    ]);
    $item = readinessItem($batch, [
        'biodata_intake_id' => $intake->id,
        'item_status' => BulkIntakeBatchItem::STATUS_NEEDS_REVIEW,
    ]);

    $readiness = app(BulkIntakeReadinessService::class)->readinessForItem($item);

    expect($readiness['status'])->toBe('not_ready')
        ->and($readiness['reason_codes'])->toContain('needs_review');
});

test('owner with existing matrimony profile is blocked', function () {
    $owner = readinessMemberUser();
    MatrimonyProfile::factory()->create(['user_id' => $owner->id]);
    $batch = readinessBatch(readinessAdminUser());
    $intake = readinessIntake($owner, [
        'parse_status' => 'parsed',
        'parsed_json' => ['core' => ['full_name' => 'Profile Exists Candidate']],
    ]);
    $item = readinessItem($batch, ['biodata_intake_id' => $intake->id]);

    $readiness = app(BulkIntakeReadinessService::class)->readinessForItem($item);

    expect($readiness['status'])->toBe('blocked')
        ->and($readiness['reason_codes'])->toContain('already_has_profile');
});

test('locked or approved intake is blocked', function () {
    $owner = readinessMemberUser();
    $batch = readinessBatch(readinessAdminUser());
    $lockedIntake = readinessIntake($owner, [
        'parse_status' => 'parsed',
        'parsed_json' => ['core' => ['full_name' => 'Locked Candidate']],
        'intake_locked' => true,
    ]);
    $approvedIntake = readinessIntake($owner, [
        'parse_status' => 'parsed',
        'parsed_json' => ['core' => ['full_name' => 'Approved Candidate']],
        'approved_by_user' => true,
    ]);
    $lockedItem = readinessItem($batch, ['biodata_intake_id' => $lockedIntake->id]);
    $approvedItem = readinessItem($batch, ['biodata_intake_id' => $approvedIntake->id]);

    $lockedReadiness = app(BulkIntakeReadinessService::class)->readinessForItem($lockedItem);
    $approvedReadiness = app(BulkIntakeReadinessService::class)->readinessForItem($approvedItem);

    expect($lockedReadiness['status'])->toBe('blocked')
        ->and($lockedReadiness['reason_codes'])->toContain('intake_locked')
        ->and($approvedReadiness['status'])->toBe('blocked')
        ->and($approvedReadiness['reason_codes'])->toContain('intake_approved_already');
});

test('ready not ready and blocked filters work', function () {
    $admin = readinessAdminUser();
    $readyOwner = readinessMemberUser();
    $blockedOwner = readinessMemberUser();
    MatrimonyProfile::factory()->create(['user_id' => $blockedOwner->id]);
    $batch = readinessBatch($admin);

    $readyIntake = readinessIntake($readyOwner, [
        'parse_status' => 'parsed',
        'parsed_json' => ['core' => ['full_name' => 'Ready Candidate']],
    ]);
    $notReadyIntake = readinessIntake(null, ['parse_status' => 'pending']);
    $blockedIntake = readinessIntake($blockedOwner, [
        'parse_status' => 'parsed',
        'parsed_json' => ['core' => ['full_name' => 'Blocked Candidate']],
    ]);

    readinessItem($batch, ['biodata_intake_id' => $readyIntake->id, 'original_filename' => 'green-filter.pdf']);
    readinessItem($batch, ['biodata_intake_id' => $notReadyIntake->id, 'original_filename' => 'amber-filter.pdf']);
    readinessItem($batch, ['biodata_intake_id' => $blockedIntake->id, 'original_filename' => 'red-filter.pdf']);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', ['bulkIntakeBatch' => $batch, 'status' => 'ready']))
        ->assertOk()
        ->assertSee('green-filter.pdf', false)
        ->assertDontSee('amber-filter.pdf', false)
        ->assertDontSee('red-filter.pdf', false);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', ['bulkIntakeBatch' => $batch, 'status' => 'not_ready']))
        ->assertOk()
        ->assertSee('amber-filter.pdf', false)
        ->assertDontSee('green-filter.pdf', false)
        ->assertDontSee('red-filter.pdf', false);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', ['bulkIntakeBatch' => $batch, 'status' => 'blocked']))
        ->assertOk()
        ->assertSee('red-filter.pdf', false)
        ->assertDontSee('green-filter.pdf', false)
        ->assertDontSee('amber-filter.pdf', false);
});

test('readiness does not call profile mutation or apply', function () {
    $this->mock(MutationService::class, function ($mock): void {
        $mock->shouldNotReceive('createDraftProfileForUser');
        $mock->shouldNotReceive('applyManualSnapshot');
        $mock->shouldNotReceive('applyFromIntake');
        $mock->shouldNotReceive('applyApprovedIntake');
    });

    $admin = readinessAdminUser();
    $owner = readinessMemberUser();
    $batch = readinessBatch($admin);
    $intake = readinessIntake($owner, [
        'raw_ocr_text' => 'Immutable OCR readiness',
        'parse_status' => 'parsed',
        'parsed_json' => ['core' => ['full_name' => 'No Mutation Candidate']],
        'approval_snapshot_json' => ['core' => ['full_name' => 'Snapshot Candidate']],
    ]);
    $item = readinessItem($batch, ['biodata_intake_id' => $intake->id]);
    $profileCountBefore = MatrimonyProfile::count();

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk();

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.items.readiness', [$batch, $item]))
        ->assertOk();

    $intake = $intake->fresh();
    expect(MatrimonyProfile::count())->toBe($profileCountBefore)
        ->and($intake->raw_ocr_text)->toBe('Immutable OCR readiness')
        ->and($intake->parsed_json)->toBe(['core' => ['full_name' => 'No Mutation Candidate']])
        ->and($intake->approval_snapshot_json)->toBe(['core' => ['full_name' => 'Snapshot Candidate']])
        ->and($intake->approved_by_user)->toBeFalse();
});

function readinessAdminUser(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function readinessMemberUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'is_admin' => false,
        'admin_role' => null,
    ], $overrides));
}

function readinessBatch(User $admin): BulkIntakeBatch
{
    return BulkIntakeBatch::create([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_name' => 'Readiness batch',
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);
}

function readinessItem(BulkIntakeBatch $batch, array $overrides = []): BulkIntakeBatchItem
{
    return BulkIntakeBatchItem::create(array_merge([
        'bulk_intake_batch_id' => $batch->id,
        'item_sequence' => ((int) BulkIntakeBatchItem::where('bulk_intake_batch_id', $batch->id)->max('item_sequence')) + 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'readiness.pdf',
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ], $overrides));
}

function readinessIntake(?User $owner, array $overrides = []): BiodataIntake
{
    return BiodataIntake::create(array_merge([
        'uploaded_by' => $owner?->id,
        'raw_ocr_text' => 'Name: Readiness Candidate',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}
