<?php

use App\Jobs\ParseIntakeJob;
use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\User;
use App\Services\AiVisionExtractionService;
use App\Services\Intake\IntakeOcrAttemptRecorder;
use App\Services\OcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('mobile biodata intake store creates owner scoped rules only intake from OCR text', function () {
    Queue::fake();

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $rawText = "नाव : राहुल पाटील\nजन्म तारीख : १२/०४/१९९६\nशिक्षण : B.Com\nमोबाईल : 9876543210";

    $response = $this->postJson('/api/v1/biodata-intakes', [
        'raw_text' => $rawText,
        'parse_now' => false,
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('intake.parse_status', 'pending')
        ->assertJsonPath('intake.parser_version', 'rules_only')
        ->assertJsonPath('intake_settings.mobile_biodata_source_mode', 'ml_kit');

    $intakeId = (int) $response->json('intake.id');
    $this->assertDatabaseHas('biodata_intakes', [
        'id' => $intakeId,
        'uploaded_by' => $user->id,
        'raw_ocr_text' => $rawText,
        'parser_version' => 'rules_only',
    ]);

    Queue::assertPushed(ParseIntakeJob::class);
});

test('mobile biodata intake laravel pipeline mode keeps active parser for mobile text', function () {
    Queue::fake();
    AdminSetting::setValue('intake_mobile_biodata_source_mode', 'laravel_pipeline');
    AdminSetting::setValue('intake_active_parser', 'hybrid_v1');
    AdminSetting::setValue('intake_auto_parse_enabled', '0');

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $rawText = "नाव : राहुल पाटील\nजन्म तारीख : १२/०४/१९९६\nशिक्षण : B.Com\nमोबाईल : 9876543210";

    $response = $this->postJson('/api/v1/biodata-intakes', [
        'raw_text' => $rawText,
        'parse_now' => false,
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('intake.parse_status', 'pending')
        ->assertJsonPath('intake.parser_version', 'hybrid_v1')
        ->assertJsonPath('intake_settings.mobile_biodata_source_mode', 'laravel_pipeline');

    $this->assertDatabaseHas('biodata_intakes', [
        'uploaded_by' => $user->id,
        'raw_ocr_text' => $rawText,
        'parser_version' => 'hybrid_v1',
    ]);

    Queue::assertNotPushed(ParseIntakeJob::class);
});

test('mobile biodata intake file upload reuses exact previous paid transcript', function () {
    Queue::fake();
    AdminSetting::setValue('intake_mobile_biodata_source_mode', 'laravel_pipeline');
    AdminSetting::setValue('intake_active_parser', 'ai_vision_extract_v1');
    AdminSetting::setValue('intake_auto_parse_enabled', '0');

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $bytes = 'same biodata image bytes for mobile reuse test';
    $paidTranscript = "मुलीचे नांव : कु. टेस्ट परसे\nजन्मतारीख : 12/03/1996\nमो 9876543210\nSarvam canonical transcript.";
    Storage::disk('local')->put('intakes/mobile-duplicate-source.jpg', $bytes);

    $source = BiodataIntake::create([
        'uploaded_by' => $user->id,
        'file_path' => 'intakes/mobile-duplicate-source.jpg',
        'original_filename' => 'mobile-duplicate-source.jpg',
        'raw_ocr_text' => 'weak upload ocr text',
        'last_parse_input_text' => $paidTranscript,
        'parsed_json' => [
            'core' => [
                'full_name' => 'Do Not Copy Parsed JSON',
            ],
        ],
        'ai_calls_used' => 1,
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'ai_vision_extract_v1',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);

    $this->mock(OcrService::class, function ($mock): void {
        $mock->shouldNotReceive('extractTextFromPath');
    });

    $response = $this->post('/api/v1/biodata-intakes', [
        'file' => UploadedFile::fake()->createWithContent('mobile-duplicate-target.jpg', $bytes),
        'parse_now' => false,
    ], ['Accept' => 'application/json']);

    $response
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('intake.parse_status', 'pending')
        ->assertJsonPath('intake.parser_version', 'ai_vision_extract_v1');

    $newIntakeId = (int) $response->json('intake.id');
    expect($newIntakeId)->not->toBe((int) $source->id);

    $this->assertDatabaseHas('biodata_intakes', [
        'id' => $newIntakeId,
        'uploaded_by' => $user->id,
        'raw_ocr_text' => $paidTranscript,
        'parser_version' => 'ai_vision_extract_v1',
    ]);
    expect(BiodataIntake::findOrFail($newIntakeId)->parsed_json)->toBeNull();

    Queue::assertNotPushed(ParseIntakeJob::class);
});

test('mobile laravel pipeline file upload stores ML Kit evidence without making it primary', function () {
    Queue::fake();
    AdminSetting::setValue('intake_mobile_biodata_source_mode', 'laravel_pipeline');
    AdminSetting::setValue('intake_auto_parse_enabled', '0');

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $serverText = "नाव : सर्वर ओसीआर\nजन्म तारीख : १२/०४/१९९६\nशिक्षण : B.Com\nमोबाईल : 9876543210";
    $mlKitText = "नाव : एमएल किट\nजन्म तारीख : १२/०४/१९९६\nमोबाईल : 9876543210";

    $this->mock(OcrService::class, function ($mock) use ($serverText): void {
        $mock->shouldReceive('extractTextFromPath')->once()->andReturn($serverText);
        $mock->shouldReceive('getLastExtractTextFromPathDebug')->andReturn([
            'kind' => 'image',
            'ocr_pipeline' => 'direct_from_original',
            'original_width' => 900,
            'original_height' => 1200,
        ]);
    });

    $response = $this->post('/api/v1/biodata-intakes', [
        'file' => UploadedFile::fake()->createWithContent('mobile-upload.jpg', 'mobile image bytes'),
        'parse_now' => false,
        'ml_kit_raw_text' => $mlKitText,
        'ml_kit_lines_json' => json_encode([
            ['text' => 'नाव : एमएल किट', 'box' => ['left' => 1, 'top' => 2, 'right' => 3, 'bottom' => 4]],
        ]),
        'ml_kit_blocks_json' => json_encode([
            ['text' => $mlKitText, 'line_count' => 2],
        ]),
    ], ['Accept' => 'application/json']);

    $response
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('intake.parse_status', 'pending');

    $intakeId = (int) $response->json('intake.id');
    $this->assertDatabaseHas('biodata_intake_ocr_attempts', [
        'intake_id' => $intakeId,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'raw_text' => $serverText,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_SERVER,
        'selected_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'is_primary' => true,
    ]);
    $this->assertDatabaseHas('biodata_intake_ocr_attempts', [
        'intake_id' => $intakeId,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_ML_KIT_FLUTTER,
        'raw_text' => $mlKitText,
        'created_by_user_id' => $user->id,
        'created_by_actor_type' => null,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_MOBILE_APP,
        'selected_by_user_id' => null,
        'selected_by_actor_type' => null,
        'selected_reason' => null,
        'is_primary' => false,
    ]);
    expect(BiodataIntake::findOrFail($intakeId)->raw_ocr_text)->toBe($serverText);
    expect(BiodataIntakeOcrAttempt::where('intake_id', $intakeId)->where('is_primary', true)->count())->toBe(1);
});

test('ocr attempt recorder keeps only one primary attempt per intake', function () {
    $user = User::factory()->create();
    $intake = BiodataIntake::create([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'first raw text',
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);

    $recorder = app(IntakeOcrAttemptRecorder::class);
    $first = $recorder->record($intake, [
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'source' => 'server',
        'created_by_user_id' => $user->id,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_PROFILE_USER,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_API,
        'raw_text' => 'first raw text',
        'is_primary' => true,
        'selected_reason' => 'first',
        'selected_by_user_id' => $user->id,
        'selected_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_PROFILE_USER,
    ]);
    $second = $recorder->record($intake, [
        'engine' => BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION,
        'source' => 'server_ai_vision',
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_SERVER,
        'raw_text' => 'second raw text',
        'is_primary' => true,
        'selected_reason' => 'second',
        'selected_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
    ]);

    expect($first->fresh()->is_primary)->toBeFalse()
        ->and($second->fresh()->is_primary)->toBeTrue()
        ->and($first->fresh()->created_by_user_id)->toBe($user->id)
        ->and($first->fresh()->created_by_actor_type)->toBe(BiodataIntakeOcrAttempt::ACTOR_PROFILE_USER)
        ->and($first->fresh()->source_surface)->toBe(BiodataIntakeOcrAttempt::SURFACE_API)
        ->and($second->fresh()->created_by_actor_type)->toBe(BiodataIntakeOcrAttempt::ACTOR_SYSTEM)
        ->and($second->fresh()->source_surface)->toBe(BiodataIntakeOcrAttempt::SURFACE_SERVER)
        ->and($second->fresh()->selected_by_actor_type)->toBe(BiodataIntakeOcrAttempt::ACTOR_SYSTEM)
        ->and(BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->where('is_primary', true)->count())->toBe(1);

    expect(fn () => $first->fresh()->forceFill(['raw_text' => 'changed raw text'])->save())
        ->toThrow(\RuntimeException::class, 'append-only');
});

test('paid Sarvam extraction stores primary OCR evidence without overwriting raw OCR text', function () {
    Queue::fake();
    config(['intake.testing_parse_job_uses_ai_vision' => true]);

    $user = User::factory()->create();
    Storage::disk('local')->put('intakes/sarvam-success.jpg', 'sarvam image bytes');
    $uploadText = 'weak upload ocr text that should stay immutable';
    $sarvamText = "नाव : गणेश पाटील\nजन्म तारीख : १२/०३/१९९६\nशिक्षण : B.Com\nनोकरी : Developer\nमोबाईल : 9876543210";

    $intake = BiodataIntake::create([
        'uploaded_by' => $user->id,
        'file_path' => 'intakes/sarvam-success.jpg',
        'original_filename' => 'sarvam-success.jpg',
        'raw_ocr_text' => $uploadText,
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);

    $this->mock(AiVisionExtractionService::class, function ($mock) use ($sarvamText): void {
        $mock->shouldReceive('resolveExtractionProvider')->andReturn([
            'provider' => 'sarvam',
            'provider_source' => 'test',
        ]);
        $mock->shouldReceive('extractTextForIntake')->once()->andReturn([
            'text' => $sarvamText,
            'meta' => [
                'ok' => true,
                'provider' => 'sarvam',
                'provider_source' => 'test',
                'extraction' => 'sarvam_document_intelligence',
                'job_id' => 'job-success-1',
                'job_state' => 'Completed',
            ],
        ]);
        $mock->shouldReceive('evaluateExtractedTextQuality')->andReturn([
            'ok' => true,
            'reason' => null,
            'chars' => mb_strlen($sarvamText),
            'non_space_chars' => mb_strlen(str_replace(' ', '', $sarvamText)),
            'lines' => 5,
            'alpha_ratio' => 0.8,
        ]);
    });

    (new ParseIntakeJob((int) $intake->id))->handle();

    $intake->refresh();
    expect($intake->raw_ocr_text)->toBe($uploadText)
        ->and($intake->last_parse_input_text)->toBe($sarvamText);

    $this->assertDatabaseHas('biodata_intake_ocr_attempts', [
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION,
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => $sarvamText,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_SERVER,
        'provider_response_id' => 'job-success-1',
        'selected_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'is_primary' => true,
    ]);
});

test('failed Sarvam extraction stores failed OCR evidence without selecting primary', function () {
    Queue::fake();
    config(['intake.testing_parse_job_uses_ai_vision' => true]);

    $user = User::factory()->create();
    Storage::disk('local')->put('intakes/sarvam-failed.jpg', 'sarvam image bytes');

    $intake = BiodataIntake::create([
        'uploaded_by' => $user->id,
        'file_path' => 'intakes/sarvam-failed.jpg',
        'original_filename' => 'sarvam-failed.jpg',
        'raw_ocr_text' => 'weak upload ocr text that should stay immutable',
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);

    $this->mock(AiVisionExtractionService::class, function ($mock): void {
        $mock->shouldReceive('resolveExtractionProvider')->andReturn([
            'provider' => 'sarvam',
            'provider_source' => 'test',
        ]);
        $mock->shouldReceive('extractTextForIntake')->once()->andReturn([
            'text' => '',
            'meta' => [
                'ok' => false,
                'reason' => 'sarvam_job_timeout',
                'provider' => 'sarvam',
                'provider_source' => 'test',
                'job_id' => 'job-timeout-1',
                'job_state' => 'Processing',
            ],
        ]);
        $mock->shouldReceive('evaluateExtractedTextQuality')->andReturn([
            'ok' => false,
            'reason' => 'ai_vision_text_blank',
            'chars' => 0,
            'non_space_chars' => 0,
            'lines' => 0,
            'alpha_ratio' => 0.0,
        ]);
    });

    (new ParseIntakeJob((int) $intake->id))->handle();

    $intake->refresh();
    expect($intake->parse_status)->toBe('error')
        ->and($intake->last_parse_input_text)->toBeNull();

    $this->assertDatabaseHas('biodata_intake_ocr_attempts', [
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION,
        'status' => BiodataIntakeOcrAttempt::STATUS_FAILED,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_SERVER,
        'failure_code' => BiodataIntakeOcrAttempt::FAILURE_PROVIDER_TIMEOUT,
        'provider_response_id' => 'job-timeout-1',
        'is_primary' => false,
    ]);
});

test('mobile biodata intake list and show are scoped to the authenticated user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $ownIntake = BiodataIntake::create([
        'uploaded_by' => $owner->id,
        'raw_ocr_text' => 'owner biodata text',
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);
    $otherIntake = BiodataIntake::create([
        'uploaded_by' => $other->id,
        'raw_ocr_text' => 'other biodata text',
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/biodata-intakes')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'intakes')
        ->assertJsonPath('intakes.0.id', $ownIntake->id);

    $this->getJson('/api/v1/biodata-intakes/'.$ownIntake->id)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('intake.id', $ownIntake->id);

    $this->getJson('/api/v1/biodata-intakes/'.$otherIntake->id)
        ->assertNotFound();
});

test('mobile biodata intake preview returns parsed snapshot and normalized draft', function () {
    $user = User::factory()->create();
    $rawText = "नाव : सुलोचना शिंदे\nजन्म तारीख : १४-०६-२००१\nशिक्षण : B.A.";
    $snapshot = [
        'snapshot_schema_version' => 1,
        'core' => [
            'full_name' => 'सुलोचना शिंदे',
            'date_of_birth' => '2001-06-14',
            'highest_education' => 'B.A.',
        ],
        'contacts' => [],
        'children' => [],
    ];

    $intake = BiodataIntake::create([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => $rawText,
        'last_parse_input_text' => $rawText,
        'parsed_json' => $snapshot,
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parsed_at' => now(),
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/biodata-intakes/'.$intake->id.'/preview')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('ready', true)
        ->assertJsonPath('preview.form_contract_version', 1)
        ->assertJsonPath('preview.parsed_snapshot.core.full_name', 'सुलोचना शिंदे')
        ->assertJsonPath('preview.normalized_draft.available', true)
        ->assertJsonPath('preview.review_sections.core.type', 'object')
        ->assertJsonPath('preview.review_sections.education.type', 'list')
        ->assertJsonPath('preview.editable_form_sections.0.key', 'basic-info')
        ->assertJsonPath('preview.review_requirements.requires_user_confirmation', true)
        ->assertJsonPath('preview.debug.mobile_ocr_text_only', true)
        ->assertJsonPath('preview.source', 'parse_snapshot');
});

test('mobile biodata intake preview keeps diagnostics separate from editable form sections', function () {
    $user = User::factory()->create();
    $rawText = "मुलाचे नाव : महेश बाळासाहेब नाटे\nजन्म तारीख : 03/10/1997\nशिक्षण : BE Mechanical\nनोकरी : Software Developer\nवडिलांचे नाव : बाळासाहेब";
    $snapshot = [
        'snapshot_schema_version' => 1,
        'core' => [
            'full_name' => 'महेश बाळासाहेब नाटे',
            'date_of_birth' => '1997-10-03',
            'highest_education' => 'BE Mechanical',
            'occupation_title' => 'Software Developer',
        ],
        'contacts' => [],
        'children' => [],
        'career_history' => [
            ['designation' => 'Software Developer'],
        ],
        'confidence_map' => [
            'full_name' => 0.6,
        ],
    ];

    $intake = BiodataIntake::create([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => $rawText,
        'last_parse_input_text' => $rawText,
        'parsed_json' => $snapshot,
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parsed_at' => now(),
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/biodata-intakes/'.$intake->id.'/preview')
        ->assertOk()
        ->assertJsonPath('preview.review_sections.career.type', 'list')
        ->assertJsonPath('preview.review_requirements.warning_fields.0', 'full_name');

    $sectionKeys = collect($response->json('preview.editable_form_sections'))
        ->pluck('key')
        ->all();

    expect($sectionKeys)
        ->toContain('basic-info')
        ->toContain('education-career')
        ->not->toContain('review_needed')
        ->not->toContain('detected_but_not_included');
});

test('mobile biodata intake store respects disabled admin auto parse setting', function () {
    Queue::fake();
    AdminSetting::setValue('intake_auto_parse_enabled', '0');

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/biodata-intakes', [
        'raw_text' => "नाव : राहुल पाटील\nजन्म तारीख : १२/०४/१९९६\nशिक्षण : B.Com\nमोबाईल : 9876543210",
        'parse_now' => true,
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('intake.parse_status', 'pending')
        ->assertJsonPath('preview', null)
        ->assertJsonPath('intake_settings.auto_parse_enabled', false);

    Queue::assertNotPushed(ParseIntakeJob::class);
});
