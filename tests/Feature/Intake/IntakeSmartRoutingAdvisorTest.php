<?php

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\User;
use App\Services\Intake\IntakeSmartRoutingAdvisor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('high quality cheap OCR recommends cheap OCR only without mutating truth fields', function () {
    $parsed = [
        'core' => [
            'full_name' => 'Parsed Candidate',
            'date_of_birth' => '1996-04-12',
        ],
        'contacts' => [
            ['phone_number' => '9876543210'],
        ],
    ];
    $intake = createRoutingAdvisorIntake([
        'raw_ocr_text' => 'Original immutable OCR text',
        'last_parse_input_text' => "Name: Parsed Candidate\nMobile: 9876543210",
        'parsed_json' => $parsed,
        'parse_status' => 'parsed',
        'ai_calls_used' => 1,
        'quality_summary_json' => [
            'score' => 0.9,
            'is_low' => false,
        ],
        'failure_codes_json' => [],
        'field_confidence_json' => [
            'full_name' => [
                'score' => 0.92,
                'present' => true,
            ],
            'primary_contact_number' => [
                'score' => 0.9,
                'present' => true,
            ],
        ],
    ]);
    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_ML_KIT_FLUTTER,
        'source' => 'mobile_app',
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'Name: Parsed Candidate',
        'quality_score' => 0.9,
        'cost_units' => 0,
    ]);

    $stored = app(IntakeSmartRoutingAdvisor::class)->storeForIntake($intake);

    expect($stored->routing_recommendation_json['recommended_action'])->toBe('cheap_ocr_only')
        ->and($stored->routing_recommendation_json['would_skip_paid_vision'])->toBeTrue()
        ->and($stored->routing_recommendation_json['would_call_paid_vision'])->toBeFalse()
        ->and($stored->routing_telemetry_json['cheap_ocr_attempt_count'])->toBe(1)
        ->and($stored->raw_ocr_text)->toBe('Original immutable OCR text')
        ->and($stored->parsed_json)->toBe($parsed)
        ->and($stored->parse_status)->toBe('parsed')
        ->and($stored->ai_calls_used)->toBe(1);
});

test('empty text with image recommends paid vision in dry run', function () {
    $intake = createRoutingAdvisorIntake([
        'file_path' => 'intakes/empty.jpg',
        'raw_ocr_text' => '',
        'parsed_json' => [],
        'parse_status' => 'error',
        'quality_summary_json' => [
            'score' => 0.0,
            'is_low' => true,
        ],
        'failure_codes_json' => [
            BiodataIntakeOcrAttempt::FAILURE_EMPTY_TEXT,
        ],
        'field_confidence_json' => [],
    ]);

    $recommendation = app(IntakeSmartRoutingAdvisor::class)->recommend($intake);

    expect($recommendation['recommended_action'])->toBe('call_sarvam')
        ->and($recommendation['would_call_paid_vision'])->toBeTrue()
        ->and($recommendation['reason_codes'])->toContain('empty_text')
        ->and($recommendation['reason_codes'])->toContain('low_quality_cheap_ocr');
});

test('low quality cheap OCR recommends paid vision without changing intake truth fields', function () {
    $parsed = [
        'core' => [
            'full_name' => 'Weak Candidate',
        ],
    ];
    $intake = createRoutingAdvisorIntake([
        'file_path' => 'intakes/weak.jpg',
        'raw_ocr_text' => 'Original weak OCR text',
        'parsed_json' => $parsed,
        'parse_status' => 'parsed',
        'quality_summary_json' => [
            'score' => 0.42,
            'is_low' => true,
        ],
        'failure_codes_json' => [],
        'field_confidence_json' => [
            'date_of_birth' => [
                'score' => 0.2,
                'present' => false,
            ],
        ],
    ]);
    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'source' => 'server_parse',
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'Weak Candidate',
        'quality_score' => 0.42,
    ]);

    $stored = app(IntakeSmartRoutingAdvisor::class)->storeForIntake($intake);

    expect($stored->routing_recommendation_json['recommended_action'])->toBe('call_sarvam')
        ->and($stored->routing_recommendation_json['would_call_paid_vision'])->toBeTrue()
        ->and($stored->routing_recommendation_json['would_skip_paid_vision'])->toBeFalse()
        ->and($stored->routing_recommendation_json['reason_codes'])->toContain('low_quality_cheap_ocr')
        ->and($stored->routing_recommendation_json['reason_codes'])->toContain('field_confidence_low')
        ->and($stored->raw_ocr_text)->toBe('Original weak OCR text')
        ->and($stored->parsed_json)->toBe($parsed)
        ->and($stored->parse_status)->toBe('parsed');
});

