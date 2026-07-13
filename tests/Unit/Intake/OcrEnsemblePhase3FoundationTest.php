<?php

use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatchItem;
use App\Models\User;
use App\Services\Intake\IntakeOcrEnsembleGate;
use App\Services\Intake\IntakeOcrEnsemblePhase3Service;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Data\Phase3ResolutionResult;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('field_resolution_json column exists after migration', function () {
    expect(Schema::hasColumn('biodata_intakes', 'field_resolution_json'))->toBeTrue();
});

test('field resolution envelope skeleton contains all sixteen structured fields', function () {
    $envelope = FieldResolutionEnvelope::skeleton(735);
    $array = $envelope->toArray();

    expect($array)->toHaveKeys(['_meta', 'fields'])
        ->and($array['_meta']['schema_version'])->toBe(OcrEnsemblePhase3Constants::SCHEMA_VERSION)
        ->and($array['_meta']['pipeline_version'])->toBe(OcrEnsemblePhase3Constants::PIPELINE_VERSION)
        ->and(array_keys($array['fields']))->toBe(OcrEnsemblePhase3Constants::STRUCTURED_FIELDS)
        ->and($array['fields']['full_name']['status'])->toBe(OcrEnsemblePhase3Constants::FIELD_STATUS_MISSING);
});

test('field resolution envelope round trips through array', function () {
    $original = FieldResolutionEnvelope::skeleton(736);
    $restored = FieldResolutionEnvelope::fromArray($original->toArray());

    expect($restored->toArray())->toBe($original->toArray());
});

test('phase3 gate requires ensemble flag and config', function () {
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, false);
    config()->set('ocr.ensemble.phase3.enabled', true);

    expect(app(IntakeOcrEnsembleGate::class)->isPhase3Enabled())->toBeFalse();

    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, true);
    config()->set('ocr.ensemble.phase3.enabled', false);

    expect(app(IntakeOcrEnsembleGate::class)->isPhase3Enabled())->toBeFalse();

    config()->set('ocr.ensemble.phase3.enabled', true);

    expect(app(IntakeOcrEnsembleGate::class)->isPhase3Enabled())->toBeTrue();
});

test('phase3 service skips when gate is disabled', function () {
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, false);

    $item = new BulkIntakeBatchItem([
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
    ]);

    $result = app(IntakeOcrEnsemblePhase3Service::class)->runForBulkItemIfApplicable($item);

    expect($result->wasSkipped())->toBeTrue()
        ->and($result->reason)->toBe('phase3_gate_disabled');
});

test('phase3 service skips text bulk items', function () {
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, true);
    config()->set('ocr.ensemble.phase3.enabled', true);

    $item = new BulkIntakeBatchItem([
        'input_type' => BulkIntakeBatchItem::INPUT_TEXT,
    ]);

    $result = app(IntakeOcrEnsemblePhase3Service::class)->runForBulkItemIfApplicable($item);

    expect($result->wasSkipped())->toBeTrue()
        ->and($result->reason)->toBe('bulk_item_ineligible');
});

test('phase3 resolve skips when intake has no usable ocr attempts', function () {
    $user = User::factory()->create();
    $intake = BiodataIntake::create([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'short',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);

    $result = app(IntakeOcrEnsemblePhase3Service::class)->resolve($intake);

    expect($result->wasSkipped())->toBeTrue()
        ->and($result->reason)->toBe('no_usable_ocr_attempts')
        ->and($intake->fresh()->field_resolution_json)->toBeNull();
});

test('phase3 resolution result helpers', function () {
    expect(Phase3ResolutionResult::skipped('x')->wasSkipped())->toBeTrue()
        ->and(Phase3ResolutionResult::notImplemented('y')->wasNotImplemented())->toBeTrue()
        ->and(Phase3ResolutionResult::resolved(FieldResolutionEnvelope::skeleton(1))->wasResolved())->toBeTrue();
});

test('phase3 production files do not import benchmark classes', function () {
    $paths = [
        app_path('Services/Intake/IntakeOcrEnsemblePhase3Service.php'),
        app_path('Services/Intake/OcrEnsemble'),
    ];

    foreach ($paths as $path) {
        $files = is_dir($path) ? glob($path.'/**/*.php') ?: [] : [$path];
        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            expect($contents)->not->toContain('OcrEnsembleBenchmark');
        }
    }
});

test('biodata intake casts field_resolution_json to array', function () {
    $intake = new BiodataIntake([
        'field_resolution_json' => FieldResolutionEnvelope::skeleton(735)->toArray(),
    ]);

    expect($intake->field_resolution_json)->toBeArray()
        ->and($intake->field_resolution_json['_meta']['intake_id'])->toBe(735);
});
