<?php

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\User;
use App\Services\Intake\IntakeOcrAttemptRecorder;
use App\Services\Intake\IntakeQualitySignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('empty OCR text produces low quality and empty_text failure', function () {
    $service = app(IntakeQualitySignalService::class);

    $summary = $service->qualitySummary('');

    expect($summary['score'])->toBe(0.0)
        ->and($summary['is_low'])->toBeTrue()
        ->and($service->failureCodes('', null))->toContain(BiodataIntakeOcrAttempt::FAILURE_EMPTY_TEXT);
});

test('normal biodata like text scores higher than empty text', function () {
    $service = app(IntakeQualitySignalService::class);
    $text = implode("\n", [
        'नाव : राहुल पाटील',
        'जन्म तारीख : १२/०४/१९९६',
        'शिक्षण : B.Com',
        'नोकरी : Software Developer',
        'मोबाईल : 9876543210',
        'पत्ता : पुणे',
    ]);

    $empty = $service->qualitySummary('');
    $normal = $service->qualitySummary($text);
    $fieldScores = $service->fieldScoresFromText($text);

    expect($normal['score'])->toBeGreaterThan($empty['score'])
        ->and($normal['line_count'])->toBe(6)
        ->and($fieldScores['primary_contact_number'])->toBeGreaterThan(0.5);
});

test('parsed_json confidence keys mark present and missing fields', function () {
    $service = app(IntakeQualitySignalService::class);
    $confidence = $service->fieldConfidence([
        'core' => [
            'full_name' => 'राहुल पाटील',
            'date_of_birth' => '1996-04-12',
            'height_cm' => 172,
            'highest_education' => 'B.Com',
            'occupation_title' => 'Developer',
            'religion_id' => 1,
            'caste_id' => 2,
        ],
        'contacts' => [
            ['phone_number' => '9876543210'],
        ],
        'addresses' => [
            ['address_line' => 'Pune'],
        ],
    ]);
    $missing = $service->fieldConfidence(['core' => []]);

    expect($confidence['full_name']['present'])->toBeTrue()
        ->and($confidence['full_name']['score'])->toBeGreaterThan(0.8)
        ->and($confidence['primary_contact_number']['present'])->toBeTrue()
        ->and($missing['full_name']['present'])->toBeFalse()
        ->and($missing['full_name']['score'])->toBeLessThan(0.2);
});

test('quality signal storage does not mutate parsed_json or raw_ocr_text', function () {
    $user = User::factory()->create();
    $parsed = [
        'core' => [
            'full_name' => 'Parsed Candidate',
            'date_of_birth' => '1996-04-12',
        ],
        'contacts' => [
            ['phone_number' => '9876543210'],
        ],
    ];
    $intake = BiodataIntake::create([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'Original OCR text',
        'parsed_json' => $parsed,
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);

    $stored = app(IntakeQualitySignalService::class)->storeForIntake(
        $intake,
        "नाव : Parsed Candidate\nमोबाईल : 9876543210",
        $parsed,
    );

    expect($stored->raw_ocr_text)->toBe('Original OCR text')
        ->and($stored->parsed_json)->toBe($parsed)
        ->and($stored->quality_summary_json)->not->toBeNull()
        ->and($stored->field_confidence_json['full_name']['present'])->toBeTrue();
});

test('provider failure records failure code without primary evidence', function () {
    $user = User::factory()->create();
    $intake = BiodataIntake::create([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'Original OCR text',
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'ai_vision_extract_v1',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);

    $attempt = app(IntakeOcrAttemptRecorder::class)->record($intake, [
        'engine' => BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION,
        'source' => 'server_ai_vision',
        'status' => BiodataIntakeOcrAttempt::STATUS_FAILED,
        'failure_code' => 'sarvam_job_timeout',
        'failure_message' => 'Provider timed out',
    ]);

    expect($attempt->failure_code)->toBe(BiodataIntakeOcrAttempt::FAILURE_PROVIDER_TIMEOUT)
        ->and($attempt->is_primary)->toBeFalse()
        ->and(BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->where('is_primary', true)->count())->toBe(0);
});

test('ML Kit evidence quality signal does not overwrite raw_ocr_text', function () {
    $user = User::factory()->create();
    $intake = BiodataIntake::create([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'Server OCR text remains primary storage',
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'hybrid_v1',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);
    $mlKitText = "नाव : एमएल किट\nजन्म तारीख : १२/०४/१९९६\nशिक्षण : B.Com\nनोकरी : Developer\nमोबाईल : 9876543210";

    $attempt = app(IntakeOcrAttemptRecorder::class)->record($intake, [
        'engine' => BiodataIntakeOcrAttempt::ENGINE_ML_KIT_FLUTTER,
        'source' => 'mobile_app',
        'created_by_user_id' => $user->id,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_MOBILE_APP,
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => $mlKitText,
        'raw_lines_json' => [
            ['text' => 'नाव : एमएल किट'],
            ['text' => 'जन्म तारीख : १२/०४/१९९६'],
            ['text' => 'शिक्षण : B.Com'],
            ['text' => 'नोकरी : Developer'],
            ['text' => 'मोबाईल : 9876543210'],
        ],
        'raw_blocks_json' => [
            ['text' => $mlKitText],
        ],
        'layout_meta_json' => [
            'image_width' => 900,
            'image_height' => 1200,
        ],
    ]);

    expect(BiodataIntake::findOrFail($intake->id)->raw_ocr_text)->toBe('Server OCR text remains primary storage')
        ->and($attempt->quality_score)->not->toBeNull()
        ->and($attempt->layout_score)->not->toBeNull()
        ->and($attempt->field_scores_json['primary_contact_number'])->toBeGreaterThan(0.5);
});

test('mobile preview and show responses include optional quality signal keys', function () {
    $user = User::factory()->create();
    $intake = BiodataIntake::create([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'Original OCR text',
        'last_parse_input_text' => "नाव : राहुल पाटील\nमोबाईल : 9876543210",
        'parsed_json' => [
            'core' => [
                'full_name' => 'राहुल पाटील',
            ],
            'contacts' => [
                ['phone_number' => '9876543210'],
            ],
        ],
        'quality_summary_json' => [
            'score' => 0.82,
            'is_low' => false,
        ],
        'failure_codes_json' => [
            BiodataIntakeOcrAttempt::FAILURE_LABEL_VALUE_SPLIT,
        ],
        'field_confidence_json' => [
            'full_name' => [
                'score' => 0.88,
                'present' => true,
                'source_path' => 'core.full_name',
                'reason' => 'parsed_value_present',
            ],
        ],
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parsed_at' => now(),
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/biodata-intakes/'.$intake->id)
        ->assertOk()
        ->assertJsonPath('intake.quality_summary.score', 0.82)
        ->assertJsonPath('intake.failure_codes.0', BiodataIntakeOcrAttempt::FAILURE_LABEL_VALUE_SPLIT)
        ->assertJsonPath('intake.field_confidence.full_name.score', 0.88);

    $this->getJson('/api/v1/biodata-intakes/'.$intake->id.'/preview')
        ->assertOk()
        ->assertJsonPath('preview.quality_summary.score', 0.82)
        ->assertJsonPath('preview.failure_codes.0', BiodataIntakeOcrAttempt::FAILURE_LABEL_VALUE_SPLIT)
        ->assertJsonPath('preview.field_confidence.full_name.score', 0.88);
});