test('trusted exact content hash duplicate is reuse eligible but never copies parsed json', function () {
    $sourceRaw = "मुलीचे नांव : कु. Matched Candidate\nजन्मतारीख : 12/03/1996\nमो 9876543210\nशिक्षण बी.कॉम";
    $targetRaw = "मुलीचे नांव : कु. Matched Candidate\nजन्मतारीख : 12/03/1996\nमो 9876543210\nशिक्षण बी.कॉम";
    $sourceParsed = routingAdvisorParsed('Matched Candidate', '1996-03-12', '9876543210', 'B.Com');
    $targetParsed = routingAdvisorParsed('Matched Candidate', '1996-03-12', '9876543210', 'B.Com');
    $targetParsed['core']['local_note'] = 'target-owned-parse';
    $source = createRoutingAdvisorIntake([
        'content_hash' => 'same-image-hash',
        'raw_ocr_text' => $sourceRaw,
        'parsed_json' => $sourceParsed,
        'approval_snapshot_json' => $sourceParsed,
        'parse_status' => 'parsed',
        'quality_summary_json' => [
            'score' => 0.88,
            'is_low' => false,
        ],
    ]);
    BiodataIntakeOcrAttempt::create([
        'intake_id' => $source->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'source' => 'server_parse',
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'Source Candidate',
        'quality_score' => 0.88,
        'is_primary' => true,
    ]);
    $target = createRoutingAdvisorIntake([
        'content_hash' => 'same-image-hash',
        'raw_ocr_text' => $targetRaw,
        'parsed_json' => $targetParsed,
        'parse_status' => 'parsed',
    ]);

    $stored = app(IntakeSmartRoutingAdvisor::class)->storeForIntake($target);

    expect($stored->routing_recommendation_json['recommended_action'])->toBe('reuse_previous')
        ->and($stored->routing_recommendation_json['reason_codes'])->toContain('duplicate_detected')
        ->and($stored->routing_recommendation_json['reason_codes'])->toContain('duplicate_reuse_eligible')
        ->and($stored->routing_recommendation_json['would_skip_paid_vision'])->toBeTrue()
        ->and($stored->routing_recommendation_json['signals']['duplicate_signal_source'])->toBe('content_hash')
        ->and($stored->routing_recommendation_json['signals']['duplicate_match_type'])->toBe('exact_content_hash')
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_intake_id'])->toBe($source->id)
        ->and($stored->routing_recommendation_json['signals']['duplicate_reuse_eligible'])->toBeTrue()
        ->and($stored->routing_recommendation_json['signals']['duplicate_reuse_trust'])->toBe('trusted')
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_has_parsed_json'])->toBeTrue()
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_has_primary_ocr_attempt'])->toBeTrue()
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_quality_score'])->toBe(0.88)
        ->and($stored->routing_recommendation_json['signals']['matched_hash_type'])->toBe('content_hash')
        ->and($stored->routing_recommendation_json['signals']['identity_fingerprint_present'])->toBeTrue()
        ->and($stored->routing_recommendation_json['signals']['duplicate_field_match_eligible'])->toBeTrue()
        ->and($stored->routing_recommendation_json['signals']['duplicate_field_match_score'])->toEqual(1.0)
        ->and($stored->routing_recommendation_json['signals']['duplicate_field_mismatch_codes'])->toBe([])
        ->and($stored->routing_recommendation_json['signals']['current_reference_name_match'])->toBe('yes')
        ->and($stored->routing_recommendation_json['signals']['current_reference_dob_match'])->toBe('yes')
        ->and($stored->routing_recommendation_json['signals']['current_reference_contact_match'])->toBe('yes')
        ->and($stored->routing_recommendation_json['signals']['normalized_text_hash_present'])->toBeFalse()
        ->and($stored->routing_recommendation_json['signals']['image_hash_present'])->toBeFalse()
        ->and($stored->routing_telemetry_json['reuse_candidate_found'])->toBeTrue()
        ->and($stored->raw_ocr_text)->toBe($targetRaw)
        ->and($stored->parsed_json)->toBe($targetParsed)
        ->and($stored->parsed_json)->not->toBe($sourceParsed);
});

