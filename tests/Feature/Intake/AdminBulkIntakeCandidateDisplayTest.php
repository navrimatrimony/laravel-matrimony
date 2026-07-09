<?php

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\User;
use App\Services\Intake\BulkIntakeCandidateDisplayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('bulk list shows parsed candidate fields from linked intake', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-07 12:00:00'));

    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $intake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Display Candidate',
                'primary_contact_number' => '9876543210',
                'date_of_birth' => '1998-04-15',
                'height_cm' => 165,
                'gender' => 'female',
                'city_text' => 'Pune',
                'highest_education' => 'MCA',
                'occupation_title' => 'Software Developer',
            ],
        ],
    ]);
    candidateDisplayItem($batch, $intake, [
        'original_filename' => 'display-candidate.pdf',
    ]);

    try {
        $this->actingAs($admin)
            ->get(route('admin.bulk-intakes.show', $batch))
            ->assertOk()
            ->assertSee('Display Candidate', false)
            ->assertSee('Mobile: 9876543210', false)
            ->assertSee('1998-04-15', false)
            ->assertSee('Age: 28', false)
            ->assertSee('5 ft 5 in', false)
            ->assertDontSee('165 cm', false)
            ->assertSee('Gender: Female', false)
            ->assertSee('Pune', false)
            ->assertSee('MCA', false)
            ->assertSee('Software Developer', false)
            ->assertSee('Parse: OK', false)
            ->assertSee('Parsed JSON: Yes', false)
            ->assertSee('data-testid="bulk-screening-badge"', false)
            ->assertSee('Eligible', false);
    } finally {
        Carbon::setTestNow();
    }
});

test('bulk list prefers reviewed snapshot values and keeps parsed json presence separate', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-07 12:00:00'));

    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $intake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Old Parsed Candidate',
                'primary_contact_number' => '9876500000',
                'date_of_birth' => '1998-04-01',
                'height_cm' => 160,
                'gender' => 'male',
                'city_text' => 'Old City',
                'highest_education' => 'BSc',
                'occupation_title' => 'Old Occupation',
            ],
        ],
        'approval_snapshot_json' => [
            'core' => [
                'full_name' => 'Reviewed Candidate',
                'primary_contact_number' => '9876543210',
                'date_of_birth' => '1998-04-15',
                'height_cm' => 168,
                'gender' => 'female',
                'city_text' => 'Pune',
                'highest_education' => 'MCA',
                'occupation_title' => 'Reviewed Occupation',
            ],
        ],
    ]);
    $item = candidateDisplayItem($batch, $intake, [
        'original_filename' => 'reviewed-candidate.pdf',
    ]);

    $candidate = app(BulkIntakeCandidateDisplayService::class)->candidateForItem($item);

    expect($candidate['full_name'])->toBe('Reviewed Candidate')
        ->and($candidate['mobile'])->toBe('9876543210')
        ->and($candidate['height'])->toBe('5 ft 6 in')
        ->and($candidate['city'])->toBe('Pune')
        ->and($candidate['education'])->toBe('MCA')
        ->and($candidate['display_source'])->toBe('approval_snapshot_json')
        ->and($candidate['reviewed_snapshot_present'])->toBeTrue()
        ->and($candidate['parsed_json_present'])->toBeTrue();

    try {
        $this->actingAs($admin)
            ->get(route('admin.bulk-intakes.show', $batch))
            ->assertOk()
            ->assertSee('Reviewed Candidate', false)
            ->assertSee('Mobile: 9876543210', false)
            ->assertSee('1998-04-15', false)
            ->assertSee('5 ft 6 in', false)
            ->assertDontSee('168 cm', false)
            ->assertSee('Gender: Female', false)
            ->assertSee('Pune', false)
            ->assertSee('MCA', false)
            ->assertSee('Reviewed Occupation', false)
            ->assertSee('Parsed JSON: Yes', false)
            ->assertSee('data-testid="bulk-candidate-reviewed-badge"', false)
            ->assertSee('data-testid="bulk-screening-badge"', false)
            ->assertSee('Eligible', false)
            ->assertDontSee('Old Parsed Candidate', false)
            ->assertDontSee('Old City', false)
            ->assertDontSee('BSc', false);
    } finally {
        Carbon::setTestNow();
    }
});

