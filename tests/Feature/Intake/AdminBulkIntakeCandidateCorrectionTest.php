<?php

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\Intake\IntakeHumanReviewSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin can open bulk candidate correction page', function () {
    $admin = candidateCorrectionAdminUser();
    $batch = candidateCorrectionBatch($admin);
    $intake = candidateCorrectionIntake([
        'last_parse_input_text' => "नाव : Parsed Candidate\nमोबाईल : 9876543210",
        'parsed_json' => [
            'core' => [
                'full_name' => 'Parsed Candidate',
                'primary_contact_number' => '9876543210',
                'date_of_birth' => '1998-04-15',
                'height_cm' => 165,
                'gender' => 'female',
                'highest_education' => 'MCA',
                'city_text' => 'Pune',
            ],
        ],
        'field_confidence_json' => [
            'full_name' => [
                'score' => 0.40,
                'is_low' => true,
                'source_path' => 'core.full_name',
            ],
        ],
    ]);
    $item = candidateCorrectionItem($batch, $intake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]))
        ->assertOk()
        ->assertSee('Bulk Candidate Correction', false)
        ->assertSee('Only these 7 fields are editable in this phase.', false)
        ->assertSee('Parsed Candidate', false)
        ->assertSee('9876543210', false)
        ->assertSee('1998-04-15', false)
        ->assertSee('165 cm', false)
        ->assertSee('MCA', false)
        ->assertSee('Pune', false)
        ->assertSee('नाव : Parsed Candidate', false)
        ->assertSee('data-testid="bulk-correction-low-confidence-name"', false)
        ->assertSee('This saves only a human-reviewed intake snapshot.', false);
});

test('admin can save seven field correction without mutating evidence or bulk item parsed data', function () {
    $admin = candidateCorrectionAdminUser();
    $batch = candidateCorrectionBatch($admin);
    $parsed = [
        'core' => [
            'full_name' => 'Parsed Candidate',
            'primary_contact_number' => '9876500000',
            'date_of_birth' => '1998-04-01',
            'height_cm' => 160,
            'gender' => 'male',
            'highest_education' => 'BSc',
            'city_text' => 'Old City',
            'occupation_title' => 'Existing occupation should stay',
        ],
        'contacts' => [
            [
                'phone_number' => '9876500000',
                'relation_type' => 'self',
                'contact_name' => 'Self',
                'is_primary' => 1,
            ],
        ],
    ];
    $intake = candidateCorrectionIntake([
        'raw_ocr_text' => 'Original OCR text',
        'last_parse_input_text' => 'Parse input text',
        'parsed_json' => $parsed,
    ]);
    $item = candidateCorrectionItem($batch, $intake);
    $userCountBefore = User::query()->count();
    $profileCountBefore = MatrimonyProfile::query()->count();

    $this->actingAs($admin)
        ->patch(route('admin.bulk-intakes.items.correct-candidate.update', [$batch, $item]), [
            'name' => 'Corrected Candidate',
            'mobile' => '+91 98765 43210',
            'date_of_birth' => '15/04/1998',
            'height' => "5'6\"",
            'gender' => 'female',
            'education' => 'MCA',
            'location' => 'Pune',
        ])
        ->assertRedirect(route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]))
        ->assertSessionHas('success');

    $intake->refresh();
    $item->refresh();

    expect(data_get($intake->approval_snapshot_json, 'core.full_name'))->toBe('Corrected Candidate')
        ->and(data_get($intake->approval_snapshot_json, 'core.primary_contact_number'))->toBe('9876543210')
        ->and(data_get($intake->approval_snapshot_json, 'contacts.0.phone_number'))->toBe('9876543210')
        ->and(data_get($intake->approval_snapshot_json, 'core.date_of_birth'))->toBe('1998-04-15')
        ->and(data_get($intake->approval_snapshot_json, 'core.height_cm'))->toBe(168)
        ->and(data_get($intake->approval_snapshot_json, 'core.height'))->toBe('5 ft 6 in')
        ->and(data_get($intake->approval_snapshot_json, 'core.gender'))->toBe('female')
        ->and(data_get($intake->approval_snapshot_json, 'core.highest_education'))->toBe('MCA')
        ->and(data_get($intake->approval_snapshot_json, 'core.city_text'))->toBe('Pune')
        ->and(data_get($intake->approval_snapshot_json, 'core.occupation_title'))->toBe('Existing occupation should stay')
        ->and($intake->review_actor_type)->toBe(IntakeHumanReviewSnapshotService::ACTOR_ADMIN)
        ->and($intake->review_surface)->toBe(IntakeHumanReviewSnapshotService::SURFACE_ADMIN_PANEL)
        ->and($intake->approval_status)->toBe(IntakeHumanReviewSnapshotService::STATUS_REVIEWED)
        ->and((int) $intake->reviewed_by_user_id)->toBe((int) $admin->id)
        ->and($intake->reviewed_at)->not->toBeNull()
        ->and($intake->parsed_json)->toBe($parsed)
        ->and($intake->raw_ocr_text)->toBe('Original OCR text')
        ->and($item->item_meta_json)->toBeNull()
        ->and(User::query()->count())->toBe($userCountBefore)
        ->and(MatrimonyProfile::query()->count())->toBe($profileCountBefore);
});

test('bulk candidate correction save is blocked after intake approval or lock', function () {
    $admin = candidateCorrectionAdminUser();
    $batch = candidateCorrectionBatch($admin);
    $intake = candidateCorrectionIntake([
        'approved_by_user' => true,
        'approved_at' => now(),
        'approval_status' => IntakeHumanReviewSnapshotService::STATUS_APPROVED,
        'approval_snapshot_json' => [
            'core' => [
                'full_name' => 'Approved Candidate',
            ],
        ],
    ]);
    $item = candidateCorrectionItem($batch, $intake);

    $this->actingAs($admin)
        ->patch(route('admin.bulk-intakes.items.correct-candidate.update', [$batch, $item]), [
            'name' => 'Should Not Save',
        ])
        ->assertSessionHasErrors('candidate');

    $intake->refresh();

    expect(data_get($intake->approval_snapshot_json, 'core.full_name'))->toBe('Approved Candidate')
        ->and($intake->raw_ocr_text)->toBe('Original OCR text');
});

function candidateCorrectionAdminUser(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function candidateCorrectionBatch(User $admin): BulkIntakeBatch
{
    return BulkIntakeBatch::create([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_name' => 'Candidate correction batch',
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);
}

function candidateCorrectionIntake(array $overrides = []): BiodataIntake
{
    return BiodataIntake::create(array_merge([
        'uploaded_by' => null,
        'raw_ocr_text' => 'Original OCR text',
        'last_parse_input_text' => null,
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}

function candidateCorrectionItem(BulkIntakeBatch $batch, BiodataIntake $intake, array $overrides = []): BulkIntakeBatchItem
{
    return BulkIntakeBatchItem::create(array_merge([
        'bulk_intake_batch_id' => $batch->id,
        'biodata_intake_id' => $intake->id,
        'item_sequence' => ((int) BulkIntakeBatchItem::where('bulk_intake_batch_id', $batch->id)->max('item_sequence')) + 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'candidate-correction.pdf',
        'source_file_path' => 'bulk-intakes/candidate-correction.pdf',
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ], $overrides));
}