test('reviewed reference with contact mismatch is not reuse eligible', function () {
    [$stored] = routingAdvisorReviewedDuplicateRecommendation(
        routingAdvisorParsed('Field Match Candidate', '1996-04-12', '9876543210', 'MCA'),
        routingAdvisorParsed('Field Match Candidate', '1996-04-12', '9123456780', 'MCA'),
        'contact-mismatch-hash'
    );

    expect($stored->routing_recommendation_json['recommended_action'])->toBe('manual_review')
        ->and($stored->routing_recommendation_json['reason_codes'])->toContain('duplicate_field_mismatch')
        ->and($stored->routing_recommendation_json['would_skip_paid_vision'])->toBeFalse()
        ->and($stored->routing_recommendation_json['signals']['duplicate_reuse_eligible'])->toBeFalse()
        ->and($stored->routing_recommendation_json['signals']['duplicate_reuse_trust'])->toBe('field_mismatch')
        ->and($stored->routing_recommendation_json['signals']['duplicate_field_match_eligible'])->toBeFalse()
        ->and($stored->routing_recommendation_json['signals']['duplicate_field_mismatch_codes'])->toContain('contact_mismatch')
        ->and($stored->routing_recommendation_json['signals']['current_reference_contact_match'])->toBe('no');
});

test('reference parsed raw and backfilled quality without verifiable evidence is not reuse eligible', function () {
    $parsed = routingAdvisorParsed('Backfilled Candidate', '1996-04-12', '9876543210', 'B.Com');
    $source = createRoutingAdvisorIntake([
        'content_hash' => 'backfilled-quality-only-hash',
        'raw_ocr_text' => "Name: Backfilled Candidate\nDOB: 1996-04-12\nMobile: 9876543210",
        'parsed_json' => $parsed,
        'parse_status' => 'parsed',
        'quality_summary_json' => [
            'score' => 0.92,
            'is_low' => false,
        ],
    ]);
    $target = createRoutingAdvisorIntake([
        'content_hash' => 'backfilled-quality-only-hash',
        'raw_ocr_text' => "Name: Backfilled Candidate\nDOB: 1996-04-12\nMobile: 9876543210",
        'parsed_json' => $parsed,
        'parse_status' => 'parsed',
    ]);

    $stored = app(IntakeSmartRoutingAdvisor::class)->storeForIntake($target);

    expect($stored->routing_recommendation_json['recommended_action'])->toBe('manual_review')
        ->and($stored->routing_recommendation_json['would_skip_paid_vision'])->toBeFalse()
        ->and($stored->routing_recommendation_json['reason_codes'])->toContain('duplicate_detected')
        ->and($stored->routing_recommendation_json['reason_codes'])->toContain('backfilled_quality_not_trusted')
        ->and($stored->routing_recommendation_json['reason_codes'])->toContain('duplicate_detected_but_untrusted')
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_intake_id'])->toBe($source->id)
        ->and($stored->routing_recommendation_json['signals']['duplicate_reuse_eligible'])->toBeFalse()
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_reason'])->toBe('reference_parsed_with_backfilled_quality_only')
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_has_verifiable_ocr_evidence'])->toBeFalse()
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_quality_source'])->toBe('backfilled')
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_ocr_attempt_count'])->toBe(0)
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_sarvam_attempt_count'])->toBe(0)
        ->and($stored->routing_recommendation_json['signals']['backfilled_quality_not_trusted'])->toBeTrue()
        ->and($stored->routing_recommendation_json['signals']['duplicate_field_match_eligible'])->toBeTrue();
});