test('bulk list shows reviewed snapshot height even when old confidence marks height low', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $intake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Old Low Confidence Candidate',
                'height_cm' => 160,
            ],
            'confidence_map' => [
                'core.height_cm' => 0.0,
            ],
            'field_status' => [
                'core' => [
                    'height_cm' => 'low_confidence',
                ],
            ],
        ],
        'approval_snapshot_json' => [
            'core' => [
                'full_name' => 'Reviewed Height Candidate',
                'height_cm' => 160,
                'height' => '5 ft 3 in',
            ],
            'confidence_map' => [
                'core.height_cm' => 0.0,
            ],
            'field_status' => [
                'core' => [
                    'height_cm' => 'low_confidence',
                ],
            ],
        ],
    ]);
    $item = candidateDisplayItem($batch, $intake, [
        'original_filename' => 'reviewed-height-candidate.pdf',
    ]);

    $candidate = app(BulkIntakeCandidateDisplayService::class)->candidateForItem($item);

    expect($candidate['height'])->toBe('5 ft 3 in')
        ->and($candidate['height_needs_review'])->toBeFalse()
        ->and($candidate['display_warnings'])->not->toContain('height_low_confidence')
        ->and($candidate['missing_fields'])->not->toContain('height');

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('Reviewed Height Candidate', false)
        ->assertSee('5 ft 3 in', false)
        ->assertDontSee('160 cm', false)
        ->assertSee('data-testid="bulk-candidate-reviewed-badge"', false);
});

test('bulk list shows eligible screening for valid reviewed candidate and prefers approval snapshot', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $parsed = [
        'core' => [
            'full_name' => 'Old Parsed Missing Mobile',
            'date_of_birth' => 'not-a-date',
            'gender' => null,
        ],
    ];
    $approval = [
        'core' => [
            'full_name' => 'Reviewed Eligible Candidate',
            'primary_contact_number' => '+91 98765 43210',
            'date_of_birth' => '1998-04-15',
            'gender' => 'female',
        ],
    ];
    $intake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'raw_ocr_text' => 'Original reviewed eligible OCR',
        'parsed_json' => $parsed,
        'approval_snapshot_json' => $approval,
    ]);
    $item = candidateDisplayItem($batch, $intake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('Reviewed Eligible Candidate', false)
        ->assertSee('Mobile: +91 98765 43210', false)
        ->assertSee('data-testid="bulk-candidate-reviewed-badge"', false)
        ->assertSee('data-testid="bulk-screening-badge"', false)
        ->assertSee('Eligible', false)
        ->assertDontSee('Mobile missing', false)
        ->assertDontSee('DOB invalid', false)
        ->assertDontSee('Old Parsed Missing Mobile', false);

    $intake->refresh();
    $item->refresh();

    expect($intake->raw_ocr_text)->toBe('Original reviewed eligible OCR')
        ->and($intake->parsed_json)->toBe($parsed)
        ->and($intake->approval_snapshot_json)->toBe($approval)
        ->and($item->item_meta_json)->toBeNull();
});

test('bulk list shows duplicate hint when same mobile exists in another intake', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    candidateDisplayIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Existing Candidate',
                'primary_contact_number' => '9876543210',
            ],
        ],
    ]);
    $currentParsed = [
        'core' => [
            'full_name' => 'Current Candidate',
            'primary_contact_number' => '+91 98765 43210',
        ],
    ];
    $intake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'raw_ocr_text' => 'Original current duplicate OCR',
        'parsed_json' => $currentParsed,
    ]);
    $item = candidateDisplayItem($batch, $intake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('data-testid="bulk-duplicate-history-hint"', false)
        ->assertSee('Same mobile found', false)
        ->assertSee('data-testid="bulk-screening-badge"', false)
        ->assertSee('Needs review', false)
        ->assertSee('Possible duplicate', false)
        ->assertSee('Mark duplicate', false);

    $intake->refresh();
    $item->refresh();

    expect($intake->raw_ocr_text)->toBe('Original current duplicate OCR')
        ->and($intake->parsed_json)->toBe($currentParsed)
        ->and($item->item_meta_json)->toBeNull();
});

test('bulk list shows manual duplicate badge and clear action', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $intake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Manual Duplicate List Candidate',
                'primary_contact_number' => '9876543210',
            ],
        ],
    ]);
    candidateDisplayItem($batch, $intake, [
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
        'item_meta_json' => [
            'duplicate_review' => [
                'status' => 'manual_duplicate',
                'matched_biodata_intake_id' => $intake->id,
                'matched_profile_id' => null,
                'reason' => 'Already exists in another intake',
                'marked_by_user_id' => $admin->id,
                'marked_at' => '2026-07-08T10:00:00+00:00',
                'cleared_by_user_id' => null,
                'cleared_at' => null,
            ],
        ],
    ]);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('Manual Duplicate List Candidate', false)
        ->assertSee('data-testid="bulk-manual-duplicate-badge"', false)
        ->assertSee('data-testid="bulk-screening-badge"', false)
        ->assertSee('Stop', false)
        ->assertSee('Manual duplicate', false)
        ->assertSee('Clear duplicate', false)
        ->assertDontSee('Mark duplicate', false);
});

