<?php

use App\Jobs\ParseIntakeJob;
use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\User;
use App\Services\Intake\IntakeOcrEnsembleGate;
use App\Services\Intake\IntakeOcrEnsemblePhase3Service;
use App\Services\OcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

function phase3ParseJobLongMarathiBody(string $candidateName): string
{
    return implode("\n", [
        "मुलीचे नांव : {$candidateName}",
        'जन्मतारीख : 12/03/1996',
        'मो 9876543210',
        'शिक्षण बी.कॉम',
        'नोकरी खाजगी',
        'जन्मस्थळ पुणे',
        'धर्म हिंदू',
        'जात मराठा',
        'पत्ता महाराष्ट्र',
        'अपेक्षा शिक्षित वर',
    ]);
}

function configureNativeParseIntakeJob(): void
{
    config([
        'intake.testing_active_parser' => 'rules_only',
        'intake.testing_parse_job_uses_ai_vision' => false,
    ]);
}

function createPhase3ParseJobIntake(User $user, string $rawOcr, ?string $assembled = null, ?array $fieldResolutionJson = null): BiodataIntake
{
    return BiodataIntake::create([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => $rawOcr,
        'last_parse_input_text' => $assembled,
        'field_resolution_json' => $fieldResolutionJson,
        'parse_status' => 'pending',
        'intake_status' => 'DRAFT',
        'intake_locked' => false,
        'approved_by_user' => false,
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
    ]);
}

test('native parse job prefers phase3 assembled last_parse_input_text when present', function () {
    Cache::flush();
    configureNativeParseIntakeJob();

    $user = User::factory()->create();
    $rawOcr = phase3ParseJobLongMarathiBody('Raw Fallback Candidate');
    $assembled = phase3ParseJobLongMarathiBody('Phase3 Assembled Candidate');

    $intake = createPhase3ParseJobIntake($user, $rawOcr, $assembled, [
        'schema_version' => 'phase3_v1',
        'fields' => ['full_name' => ['status' => 'resolved']],
    ]);

    $this->partialMock(OcrService::class, function ($mock): void {
        $mock->shouldNotReceive('buildParseInputFromDbRawOcr');
        $mock->shouldNotReceive('resolveParseInputText');
    });

    (new ParseIntakeJob((int) $intake->id))->handle();

    $intake->refresh();
    $parsed = is_array($intake->parsed_json) ? $intake->parsed_json : [];
    $ocrDebug = Cache::get('intake.parse_ocr_debug.'.$intake->id);

    expect($intake->parse_status)->toBe('parsed')
        ->and($parsed['core']['full_name'] ?? null)->toBe('Phase3 Assembled Candidate')
        ->and($ocrDebug['parse_input_source'] ?? null)->toBe('ensemble_assembled_phase3')
        ->and($ocrDebug['ocr_pipeline'] ?? null)->toBe('phase3_last_parse_input_text')
        ->and((string) $intake->last_parse_input_text)->toContain('Phase3 Assembled Candidate')
        ->and((string) $intake->last_parse_input_text)->not->toContain('Raw Fallback Candidate');
});

test('native parse job falls back to buildParseInputFromDbRawOcr when last_parse_input_text is missing', function () {
    Cache::flush();
    configureNativeParseIntakeJob();

    $user = User::factory()->create();
    $rawOcr = phase3ParseJobLongMarathiBody('Raw Fallback Candidate');
    $intake = createPhase3ParseJobIntake($user, $rawOcr, null, null);

    $this->partialMock(OcrService::class, function ($mock) use ($intake, $rawOcr): void {
        $mock->shouldReceive('buildParseInputFromDbRawOcr')
            ->once()
            ->with(\Mockery::on(fn (BiodataIntake $model): bool => (int) $model->id === (int) $intake->id))
            ->andReturn([
                'text' => $rawOcr,
                'ocr_debug' => [
                    'kind' => 'stored_text',
                    'ocr_source_type' => 'raw_ocr_text_column',
                    'ocr_pipeline' => 'reparse_raw_ocr_text_only',
                ],
            ]);
        $mock->shouldNotReceive('resolveParseInputText');
    });

    (new ParseIntakeJob((int) $intake->id))->handle();

    $intake->refresh();
    $parsed = is_array($intake->parsed_json) ? $intake->parsed_json : [];

    expect($intake->parse_status)->toBe('parsed')
        ->and($parsed['core']['full_name'] ?? null)->toBe('Raw Fallback Candidate');
});

