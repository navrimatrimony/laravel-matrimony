<?php

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\Intake\IntakeHumanReviewSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

require __DIR__.'/Support/BulkIntakeRegistrationHelpers.php';

test('admin can open bulk candidate correction page', function () {
    Storage::disk('local')->put(
        'testing/candidate-correction.png',
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=')
    );

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
    $item = candidateCorrectionItem($batch, $intake, [
        'original_filename' => 'candidate-correction.png',
        'source_file_path' => 'testing/candidate-correction.png',
    ]);

    $response = $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]))
        ->assertOk()
        ->assertSee('Bulk Candidate Correction', false)
        ->assertSee('bulk-correction-layout', false)
        ->assertSee('@media (min-width: 1024px)', false)
        ->assertSee('data-testid="bulk-correction-two-column-layout"', false)
        ->assertSee('data-testid="bulk-correction-left-evidence"', false)
        ->assertSee('data-testid="bulk-correction-right-form"', false)
        ->assertDontSee('lg:grid-cols-[', false)
        ->assertDontSee('lg:sticky', false)
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
        ->assertSee('data-testid="bulk-height-combobox"', false)
        ->assertSee('data-testid="bulk-correction-height-input"', false)
        ->assertSee('5&#039;3&quot; / 160 cm', false)
        ->assertDontSee('list="bulk-height-options"', false)
        ->assertDontSee('datalist id="bulk-height-options"', false)
        ->assertDontSee('data-testid="bulk-correction-height-free-text"', false)
        ->assertDontSee('name="height_cm"', false)
        ->assertSee('data-testid="bulk-image-zoom-toolbar"', false)
        ->assertSee('data-testid="bulk-image-zoom-container"', false)
        ->assertSee('data-testid="bulk-image-preview"', false)
        ->assertSee('loading="lazy"', false)
        ->assertSee('decoding="async"', false)
        ->assertSee('data-bulk-image-zoom', false)
        ->assertSee('data-zoom-action="in"', false)
        ->assertSee('data-zoom-action="out"', false)
        ->assertSee('education-multiselect-root-bulk-correction-education-', false)
        ->assertSee('location-typeahead-wrapper', false)
        ->assertSee('religion-caste-component', false)
        ->assertSee('occupation-engine-root', false)
        ->assertSee('data-display-sync-name="location"', false)
        ->assertSee('data-search-url="', false)
        ->assertSee('/api/location/search', false)
        ->assertSee('data-testid="bulk-correction-low-confidence-name"', false)
        ->assertSee('Saves only the reviewed intake snapshot.', false);

    $html = $response->getContent();
    $left = strpos($html, 'data-testid="bulk-correction-left-evidence"');
    $sourceText = strpos($html, 'Last parse input text');
    $right = strpos($html, 'data-testid="bulk-correction-right-form"');
    $form = strpos($html, 'id="bulk-candidate-correction-form"');
    $zoom = strpos($html, 'data-testid="bulk-image-zoom-toolbar"');

    expect($left)->not->toBeFalse()
        ->and($sourceText)->not->toBeFalse()
        ->and($right)->not->toBeFalse()
        ->and($form)->not->toBeFalse()
        ->and($zoom)->not->toBeFalse()
        ->and($zoom)->toBeGreaterThan($left)
        ->and($zoom)->toBeLessThan($right)
        ->and($sourceText)->toBeGreaterThan($left)
        ->and($sourceText)->toBeLessThan($right)
        ->and($form)->toBeGreaterThan($right)
        ->and($response->getContent())->toContain('items/'.$item->id.'/evidence-image');
});

