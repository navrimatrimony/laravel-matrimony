<?php

use App\Jobs\ParseIntakeJob;
use App\Jobs\ProcessBulkIntakeBatchItemJob;
use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\User;
use App\Services\Intake\BulkIntakeBatchService;
use App\Services\Intake\IntakeCreationService;
use App\Services\Intake\IntakeOcrEnsembleGate;
use App\Services\Intake\IntakeOcrEnsemblePhase1Service;
use App\Services\Intake\IntakeSourceContextRecorder;
use App\Services\OcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, '0');
});

test('1.T01 flag off uses legacy prepare path for bulk file', function () {
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, '0');

    $this->mock(OcrService::class, function ($mock): void {
        $mock->shouldReceive('extractTextFromPath')->once()->andReturn('Legacy bulk OCR text for candidate');
        $mock->shouldReceive('getLastExtractTextFromPathDebug')->once()->andReturn(['kind' => 'image']);
    });

    $prepared = app(IntakeCreationService::class)->prepareForBulkFile(
        null,
        UploadedFile::fake()->createWithContent('legacy.jpg', 'jpeg-bytes')
    );

    expect($prepared['raw_ocr_text'])->toBe('Legacy bulk OCR text for candidate')
        ->and($prepared['ensemble_phase1'] ?? null)->toBeNull();
});

test('1.T02 phase1 service keeps preprocessing version when preprocess degrades', function () {
    $this->mock(OcrService::class, function ($mock): void {
        $mock->shouldReceive('extractTextFromPath')->once()->andReturn('Degraded preprocess OCR');
        $mock->shouldReceive('getLastExtractTextFromPathDebug')->once()->andReturn([
            'kind' => 'image',
            'preprocess_used' => false,
            'skipped_preprocessing_reason' => 'driver_none',
        ]);
    });

    $result = app(IntakeOcrEnsemblePhase1Service::class)->extractFromStoredFile('intakes/test.jpg', 'test.jpg');

    expect($result['preprocessing_version'])->toBe(IntakeOcrEnsemblePhase1Service::PREPROCESSING_VERSION)
        ->and($result['debug']['ensemble_pipeline'])->toBe(IntakeOcrEnsemblePhase1Service::PIPELINE_VERSION);
});

test('1.T03 bulk file with ensemble flag on records ocr attempt and item meta', function () {
    Queue::fake();
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, '1');

    $this->mock(OcrService::class, function ($mock): void {
        $mock->shouldReceive('extractTextFromPath')->once()->andReturn('नाव : Ensemble Candidate मोबाईल : 9000000999');
        $mock->shouldReceive('getLastExtractTextFromPathDebug')->once()->andReturn([
            'kind' => 'image',
            'preprocess_used' => true,
            'preset_resolved' => 'photo_capture',
        ]);
    });

    $admin = ensemblePhase1Admin();
    $batch = ensemblePhase1Batch($admin);
    $item = app(BulkIntakeBatchService::class)->createPendingItemFromUploadedFile(
        $batch,
        UploadedFile::fake()->createWithContent('ensemble.jpg', 'file-bytes'),
        1,
        true
    );

    ensemblePhase1HandleJob($item, $admin, true);

    $item->refresh();
    $intake = BiodataIntake::query()->sole();
    $attempt = BiodataIntakeOcrAttempt::query()->where('intake_id', $intake->id)->sole();

    expect($item->item_meta_json['ocr_ensemble_status'] ?? null)->toBe('ocr_ready')
        ->and($item->item_meta_json['ocr_ensemble_pipeline'] ?? null)->toBe(IntakeOcrEnsemblePhase1Service::PIPELINE_VERSION)
        ->and($attempt->preprocessing_version)->toBe(IntakeOcrEnsemblePhase1Service::PREPROCESSING_VERSION)
        ->and(data_get($attempt->engine_meta_json, 'ensemble_phase1'))->toBeTrue()
        ->and($attempt->selected_reason)->toBe('ensemble_phase1_bulk_upload');

    Queue::assertPushed(ParseIntakeJob::class, 1);
});

test('1.T04 bulk file with ensemble flag off has no ensemble item meta', function () {
    Queue::fake();
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, '0');

    $this->mock(OcrService::class, function ($mock): void {
        $mock->shouldReceive('extractTextFromPath')->once()->andReturn('Legacy path OCR text long enough');
        $mock->shouldReceive('getLastExtractTextFromPathDebug')->once()->andReturn(['kind' => 'image']);
    });

    $admin = ensemblePhase1Admin();
    $batch = ensemblePhase1Batch($admin);
    $item = app(BulkIntakeBatchService::class)->createPendingItemFromUploadedFile(
        $batch,
        UploadedFile::fake()->createWithContent('legacy-flag-off.jpg', 'file-bytes'),
        1,
        true
    );

    ensemblePhase1HandleJob($item, $admin, true);

    $item->refresh();

    expect($item->item_meta_json['ocr_ensemble_status'] ?? null)->toBeNull()
        ->and($item->item_meta_json['ocr_ensemble_pipeline'] ?? null)->toBeNull();
});

