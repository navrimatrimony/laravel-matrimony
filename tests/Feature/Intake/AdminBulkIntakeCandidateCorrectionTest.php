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
        ->assertSee('data-testid="bulk-correction-two-column-layout"', false)
        ->assertSee('data-testid="bulk-correction-left-evidence"', false)
        ->assertSee('data-testid="bulk-correction-right-form"', false)
        ->assertDontSee('Only these 7 fields are editable in this phase.', false)
        ->assertSee('Parsed Candidate', false)
        ->assertSee('9876543210', false)
        ->assertSee('1998-04-15', false)
        ->assertSee('165 cm', false)
        ->assertSee('MCA', false)
        ->assertSee('Pune', false)
        ->assertSee('नाव : Parsed Candidate', false)
        ->assertSee('type="date"', false)
        ->assertSee('data-testid="bulk-correction-date-input"', false)
        ->assertSee('data-testid="bulk-correction-height-input"', false)
        ->assertSee('list="bulk-height-options"', false)
        ->assertSee('datalist id="bulk-height-options"', false)
        ->assertDontSee('data-testid="bulk-correction-height-free-text"', false)
        ->assertDontSee('name="height_cm"', false)
        ->assertSee('education-multiselect-root-bulk-correction-education-', false)
        ->assertSee('location-typeahead-wrapper', false)
        ->assertSee('data-display-sync-name="location"', false)
        ->assertSee('data-search-url="', false)
        ->assertSee('/api/location/search', false)
        ->assertSee('data-testid="bulk-correction-low-confidence-name"', false)
        ->assertSee('Saves only the reviewed intake snapshot.', false);
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

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('Corrected Candidate', false)
        ->assertSee('Mobile: 9876543210', false)
        ->assertSee('1998-04-15', false)
        ->assertSee('168 cm', false)
        ->assertSee('Gender: Female', false)
        ->assertSee('MCA', false)
        ->assertSee('Pune', false)
        ->assertSee('data-testid="bulk-candidate-reviewed-badge"', false)
        ->assertSee('Reviewed', false)
        ->assertSee('Parsed JSON: Yes', false)
        ->assertDontSee('Parsed Candidate', false)
        ->assertDontSee('9876500000', false)
        ->assertDontSee('Old City', false);
});

test('admin can save centralized education height and location engine payloads into reviewed snapshot', function () {
    $admin = candidateCorrectionAdminUser();
    $batch = candidateCorrectionBatch($admin);
    $parsed = [
        'core' => [
            'full_name' => 'Engine Candidate',
            'primary_contact_number' => '9876500000',
            'date_of_birth' => '1998-04-01',
            'height_cm' => 160,
            'gender' => 'male',
            'highest_education' => 'Old Education',
            'city_text' => 'Old City',
        ],
    ];
    $intake = candidateCorrectionIntake([
        'raw_ocr_text' => 'Original OCR text',
        'parsed_json' => $parsed,
    ]);
    $item = candidateCorrectionItem($batch, $intake);

    $this->actingAs($admin)
        ->patch(route('admin.bulk-intakes.items.correct-candidate.update', [$batch, $item]), [
            'name' => 'Engine Candidate',
            'mobile' => '9876543210',
            'date_of_birth' => '1998-04-15',
            'height' => '165 cm',
            'height_cm' => 160,
            'gender' => 'female',
            'education_slots' => json_encode([
                ['t' => 'c', 'x' => 'Custom Marine Engineering Diploma'],
            ]),
            'location_input' => 'Satara',
        ])
        ->assertRedirect(route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]));

    $intake->refresh();
    $item->refresh();

    expect(data_get($intake->approval_snapshot_json, 'core.height_cm'))->toBe(165)
        ->and(data_get($intake->approval_snapshot_json, 'core.height'))->toBe('5 ft 5 in')
        ->and(data_get($intake->approval_snapshot_json, 'core.highest_education'))->toBe('Custom Marine Engineering Diploma')
        ->and(data_get($intake->approval_snapshot_json, 'core.city_text'))->toBe('Satara')
        ->and($intake->raw_ocr_text)->toBe('Original OCR text')
        ->and($intake->parsed_json)->toBe($parsed)
        ->and($item->item_meta_json)->toBeNull();
});