test('admin can save comma separated mobiles and stores all contact numbers', function () {
    $admin = candidateCorrectionAdminUser();
    $batch = candidateCorrectionBatch($admin);
    $intake = candidateCorrectionIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Multi Mobile Candidate',
            ],
        ],
    ]);
    $item = candidateCorrectionItem($batch, $intake);

    $this->actingAs($admin)
        ->patch(route('admin.bulk-intakes.items.correct-candidate.update', [$batch, $item]), [
            'name' => 'Multi Mobile Candidate',
            'mobile' => '9876543210, 9123456789',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $intake->refresh();

    expect(data_get($intake->approval_snapshot_json, 'core.primary_contact_number'))->toBe('9876543210')
        ->and(data_get($intake->approval_snapshot_json, 'core.all_contact_numbers'))->toBe(['9876543210', '9123456789'])
        ->and(data_get($intake->approval_snapshot_json, 'contacts.0.phone_number'))->toBe('9876543210');
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
    $showRoute = route('admin.bulk-intakes.show', [
        'bulkIntakeBatch' => $batch,
        'highlight_item' => $item->id,
    ]);

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
        ->assertRedirect($showRoute)
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
        ->get($showRoute)
        ->assertOk()
        ->assertSee('id="bulk-item-'.$item->id.'"', false)
        ->assertSee('Corrected Candidate', false)
        ->assertSee('Mobile: 9876543210', false)
        ->assertSee('1998-04-15', false)
        ->assertSee("5'6\"")
        ->assertDontSee('168 cm', false)
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
            'after_save' => 'stay',
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
        ->assertSee('data-testid="bulk-height-combobox"', false)
        ->assertSee('data-testid="bulk-correction-height-input"', false)
        ->assertSee('value="157 cm"', false)
        ->assertDontSee('list="bulk-height-options"', false)
        ->assertDontSee('datalist id="bulk-height-options"', false)
        ->assertDontSee('data-testid="bulk-correction-height-free-text"', false)
        ->assertDontSee('name="height_cm"', false);

    $html = $response->getContent();

    expect(substr_count($html, 'name="height"'))->toBe(1)
        ->and(substr_count($html, 'data-testid="bulk-correction-height-input"'))->toBe(1);
});

test('bulk candidate correction normalizes common typed height formats', function () {
    $admin = candidateCorrectionAdminUser();
    $batch = candidateCorrectionBatch($admin);

    foreach ([
        "5'3\"" => 160,
        '160 cm' => 160,
        '5 ft 3 in' => 160,
        '5 feet 3 inch' => 160,
        '5ft3in' => 160,
    ] as $heightInput => $expectedCm) {
        $parsed = [
            'core' => [
                'full_name' => 'Height Format Candidate',
                'primary_contact_number' => '9876500000',
                'date_of_birth' => '1998-04-01',
                'height_cm' => 150,
                'gender' => 'male',
                'highest_education' => 'BSc',
                'city_text' => 'Old City',
            ],
        ];
        $intake = candidateCorrectionIntake([
            'raw_ocr_text' => 'Original OCR text for height format '.$heightInput,
            'parsed_json' => $parsed,
        ]);
        $item = candidateCorrectionItem($batch, $intake);

        $this->actingAs($admin)
            ->patch(route('admin.bulk-intakes.items.correct-candidate.update', [$batch, $item]), [
                'name' => 'Height Format Candidate',
                'mobile' => '9876543210',
                'date_of_birth' => '1998-04-15',
                'height' => $heightInput,
                'gender' => 'female',
                'education' => 'MCA',
                'location' => 'Pune',
                'after_save' => 'stay',
            ])
            ->assertRedirect(route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]));

        $intake->refresh();
        $item->refresh();

        expect(data_get($intake->approval_snapshot_json, 'core.height_cm'))->toBe($expectedCm)
            ->and(data_get($intake->approval_snapshot_json, 'core.height'))->toBe('5 ft 3 in')
            ->and($intake->raw_ocr_text)->toBe('Original OCR text for height format '.$heightInput)
            ->and($intake->parsed_json)->toBe($parsed)
            ->and($item->item_meta_json)->toBeNull();
    }
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
            'after_save' => 'stay',
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