test('reviewed reference without ocr attempts can still be reuse eligible when field match passes', function () {
    $parsed = routingAdvisorParsed('Reviewed Candidate', '1996-04-12', '9876543210', 'B.Com');
    $source = createRoutingAdvisorIntake([
        'content_hash' => 'reviewed-without-attempts-hash',
        'raw_ocr_text' => 'Reviewed candidate raw text',
        'parsed_json' => $parsed,
        'approval_snapshot_json' => $parsed,
        'parse_status' => 'parsed',
        'quality_summary_json' => [
            'score' => 0.91,
            'is_low' => false,
        ],
    ]);
    $target = createRoutingAdvisorIntake([
        'content_hash' => 'reviewed-without-attempts-hash',
        'raw_ocr_text' => 'Reviewed candidate raw text',
        'parsed_json' => $parsed,
        'parse_status' => 'parsed',
    ]);

    $stored = app(IntakeSmartRoutingAdvisor::class)->storeForIntake($target);

    expect($stored->routing_recommendation_json['recommended_action'])->toBe('reuse_previous')
        ->and($stored->routing_recommendation_json['would_skip_paid_vision'])->toBeTrue()
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_intake_id'])->toBe($source->id)
        ->and($stored->routing_recommendation_json['signals']['duplicate_reuse_eligible'])->toBeTrue()
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_reason'])->toBe('reference_has_reviewed_snapshot')
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_has_verifiable_ocr_evidence'])->toBeTrue()
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_quality_source'])->toBe('reviewed')
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_ocr_attempt_count'])->toBe(0)
        ->and($stored->routing_recommendation_json['signals']['backfilled_quality_not_trusted'])->toBeFalse();
});

test('reference with primary ocr attempt can be reuse eligible when field match passes', function () {
    $parsed = routingAdvisorParsed('Primary Evidence Candidate', '1996-04-12', '9876543210', 'B.Com');
    $source = createRoutingAdvisorIntake([
        'content_hash' => 'primary-evidence-hash',
        'raw_ocr_text' => 'Primary evidence candidate raw text',
        'parsed_json' => $parsed,
        'parse_status' => 'parsed',
        'quality_summary_json' => [
            'score' => 0.91,
            'is_low' => false,
        ],
    ]);
    BiodataIntakeOcrAttempt::create([
        'intake_id' => $source->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'source' => 'server_parse',
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'Primary evidence candidate raw text',
        'quality_score' => 0.91,
        'is_primary' => true,
    ]);
    $target = createRoutingAdvisorIntake([
        'content_hash' => 'primary-evidence-hash',
        'raw_ocr_text' => 'Primary evidence candidate raw text',
        'parsed_json' => $parsed,
        'parse_status' => 'parsed',
    ]);

    $stored = app(IntakeSmartRoutingAdvisor::class)->storeForIntake($target);

    expect($stored->routing_recommendation_json['recommended_action'])->toBe('reuse_previous')
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_intake_id'])->toBe($source->id)
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_reason'])->toBe('reference_parsed_with_strong_ocr_evidence')
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_has_primary_ocr_attempt'])->toBeTrue()
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_has_verifiable_ocr_evidence'])->toBeTrue()
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_quality_source'])->toBe('attempt')
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_ocr_attempt_count'])->toBe(1)
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_sarvam_attempt_count'])->toBe(0)
        ->and($stored->routing_recommendation_json['signals']['backfilled_quality_not_trusted'])->toBeFalse();
});