test('bulk candidate correction page renders only one height input control', function () {
    $admin = candidateCorrectionAdminUser();
    $batch = candidateCorrectionBatch($admin);
    $intake = candidateCorrectionIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Single Height Candidate',
                'primary_contact_number' => '9876543210',
                'date_of_birth' => '1998-04-15',
                'height_cm' => 157,
                'gender' => 'female',
                'highest_education' => 'MCA',
                'city_text' => 'Pune',
            ],
        ],
    ]);
    $item = candidateCorrectionItem($batch, $intake);

    $response = $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]))
        ->assertOk()
        ->assertSee('data-testid="bulk-correction-height-input"', false)
        ->assertSee('value="157 cm"', false)
        ->assertDontSee('data-testid="bulk-correction-height-free-text"', false)
        ->assertDontSee('name="height_cm"', false);

    $html = $response->getContent();

    expect(substr_count($html, 'name="height"'))->toBe(1)
        ->and(substr_count($html, 'data-testid="bulk-correction-height-input"'))->toBe(1);
});

test('typed height and unselected typed location save into reviewed snapshot', function () {
    $admin = candidateCorrectionAdminUser();
    $batch = candidateCorrectionBatch($admin);
    $parsed = [
        'core' => [
            'full_name' => 'Free Text Candidate',
            'primary_contact_number' => '9876500000',
            'date_of_birth' => '1998-04-01',
            'height_cm' => 160,
            'gender' => 'male',
            'highest_education' => 'BSc',
            'city_text' => 'Old City',
        ],
    ];
    $intake = candidateCorrectionIntake([
        'raw_ocr_text' => 'Original OCR text for free text save',
        'parsed_json' => $parsed,
    ]);
    $item = candidateCorrectionItem($batch, $intake);

    $this->actingAs($admin)
        ->patch(route('admin.bulk-intakes.items.correct-candidate.update', [$batch, $item]), [
            'name' => 'Free Text Candidate',
            'mobile' => '9876543210',
            'date_of_birth' => '1998-04-15',
            'height' => "5'2\"",
            'gender' => 'female',
            'education' => 'MCA',
            'location' => 'Typed Free Text Village',
        ])
        ->assertRedirect(route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]));

    $intake->refresh();
    $item->refresh();

    expect(data_get($intake->approval_snapshot_json, 'core.height_cm'))->toBe(157)
        ->and(data_get($intake->approval_snapshot_json, 'core.height'))->toBe('5 ft 2 in')
        ->and(data_get($intake->approval_snapshot_json, 'core.city_text'))->toBe('Typed Free Text Village')
        ->and($intake->raw_ocr_text)->toBe('Original OCR text for free text save')
        ->and($intake->parsed_json)->toBe($parsed)
        ->and($item->item_meta_json)->toBeNull();
});

test('admin can mark bulk candidate correction item as needs review without mutating evidence', function () {
    $admin = candidateCorrectionAdminUser();
    $batch = candidateCorrectionBatch($admin);
    $parsed = [
        'core' => [
            'full_name' => 'Needs Review Candidate',
            'primary_contact_number' => '9876543210',
        ],
    ];
    $intake = candidateCorrectionIntake([
        'raw_ocr_text' => 'Original OCR text for review flag',
        'parsed_json' => $parsed,
    ]);
    $item = candidateCorrectionItem($batch, $intake);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.mark-needs-review', [$batch, $item]), [
            'reason' => 'Candidate correction needs manual review',
        ])
        ->assertRedirect(route('admin.bulk-intakes.show', $batch))
        ->assertSessionHas('success');

    $item->refresh();
    $intake->refresh();

    expect($item->item_status)->toBe(BulkIntakeBatchItem::STATUS_NEEDS_REVIEW)
        ->and(data_get($item->item_meta_json, 'previous_item_status'))->toBe(BulkIntakeBatchItem::STATUS_INTAKE_CREATED)
        ->and(data_get($item->item_meta_json, 'needs_review_reason'))->toBe('Candidate correction needs manual review')
        ->and($intake->raw_ocr_text)->toBe('Original OCR text for review flag')
        ->and($intake->parsed_json)->toBe($parsed);
});