test('reviewed height reopens as saved display text instead of height cm fallback', function () {
    $admin = candidateCorrectionAdminUser();
    $batch = candidateCorrectionBatch($admin);
    $intake = candidateCorrectionIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Height Rehydrate Candidate',
                'primary_contact_number' => '9876543210',
                'height_cm' => 160,
            ],
        ],
        'approval_snapshot_json' => [
            'core' => [
                'full_name' => 'Height Rehydrate Candidate',
                'primary_contact_number' => '9876543210',
                'height_cm' => 160,
                'height' => '5 ft 3 in',
            ],
        ],
    ]);
    $item = candidateCorrectionItem($batch, $intake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]))
        ->assertOk()
        ->assertSee('data-testid="bulk-height-combobox"', false)
        ->assertSee('data-testid="bulk-correction-height-input"', false)
        ->assertSee('value="5 ft 3 in"', false)
        ->assertDontSee('value="160 cm"', false);
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
        ->assertSee('Mobile does not normalize to valid 10 digit Indian number(s).', false)
        ->assertSee('data-testid="bulk-correction-warning-date_of_birth"', false)
        ->assertSee('Age is below 18 and should be reviewed.', false)
        ->assertSee('data-testid="bulk-correction-warning-height"', false)
        ->assertSee('Enter height as cm or feet/inches.', false)
        ->assertSee('data-testid="bulk-correction-warning-gender"', false)
        ->assertSee('Select Male, Female, or Unknown.', false);
});

test('bulk candidate correction page does not render screening or duplicate cards', function () {
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
        ->assertDontSee('data-testid="bulk-correction-duplicate-history-card"', false)
        ->assertDontSee('data-testid="bulk-correction-screening-advisor-card"', false)
        ->assertDontSee('data-testid="bulk-correction-ready-for-consent-card"', false)
        ->assertDontSee('data-testid="bulk-correction-manual-screening-card"', false)
        ->assertDontSee('data-testid="bulk-correction-manual-duplicate-card"', false)
        ->assertDontSee('Review flag', false);

    $intake->refresh();
    $item->refresh();

    expect($intake->raw_ocr_text)->toBe('Original duplicate hint OCR text')
        ->and($intake->parsed_json)->toBe($parsed)
        ->and($item->item_meta_json)->toBeNull();
});

test('admin can mark item manual duplicate from correction page without mutating intake evidence', function () {
    $admin = candidateCorrectionAdminUser();
    $batch = candidateCorrectionBatch($admin);
    $matched = candidateCorrectionIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Matched Candidate',
            ],
        ],
    ]);
    $parsed = [
        'core' => [
            'full_name' => 'Manual Duplicate Candidate',
            'primary_contact_number' => '9876543210',
        ],
    ];
    $approval = [
        'core' => [
            'full_name' => 'Reviewed Manual Duplicate Candidate',
            'primary_contact_number' => '9876543210',
        ],
    ];
    $intake = candidateCorrectionIntake([
        'raw_ocr_text' => 'Original duplicate manual OCR text',
        'parsed_json' => $parsed,
        'approval_snapshot_json' => $approval,
    ]);
    $item = candidateCorrectionItem($batch, $intake);

    $this->actingAs($admin)
        ->from(route('admin.bulk-intakes.show', $batch))
        ->post(route('admin.bulk-intakes.items.mark-duplicate', [$batch, $item]), [
            'matched_biodata_intake_id' => $matched->id,
            'reason' => 'Same biodata was uploaded before.',
        ])
        ->assertRedirect(route('admin.bulk-intakes.show', $batch))
        ->assertSessionHas('success');

    $item->refresh();
    $intake->refresh();

    $duplicateReview = data_get($item->item_meta_json, 'duplicate_review');

    expect($item->item_status)->toBe(BulkIntakeBatchItem::STATUS_INTAKE_CREATED)
        ->and($duplicateReview)->toBeArray()
        ->and(data_get($duplicateReview, 'status'))->toBe('manual_duplicate')
        ->and(data_get($duplicateReview, 'matched_biodata_intake_id'))->toBe($matched->id)
        ->and(data_get($duplicateReview, 'matched_profile_id'))->toBeNull()
        ->and(data_get($duplicateReview, 'reason'))->toBe('Same biodata was uploaded before.')
        ->and(data_get($duplicateReview, 'marked_by_user_id'))->toBe($admin->id)
        ->and(data_get($duplicateReview, 'marked_at'))->not->toBeNull()
        ->and(data_get($duplicateReview, 'cleared_by_user_id'))->toBeNull()
        ->and(data_get($duplicateReview, 'cleared_at'))->toBeNull()
        ->and(data_get($duplicateReview, 'full_name'))->toBeNull()
        ->and(data_get($duplicateReview, 'primary_contact_number'))->toBeNull()
        ->and(data_get($duplicateReview, 'candidate'))->toBeNull()
        ->and($intake->raw_ocr_text)->toBe('Original duplicate manual OCR text')
        ->and($intake->parsed_json)->toBe($parsed)
        ->and($intake->approval_snapshot_json)->toBe($approval);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('data-testid="bulk-manual-duplicate-badge"', false)
        ->assertSee('Manual duplicate', false)
        ->assertSee('Clear duplicate', false);
});

