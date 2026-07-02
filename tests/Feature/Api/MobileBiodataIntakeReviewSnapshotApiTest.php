<?php

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\Intake\IntakeHumanReviewSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('authenticated owner can save mobile reviewed snapshot without mutating profile or evidence', function () {
    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create([
        'user_id' => $user->id,
        'full_name' => 'Profile Before Review',
    ]);
    $parsed = [
        'snapshot_schema_version' => 1,
        'core' => [
            'full_name' => 'Parsed Candidate',
            'date_of_birth' => '1996-05-04',
            'birth_time' => '10:15',
        ],
        'contacts' => [
            [
                'phone_number' => '9876543210',
                'relation_type' => 'self',
                'contact_name' => 'Self',
                'is_primary' => 1,
            ],
        ],
    ];

    $intake = BiodataIntake::create([
        'uploaded_by' => $user->id,
        'matrimony_profile_id' => $profile->id,
        'raw_ocr_text' => 'Original OCR text',
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parsed_json' => $parsed,
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);

    $attempt = BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_ML_KIT_FLUTTER,
        'source' => 'mobile_app',
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'ML Kit evidence text',
        'normalized_text' => 'ML Kit evidence text',
    ]);

    Sanctum::actingAs($user);

    $response = $this->patchJson('/api/v1/biodata-intakes/'.$intake->id.'/review-snapshot', [
        'reviewed_snapshot' => [
            'core' => [
                'full_name' => 'Corrected Candidate',
                'date_of_birth' => '1996-05-04',
                'birth_time' => '10:15',
            ],
            'contacts' => [
                [
                    'phone_number' => '9876543210',
                    'relation_type' => 'self',
                    'contact_name' => 'Self',
                    'is_primary' => 1,
                ],
            ],
            'unsupported_section' => [
                'full_name' => 'Should be ignored',
            ],
        ],
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('intake_id', $intake->id)
        ->assertJsonPath('approval_status', IntakeHumanReviewSnapshotService::STATUS_REVIEWED)
        ->assertJsonPath('review_actor_type', IntakeHumanReviewSnapshotService::ACTOR_PROFILE_USER)
        ->assertJsonPath('review_surface', IntakeHumanReviewSnapshotService::SURFACE_MOBILE_APP)
        ->assertJsonPath('approval_snapshot.core.full_name', 'Corrected Candidate');

    $intake->refresh();
    $attempt->refresh();
    $profile->refresh();

    expect(data_get($intake->approval_snapshot_json, 'core.full_name'))->toBe('Corrected Candidate')
        ->and($intake->review_actor_type)->toBe(IntakeHumanReviewSnapshotService::ACTOR_PROFILE_USER)
        ->and($intake->review_surface)->toBe(IntakeHumanReviewSnapshotService::SURFACE_MOBILE_APP)
        ->and((int) $intake->reviewed_by_user_id)->toBe((int) $user->id)
        ->and($intake->approval_policy)->toBe(IntakeHumanReviewSnapshotService::POLICY_PHASE2C_PROFILE_USER_REVIEW_V1)
        ->and($intake->approval_status)->toBe(IntakeHumanReviewSnapshotService::STATUS_REVIEWED)
        ->and($intake->reviewed_at)->not->toBeNull()
        ->and(data_get($intake->parsed_json, 'core.full_name'))->toBe('Parsed Candidate')
        ->and($intake->raw_ocr_text)->toBe('Original OCR text')
        ->and($intake->approved_by_user)->toBeFalse()
        ->and($intake->approved_at)->toBeNull()
        ->and($attempt->raw_text)->toBe('ML Kit evidence text')
        ->and($profile->full_name)->toBe('Profile Before Review');

    expect($intake->approval_snapshot_json)->not->toHaveKey('unsupported_section');
    expect(BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count())->toBe(1);
});

test('another user cannot save reviewed snapshot for someone elses intake', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $intake = BiodataIntake::create([
        'uploaded_by' => $owner->id,
        'raw_ocr_text' => 'Owner OCR text',
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Owner Candidate',
            ],
        ],
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);

    Sanctum::actingAs($other);

    $this->patchJson('/api/v1/biodata-intakes/'.$intake->id.'/review-snapshot', [
        'reviewed_snapshot' => [
            'core' => [
                'full_name' => 'Wrong User Edit',
            ],
        ],
    ])->assertNotFound();

    $intake->refresh();
    expect($intake->approval_snapshot_json)->toBeNull()
        ->and($intake->review_actor_type)->toBeNull()
        ->and(data_get($intake->parsed_json, 'core.full_name'))->toBe('Owner Candidate')
        ->and($intake->raw_ocr_text)->toBe('Owner OCR text');
});

test('mobile approve endpoint remains reachable separately from review snapshot', function () {
    $user = User::factory()->create();
    $intake = BiodataIntake::create([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'Owner OCR text',
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Owner Candidate',
            ],
        ],
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/biodata-intakes/'.$intake->id.'/approve', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('snapshot');
});
