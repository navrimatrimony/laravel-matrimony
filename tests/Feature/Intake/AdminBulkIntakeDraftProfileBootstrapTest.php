<?php

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\IntakeSourceContext;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin can bootstrap draft profile for ready item', function () {
    $admin = draftBootstrapAdminUser();
    $owner = draftBootstrapMemberUser([
        'name' => 'Owner Default Name',
        'registering_for' => 'self',
    ]);
    $batch = draftBootstrapBatch($admin);
    $intake = draftBootstrapIntake($owner, [
        'raw_ocr_text' => 'Immutable OCR bootstrap',
        'parse_status' => 'parsed',
        'parsed_json' => ['core' => ['full_name' => 'Parsed Candidate']],
        'approval_snapshot_json' => ['core' => ['full_name' => 'Original Snapshot']],
    ]);
    $item = draftBootstrapItem($batch, ['biodata_intake_id' => $intake->id]);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.bootstrap-draft-profile', [$batch, $item]), [
            'bootstrap_confirmed' => '1',
        ])
        ->assertRedirect(route('admin.bulk-intakes.show', $batch));

    $profile = MatrimonyProfile::query()->where('user_id', $owner->id)->sole();
    $intake = $intake->fresh();
    $item = $item->fresh();
    $context = IntakeSourceContext::query()
        ->where('biodata_intake_id', $intake->id)
        ->where('source_type', IntakeSourceContext::SOURCE_ADMIN_MANUAL)
        ->sole();

    expect($profile->lifecycle_state)->toBe('draft')
        ->and((int) $intake->matrimony_profile_id)->toBe((int) $profile->id)
        ->and($intake->approved_by_user)->toBeFalse()
        ->and($intake->raw_ocr_text)->toBe('Immutable OCR bootstrap')
        ->and($intake->parsed_json)->toBe(['core' => ['full_name' => 'Parsed Candidate']])
        ->and($intake->approval_snapshot_json)->toBe(['core' => ['full_name' => 'Original Snapshot']])
        ->and($item->item_status)->toBe(BulkIntakeBatchItem::STATUS_PROFILE_DRAFT_CREATED)
        ->and($item->item_meta_json['draft_profile_id'])->toBe($profile->id)
        ->and($context->source_meta_json['action'])->toBe('draft_profile_bootstrapped')
        ->and($context->source_meta_json['profile_id'])->toBe($profile->id)
        ->and($context->source_meta_json['no_parsed_fields_applied'])->toBeTrue();
});

test('cannot bootstrap without confirmation', function () {
    $admin = draftBootstrapAdminUser();
    $owner = draftBootstrapMemberUser();
    $batch = draftBootstrapBatch($admin);
    $intake = draftBootstrapReadyIntake($owner);
    $item = draftBootstrapItem($batch, ['biodata_intake_id' => $intake->id]);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.bootstrap-draft-profile', [$batch, $item]))
        ->assertSessionHasErrors('bootstrap_confirmed');

    expect(MatrimonyProfile::count())->toBe(0)
        ->and($intake->fresh()->matrimony_profile_id)->toBeNull();
});

test('unclaimed item cannot bootstrap', function () {
    $admin = draftBootstrapAdminUser();
    $batch = draftBootstrapBatch($admin);
    $intake = draftBootstrapReadyIntake(null);
    $item = draftBootstrapItem($batch, ['biodata_intake_id' => $intake->id]);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.bootstrap-draft-profile', [$batch, $item]), [
            'bootstrap_confirmed' => '1',
        ])
        ->assertSessionHasErrors('bootstrap_confirmed');

    expect(MatrimonyProfile::count())->toBe(0)
        ->and($intake->fresh()->matrimony_profile_id)->toBeNull();
});

test('parse pending or error item cannot bootstrap', function () {
    $admin = draftBootstrapAdminUser();
    $owner = draftBootstrapMemberUser();
    $batch = draftBootstrapBatch($admin);
    $pendingIntake = draftBootstrapIntake($owner, ['parse_status' => 'pending']);
    $errorIntake = draftBootstrapIntake($owner, [
        'parse_status' => 'error',
        'last_error' => 'Parser failed',
    ]);
    $pendingItem = draftBootstrapItem($batch, ['biodata_intake_id' => $pendingIntake->id]);
    $errorItem = draftBootstrapItem($batch, ['biodata_intake_id' => $errorIntake->id]);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.bootstrap-draft-profile', [$batch, $pendingItem]), [
            'bootstrap_confirmed' => '1',
        ])
        ->assertSessionHasErrors('bootstrap_confirmed');

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.bootstrap-draft-profile', [$batch, $errorItem]), [
            'bootstrap_confirmed' => '1',
        ])
        ->assertSessionHasErrors('bootstrap_confirmed');

    expect(MatrimonyProfile::count())->toBe(0);
});

test('needs review item cannot bootstrap', function () {
    $admin = draftBootstrapAdminUser();
    $owner = draftBootstrapMemberUser();
    $batch = draftBootstrapBatch($admin);
    $intake = draftBootstrapReadyIntake($owner);
    $item = draftBootstrapItem($batch, [
        'biodata_intake_id' => $intake->id,
        'item_status' => BulkIntakeBatchItem::STATUS_NEEDS_REVIEW,
    ]);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.bootstrap-draft-profile', [$batch, $item]), [
            'bootstrap_confirmed' => '1',
        ])
        ->assertSessionHasErrors('bootstrap_confirmed');

    expect(MatrimonyProfile::count())->toBe(0);
});