test('bulk list shows manual screening badge and clear action', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $intake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Manual Screening List Candidate',
                'primary_contact_number' => '9876543210',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
            ],
        ],
    ]);
    candidateDisplayItem($batch, $intake, [
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
        'item_meta_json' => [
            'screening_review' => [
                'status' => 'eligible_for_consent',
                'reason_key' => 'admin_verified',
                'note' => 'Ready for consent outreach.',
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
        ->assertSee('Manual Screening List Candidate', false)
        ->assertSee('data-testid="bulk-manual-screening-badge"', false)
        ->assertSee('Eligible for consent', false)
        ->assertSee('data-testid="bulk-screening-advisor-hint"', false)
        ->assertSee('Clear screening', false)
        ->assertDontSee('data-testid="bulk-screening-badge"', false)
        ->assertDontSee('Set screening', false);
});

test('bulk list shows set screening action when no manual screening exists', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $intake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Set Screening Candidate',
                'primary_contact_number' => '9876543210',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
            ],
        ],
    ]);
    candidateDisplayItem($batch, $intake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('Set screening', false)
        ->assertSee('data-testid="bulk-screening-badge"', false)
        ->assertSee('Eligible', false)
        ->assertDontSee('data-testid="bulk-manual-screening-badge"', false)
        ->assertDontSee('Clear screening', false);
});

test('manual screening badge does not change item status or intake evidence', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $parsed = [
        'core' => [
            'full_name' => 'Screening Evidence Candidate',
            'primary_contact_number' => '9876543210',
        ],
    ];
    $intake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'raw_ocr_text' => 'Original screening evidence OCR',
        'parsed_json' => $parsed,
        'approval_snapshot_json' => [
            'core' => [
                'full_name' => 'Reviewed Screening Evidence Candidate',
            ],
        ],
    ]);
    $item = candidateDisplayItem($batch, $intake, [
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ]);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.save-screening-review', [$batch, $item]), [
            'status' => 'stopped',
            'reason_key' => 'invalid_candidate',
            'note' => 'Not a valid matrimony candidate.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $item->refresh();
    $intake->refresh();

    expect($item->item_status)->toBe(BulkIntakeBatchItem::STATUS_INTAKE_CREATED)
        ->and(data_get($item->item_meta_json, 'screening_review.status'))->toBe('stopped')
        ->and(data_get($item->item_meta_json, 'screening_review.full_name'))->toBeNull()
        ->and(data_get($item->item_meta_json, 'screening_review.candidate'))->toBeNull()
        ->and($intake->raw_ocr_text)->toBe('Original screening evidence OCR')
        ->and($intake->parsed_json)->toBe($parsed)
        ->and($intake->approval_snapshot_json)->toBe([
            'core' => [
                'full_name' => 'Reviewed Screening Evidence Candidate',
            ],
        ]);
});

test('bulk list shows needs review screening for missing mobile', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $intake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Missing Mobile Candidate',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
            ],
        ],
    ]);
    candidateDisplayItem($batch, $intake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('Missing Mobile Candidate', false)
        ->assertSee('data-testid="bulk-screening-badge"', false)
        ->assertSee('Needs review', false)
        ->assertSee('Mobile missing', false)
        ->assertSee('Mobile: —', false);
});

test('bulk list does not show duplicate hint for unique candidate', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $intake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Unique Candidate',
                'primary_contact_number' => '9123456789',
                'date_of_birth' => '1998-04-15',
            ],
        ],
    ]);
    candidateDisplayItem($batch, $intake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('Unique Candidate', false)
        ->assertDontSee('data-testid="bulk-duplicate-history-hint"', false)
        ->assertDontSee('Same mobile found', false)
        ->assertDontSee('Same name + DOB', false)
        ->assertDontSee('Previous intake found', false);
});

test('duplicate hints prefer reviewed snapshot over parsed json', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    candidateDisplayIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Reviewed Mobile Reference',
                'primary_contact_number' => '9000000000',
            ],
        ],
    ]);
    $parsed = [
        'core' => [
            'full_name' => 'Old Parsed Candidate',
            'primary_contact_number' => '9111111111',
        ],
    ];
    $intake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'parsed_json' => $parsed,
        'approval_snapshot_json' => [
            'core' => [
                'full_name' => 'Reviewed Candidate',
                'primary_contact_number' => '+91 90000 00000',
            ],
        ],
    ]);
    $item = candidateDisplayItem($batch, $intake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('Reviewed Candidate', false)
        ->assertSee('Same mobile found', false)
        ->assertSee('data-testid="bulk-candidate-reviewed-badge"', false)
        ->assertDontSee('Mobile: 9111111111', false);

    $intake->refresh();
    $item->refresh();

    expect($intake->parsed_json)->toBe($parsed)
        ->and($item->item_meta_json)->toBeNull();
});

test('bulk list handles missing candidate fields safely', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $intake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Partial Candidate',
            ],
        ],
    ]);
    candidateDisplayItem($batch, $intake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('Partial Candidate', false)
        ->assertSee('Mobile: —', false)
        ->assertSee('Age: —', false)
        ->assertSee('Gender: —', false)
        ->assertSee('Parsed JSON: Yes', false);
});

