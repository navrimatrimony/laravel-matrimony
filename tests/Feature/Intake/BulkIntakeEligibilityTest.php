<?php

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\BulkIntakeIdentityHistory;
use App\Models\User;
use App\Services\Intake\BulkIntakeCandidateScreeningReviewService;
use App\Services\Intake\BulkIntakeDuplicateGateService;
use App\Services\Intake\BulkIntakeEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('eligibleForPipeline marks complete candidate as eligible', function () {
    $item = eligibilityPipelineFixtureItem([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Eligible Pipeline Candidate',
                'primary_contact_number' => '9876543101',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
            ],
        ],
    ]);

    $pipeline = app(BulkIntakeEligibilityService::class)->eligibleForPipeline($item);

    expect($pipeline['eligible'])->toBeTrue()
        ->and($pipeline['bucket'])->toBe(BulkIntakeEligibilityService::FILTER_ELIGIBLE)
        ->and($pipeline['source'])->toBe('auto');
});

test('eligibleForPipeline blocks candidate without mobile', function () {
    $item = eligibilityPipelineFixtureItem([
        'parsed_json' => [
            'core' => [
                'full_name' => 'No Mobile Candidate',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
            ],
        ],
    ]);

    $pipeline = app(BulkIntakeEligibilityService::class)->eligibleForPipeline($item);

    expect($pipeline['eligible'])->toBeFalse()
        ->and($pipeline['bucket'])->toBe(BulkIntakeEligibilityService::FILTER_NEEDS_CHECK)
        ->and($pipeline['reason_codes'])->toContain('missing_mobile');
});

test('eligibleForPipeline blocks candidate with married history', function () {
    BulkIntakeIdentityHistory::query()->create([
        'reason_code' => BulkIntakeIdentityHistory::REASON_ALREADY_MARRIED,
        'normalized_mobile' => '9876543102',
        'source_type' => BulkIntakeIdentityHistory::SOURCE_ADMIN_SCREENING,
    ]);

    $item = eligibilityPipelineFixtureItem([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Married History Candidate',
                'primary_contact_number' => '9876543102',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
            ],
        ],
    ]);

    $pipeline = app(BulkIntakeEligibilityService::class)->eligibleForPipeline($item);

    expect($pipeline['eligible'])->toBeFalse()
        ->and($pipeline['bucket'])->toBe(BulkIntakeEligibilityService::FILTER_BLOCKED)
        ->and($pipeline['reason_codes'])->toContain('already_married');
});

test('name and dob match without secondary confirmation stays needs check not blocked', function () {
    eligibilityPipelineIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Shared Name Candidate',
                'primary_contact_number' => '9876543103',
                'date_of_birth' => '1995-03-20',
                'gender' => 'male',
                'highest_education' => 'B.E.',
            ],
        ],
    ]);

    $item = eligibilityPipelineFixtureItem([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Shared Name Candidate',
                'primary_contact_number' => '9876543199',
                'date_of_birth' => '1995-03-20',
                'gender' => 'female',
                'highest_education' => 'M.Sc.',
            ],
        ],
    ]);

    $gate = app(BulkIntakeDuplicateGateService::class)->evaluateForItem($item);
    $pipeline = app(BulkIntakeEligibilityService::class)->eligibleForPipeline($item);

    expect($gate['auto_blocked'])->toBeFalse()
        ->and($pipeline['bucket'])->toBe(BulkIntakeEligibilityService::FILTER_NEEDS_CHECK)
        ->and($pipeline['reason_codes'])->toContain('name_dob_needs_confirmation');
});

test('name and dob match with gender and education confirms duplicate block', function () {
    eligibilityPipelineIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Confirmed Duplicate Candidate',
                'primary_contact_number' => '9876543104',
                'date_of_birth' => '1992-08-11',
                'gender' => 'female',
                'highest_education' => 'B.Pharm',
            ],
        ],
    ]);

    $item = eligibilityPipelineFixtureItem([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Confirmed Duplicate Candidate',
                'primary_contact_number' => '9876543188',
                'date_of_birth' => '1992-08-11',
                'gender' => 'female',
                'highest_education' => 'B.Pharm',
            ],
        ],
    ]);

    $pipeline = app(BulkIntakeEligibilityService::class)->eligibleForPipeline($item);

    expect($pipeline['bucket'])->toBe(BulkIntakeEligibilityService::FILTER_BLOCKED)
        ->and($pipeline['reason_codes'])->toContain('auto_duplicate_intake');
});