test('admin can clear manual duplicate without changing item status or intake evidence', function () {
    $admin = candidateCorrectionAdminUser();
    $batch = candidateCorrectionBatch($admin);
    $parsed = [
        'core' => [
            'full_name' => 'Clear Manual Duplicate Candidate',
        ],
    ];
    $approval = [
        'core' => [
            'full_name' => 'Reviewed Clear Manual Duplicate Candidate',
        ],
    ];
    $intake = candidateCorrectionIntake([
        'raw_ocr_text' => 'Original clear duplicate OCR text',
        'parsed_json' => $parsed,
        'approval_snapshot_json' => $approval,
    ]);
    $item = candidateCorrectionItem($batch, $intake, [
        'item_meta_json' => [
            'existing_key' => 'keep',
            'duplicate_review' => [
                'status' => 'manual_duplicate',
                'matched_biodata_intake_id' => $intake->id,
                'matched_profile_id' => null,
                'reason' => 'Existing duplicate mark',
                'marked_by_user_id' => $admin->id,
                'marked_at' => '2026-07-08T10:00:00+00:00',
                'cleared_by_user_id' => null,
                'cleared_at' => null,
            ],
        ],
    ]);

    $this->actingAs($admin)
        ->from(route('admin.bulk-intakes.show', $batch))
        ->post(route('admin.bulk-intakes.items.clear-duplicate', [$batch, $item]))
        ->assertRedirect(route('admin.bulk-intakes.show', $batch))
        ->assertSessionHas('success');

    $item->refresh();
    $intake->refresh();

    expect($item->item_status)->toBe(BulkIntakeBatchItem::STATUS_INTAKE_CREATED)
        ->and(data_get($item->item_meta_json, 'existing_key'))->toBe('keep')
        ->and(data_get($item->item_meta_json, 'duplicate_review.status'))->toBe('cleared')
        ->and(data_get($item->item_meta_json, 'duplicate_review.matched_biodata_intake_id'))->toBe($intake->id)
        ->and(data_get($item->item_meta_json, 'duplicate_review.reason'))->toBe('Existing duplicate mark')
        ->and(data_get($item->item_meta_json, 'duplicate_review.marked_by_user_id'))->toBe($admin->id)
        ->and(data_get($item->item_meta_json, 'duplicate_review.cleared_by_user_id'))->toBe($admin->id)
        ->and(data_get($item->item_meta_json, 'duplicate_review.cleared_at'))->not->toBeNull()
        ->and($intake->raw_ocr_text)->toBe('Original clear duplicate OCR text')
        ->and($intake->parsed_json)->toBe($parsed)
        ->and($intake->approval_snapshot_json)->toBe($approval);
});

test('non admin cannot mark or clear manual duplicate', function () {
    $admin = candidateCorrectionAdminUser();
    $member = User::factory()->create([
        'is_admin' => false,
        'admin_role' => null,
    ]);
    $batch = candidateCorrectionBatch($admin);
    $intake = candidateCorrectionIntake([
        'raw_ocr_text' => 'Original non admin duplicate OCR text',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Non Admin Candidate',
            ],
        ],
    ]);
    $item = candidateCorrectionItem($batch, $intake);

    $this->actingAs($member)
        ->post(route('admin.bulk-intakes.items.mark-duplicate', [$batch, $item]), [
            'reason' => 'Forbidden duplicate mark',
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('admin.bulk-intakes.items.clear-duplicate', [$batch, $item]))
        ->assertForbidden();

    $item->refresh();
    $intake->refresh();

    expect($item->item_status)->toBe(BulkIntakeBatchItem::STATUS_INTAKE_CREATED)
        ->and($item->item_meta_json)->toBeNull()
        ->and($intake->raw_ocr_text)->toBe('Original non admin duplicate OCR text')
        ->and(data_get($intake->parsed_json, 'core.full_name'))->toBe('Non Admin Candidate');
});