test('owner already has profile blocks bootstrap', function () {
    $admin = draftBootstrapAdminUser();
    $owner = draftBootstrapMemberUser();
    MatrimonyProfile::factory()->create(['user_id' => $owner->id]);
    $batch = draftBootstrapBatch($admin);
    $intake = draftBootstrapReadyIntake($owner);
    $item = draftBootstrapItem($batch, ['biodata_intake_id' => $intake->id]);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.bootstrap-draft-profile', [$batch, $item]), [
            'bootstrap_confirmed' => '1',
        ])
        ->assertSessionHasErrors('bootstrap_confirmed');

    expect(MatrimonyProfile::query()->where('user_id', $owner->id)->count())->toBe(1)
        ->and($intake->fresh()->matrimony_profile_id)->toBeNull();
});

test('bootstrap does not apply parsed fields', function () {
    $admin = draftBootstrapAdminUser();
    $owner = draftBootstrapMemberUser([
        'name' => 'Owner Default Name',
        'registering_for' => 'self',
    ]);
    $batch = draftBootstrapBatch($admin);
    $intake = draftBootstrapReadyIntake($owner, [
        'parsed_json' => [
            'core' => [
                'full_name' => 'Parsed Biodata Name',
                'highest_education' => 'Parsed Education',
            ],
            'career_history' => [
                ['company_name' => 'Parsed Company'],
            ],
        ],
    ]);
    $item = draftBootstrapItem($batch, ['biodata_intake_id' => $intake->id]);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.bootstrap-draft-profile', [$batch, $item]), [
            'bootstrap_confirmed' => '1',
        ])
        ->assertRedirect(route('admin.bulk-intakes.show', $batch));

    $profile = MatrimonyProfile::query()->where('user_id', $owner->id)->sole();

    expect($profile->full_name)->toBe('Owner Default Name')
        ->and($profile->full_name)->not->toBe('Parsed Biodata Name')
        ->and($profile->highest_education)->toBeNull()
        ->and($profile->company_name)->toBeNull();
});

test('non admin cannot bootstrap', function () {
    $admin = draftBootstrapAdminUser();
    $member = draftBootstrapMemberUser();
    $owner = draftBootstrapMemberUser();
    $batch = draftBootstrapBatch($admin);
    $intake = draftBootstrapReadyIntake($owner);
    $item = draftBootstrapItem($batch, ['biodata_intake_id' => $intake->id]);

    $this->actingAs($member)
        ->post(route('admin.bulk-intakes.items.bootstrap-draft-profile', [$batch, $item]), [
            'bootstrap_confirmed' => '1',
        ])
        ->assertForbidden();

    expect(MatrimonyProfile::count())->toBe(0)
        ->and($intake->fresh()->matrimony_profile_id)->toBeNull();
});

test('bootstrap cannot duplicate profile on second call', function () {
    $admin = draftBootstrapAdminUser();
    $owner = draftBootstrapMemberUser();
    $batch = draftBootstrapBatch($admin);
    $intake = draftBootstrapReadyIntake($owner);
    $item = draftBootstrapItem($batch, ['biodata_intake_id' => $intake->id]);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.bootstrap-draft-profile', [$batch, $item]), [
            'bootstrap_confirmed' => '1',
        ])
        ->assertRedirect(route('admin.bulk-intakes.show', $batch));

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.bootstrap-draft-profile', [$batch, $item]), [
            'bootstrap_confirmed' => '1',
        ])
        ->assertSessionHasErrors('bootstrap_confirmed');

    expect(MatrimonyProfile::query()->where('user_id', $owner->id)->count())->toBe(1);
});

function draftBootstrapAdminUser(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function draftBootstrapMemberUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'is_admin' => false,
        'admin_role' => null,
        'registering_for' => 'self',
    ], $overrides));
}

function draftBootstrapBatch(User $admin): BulkIntakeBatch
{
    return BulkIntakeBatch::create([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_name' => 'Draft bootstrap batch',
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);
}

function draftBootstrapItem(BulkIntakeBatch $batch, array $overrides = []): BulkIntakeBatchItem
{
    return BulkIntakeBatchItem::create(array_merge([
        'bulk_intake_batch_id' => $batch->id,
        'item_sequence' => ((int) BulkIntakeBatchItem::where('bulk_intake_batch_id', $batch->id)->max('item_sequence')) + 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'draft-bootstrap.pdf',
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ], $overrides));
}

function draftBootstrapReadyIntake(?User $owner, array $overrides = []): BiodataIntake
{
    return draftBootstrapIntake($owner, array_merge([
        'parse_status' => 'parsed',
        'parsed_json' => ['core' => ['full_name' => 'Ready Candidate']],
    ], $overrides));
}

function draftBootstrapIntake(?User $owner, array $overrides = []): BiodataIntake
{
    return BiodataIntake::create(array_merge([
        'uploaded_by' => $owner?->id,
        'raw_ocr_text' => 'Name: Draft Bootstrap Candidate',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}