test('bulk list keeps text item display short and prefers linked parsed status', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $longSummary = 'Full biodata raw text should not be dumped in the table. '
        .str_repeat('Candidate family education occupation mobile address horoscope details. ', 8);
    $intake = candidateDisplayIntake([
        'uploaded_by' => null,
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Short Text Candidate',
                'primary_contact_number' => '9876543210',
                'height_cm' => 166,
                'highest_education' => 'MBA',
            ],
        ],
    ]);
    candidateDisplayItem($batch, $intake, [
        'input_type' => BulkIntakeBatchItem::INPUT_TEXT,
        'original_filename' => null,
        'summary_text' => $longSummary,
        'item_status' => BulkIntakeBatchItem::STATUS_PARSE_QUEUED,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('Text item #1', false)
        ->assertSee('text · parsed', false)
        ->assertSee('Short Text Candidate', false)
        ->assertSee('Mobile: 9876543210', false)
        ->assertSee('5 ft 5 in', false)
        ->assertDontSee('166 cm', false)
        ->assertSee('MBA', false)
        ->assertSee('Parse: OK', false)
        ->assertSee('Parsed JSON: Yes', false)
        ->assertDontSee($longSummary, false)
        ->assertDontSee('text · parse queued', false)
        ->assertDontSee('Free parse queued', false)
        ->assertDontSee('Unclaimed / consent pending', false);
});

test('bulk list explains queued and pending free parse states', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $queuedIntake = candidateDisplayIntake([
        'parse_status' => 'pending',
        'parsed_json' => [],
    ]);
    candidateDisplayItem($batch, $queuedIntake, [
        'item_status' => BulkIntakeBatchItem::STATUS_PARSE_QUEUED,
    ]);
    $pendingIntake = candidateDisplayIntake([
        'parse_status' => 'pending',
        'parsed_json' => [],
    ]);
    candidateDisplayItem($batch, $pendingIntake, [
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('Candidate fields appear after free parse completes. Manual transcript is only needed if OCR/free parse fails.', false)
        ->assertSee('Free parse queued', false)
        ->assertSee('Waiting for free parse', false)
        ->assertSee('Mobile: —', false);
});

test('bulk list shows OCR failure diagnostics when parsed json is missing', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $intake = candidateDisplayIntake([
        'raw_ocr_text' => '',
        'parse_status' => 'pending',
        'parsed_json' => [],
    ]);
    candidateDisplayItem($batch, $intake, [
        'item_status' => BulkIntakeBatchItem::STATUS_NEEDS_REVIEW,
        'failure_code' => 'empty_ocr_text',
        'failure_message' => 'OCR did not extract usable text from this file.',
        'item_meta_json' => [
            'ocr_text_usable' => false,
            'ocr_failure_code' => 'empty_ocr_text',
            'ocr_failure_message' => 'OCR did not extract usable text from this file.',
            'auto_parse_skipped_reason' => 'empty_ocr_text',
        ],
    ]);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('Mobile: —', false)
        ->assertSee('Parsed JSON: No', false)
        ->assertSee('OCR failed: no text extracted', false)
        ->assertSee('OCR failed / no text extracted', false)
        ->assertSee('Add manual transcript (OCR failed fallback)', false);
});

test('bulk list uses OCR fallback name for review when parsed full name is missing', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $intake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'raw_ocr_text' => "बायोडाटा\nकुमारी अंजली गोरखनाथ जाधव\nजन्म तारीख : 12/03/1996",
        'parsed_json' => [
            'core' => [
                'full_name' => null,
            ],
        ],
    ]);
    $item = candidateDisplayItem($batch, $intake);

    $candidate = app(BulkIntakeCandidateDisplayService::class)->candidateForItem($item);

    expect($candidate['full_name'])->toBe('अंजली गोरखनाथ जाधव')
        ->and($candidate['name_source'])->toBe('ocr_fallback')
        ->and($candidate['name_needs_review'])->toBeTrue();

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('अंजली गोरखनाथ जाधव', false)
        ->assertSee('review', false);
});

test('bulk list cleans OCR junk prefix from parsed candidate name', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $intake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => '4 af आशाराणी तात्यासो कदम',
            ],
        ],
    ]);
    $item = candidateDisplayItem($batch, $intake);

    $candidate = app(BulkIntakeCandidateDisplayService::class)->candidateForItem($item);

    expect($candidate['full_name'])->toBe('आशाराणी तात्यासो कदम')
        ->and($candidate['name_source'])->toBe('parsed_json')
        ->and($candidate['name_needs_review'])->toBeTrue();

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('आशाराणी तात्यासो कदम', false)
        ->assertDontSee('4 af आशाराणी', false)
        ->assertSee('review', false);
});