test('admin can set eligible_for_consent screening decision', function () {
    $admin = candidateCorrectionAdminUser();
    $batch = candidateCorrectionBatch($admin);
    $parsed = [
        'core' => [
            'full_name' => 'Eligible Screening Candidate',
            'primary_contact_number' => '9876543210',
        ],
    ];
    $approval = [
        'core' => [
            'full_name' => 'Reviewed Eligible Screening Candidate',
            'primary_contact_number' => '9876543210',
        ],
    ];
    $intake = candidateCorrectionIntake([
        'raw_ocr_text' => 'Original eligible screening OCR text',
        'parsed_json' => $parsed,
        'approval_snapshot_json' => $approval,
    ]);
    $item = candidateCorrectionItem($batch, $intake);

    $this->actingAs($admin)
        ->from(route('admin.bulk-intakes.show', $batch))
        ->post(route('admin.bulk-intakes.items.save-screening-review', [$batch, $item]), [
            'status' => 'eligible_for_consent',
            'reason_key' => 'admin_verified',
            'note' => 'Verified by admin after correction.',
        ])
        ->assertRedirect(route('admin.bulk-intakes.show', $batch))
        ->assertSessionHas('success');

    $item->refresh();
    $intake->refresh();

    $screeningReview = data_get($item->item_meta_json, 'screening_review');

    expect($item->item_status)->toBe(BulkIntakeBatchItem::STATUS_INTAKE_CREATED)
        ->and($screeningReview)->toBeArray()
        ->and(data_get($screeningReview, 'status'))->toBe('eligible_for_consent')
        ->and(data_get($screeningReview, 'reason_key'))->toBe('admin_verified')
        ->and(data_get($screeningReview, 'note'))->toBe('Verified by admin after correction.')
        ->and(data_get($screeningReview, 'reviewed_by_user_id'))->toBe($admin->id)
        ->and(data_get($screeningReview, 'reviewed_at'))->not->toBeNull()
        ->and(data_get($screeningReview, 'cleared_by_user_id'))->toBeNull()
        ->and(data_get($screeningReview, 'cleared_at'))->toBeNull()
        ->and(data_get($screeningReview, 'full_name'))->toBeNull()
        ->and(data_get($screeningReview, 'primary_contact_number'))->toBeNull()
        ->and(data_get($screeningReview, 'candidate'))->toBeNull()
        ->and($intake->raw_ocr_text)->toBe('Original eligible screening OCR text')
        ->and($intake->parsed_json)->toBe($parsed)
        ->and($intake->approval_snapshot_json)->toBe($approval);
});

test('admin can set needs_review screening decision', function () {
    $admin = candidateCorrectionAdminUser();
    $batch = candidateCorrectionBatch($admin);
    $intake = candidateCorrectionIntake([
        'raw_ocr_text' => 'Original needs review screening OCR text',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Needs Review Screening Candidate',
            ],
        ],
    ]);
    $item = candidateCorrectionItem($batch, $intake);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.save-screening-review', [$batch, $item]), [
            'status' => 'needs_review',
            'reason_key' => 'missing_mobile',
            'note' => 'Mobile missing from biodata.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $item->refresh();

    expect(data_get($item->item_meta_json, 'screening_review.status'))->toBe('needs_review')
        ->and(data_get($item->item_meta_json, 'screening_review.reason_key'))->toBe('missing_mobile')
        ->and($item->item_status)->toBe(BulkIntakeBatchItem::STATUS_INTAKE_CREATED);
});

test('admin can set stopped screening decision', function () {
    $admin = candidateCorrectionAdminUser();
    $batch = candidateCorrectionBatch($admin);
    $intake = candidateCorrectionIntake([
        'raw_ocr_text' => 'Original stopped screening OCR text',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Stopped Screening Candidate',
            ],
        ],
    ]);
    $item = candidateCorrectionItem($batch, $intake);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.save-screening-review', [$batch, $item]), [
            'status' => 'stopped',
            'reason_key' => 'not_interested',
            'note' => 'Candidate declined.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $item->refresh();

    expect(data_get($item->item_meta_json, 'screening_review.status'))->toBe('stopped')
        ->and(data_get($item->item_meta_json, 'screening_review.reason_key'))->toBe('not_interested')
        ->and($item->item_status)->toBe(BulkIntakeBatchItem::STATUS_INTAKE_CREATED);
});