test('native parse job keeps legacy resolveParseInputText when raw ocr is blank', function () {
    Cache::flush();
    configureNativeParseIntakeJob();

    $user = User::factory()->create();
    $intake = createPhase3ParseJobIntake($user, '', null, null);

    $legacyText = phase3ParseJobLongMarathiBody('Legacy Resolve Candidate');

    $this->partialMock(OcrService::class, function ($mock) use ($legacyText): void {
        $mock->shouldReceive('resolveParseInputText')
            ->once()
            ->andReturn([
                'text' => $legacyText,
                'ocr_debug' => [
                    'kind' => 'stored_text',
                    'ocr_source_type' => 'manual_prepared',
                    'ocr_pipeline' => 'manual_then_direct_tesseract',
                ],
            ]);
        $mock->shouldNotReceive('buildParseInputFromDbRawOcr');
    });

    (new ParseIntakeJob((int) $intake->id))->handle();

    $intake->refresh();
    $parsed = is_array($intake->parsed_json) ? $intake->parsed_json : [];

    expect($intake->parse_status)->toBe('parsed')
        ->and($parsed['core']['full_name'] ?? null)->toBe('Legacy Resolve Candidate');
});

test('phase3 disabled leaves parse job on raw ocr fallback path', function () {
    Cache::flush();
    configureNativeParseIntakeJob();
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, false);
    config()->set('ocr.ensemble.phase3.enabled', true);

    $user = User::factory()->create();
    $rawOcr = <<<'TXT'
मुलाचे नाव : Gate Off Raw Candidate
मोबाईल : 9876543210
जन्म तारीख : 04/01/1992
कौटुंबिक माहिती ठेवली.
TXT;

    $intake = BiodataIntake::create([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => $rawOcr,
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);

    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => $rawOcr,
        'is_primary' => true,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_SERVER,
    ]);

    $batch = BulkIntakeBatch::create([
        'uploaded_by_user_id' => $user->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_status' => BulkIntakeBatch::STATUS_PENDING,
    ]);

    $item = BulkIntakeBatchItem::create([
        'bulk_intake_batch_id' => $batch->id,
        'biodata_intake_id' => $intake->id,
        'item_sequence' => 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'phase3-gate-off.pdf',
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ]);

    $phase3Result = app(IntakeOcrEnsemblePhase3Service::class)->runForBulkItemIfApplicable($item);
    $fresh = $intake->fresh();

    expect($phase3Result->wasSkipped())->toBeTrue()
        ->and($phase3Result->reason)->toBe('phase3_gate_disabled')
        ->and($fresh->last_parse_input_text)->toBeNull()
        ->and($fresh->field_resolution_json)->toBeNull();

    (new ParseIntakeJob((int) $intake->id))->handle();

    $intake->refresh();
    $parsed = is_array($intake->parsed_json) ? $intake->parsed_json : [];
    $ocrDebug = Cache::get('intake.parse_ocr_debug.'.$intake->id);

    expect($intake->parse_status)->toBe('parsed')
        ->and(strtolower((string) ($parsed['core']['full_name'] ?? '')))->toBe('gate off raw candidate')
        ->and($ocrDebug['ocr_pipeline'] ?? null)->toBe('reparse_raw_ocr_text_only');
});

test('phase3 enabled end to end uses persisted assembled parse input in parse job', function () {
    Cache::flush();
    configureNativeParseIntakeJob();
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, true);
    config()->set('ocr.ensemble.phase3.enabled', true);

    $user = User::factory()->create();
    $rawOcr = <<<'TXT'
मुलाचे नाव : Integration Raw OCR
मोबाईल : 9876543210
जन्म तारीख : 04/01/1992
कौटुंबिक माहिती ठेवली.
TXT;

    $intake = BiodataIntake::create([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => $rawOcr,
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);

    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => $rawOcr,
        'is_primary' => true,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_SERVER,
    ]);

    $batch = BulkIntakeBatch::create([
        'uploaded_by_user_id' => $user->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_status' => BulkIntakeBatch::STATUS_PENDING,
    ]);

    $item = BulkIntakeBatchItem::create([
        'bulk_intake_batch_id' => $batch->id,
        'biodata_intake_id' => $intake->id,
        'item_sequence' => 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'phase3-enabled.pdf',
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ]);

    $phase3Result = app(IntakeOcrEnsemblePhase3Service::class)->runForBulkItemIfApplicable($item);
    $fresh = $intake->fresh();

    expect($phase3Result->wasResolved())->toBeTrue()
        ->and($fresh->last_parse_input_text)->toContain('Integration Raw OCR')
        ->and($fresh->field_resolution_json)->toBeArray();

    $this->partialMock(OcrService::class, function ($mock): void {
        $mock->shouldNotReceive('buildParseInputFromDbRawOcr');
        $mock->shouldNotReceive('resolveParseInputText');
    });

    (new ParseIntakeJob((int) $intake->id))->handle();

    $intake->refresh();
    $ocrDebug = Cache::get('intake.parse_ocr_debug.'.$intake->id);

    expect($intake->parse_status)->toBe('parsed')
        ->and($ocrDebug['parse_input_source'] ?? null)->toBe('ensemble_assembled_phase3')
        ->and((string) $intake->last_parse_input_text)->toBe((string) $fresh->last_parse_input_text);
});