test('relation label names are not used as candidate names', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $intake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'raw_ocr_text' => 'मामा राजू गोरखनाथ जाधव',
        'parsed_json' => [
            'core' => [
                'full_name' => 'मामा राजू गोरखनाथ जाधव',
            ],
        ],
    ]);
    $item = candidateDisplayItem($batch, $intake);

    $candidate = app(BulkIntakeCandidateDisplayService::class)->candidateForItem($item);

    expect($candidate['full_name'])->toBeNull()
        ->and($candidate['name_source'])->toBeNull();

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertDontSee('मामा राजू गोरखनाथ जाधव', false);
});

test('impossible DOB is not shown as trusted age', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $intake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'DOB Review Candidate',
                'date_of_birth' => '1898-08-18',
            ],
        ],
    ]);
    $item = candidateDisplayItem($batch, $intake);

    $candidate = app(BulkIntakeCandidateDisplayService::class)->candidateForItem($item);

    expect($candidate['date_of_birth'])->toBeNull()
        ->and($candidate['age'])->toBeNull()
        ->and($candidate['dob_needs_review'])->toBeTrue();

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertDontSee('1898-08-18', false)
        ->assertSee('Age: —', false)
        ->assertSee('review', false);
});

test('very tall but possible height is shown with review flag', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $intake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Height Review Candidate',
                'height_cm' => 198.12,
            ],
        ],
    ]);
    $item = candidateDisplayItem($batch, $intake);

    $candidate = app(BulkIntakeCandidateDisplayService::class)->candidateForItem($item);

    expect($candidate['height'])->toBe('6 ft 6 in')
        ->and($candidate['height_needs_review'])->toBeTrue();

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('6 ft 6 in', false)
        ->assertDontSee('198 cm', false)
        ->assertSee('review', false);
});

test('long address line is not dumped as city', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $garbageAddress = 'गाव पोस्ट तालुका जिल्हा पत्ता मोबाईल जन्म शिक्षण नोकरी वडील आई '.str_repeat('अतिरिक्त मजकूर ', 8);
    $intake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'City Review Candidate',
                'address_line' => $garbageAddress,
            ],
        ],
    ]);
    $item = candidateDisplayItem($batch, $intake);

    $candidate = app(BulkIntakeCandidateDisplayService::class)->candidateForItem($item);

    expect($candidate['city'])->toBeNull();

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertDontSee($garbageAddress, false);
});

test('long education and occupation OCR paragraphs are not dumped fully', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $longEducation = 'B.Com शिक्षण मोबाईल 9876543210 जन्म 12/03/1996 वडील गोरखनाथ पत्ता पुणे '.str_repeat('जास्त मजकूर ', 6);
    $longOccupation = 'Software Developer Mobile 9876543210 Address Pune Father Gorakhnath '.str_repeat('extra text ', 8);
    $intake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Long Field Candidate',
                'highest_education' => $longEducation,
                'occupation_title' => $longOccupation,
            ],
        ],
    ]);
    $item = candidateDisplayItem($batch, $intake);

    $candidate = app(BulkIntakeCandidateDisplayService::class)->candidateForItem($item);

    expect($candidate['education'])->toBe('Review')
        ->and($candidate['occupation'])->toBe('Review')
        ->and($candidate['education_needs_review'])->toBeTrue()
        ->and($candidate['occupation_needs_review'])->toBeTrue();

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('Review', false)
        ->assertDontSee($longEducation, false)
        ->assertDontSee($longOccupation, false);
});

test('bulk list does not store parsed data on bulk item', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $intake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'raw_ocr_text' => "बायोडाटा\nकुमारी स्टोरेज टेस्ट",
        'parsed_json' => [
            'core' => [
                'full_name' => null,
            ],
        ],
    ]);
    $item = candidateDisplayItem($batch, $intake);

    app(BulkIntakeCandidateDisplayService::class)->candidateForItem($item);

    expect($item->fresh()->item_meta_json)->toBeNull()
        ->and(Schema::hasColumn('bulk_intake_batch_items', 'parsed_json'))->toBeFalse()
        ->and(Schema::hasColumn('bulk_intake_batch_items', 'profile_data_json'))->toBeFalse()
        ->and(Schema::hasColumn('bulk_intake_batch_items', 'parsed_profile_json'))->toBeFalse()
        ->and(Schema::hasColumn('bulk_intake_batch_items', 'normalized_profile_json'))->toBeFalse();
});

test('owner and profile actions stay hidden from main list while extraction actions remain visible', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $pendingIntake = candidateDisplayIntake([
        'uploaded_by' => null,
        'parse_status' => 'pending',
        'parsed_json' => [],
    ]);
    candidateDisplayItem($batch, $pendingIntake);
    $fallbackIntake = candidateDisplayIntake([
        'uploaded_by' => null,
        'parse_status' => 'error',
        'last_error' => 'empty_text',
        'parsed_json' => [],
    ]);
    candidateDisplayItem($batch, $fallbackIntake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('Open intake review', false)
        ->assertSee('Add manual transcript (OCR failed fallback)', false)
        ->assertSee('Queue free parse item', false)
        ->assertSee('Mark needs review', false)
        ->assertDontSee('Profile Readiness', false)
        ->assertDontSee('Profile Readiness details', false)
        ->assertDontSee('Ready for Profile Review', false)
        ->assertDontSee('Owner Missing', false)
        ->assertDontSee('Not ready', false)
        ->assertDontSee('Assign owner', false)
        ->assertDontSee('Create owner', false)
        ->assertDontSee('Create draft profile', false)
        ->assertDontSee('Preview parsed fields', false);
});