test('1.T05 text-only bulk item skips ensemble even when flag on', function () {
    Queue::fake();
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, '1');

    $this->mock(OcrService::class, function ($mock): void {
        $mock->shouldNotReceive('extractTextFromPath');
    });

    $admin = ensemblePhase1Admin();
    $batch = ensemblePhase1Batch($admin);
    $item = app(BulkIntakeBatchService::class)->createPendingItemFromRawText(
        $batch,
        'Name: Text Only Candidate Mobile: 9000000888',
        1,
        true
    );

    ensemblePhase1HandleJob($item, $admin, true);

    $item->refresh();

    expect($item->item_meta_json['ocr_ensemble_status'] ?? null)->toBeNull()
        ->and(BiodataIntake::query()->sole()->raw_ocr_text)->toContain('Text Only Candidate');
});

test('1.T07 ensemble skips OCR when duplicate file reuses transcript', function () {
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, '1');

    $user = User::factory()->create();
    $fileBytes = 'duplicate-ensemble-file-bytes';
    $firstFile = UploadedFile::fake()->createWithContent('dup.jpg', $fileBytes);

    $this->mock(OcrService::class, function ($mock): void {
        $mock->shouldReceive('extractTextFromPath')->once()->andReturn('First upload OCR text long enough');
        $mock->shouldReceive('getLastExtractTextFromPathDebug')->once()->andReturn(['kind' => 'image']);
    });

    $firstPrepared = app(IntakeCreationService::class)->prepareForBulkFile($user->id, $firstFile);
    app(IntakeCreationService::class)->persistPrepared($user->id, $firstPrepared);

    $this->mock(OcrService::class, function ($mock): void {
        $mock->shouldNotReceive('extractTextFromPath');
    });

    $secondFile = UploadedFile::fake()->createWithContent('dup-copy.jpg', $fileBytes);
    $secondPrepared = app(IntakeCreationService::class)->prepareForBulkFile($user->id, $secondFile);

    expect($secondPrepared['ensemble_phase1_skipped'] ?? null)->toBeTrue()
        ->and($secondPrepared['ensemble_skip_reason'] ?? null)->toBe('reused_transcript')
        ->and($secondPrepared['raw_ocr_text'])->toBe('First upload OCR text long enough');
});

test('1.T06 empty ensemble OCR marks empty_ocr_text needs review', function () {
    Queue::fake();
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, '1');

    $this->mock(OcrService::class, function ($mock): void {
        $mock->shouldReceive('extractTextFromPath')->once()->andReturn('');
        $mock->shouldReceive('getLastExtractTextFromPathDebug')->once()->andReturn(['kind' => 'image']);
    });

    $admin = ensemblePhase1Admin();
    $batch = ensemblePhase1Batch($admin);
    $item = app(BulkIntakeBatchService::class)->createPendingItemFromUploadedFile(
        $batch,
        UploadedFile::fake()->createWithContent('empty-ensemble.jpg', 'file-bytes'),
        1,
        true
    );

    ensemblePhase1HandleJob($item, $admin, true);

    $item->refresh();

    expect($item->item_status)->toBe(BulkIntakeBatchItem::STATUS_NEEDS_REVIEW)
        ->and($item->failure_code)->toBe('empty_ocr_text')
        ->and($item->item_meta_json['ocr_ensemble_status'] ?? null)->toBe('ocr_ready');

    Queue::assertNotPushed(ParseIntakeJob::class);
});

function ensemblePhase1Admin(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function ensemblePhase1Batch(User $admin): BulkIntakeBatch
{
    return BulkIntakeBatch::create([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_name' => 'Ensemble phase1 batch',
        'batch_status' => BulkIntakeBatch::STATUS_PROCESSING,
    ]);
}

function ensemblePhase1HandleJob(BulkIntakeBatchItem $item, User $admin, bool $queueFreeParseAfterUpload): void
{
    (new ProcessBulkIntakeBatchItemJob((int) $item->id, (int) $admin->id, $queueFreeParseAfterUpload))
        ->handle(
            app(BulkIntakeBatchService::class),
            app(IntakeCreationService::class),
            app(IntakeSourceContextRecorder::class),
        );
}