test('reference with successful sarvam attempt can be reuse eligible when field match passes', function () {
    $parsed = routingAdvisorParsed('Sarvam Evidence Candidate', '1996-04-12', '9876543210', 'B.Com');
    $source = createRoutingAdvisorIntake([
        'content_hash' => 'sarvam-evidence-hash',
        'raw_ocr_text' => 'Sarvam evidence candidate raw text',
        'parsed_json' => $parsed,
        'parse_status' => 'parsed',
        'quality_summary_json' => [
            'score' => 0.91,
            'is_low' => false,
        ],
    ]);
    BiodataIntakeOcrAttempt::create([
        'intake_id' => $source->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION,
        'source' => 'server_ai_vision',
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'Sarvam evidence candidate raw text',
        'quality_score' => 0.91,
        'is_primary' => false,
    ]);
    $target = createRoutingAdvisorIntake([
        'content_hash' => 'sarvam-evidence-hash',
        'raw_ocr_text' => 'Sarvam evidence candidate raw text',
        'parsed_json' => $parsed,
        'parse_status' => 'parsed',
    ]);

    $stored = app(IntakeSmartRoutingAdvisor::class)->storeForIntake($target);

    expect($stored->routing_recommendation_json['recommended_action'])->toBe('reuse_previous')
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_intake_id'])->toBe($source->id)
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_reason'])->toBe('reference_parsed_with_strong_ocr_evidence')
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_has_sarvam_attempt'])->toBeTrue()
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_has_verifiable_ocr_evidence'])->toBeTrue()
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_quality_source'])->toBe('attempt')
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_ocr_attempt_count'])->toBe(1)
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_sarvam_attempt_count'])->toBe(1)
        ->and($stored->routing_recommendation_json['signals']['backfilled_quality_not_trusted'])->toBeFalse();
});

test('reviewed reference with dob mismatch is not reuse eligible', function () {
    [$stored] = routingAdvisorReviewedDuplicateRecommendation(
        routingAdvisorParsed('Field Match Candidate', '1996-04-12', '9876543210', 'MCA'),
        routingAdvisorParsed('Field Match Candidate', '1997-04-12', '9876543210', 'MCA'),
        'dob-mismatch-hash'
    );

    expect($stored->routing_recommendation_json['recommended_action'])->toBe('manual_review')
        ->and($stored->routing_recommendation_json['reason_codes'])->toContain('duplicate_field_mismatch')
        ->and($stored->routing_recommendation_json['signals']['duplicate_field_mismatch_codes'])->toContain('dob_mismatch')
        ->and($stored->routing_recommendation_json['signals']['current_reference_dob_match'])->toBe('no')
        ->and($stored->routing_recommendation_json['signals']['duplicate_reuse_eligible'])->toBeFalse();
});

test('reviewed reference missing dob while current has dob is not reuse eligible', function () {
    $reference = routingAdvisorParsed('Field Match Candidate', null, '9876543210', 'MCA');
    unset($reference['core']['date_of_birth']);

    [$stored] = routingAdvisorReviewedDuplicateRecommendation(
        routingAdvisorParsed('Field Match Candidate', '1996-04-12', '9876543210', 'MCA'),
        $reference,
        'missing-dob-hash'
    );

    expect($stored->routing_recommendation_json['recommended_action'])->toBe('manual_review')
        ->and($stored->routing_recommendation_json['signals']['duplicate_field_mismatch_codes'])->toContain('reference_missing_dob')
        ->and($stored->routing_recommendation_json['signals']['current_reference_dob_match'])->toBe('unknown')
        ->and($stored->routing_recommendation_json['signals']['duplicate_reuse_eligible'])->toBeFalse();
});