test('routes for hidden owner and profile actions still exist', function () {
    expect(route('admin.bulk-intakes.items.assign-owner', ['bulkIntakeBatch' => 123, 'bulkIntakeBatchItem' => 456]))
        ->toBe(url('/admin/bulk-intakes/123/items/456/assign-owner'))
        ->and(route('admin.bulk-intakes.items.create-owner', ['bulkIntakeBatch' => 123, 'bulkIntakeBatchItem' => 456]))
        ->toBe(url('/admin/bulk-intakes/123/items/456/create-owner'))
        ->and(route('admin.bulk-intakes.items.bootstrap-draft-profile', ['bulkIntakeBatch' => 123, 'bulkIntakeBatchItem' => 456]))
        ->toBe(url('/admin/bulk-intakes/123/items/456/bootstrap-draft-profile'))
        ->and(route('admin.bulk-intakes.items.apply-preview', ['bulkIntakeBatch' => 123, 'bulkIntakeBatchItem' => 456]))
        ->toBe(url('/admin/bulk-intakes/123/items/456/apply-preview'))
        ->and(route('admin.bulk-intakes.items.readiness', ['bulkIntakeBatch' => 123, 'bulkIntakeBatchItem' => 456]))
        ->toBe(url('/admin/bulk-intakes/123/items/456/readiness'));
});

test('bulk status pending and screening manual filters work together', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);

    $pendingManualIntake = candidateDisplayIntake([
        'parse_status' => 'pending',
        'parsed_json' => [],
    ]);
    candidateDisplayItem($batch, $pendingManualIntake, [
        'item_status' => BulkIntakeBatchItem::STATUS_PENDING,
        'original_filename' => 'pending-manual-screening.pdf',
        'item_meta_json' => [
            'screening_review' => [
                'status' => 'needs_review',
                'reason_key' => 'admin_followup_needed',
                'note' => 'Pending item manual review',
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => '2026-07-08T10:00:00+00:00',
                'cleared_by_user_id' => null,
                'cleared_at' => null,
            ],
        ],
    ]);

    $advisorPendingIntake = candidateDisplayIntake([
        'parse_status' => 'pending',
        'parsed_json' => [],
    ]);
    candidateDisplayItem($batch, $advisorPendingIntake, [
        'item_status' => BulkIntakeBatchItem::STATUS_PENDING,
        'original_filename' => 'pending-advisor-only.pdf',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', [
            'bulkIntakeBatch' => $batch,
            'status' => 'pending',
            'screening' => 'manual',
        ]))
        ->assertOk()
        ->assertSee('pending-manual-screening.pdf', false)
        ->assertDontSee('pending-advisor-only.pdf', false)
        ->assertSee('data-testid="bulk-manual-screening-badge"', false)
        ->assertSee('All (2)', false)
        ->assertSee('Manual (1)', false)
        ->assertSee('Advisor (1)', false);
});

test('manual screening bucket overrides advisor when both signals differ', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $intake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Manual Overrides Advisor Candidate',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
            ],
        ],
    ]);
    candidateDisplayItem($batch, $intake, [
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
        ->get(route('admin.bulk-intakes.show', [
            'bulkIntakeBatch' => $batch,
            'screening' => 'eligible',
        ]))
        ->assertOk()
        ->assertSee('Manual Overrides Advisor Candidate', false)
        ->assertSee('data-testid="bulk-manual-screening-badge"', false)
        ->assertSee('Eligible for consent', false);
});

test('bulk list renders screening queue pills with counts', function () {
    $admin = candidateDisplayAdminUser();
    [$batch] = candidateDisplayScreeningQueueFixtures($admin);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('data-testid="bulk-screening-filter-pills"', false)
        ->assertSee('data-testid="bulk-screening-filter-all"', false)
        ->assertSee('All (6)', false)
        ->assertSee('Eligible for consent (2)', false)
        ->assertSee('Needs review (2)', false)
        ->assertSee('Stopped (2)', false)
        ->assertSee('Advisor (3)', false)
        ->assertSee('Manual (3)', false);
});

test('bulk screening filter eligible shows only eligible items', function () {
    $admin = candidateDisplayAdminUser();
    [$batch] = candidateDisplayScreeningQueueFixtures($admin);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', ['bulkIntakeBatch' => $batch, 'screening' => 'eligible']))
        ->assertOk()
        ->assertSee('Advisor Eligible Candidate', false)
        ->assertSee('Manual Eligible Candidate', false)
        ->assertDontSee('Advisor Needs Review Candidate', false)
        ->assertDontSee('Manual Needs Review Candidate', false)
        ->assertDontSee('Advisor Stopped Candidate', false)
        ->assertDontSee('Manual Stopped Candidate', false);
});

