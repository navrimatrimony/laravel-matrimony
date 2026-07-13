<?php

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\User;
use App\Services\Intake\IntakeOcrEnsemblePhase3Service;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function createPhase3ResolveIntake(User $user, string $rawOcrText, array $overrides = []): BiodataIntake
{
    return BiodataIntake::create(array_merge([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => $rawOcrText,
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}

function createPhase3PrimaryOcrAttempt(BiodataIntake $intake, string $rawText): BiodataIntakeOcrAttempt
{
    return BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => $rawText,
        'is_primary' => true,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_SERVER,
    ]);
}

test('phase3 resolve persists field_resolution_json and last_parse_input_text', function () {
    $user = User::factory()->create();
    $rawOcr = <<<'TXT'
मुलाचे नाव : अविनाश अर्जुन खोडवे
जन्म तारीख : 04/01/1992
मोबाईल : 9876543210
कौटुंबिक माहिती
वडीलांचे नाव : रामदास खोडवे
TXT;
    $intake = createPhase3ResolveIntake($user, $rawOcr);
    createPhase3PrimaryOcrAttempt($intake, $rawOcr);

    $result = app(IntakeOcrEnsemblePhase3Service::class)->resolve($intake->fresh());
    $fresh = $intake->fresh();

    expect($result->wasResolved())->toBeTrue()
        ->and($result->envelope)->not->toBeNull()
        ->and($result->assembledParseInputText)->not->toBeNull()
        ->and($fresh->field_resolution_json)->toBeArray()
        ->and(array_keys($fresh->field_resolution_json['fields']))->toBe(OcrEnsemblePhase3Constants::STRUCTURED_FIELDS)
        ->and($fresh->field_resolution_json['_meta']['vote_mode'])->toBe(OcrEnsemblePhase3Constants::VOTE_MODE_SINGLE_ENGINE_PASS_THROUGH)
        ->and($fresh->field_resolution_json['_meta']['intake_id'])->toBe($intake->id)
        ->and($fresh->field_resolution_json['fields']['full_name']['status'])->toBe(OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED)
        ->and($fresh->field_resolution_json['fields']['primary_contact_number']['final'])->toBe('9876543210')
        ->and($fresh->last_parse_input_text)->toContain('मुलाचे नाव :')
        ->and($fresh->last_parse_input_text)->toContain('अविनाश')
        ->and($fresh->last_parse_input_text)->toContain('वडीलांचे नाव : रामदास खोडवे');
});

test('phase3 resolve stores validator metadata on resolved fields', function () {
    $user = User::factory()->create();
    $rawOcr = "मुलाचे नाव : Test Candidate\nमोबाईल : 9876543210\nअपेक्षा माहिती ठेवली.";
    $intake = createPhase3ResolveIntake($user, $rawOcr);
    createPhase3PrimaryOcrAttempt($intake, $rawOcr);

    app(IntakeOcrEnsemblePhase3Service::class)->resolve($intake->fresh());

    $mobile = $intake->fresh()->field_resolution_json['fields']['primary_contact_number'];

    expect($mobile['status'])->toBe(OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED)
        ->and($mobile['source'])->toBe(OcrEnsemblePhase3Constants::FIELD_SOURCE_VALIDATOR)
        ->and($mobile['reason'])->toBe('single_engine_pass_through_after_validator')
        ->and($mobile['validator']['passed'])->toBeTrue()
        ->and($mobile['winning_engine'])->toBe(OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR);
});

test('phase3 resolve skips when assembled parse input is too short', function () {
    $user = User::factory()->create();
    $intake = createPhase3ResolveIntake($user, 'ab');
    createPhase3PrimaryOcrAttempt($intake, 'ab');

    $result = app(IntakeOcrEnsemblePhase3Service::class)->resolve($intake->fresh());

    expect($result->wasSkipped())->toBeTrue()
        ->and($result->reason)->toBe('assembled_parse_input_too_short')
        ->and($intake->fresh()->field_resolution_json)->toBeNull()
        ->and($intake->fresh()->last_parse_input_text)->toBeNull();
});

test('phase3 resolve leaves missing fields explicit in envelope', function () {
    $user = User::factory()->create();
    $rawOcr = "मुलाचे नाव : Lone Name Field\nअपेक्षा विभाग ठेवला.";
    $intake = createPhase3ResolveIntake($user, $rawOcr);
    createPhase3PrimaryOcrAttempt($intake, $rawOcr);

    app(IntakeOcrEnsemblePhase3Service::class)->resolve($intake->fresh());

    $fields = $intake->fresh()->field_resolution_json['fields'];

    expect($fields['full_name']['status'])->toBe(OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED)
        ->and($fields['marital_status']['status'])->toBe(OcrEnsemblePhase3Constants::FIELD_STATUS_MISSING)
        ->and($fields['marital_status']['final'])->toBeNull();
});

test('phase3 resolve does not modify raw_ocr_text', function () {
    $user = User::factory()->create();
    $rawOcr = "मुलाचे नाव : Immutable OCR\nमोबाईल : 9876543210\nपरिचय ठेवला.";
    $intake = createPhase3ResolveIntake($user, $rawOcr);
    createPhase3PrimaryOcrAttempt($intake, $rawOcr);

    app(IntakeOcrEnsemblePhase3Service::class)->resolve($intake->fresh());

    expect($intake->fresh()->raw_ocr_text)->toBe($rawOcr);
});