test('reviewed reference missing name while current has name is not reuse eligible', function () {
    $reference = routingAdvisorParsed(null, '1996-04-12', '9876543210', 'MCA');
    unset($reference['core']['full_name']);

    [$stored] = routingAdvisorReviewedDuplicateRecommendation(
        routingAdvisorParsed('Field Match Candidate', '1996-04-12', '9876543210', 'MCA'),
        $reference,
        'missing-name-hash'
    );

    expect($stored->routing_recommendation_json['recommended_action'])->toBe('manual_review')
        ->and($stored->routing_recommendation_json['signals']['duplicate_field_mismatch_codes'])->toContain('reference_missing_name')
        ->and($stored->routing_recommendation_json['signals']['current_reference_name_match'])->toBe('unknown')
        ->and($stored->routing_recommendation_json['signals']['duplicate_reuse_eligible'])->toBeFalse();
});

test('weak duplicate with only education and address match remains manual review', function () {
    [$stored] = routingAdvisorReviewedDuplicateRecommendation(
        routingAdvisorWeakIdentityParsed('MCA', 'Pune'),
        routingAdvisorWeakIdentityParsed('MCA', 'Pune'),
        'weak-identity-overlap-hash'
    );

    expect($stored->routing_recommendation_json['recommended_action'])->toBe('manual_review')
        ->and($stored->routing_recommendation_json['reason_codes'])->toContain('duplicate_field_mismatch')
        ->and($stored->routing_recommendation_json['signals']['duplicate_field_match_eligible'])->toBeFalse()
        ->and($stored->routing_recommendation_json['signals']['duplicate_field_mismatch_codes'])->toContain('insufficient_identity_overlap')
        ->and($stored->routing_recommendation_json['signals']['duplicate_field_mismatch_codes'])->toContain('only_weak_fields_match')
        ->and($stored->routing_recommendation_json['signals']['current_reference_core_fields_compared'])->toBe(0)
        ->and($stored->routing_recommendation_json['signals']['duplicate_reuse_eligible'])->toBeFalse();
});

test('weak exact content hash duplicate is not reuse eligible', function () {
    $source = createRoutingAdvisorIntake([
        'content_hash' => 'weak-same-image-hash',
        'raw_ocr_text' => 'Weak source OCR text',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Weak Source',
            ],
        ],
        'parse_status' => 'parsed',
    ]);
    $targetParsed = [
        'core' => [
            'full_name' => 'Weak Target',
        ],
    ];
    $target = createRoutingAdvisorIntake([
        'content_hash' => 'weak-same-image-hash',
        'raw_ocr_text' => 'Weak target OCR text',
        'parsed_json' => $targetParsed,
        'parse_status' => 'parsed',
    ]);

    $stored = app(IntakeSmartRoutingAdvisor::class)->storeForIntake($target);

    expect($stored->routing_recommendation_json['recommended_action'])->toBe('manual_review')
        ->and($stored->routing_recommendation_json['reason_codes'])->toContain('duplicate_detected')
        ->and($stored->routing_recommendation_json['reason_codes'])->toContain('duplicate_detected_but_untrusted')
        ->and($stored->routing_recommendation_json['would_skip_paid_vision'])->toBeFalse()
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_intake_id'])->toBe($source->id)
        ->and($stored->routing_recommendation_json['signals']['duplicate_reuse_eligible'])->toBeFalse()
        ->and($stored->routing_recommendation_json['signals']['duplicate_reuse_trust'])->toBe('weak')
        ->and($stored->routing_recommendation_json['signals']['duplicate_reference_reason'])->toBe('reference_lacks_verifiable_ocr_evidence')
        ->and($stored->parsed_json)->toBe($targetParsed);
});