test('bulk screening filter needs review shows only needs review items', function () {
    $admin = candidateDisplayAdminUser();
    [$batch] = candidateDisplayScreeningQueueFixtures($admin);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', ['bulkIntakeBatch' => $batch, 'screening' => 'needs_review']))
        ->assertOk()
        ->assertSee('Advisor Needs Review Candidate', false)
        ->assertSee('Manual Needs Review Candidate', false)
        ->assertDontSee('Advisor Eligible Candidate', false)
        ->assertDontSee('Manual Eligible Candidate', false)
        ->assertDontSee('Advisor Stopped Candidate', false)
        ->assertDontSee('Manual Stopped Candidate', false);
});

test('bulk screening filter stopped shows only stopped items', function () {
    $admin = candidateDisplayAdminUser();
    [$batch] = candidateDisplayScreeningQueueFixtures($admin);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', ['bulkIntakeBatch' => $batch, 'screening' => 'stopped']))
        ->assertOk()
        ->assertSee('Advisor Stopped Candidate', false)
        ->assertSee('Manual Stopped Candidate', false)
        ->assertDontSee('Advisor Eligible Candidate', false)
        ->assertDontSee('Manual Eligible Candidate', false)
        ->assertDontSee('Advisor Needs Review Candidate', false)
        ->assertDontSee('Manual Needs Review Candidate', false);
});

test('bulk screening filter advisor shows only items without manual screening', function () {
    $admin = candidateDisplayAdminUser();
    [$batch] = candidateDisplayScreeningQueueFixtures($admin);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', ['bulkIntakeBatch' => $batch, 'screening' => 'advisor']))
        ->assertOk()
        ->assertSee('Advisor Eligible Candidate', false)
        ->assertSee('Advisor Needs Review Candidate', false)
        ->assertSee('Advisor Stopped Candidate', false)
        ->assertDontSee('Manual Eligible Candidate', false)
        ->assertDontSee('Manual Needs Review Candidate', false)
        ->assertDontSee('Manual Stopped Candidate', false);
});

test('bulk screening filter manual shows only items with manual screening', function () {
    $admin = candidateDisplayAdminUser();
    [$batch] = candidateDisplayScreeningQueueFixtures($admin);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', ['bulkIntakeBatch' => $batch, 'screening' => 'manual']))
        ->assertOk()
        ->assertSee('Manual Eligible Candidate', false)
        ->assertSee('Manual Needs Review Candidate', false)
        ->assertSee('Manual Stopped Candidate', false)
        ->assertDontSee('Advisor Eligible Candidate', false)
        ->assertDontSee('Advisor Needs Review Candidate', false)
        ->assertDontSee('Advisor Stopped Candidate', false);
});

test('bulk screening counts respect status filter dataset', function () {
    $admin = candidateDisplayAdminUser();
    [$batch, $pendingItem] = candidateDisplayScreeningQueueFixtures($admin, withPendingItem: true);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', ['bulkIntakeBatch' => $batch, 'status' => 'parsed']))
        ->assertOk()
        ->assertSee('All (6)', false)
        ->assertSee('Eligible for consent (2)', false)
        ->assertSee('Needs review (2)', false)
        ->assertSee('Stopped (2)', false)
        ->assertSee('Advisor (3)', false)
        ->assertSee('Manual (3)', false)
        ->assertDontSee('Pending Parse Candidate', false);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', ['bulkIntakeBatch' => $batch, 'status' => 'all']))
        ->assertOk()
        ->assertSee('All (7)', false)
        ->assertSee('Pending Parse Candidate', false)
        ->assertSee('id="bulk-item-'.$pendingItem->id.'"', false);
});

test('bulk status and screening filters work together', function () {
    $admin = candidateDisplayAdminUser();
    [$batch] = candidateDisplayScreeningQueueFixtures($admin, withPendingItem: true);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', [
            'bulkIntakeBatch' => $batch,
            'status' => 'parsed',
            'screening' => 'eligible',
        ]))
        ->assertOk()
        ->assertSee('Advisor Eligible Candidate', false)
        ->assertSee('Manual Eligible Candidate', false)
        ->assertDontSee('Advisor Needs Review Candidate', false)
        ->assertDontSee('Pending Parse Candidate', false);
});

test('bulk screening filter preserves status query param in pill links', function () {
    $admin = candidateDisplayAdminUser();
    [$batch] = candidateDisplayScreeningQueueFixtures($admin);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', [
            'bulkIntakeBatch' => $batch,
            'status' => 'parsed',
            'screening' => 'eligible',
        ]))
        ->assertOk()
        ->assertSee('status=parsed', false)
        ->assertSee('screening=needs_review', false)
        ->assertSee('data-testid="bulk-screening-clear-filters"', false);
});

