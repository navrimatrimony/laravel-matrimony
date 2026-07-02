<?php

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\Intake\IntakeHumanReviewSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('human review snapshot service stores actor neutral metadata without mutating parse or OCR evidence', function (
    string $actorType,
    string $reviewSurface,
) {
    $user = User::factory()->create();
    $parsedSnapshot = [
        'snapshot_schema_version' => 1,
        'core' => [
            'full_name' => 'Parsed Candidate',
            'date_of_birth' => '1996-11-16',
        ],
    ];
    $reviewedSnapshot = [
        'snapshot_schema_version' => 1,
        'core' => [
            'full_name' => 'Reviewed Candidate',
            'date_of_birth' => '1996-11-16',
        ],
    ];

    $intake = BiodataIntake::create([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'parsed candidate biodata text',
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parsed_json' => $parsedSnapshot,
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);
    $attempt = BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'source' => 'test',
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'ocr evidence text',
        'is_primary' => true,
    ]);
    $profileCountBefore = MatrimonyProfile::count();

    $reviewed = app(IntakeHumanReviewSnapshotService::class)->saveReviewedSnapshot($intake, $reviewedSnapshot, [
        'reviewed_by_user_id' => $user->id,
        'review_actor_type' => $actorType,
        'review_surface' => $reviewSurface,
        'approval_policy' => 'phase2a_test_policy',
        'approval_status' => IntakeHumanReviewSnapshotService::STATUS_REVIEWED,
    ]);

    expect($reviewed->approval_snapshot_json)->toBe($reviewedSnapshot)
        ->and($reviewed->parsed_json)->toBe($parsedSnapshot)
        ->and($reviewed->reviewed_by_user_id)->toBe($user->id)
        ->and($reviewed->review_actor_type)->toBe($actorType)
        ->and($reviewed->review_surface)->toBe($reviewSurface)
        ->and($reviewed->approval_policy)->toBe('phase2a_test_policy')
        ->and($reviewed->approval_status)->toBe(IntakeHumanReviewSnapshotService::STATUS_REVIEWED)
        ->and($reviewed->reviewed_at)->not->toBeNull()
        ->and(BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count())->toBe(1)
        ->and($attempt->fresh()->raw_text)->toBe('ocr evidence text')
        ->and(MatrimonyProfile::count())->toBe($profileCountBefore);
})->with([
    'admin from admin panel' => [
        IntakeHumanReviewSnapshotService::ACTOR_ADMIN,
        IntakeHumanReviewSnapshotService::SURFACE_ADMIN_PANEL,
    ],
    'profile user from mobile app' => [
        IntakeHumanReviewSnapshotService::ACTOR_PROFILE_USER,
        IntakeHumanReviewSnapshotService::SURFACE_MOBILE_APP,
    ],
    'suchak from website' => [
        IntakeHumanReviewSnapshotService::ACTOR_SUCHAK,
        IntakeHumanReviewSnapshotService::SURFACE_WEBSITE,
    ],
]);