test('duplicate gate override allows pipeline needs check instead of blocked for history', function () {
    $admin = eligibilityPipelineAdmin();

    BulkIntakeIdentityHistory::query()->create([
        'reason_code' => BulkIntakeIdentityHistory::REASON_NOT_INTERESTED,
        'normalized_mobile' => '9876543105',
        'source_type' => BulkIntakeIdentityHistory::SOURCE_ADMIN_SCREENING,
    ]);

    $item = eligibilityPipelineFixtureItem([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Override Candidate',
                'primary_contact_number' => '9876543105',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
            ],
        ],
    ], [
        'item_meta_json' => [
            'duplicate_gate_override' => [
                'status' => BulkIntakeDuplicateGateService::OVERRIDE_PROCEED,
                'reason' => 'Different person',
                'overridden_by_user_id' => $admin->id,
                'overridden_at' => '2026-07-09T10:00:00+00:00',
                'cleared_by_user_id' => null,
                'cleared_at' => null,
            ],
        ],
    ]);

    $pipeline = app(BulkIntakeEligibilityService::class)->eligibleForPipeline($item);

    expect($pipeline['bucket'])->not->toBe(BulkIntakeEligibilityService::FILTER_BLOCKED);
});

test('batch show renders three primary eligibility filters and pipeline badge', function () {
    $admin = eligibilityPipelineAdmin();
    $batch = eligibilityPipelineBatch($admin);
    $intake = eligibilityPipelineIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Pipeline Badge Candidate',
                'primary_contact_number' => '9876543106',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
            ],
        ],
    ]);
    eligibilityPipelineItem($batch, $intake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('Eligibility gate', false)
        ->assertSee('data-testid="bulk-screening-filter-eligible"', false)
        ->assertSee('data-testid="bulk-screening-filter-blocked"', false)
        ->assertSee('data-testid="bulk-screening-filter-needs_check"', false)
        ->assertSee('More filters', false)
        ->assertSee('data-testid="bulk-screening-filter-ready"', false)
        ->assertSee('data-testid="bulk-pipeline-badge"', false)
        ->assertSee('Eligible', false);
});

test('admin override eligible makes pipeline eligible when identity complete', function () {
    $admin = eligibilityPipelineAdmin();
    $item = eligibilityPipelineFixtureItem([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Override Eligible Candidate',
                'primary_contact_number' => '9876543107',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
            ],
        ],
    ]);

    app(BulkIntakeCandidateScreeningReviewService::class)->saveReview($item, $admin, [
        'status' => BulkIntakeCandidateScreeningReviewService::STATUS_ELIGIBLE,
        'reason_key' => 'admin_verified',
    ]);

    $pipeline = app(BulkIntakeEligibilityService::class)->eligibleForPipeline($item->fresh());

    expect($pipeline['eligible'])->toBeTrue()
        ->and($pipeline['bucket'])->toBe(BulkIntakeEligibilityService::FILTER_ELIGIBLE)
        ->and($pipeline['source'])->toBe('override');
});

function eligibilityPipelineAdmin(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function eligibilityPipelineBatch(User $admin): BulkIntakeBatch
{
    return BulkIntakeBatch::create([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_name' => 'Eligibility pipeline batch',
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);
}

function eligibilityPipelineIntake(array $overrides = []): BiodataIntake
{
    return BiodataIntake::create(array_merge([
        'uploaded_by' => null,
        'raw_ocr_text' => 'Eligibility pipeline OCR',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}

function eligibilityPipelineFixtureItem(array $intakeOverrides = [], array $itemOverrides = []): BulkIntakeBatchItem
{
    $admin = eligibilityPipelineAdmin();
    $batch = eligibilityPipelineBatch($admin);
    $intake = eligibilityPipelineIntake($intakeOverrides);

    return eligibilityPipelineItem($batch, $intake, $itemOverrides);
}

function eligibilityPipelineItem(?BulkIntakeBatch $batch = null, ?BiodataIntake $intake = null, array $overrides = []): BulkIntakeBatchItem
{
    $batch ??= eligibilityPipelineBatch(eligibilityPipelineAdmin());
    $intake ??= eligibilityPipelineIntake();

    return BulkIntakeBatchItem::create(array_merge([
        'bulk_intake_batch_id' => $batch->id,
        'biodata_intake_id' => $intake->id,
        'item_sequence' => ((int) BulkIntakeBatchItem::where('bulk_intake_batch_id', $batch->id)->max('item_sequence')) + 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'eligibility-pipeline.pdf',
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ], $overrides));
}