test('self duplicate reference is classified circular and does not skip paid vision', function () {
    $intake = createRoutingAdvisorIntake([
        'raw_ocr_text' => 'Self reference OCR text',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Self Reference',
            ],
        ],
        'parse_status' => 'parsed',
    ]);
    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_REUSED_TRANSCRIPT,
        'source' => 'ai_vision_reuse',
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'engine_meta_json' => [
            'reused_from' => 'identity_fingerprint_cache',
            'source_intake_id' => $intake->id,
        ],
    ]);

    $recommendation = app(IntakeSmartRoutingAdvisor::class)->recommend($intake);

    expect($recommendation['recommended_action'])->toBe('manual_review')
        ->and($recommendation['reason_codes'])->toContain('duplicate_detected_but_untrusted')
        ->and($recommendation['would_skip_paid_vision'])->toBeFalse()
        ->and($recommendation['signals']['duplicate_reference_intake_id'])->toBe($intake->id)
        ->and($recommendation['signals']['duplicate_reuse_trust'])->toBe('circular')
        ->and($recommendation['signals']['duplicate_reference_is_self_or_circular'])->toBeTrue()
        ->and($recommendation['signals']['duplicate_reference_reason'])->toBe('self_reference');
});

test('missing duplicate reference is not reuse eligible', function () {
    $intake = createRoutingAdvisorIntake([
        'raw_ocr_text' => 'Missing reference OCR text',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Missing Reference',
            ],
        ],
        'parse_status' => 'parsed',
    ]);
    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_REUSED_TRANSCRIPT,
        'source' => 'ai_vision_reuse',
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'engine_meta_json' => [
            'reused_from' => 'historical_paid_transcript',
            'source_intake_id' => 999999,
        ],
    ]);

    $recommendation = app(IntakeSmartRoutingAdvisor::class)->recommend($intake);

    expect($recommendation['recommended_action'])->toBe('manual_review')
        ->and($recommendation['would_skip_paid_vision'])->toBeFalse()
        ->and($recommendation['signals']['duplicate_reuse_eligible'])->toBeFalse()
        ->and($recommendation['signals']['duplicate_reuse_trust'])->toBe('missing_reference')
        ->and($recommendation['signals']['duplicate_reference_reason'])->toBe('reference_intake_missing');
});

test('legacy parsed raw no signal recommendation explains missing stored signals without mutating intake', function () {
    $parsed = [
        'core' => [
            'full_name' => 'Legacy Parsed',
        ],
    ];
    $intake = createRoutingAdvisorIntake([
        'raw_ocr_text' => 'Legacy OCR text without quality attempts or hashes',
        'parsed_json' => $parsed,
        'parse_status' => 'pending',
        'quality_summary_json' => null,
        'failure_codes_json' => [],
        'field_confidence_json' => null,
    ]);

    $recommendation = app(IntakeSmartRoutingAdvisor::class)->recommend($intake);

    expect($recommendation['recommended_action'])->toBe('unknown')
        ->and($recommendation['reason_codes'])->toContain('no_signal')
        ->and($recommendation['reason_codes'])->toContain('legacy_intake_missing_quality_signals')
        ->and($recommendation['reason_codes'])->toContain('legacy_intake_missing_ocr_attempts')
        ->and($recommendation['reason_codes'])->toContain('legacy_intake_missing_hashes')
        ->and($recommendation['signals']['has_parsed_json'])->toBeTrue()
        ->and($recommendation['signals']['has_raw_ocr_text'])->toBeTrue()
        ->and($recommendation['signals']['has_quality_summary'])->toBeFalse()
        ->and($recommendation['signals']['has_field_confidence'])->toBeFalse()
        ->and($recommendation['signals']['ocr_attempt_count'])->toBe(0)
        ->and($recommendation['signals']['primary_ocr_attempt_exists'])->toBeFalse()
        ->and($recommendation['signals']['cheap_ocr_attempt_count'])->toBe(0)
        ->and($recommendation['signals']['sarvam_attempt_count'])->toBe(0)
        ->and($recommendation['signals']['normalized_text_hash_present'])->toBeFalse()
        ->and($recommendation['signals']['image_hash_present'])->toBeFalse()
        ->and($intake->fresh()->parse_status)->toBe('pending')
        ->and($intake->fresh()->parsed_json)->toBe($parsed)
        ->and($intake->fresh()->raw_ocr_text)->toBe('Legacy OCR text without quality attempts or hashes');
});

