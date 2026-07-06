<?php

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin can view apply preview for draft profile linked intake', function () {
    $admin = applyPreviewAdminUser();
    $owner = applyPreviewMemberUser();
    $profile = applyPreviewDraftProfile($owner, [
        'full_name' => 'Owner Default Name',
        'highest_education' => null,
    ]);
    $batch = applyPreviewBatch($admin);
    $intake = applyPreviewParsedIntake($owner, $profile, [
        'parsed_json' => [
            'core' => [
                'full_name' => 'Parsed Candidate',
                'highest_education' => 'MBA',
            ],
        ],
    ]);
    $item = applyPreviewItem($batch, $intake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.items.apply-preview', [$batch, $item]))
        ->assertOk()
        ->assertSee('Preview only', false)
        ->assertSee('Full name', false)
        ->assertSee('Parsed Candidate', false)
        ->assertSee('MBA', false);

    $profile->refresh();
    expect($profile->full_name)->toBe('Owner Default Name')
        ->and($profile->highest_education)->toBeNull();
});

test('preview requires linked profile', function () {
    $admin = applyPreviewAdminUser();
    $owner = applyPreviewMemberUser();
    $batch = applyPreviewBatch($admin);
    $intake = applyPreviewParsedIntake($owner, null);
    $item = applyPreviewItem($batch, $intake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.items.apply-preview', [$batch, $item]))
        ->assertOk()
        ->assertSee('missing_linked_profile', false);

    expect(MatrimonyProfile::count())->toBe(0)
        ->and($intake->fresh()->matrimony_profile_id)->toBeNull();
});

test('preview requires parsed json', function () {
    $admin = applyPreviewAdminUser();
    $owner = applyPreviewMemberUser();
    $profile = applyPreviewDraftProfile($owner);
    $batch = applyPreviewBatch($admin);
    $intake = applyPreviewParsedIntake($owner, $profile, [
        'parsed_json' => [],
    ]);
    $item = applyPreviewItem($batch, $intake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.items.apply-preview', [$batch, $item]))
        ->assertOk()
        ->assertSee('missing_parsed_json', false);
});

test('preview does not mutate profile', function () {
    $admin = applyPreviewAdminUser();
    $owner = applyPreviewMemberUser();
    $profile = applyPreviewDraftProfile($owner, [
        'full_name' => 'Existing Profile Name',
        'date_of_birth' => '1990-01-01',
    ]);
    $before = $profile->fresh()->getAttributes();
    $batch = applyPreviewBatch($admin);
    $intake = applyPreviewParsedIntake($owner, $profile, [
        'parsed_json' => [
            'core' => [
                'full_name' => 'Parsed Replacement Name',
                'date_of_birth' => '1995-05-15',
            ],
        ],
    ]);
    $item = applyPreviewItem($batch, $intake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.items.apply-preview', [$batch, $item]))
        ->assertOk()
        ->assertSee('Parsed Replacement Name', false)
        ->assertSee('1995-05-15', false);

    expect($profile->fresh()->getAttributes())->toBe($before);
});

test('non admin cannot access preview', function () {
    $admin = applyPreviewAdminUser();
    $member = applyPreviewMemberUser();
    $owner = applyPreviewMemberUser();
    $profile = applyPreviewDraftProfile($owner);
    $batch = applyPreviewBatch($admin);
    $intake = applyPreviewParsedIntake($owner, $profile);
    $item = applyPreviewItem($batch, $intake);

    $this->actingAs($member)
        ->get(route('admin.bulk-intakes.items.apply-preview', [$batch, $item]))
        ->assertForbidden();
});

test('unsupported fields are marked review safely', function () {
    $admin = applyPreviewAdminUser();
    $owner = applyPreviewMemberUser();
    $profile = applyPreviewDraftProfile($owner);
    $batch = applyPreviewBatch($admin);
    $intake = applyPreviewParsedIntake($owner, $profile, [
        'parsed_json' => [
            'core' => [
                'full_name' => 'Parsed Candidate',
                'unknown_field_for_preview' => 'Unexpected value',
            ],
        ],
    ]);
    $item = applyPreviewItem($batch, $intake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.items.apply-preview', [$batch, $item]))
        ->assertOk()
        ->assertSee('unknown_field_for_preview', false)
        ->assertSee('unsupported_field', false)
        ->assertSee('review', false);
});

function applyPreviewAdminUser(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function applyPreviewMemberUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'is_admin' => false,
        'admin_role' => null,
        'registering_for' => 'self',
    ], $overrides));
}

function applyPreviewDraftProfile(User $owner, array $overrides = []): MatrimonyProfile
{
    return MatrimonyProfile::factory()->create(array_merge([
        'user_id' => $owner->id,
        'full_name' => 'Preview Draft Profile',
        'lifecycle_state' => 'draft',
    ], $overrides));
}

function applyPreviewBatch(User $admin): BulkIntakeBatch
{
    return BulkIntakeBatch::create([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_name' => 'Apply preview batch',
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);
}

function applyPreviewItem(BulkIntakeBatch $batch, BiodataIntake $intake, array $overrides = []): BulkIntakeBatchItem
{
    return BulkIntakeBatchItem::create(array_merge([
        'bulk_intake_batch_id' => $batch->id,
        'biodata_intake_id' => $intake->id,
        'item_sequence' => ((int) BulkIntakeBatchItem::where('bulk_intake_batch_id', $batch->id)->max('item_sequence')) + 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'apply-preview.pdf',
        'item_status' => BulkIntakeBatchItem::STATUS_PROFILE_DRAFT_CREATED,
    ], $overrides));
}

function applyPreviewParsedIntake(User $owner, ?MatrimonyProfile $profile, array $overrides = []): BiodataIntake
{
    return BiodataIntake::create(array_merge([
        'uploaded_by' => $owner->id,
        'matrimony_profile_id' => $profile?->id,
        'raw_ocr_text' => 'Name: Apply Preview Candidate',
        'parsed_json' => ['core' => ['full_name' => 'Ready Preview Candidate']],
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}