test('admin can clear screening decision without changing item status or intake evidence', function () {
    $admin = candidateCorrectionAdminUser();
    $batch = candidateCorrectionBatch($admin);
    $parsed = [
        'core' => [
            'full_name' => 'Clear Screening Candidate',
        ],
    ];
    $approval = [
        'core' => [
            'full_name' => 'Reviewed Clear Screening Candidate',
        ],
    ];
    $intake = candidateCorrectionIntake([
        'raw_ocr_text' => 'Original clear screening OCR text',
        'parsed_json' => $parsed,
        'approval_snapshot_json' => $approval,
    ]);
    $item = candidateCorrectionItem($batch, $intake, [
        'item_meta_json' => [
            'existing_key' => 'keep',
            'screening_review' => [
                'status' => 'stopped',
                'reason_key' => 'wrong_number',
                'note' => 'Existing screening mark',
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => '2026-07-08T10:00:00+00:00',
                'cleared_by_user_id' => null,
                'cleared_at' => null,
            ],
        ],
    ]);

    $this->actingAs($admin)
        ->from(route('admin.bulk-intakes.show', $batch))
        ->post(route('admin.bulk-intakes.items.clear-screening-review', [$batch, $item]))
        ->assertRedirect(route('admin.bulk-intakes.show', $batch))
        ->assertSessionHas('success');

    $item->refresh();
    $intake->refresh();

    expect($item->item_status)->toBe(BulkIntakeBatchItem::STATUS_INTAKE_CREATED)
        ->and(data_get($item->item_meta_json, 'existing_key'))->toBe('keep')
        ->and(data_get($item->item_meta_json, 'screening_review.status'))->toBe('cleared')
        ->and(data_get($item->item_meta_json, 'screening_review.reason_key'))->toBe('wrong_number')
        ->and(data_get($item->item_meta_json, 'screening_review.note'))->toBe('Existing screening mark')
        ->and(data_get($item->item_meta_json, 'screening_review.reviewed_by_user_id'))->toBe($admin->id)
        ->and(data_get($item->item_meta_json, 'screening_review.cleared_by_user_id'))->toBe($admin->id)
        ->and(data_get($item->item_meta_json, 'screening_review.cleared_at'))->not->toBeNull()
        ->and($intake->raw_ocr_text)->toBe('Original clear screening OCR text')
        ->and($intake->parsed_json)->toBe($parsed)
        ->and($intake->approval_snapshot_json)->toBe($approval);
});

test('non admin cannot set or clear screening decision', function () {
    $admin = candidateCorrectionAdminUser();
    $member = User::factory()->create([
        'is_admin' => false,
        'admin_role' => null,
    ]);
    $batch = candidateCorrectionBatch($admin);
    $intake = candidateCorrectionIntake([
        'raw_ocr_text' => 'Original non admin screening OCR text',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Non Admin Screening Candidate',
            ],
        ],
    ]);
    $item = candidateCorrectionItem($batch, $intake);

    $this->actingAs($member)
        ->post(route('admin.bulk-intakes.items.save-screening-review', [$batch, $item]), [
            'status' => 'needs_review',
            'reason_key' => 'missing_mobile',
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('admin.bulk-intakes.items.clear-screening-review', [$batch, $item]))
        ->assertForbidden();

    $item->refresh();
    $intake->refresh();

    expect($item->item_status)->toBe(BulkIntakeBatchItem::STATUS_INTAKE_CREATED)
        ->and($item->item_meta_json)->toBeNull()
        ->and($intake->raw_ocr_text)->toBe('Original non admin screening OCR text')
        ->and(data_get($intake->parsed_json, 'core.full_name'))->toBe('Non Admin Screening Candidate');
});

test('read-only screening advisor still works on batch show when no manual screening exists', function () {
    $admin = candidateCorrectionAdminUser();
    $batch = candidateCorrectionBatch($admin);
    $intake = candidateCorrectionIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Advisor Only Candidate',
                'primary_contact_number' => '9876543210',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
            ],
        ],
    ]);
    $item = candidateCorrectionItem($batch, $intake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('data-testid="bulk-screening-badge"', false)
        ->assertSee('Eligible', false)
        ->assertDontSee('data-testid="bulk-manual-screening-badge"', false);
});