test('bulk highlight item survives screening filter', function () {
    $admin = candidateDisplayAdminUser();
    [$batch, , $manualEligibleItem] = candidateDisplayScreeningQueueFixtures($admin);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', [
            'bulkIntakeBatch' => $batch,
            'screening' => 'manual',
            'highlight_item' => $manualEligibleItem->id,
        ]))
        ->assertOk()
        ->assertSee('id="bulk-item-'.$manualEligibleItem->id.'"', false)
        ->assertSee('background-color: #ecfdf5;', false)
        ->assertSee('Manual Eligible Candidate', false)
        ->assertDontSee('Advisor Eligible Candidate', false);
});

test('bulk screening clear filters link resets status and screening params', function () {
    $admin = candidateDisplayAdminUser();
    [$batch] = candidateDisplayScreeningQueueFixtures($admin);

    $response = $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', [
            'bulkIntakeBatch' => $batch,
            'status' => 'parsed',
            'screening' => 'eligible',
        ]))
        ->assertOk();

    $clearUrl = route('admin.bulk-intakes.show', ['bulkIntakeBatch' => $batch]);
    $response->assertSee($clearUrl, false);
});

function candidateDisplayAdminUser(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function candidateDisplayBatch(User $admin): BulkIntakeBatch
{
    return BulkIntakeBatch::create([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_name' => 'Candidate display batch',
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);
}

function candidateDisplayIntake(array $overrides = []): BiodataIntake
{
    return BiodataIntake::create(array_merge([
        'uploaded_by' => null,
        'raw_ocr_text' => 'Name: Candidate Display',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}

function candidateDisplayItem(BulkIntakeBatch $batch, BiodataIntake $intake, array $overrides = []): BulkIntakeBatchItem
{
    return BulkIntakeBatchItem::create(array_merge([
        'bulk_intake_batch_id' => $batch->id,
        'biodata_intake_id' => $intake->id,
        'item_sequence' => ((int) BulkIntakeBatchItem::where('bulk_intake_batch_id', $batch->id)->max('item_sequence')) + 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'candidate-display.pdf',
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ], $overrides));
}

/**
 * @return array{0: BulkIntakeBatch, 1?: BulkIntakeBatchItem, 2?: BulkIntakeBatchItem}
 */
function candidateDisplayScreeningQueueFixtures(User $admin, bool $withPendingItem = false): array
{
    $batch = candidateDisplayBatch($admin);

    $advisorEligibleIntake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Advisor Eligible Candidate',
                'primary_contact_number' => '9876543201',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
            ],
        ],
    ]);
    candidateDisplayItem($batch, $advisorEligibleIntake);

    $advisorNeedsReviewIntake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Advisor Needs Review Candidate',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
            ],
        ],
    ]);
    candidateDisplayItem($batch, $advisorNeedsReviewIntake);

    $advisorStoppedIntake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Advisor Stopped Candidate',
                'primary_contact_number' => '12345',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
            ],
        ],
    ]);
    candidateDisplayItem($batch, $advisorStoppedIntake);

    $manualEligibleIntake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Manual Eligible Candidate',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
            ],
        ],
    ]);
    $manualEligibleItem = candidateDisplayItem($batch, $manualEligibleIntake, [
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

    $manualNeedsReviewIntake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Manual Needs Review Candidate',
                'primary_contact_number' => '9876543205',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
            ],
        ],
    ]);
    candidateDisplayItem($batch, $manualNeedsReviewIntake, [
        'item_meta_json' => [
            'screening_review' => [
                'status' => 'needs_review',
                'reason_key' => 'admin_followup_needed',
                'note' => null,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => '2026-07-08T10:00:00+00:00',
                'cleared_by_user_id' => null,
                'cleared_at' => null,
            ],
        ],
    ]);

    $manualStoppedIntake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Manual Stopped Candidate',
                'primary_contact_number' => '9876543206',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
            ],
        ],
    ]);
    candidateDisplayItem($batch, $manualStoppedIntake, [
        'item_meta_json' => [
            'screening_review' => [
                'status' => 'stopped',
                'reason_key' => 'not_interested',
                'note' => null,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => '2026-07-08T10:00:00+00:00',
                'cleared_by_user_id' => null,
                'cleared_at' => null,
            ],
        ],
    ]);

    $pendingItem = null;
    if ($withPendingItem) {
        $pendingIntake = candidateDisplayIntake([
            'parse_status' => 'pending',
            'parsed_json' => [
                'core' => [
                    'full_name' => 'Pending Parse Candidate',
                ],
            ],
        ]);
        $pendingItem = candidateDisplayItem($batch, $pendingIntake);
    }

    return $pendingItem === null
        ? [$batch, null, $manualEligibleItem]
        : [$batch, $pendingItem, $manualEligibleItem];
}