test('provider failure is captured in telemetry and reason codes', function () {
    $intake = createRoutingAdvisorIntake([
        'file_path' => 'intakes/sarvam-timeout.jpg',
        'parse_status' => 'error',
        'quality_summary_json' => [
            'score' => 0.2,
            'is_low' => true,
        ],
        'failure_codes_json' => [
            BiodataIntakeOcrAttempt::FAILURE_PROVIDER_TIMEOUT,
        ],
    ]);
    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION,
        'source' => 'server_ai_vision',
        'status' => BiodataIntakeOcrAttempt::STATUS_FAILED,
        'failure_code' => BiodataIntakeOcrAttempt::FAILURE_PROVIDER_TIMEOUT,
        'failure_message' => 'Provider timed out',
        'duration_ms' => 1200,
        'cost_units' => 1,
    ]);

    $stored = app(IntakeSmartRoutingAdvisor::class)->storeForIntake($intake);

    expect($stored->routing_recommendation_json['recommended_action'])->toBe('manual_review')
        ->and($stored->routing_recommendation_json['reason_codes'])->toContain('provider_error')
        ->and($stored->routing_telemetry_json['sarvam_attempt_count'])->toBe(1)
        ->and($stored->routing_telemetry_json['failed_provider_count'])->toBe(1)
        ->and($stored->routing_telemetry_json['last_provider_failure_code'])->toBe(BiodataIntakeOcrAttempt::FAILURE_PROVIDER_TIMEOUT)
        ->and($stored->routing_telemetry_json['cost_units'])->toEqual(1.0);
});

function createRoutingAdvisorIntake(array $overrides = []): BiodataIntake
{
    $user = User::factory()->create();

    return BiodataIntake::create(array_merge([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'Original OCR text',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}

function routingAdvisorParsed(?string $name, ?string $dateOfBirth, ?string $phone, ?string $education): array
{
    return [
        'core' => array_filter([
            'full_name' => $name,
            'date_of_birth' => $dateOfBirth,
            'primary_contact_number' => $phone,
            'highest_education' => $education,
        ], static fn ($value): bool => $value !== null),
        'contacts' => $phone !== null ? [
            [
                'phone_number' => $phone,
                'is_primary' => true,
            ],
        ] : [],
    ];
}

function routingAdvisorWeakIdentityParsed(string $education, string $address): array
{
    return [
        'core' => [
            'highest_education' => $education,
            'current_address' => $address,
        ],
        'education_history' => [
            ['degree' => $education],
        ],
        'addresses' => [
            ['type' => 'residence', 'address' => $address],
        ],
    ];
}

function routingAdvisorReviewedDuplicateRecommendation(array $currentParsed, array $referenceSnapshot, string $contentHash): array
{
    $reference = createRoutingAdvisorIntake([
        'content_hash' => $contentHash,
        'raw_ocr_text' => 'Reference duplicate OCR text',
        'parsed_json' => $referenceSnapshot,
        'approval_snapshot_json' => $referenceSnapshot,
        'parse_status' => 'parsed',
        'quality_summary_json' => [
            'score' => 0.9,
            'is_low' => false,
        ],
    ]);
    BiodataIntakeOcrAttempt::create([
        'intake_id' => $reference->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'source' => 'server_parse',
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'Reference duplicate OCR text',
        'quality_score' => 0.9,
        'is_primary' => true,
    ]);
    $current = createRoutingAdvisorIntake([
        'content_hash' => $contentHash,
        'raw_ocr_text' => 'Current duplicate OCR text',
        'parsed_json' => $currentParsed,
        'parse_status' => 'parsed',
    ]);

    return [app(IntakeSmartRoutingAdvisor::class)->storeForIntake($current), $reference];
}