test('batch show displays ready for consent badge when manual eligible and identity complete', function () {
    $admin = candidateCorrectionAdminUser();
    $batch = candidateCorrectionBatch($admin);
    $intake = candidateCorrectionIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Ready Correction Candidate',
                'primary_contact_number' => '9876543210',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
            ],
        ],
    ]);
    $item = candidateCorrectionItem($batch, $intake, [
        'item_meta_json' => [
            'screening_review' => [
                'status' => 'eligible_for_consent',
                'reason_key' => 'admin_verified',
                'note' => null,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => '2026-07-08T10:00:00+00:00',
                'cleared_by_user_id' => null,
                'cleared_at' => null,
            ],
        ],
    ]);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('data-testid="bulk-ready-for-consent-badge"', false)
        ->assertSee('Ready for Consent', false)
        ->assertSee('id="bulk-item-'.$item->id.'"', false);
});

test('batch show hides ready badge when eligible manual screening lacks mobile', function () {
    $admin = candidateCorrectionAdminUser();
    $batch = candidateCorrectionBatch($admin);
    $intake = candidateCorrectionIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Not Ready Missing Mobile Candidate',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
            ],
        ],
    ]);
    candidateCorrectionItem($batch, $intake, [
        'item_meta_json' => [
            'screening_review' => [
                'status' => 'eligible_for_consent',
                'reason_key' => 'corrected_basic_fields',
                'note' => null,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => '2026-07-08T10:00:00+00:00',
                'cleared_by_user_id' => null,
                'cleared_at' => null,
            ],
        ],
    ]);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertDontSee('data-testid="bulk-ready-for-consent-badge"', false);
});

test('admin can save religion caste and occupation correction into reviewed snapshot', function () {
    $admin = candidateCorrectionAdminUser();
    $batch = candidateCorrectionBatch($admin);
    $masters = registrationMasterIds();
    $career = registrationCareerMasters();
    $parsed = [
        'core' => [
            'full_name' => 'Community Candidate',
            'primary_contact_number' => '9876543210',
            'date_of_birth' => '1998-04-15',
            'gender' => 'female',
            'highest_education' => 'BCom',
            'city_text' => 'Pune',
            'occupation_title' => 'Old OCR Occupation',
        ],
    ];
    $intake = candidateCorrectionIntake([
        'parsed_json' => $parsed,
    ]);
    $item = candidateCorrectionItem($batch, $intake);

    $this->actingAs($admin)
        ->patch(route('admin.bulk-intakes.items.correct-candidate.update', [$batch, $item]), [
            'name' => 'Community Candidate',
            'mobile' => '9876543210',
            'date_of_birth' => '1998-04-15',
            'gender' => 'female',
            'education' => 'BCom',
            'location' => 'Pune',
            'religion_id' => $masters['religion_id'],
            'caste_id' => $masters['caste_id'],
            'occupation_master_id' => $career['occupation_master_id'],
            'working_with_type_id' => $career['working_with_type_id'],
            'occupation_title' => 'Software Engineer',
            'company_name' => 'Test Company',
            'after_save' => 'stay',
        ])
        ->assertRedirect(route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]));

    $intake->refresh();

    expect(data_get($intake->approval_snapshot_json, 'core.religion_id'))->toBe($masters['religion_id'])
        ->and(data_get($intake->approval_snapshot_json, 'core.caste_id'))->toBe($masters['caste_id'])
        ->and(data_get($intake->approval_snapshot_json, 'core.occupation_master_id'))->toBe($career['occupation_master_id'])
        ->and(data_get($intake->approval_snapshot_json, 'core.working_with_type_id'))->toBe($career['working_with_type_id'])
        ->and(data_get($intake->approval_snapshot_json, 'core.occupation_title'))->toBe('सॉफ्टवेअर अभियंता')
        ->and(data_get($intake->approval_snapshot_json, 'core.company_name'))->toBe('Test Company')
        ->and($intake->parsed_json)->toBe($parsed);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('Religion: Hindu', false)
        ->assertSee('Caste: Maratha', false)
        ->assertSee('सॉफ्टवेअर अभियंता', false)
        ->assertDontSee('Old OCR Occupation', false);
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