test('non admin cannot mark bulk candidate correction item as needs review', function () {
    $admin = candidateCorrectionAdminUser();
    $member = User::factory()->create([
        'is_admin' => false,
        'admin_role' => null,
    ]);
    $batch = candidateCorrectionBatch($admin);
    $intake = candidateCorrectionIntake();
    $item = candidateCorrectionItem($batch, $intake);

    $this->actingAs($member)
        ->post(route('admin.bulk-intakes.items.mark-needs-review', [$batch, $item]), [
            'reason' => 'Forbidden review flag',
        ])
        ->assertForbidden();

    $item->refresh();
    $intake->refresh();

    expect($item->item_status)->toBe(BulkIntakeBatchItem::STATUS_INTAKE_CREATED)
        ->and($item->item_meta_json)->toBeNull()
        ->and($intake->raw_ocr_text)->toBe('Original OCR text')
        ->and($intake->parsed_json)->toBe([]);
});

test('bulk candidate correction page renders validation warnings for suspicious extracted fields', function () {
    $admin = candidateCorrectionAdminUser();
    $batch = candidateCorrectionBatch($admin);
    $intake = candidateCorrectionIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Warning Candidate',
                'primary_contact_number' => '12345',
                'date_of_birth' => '2012-04-15',
                'height' => 'very tall',
                'gender' => 'not-sure',
                'highest_education' => 'BCom',
                'city_text' => 'Pune',
            ],
        ],
    ]);
    $item = candidateCorrectionItem($batch, $intake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]))
        ->assertOk()
        ->assertSee('data-testid="bulk-correction-warning-mobile"', false)
        ->assertSee('Mobile does not normalize to a valid 10 digit Indian number.', false)
        ->assertSee('data-testid="bulk-correction-warning-date_of_birth"', false)
        ->assertSee('Age is below 18 and should be reviewed.', false)
        ->assertSee('data-testid="bulk-correction-warning-height"', false)
        ->assertSee('Enter height as cm or feet/inches.', false)
        ->assertSee('data-testid="bulk-correction-warning-gender"', false)
        ->assertSee('Select Male, Female, or Unknown.', false);
});

test('bulk candidate correction page renders read only duplicate history hints without mutating evidence', function () {
    $admin = candidateCorrectionAdminUser();
    $batch = candidateCorrectionBatch($admin);
    candidateCorrectionIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Duplicate Reference',
                'primary_contact_number' => '9876543210',
                'date_of_birth' => '1998-04-15',
            ],
        ],
    ]);
    $parsed = [
        'core' => [
            'full_name' => 'Duplicate Current',
            'primary_contact_number' => '+91 98765 43210',
            'date_of_birth' => '1998-04-15',
        ],
    ];
    $intake = candidateCorrectionIntake([
        'raw_ocr_text' => 'Original duplicate hint OCR text',
        'parsed_json' => $parsed,
    ]);
    $item = candidateCorrectionItem($batch, $intake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]))
        ->assertOk()
        ->assertSee('data-testid="bulk-correction-duplicate-history-card"', false)
        ->assertSee('Duplicate / history hints', false)
        ->assertSee('data-testid="bulk-correction-duplicate-history-hint"', false)
        ->assertSee('Same mobile found', false)
        ->assertSee('Matched intake', false)
        ->assertDontSee('9876543210', false);

    $intake->refresh();
    $item->refresh();

    expect($intake->raw_ocr_text)->toBe('Original duplicate hint OCR text')
        ->and($intake->parsed_json)->toBe($parsed)
        ->and($item->item_meta_json)->toBeNull();
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
