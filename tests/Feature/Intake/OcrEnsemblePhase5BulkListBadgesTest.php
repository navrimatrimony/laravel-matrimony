<?php

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\User;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionFieldRecord;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionMeta;
use App\Services\Intake\OcrEnsemble\OcrEnsembleBulkListBadgePresenter;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase4Constants;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase5Constants;

function phase5b2Admin(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function phase5b2Batch(User $admin): BulkIntakeBatch
{
    return BulkIntakeBatch::create([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_name' => 'Phase 5 B2 badges batch',
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);
}

function phase5b2Intake(array $overrides = []): BiodataIntake
{
    return BiodataIntake::create(array_merge([
        'uploaded_by' => null,
        'raw_ocr_text' => 'legacy ocr transcript text for badge tests',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Badge Candidate',
                'primary_contact_number' => '9876543210',
            ],
        ],
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}

function phase5b2Item(BulkIntakeBatch $batch, ?BiodataIntake $intake, array $overrides = []): BulkIntakeBatchItem
{
    return BulkIntakeBatchItem::create(array_merge([
        'bulk_intake_batch_id' => $batch->id,
        'biodata_intake_id' => $intake?->id,
        'item_sequence' => ((int) BulkIntakeBatchItem::where('bulk_intake_batch_id', $batch->id)->max('item_sequence')) + 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'badge.pdf',
        'source_file_path' => 'bulk-intakes/badge.pdf',
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
        'item_meta_json' => [],
    ], $overrides));
}

function phase5b2Envelope(int $intakeId, bool $withSarvam = false): array
{
    $fields = [];
    foreach (OcrEnsemblePhase3Constants::STRUCTURED_FIELDS as $fieldKey) {
        $fields[$fieldKey] = FieldResolutionFieldRecord::missingSkeleton('not_present');
    }

    $fields['religion'] = new FieldResolutionFieldRecord(
        final: 'Hindu',
        status: OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
        source: $withSarvam
            ? OcrEnsemblePhase4Constants::FIELD_SOURCE_SARVAM_JUDGE
            : OcrEnsemblePhase3Constants::FIELD_SOURCE_SINGLE_ENGINE,
        winningEngine: $withSarvam
            ? OcrEnsemblePhase4Constants::ENGINE_SARVAM_JUDGE
            : OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
        confidence: null,
        reason: $withSarvam ? OcrEnsemblePhase4Constants::MERGE_REASON : 'single_engine_pass_through',
        candidates: [OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => 'Hindu'],
        normalized: [OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => 'Hindu'],
        validator: [
            'passed' => true,
            'code' => 'ok',
            'detail' => null,
        ],
        merge: $withSarvam ? [
            'previous_final' => null,
            'previous_source' => OcrEnsemblePhase3Constants::FIELD_SOURCE_MISSING,
            'previous_confidence' => null,
            'previous_status' => OcrEnsemblePhase3Constants::FIELD_STATUS_MISSING,
            'previous_winning_engine' => null,
            'previous_reason' => '',
            'previous_validator' => [
                'passed' => false,
                'code' => '',
                'detail' => null,
            ],
            'sarvam_confidence' => 0.9,
            'sarvam_reason' => 'judge',
            'merged_by' => OcrEnsemblePhase4Constants::FIELD_SOURCE_SARVAM_JUDGE,
        ] : null,
    );

    return (new FieldResolutionEnvelope(
        meta: FieldResolutionMeta::skeleton($intakeId),
        fields: $fields,
    ))->toArray();
}

function phase5b2BadgeKeys(BulkIntakeBatchItem $item): array
{
    $item->loadMissing(['biodataIntake.ocrAttempts']);

    return array_column(app(OcrEnsembleBulkListBadgePresenter::class)->badgesForItem($item), 'key');
}

test('presenter marks legacy intake with legacy path badge', function () {
    $admin = phase5b2Admin();
    $batch = phase5b2Batch($admin);
    $intake = phase5b2Intake(['field_resolution_json' => null]);
    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'legacy attempt',
        'is_primary' => true,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_SERVER,
    ]);
    $item = phase5b2Item($batch, $intake, ['item_meta_json' => []]);

    expect(phase5b2BadgeKeys($item->fresh()))->toBe([
        OcrEnsembleBulkListBadgePresenter::BADGE_LEGACY_PATH,
    ]);
});

test('presenter marks phase3 complete and comparison ready', function () {
    $admin = phase5b2Admin();
    $batch = phase5b2Batch($admin);
    $intake = phase5b2Intake();
    $intake->field_resolution_json = phase5b2Envelope((int) $intake->id);
    $intake->last_parse_input_text = "धर्म : Hindu\nनाव : Badge";
    $intake->save();
    $item = phase5b2Item($batch, $intake->fresh(), [
        'item_meta_json' => [
            'ocr_ensemble_status' => 'ocr_ready',
            'ocr_ensemble_pipeline' => 'phase1_v1',
        ],
    ]);

    expect(phase5b2BadgeKeys($item->fresh()))->toBe([
        OcrEnsembleBulkListBadgePresenter::BADGE_OCR_COMPLETE,
        OcrEnsembleBulkListBadgePresenter::BADGE_PHASE3_COMPLETE,
        OcrEnsembleBulkListBadgePresenter::BADGE_COMPARISON_READY,
    ]);
});

test('presenter marks phase4 sarvam reviewed', function () {
    $admin = phase5b2Admin();
    $batch = phase5b2Batch($admin);
    $intake = phase5b2Intake();
    $intake->field_resolution_json = phase5b2Envelope((int) $intake->id, true);
    $intake->save();
    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION,
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'religion : Hindu',
        'is_primary' => false,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_SERVER,
    ]);
    $item = phase5b2Item($batch, $intake->fresh(), [
        'item_meta_json' => [
            'ocr_ensemble_status' => 'ocr_ready',
        ],
    ]);

    expect(phase5b2BadgeKeys($item->fresh()))->toBe([
        OcrEnsembleBulkListBadgePresenter::BADGE_OCR_COMPLETE,
        OcrEnsembleBulkListBadgePresenter::BADGE_PHASE3_COMPLETE,
        OcrEnsembleBulkListBadgePresenter::BADGE_SARVAM_REVIEWED,
        OcrEnsembleBulkListBadgePresenter::BADGE_COMPARISON_READY,
    ]);
});

test('presenter marks comparison ready when field resolution exists', function () {
    $admin = phase5b2Admin();
    $batch = phase5b2Batch($admin);
    $intake = phase5b2Intake([
        'field_resolution_json' => FieldResolutionEnvelope::skeleton(1)->toArray(),
    ]);
    $intake->field_resolution_json = FieldResolutionEnvelope::skeleton((int) $intake->id)->toArray();
    $intake->save();
    $item = phase5b2Item($batch, $intake->fresh(), [
        'item_meta_json' => ['ocr_ensemble_status' => 'ocr_ready'],
    ]);

    $keys = phase5b2BadgeKeys($item->fresh());

    expect($keys)->toContain(OcrEnsembleBulkListBadgePresenter::BADGE_COMPARISON_READY)
        ->and($keys)->toContain(OcrEnsembleBulkListBadgePresenter::BADGE_PHASE3_COMPLETE);
});

test('presenter marks no ocr when intake has empty transcript and no attempts', function () {
    $admin = phase5b2Admin();
    $batch = phase5b2Batch($admin);
    $intake = phase5b2Intake([
        'raw_ocr_text' => '',
        'field_resolution_json' => null,
    ]);
    $item = phase5b2Item($batch, $intake, ['item_meta_json' => []]);

    expect(phase5b2BadgeKeys($item->fresh()))->toBe([
        OcrEnsembleBulkListBadgePresenter::BADGE_NO_OCR,
    ]);
});

test('presenter badge order is deterministic across DISPLAY_ORDER', function () {
    $admin = phase5b2Admin();
    $batch = phase5b2Batch($admin);
    $intake = phase5b2Intake();
    $intake->field_resolution_json = phase5b2Envelope((int) $intake->id, true);
    $intake->save();
    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION,
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'sarvam',
        'is_primary' => false,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_SERVER,
    ]);
    $item = phase5b2Item($batch, $intake->fresh(), [
        'item_meta_json' => [
            'ocr_ensemble_status' => 'ocr_ensemble_processing',
        ],
    ]);

    // processing + FR + sarvam: awaiting + phase3 + sarvam + comparison (no ocr_complete)
    $keys = phase5b2BadgeKeys($item->fresh());
    $expected = [
        OcrEnsembleBulkListBadgePresenter::BADGE_PHASE3_COMPLETE,
        OcrEnsembleBulkListBadgePresenter::BADGE_SARVAM_REVIEWED,
        OcrEnsembleBulkListBadgePresenter::BADGE_COMPARISON_READY,
        OcrEnsembleBulkListBadgePresenter::BADGE_AWAITING_REVIEW,
    ];
    expect($keys)->toBe($expected);

    $badges = app(OcrEnsembleBulkListBadgePresenter::class)->badgesForItem($item->fresh());
    expect(array_column($badges, 'key'))->toBe($expected)
        ->and(array_column($badges, 'label'))->toBe([
            'फील्ड तयार',
            'AI तपास पूर्ण',
            'तुलना तयार',
            'तपासा बाकी',
        ]);
});

test('bulk intake list renders ocr ensemble badges for legacy and phase3 items', function () {
    $admin = phase5b2Admin();
    $batch = phase5b2Batch($admin);

    $legacyIntake = phase5b2Intake(['field_resolution_json' => null, 'raw_ocr_text' => 'legacy text present']);
    BiodataIntakeOcrAttempt::create([
        'intake_id' => $legacyIntake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'legacy',
        'is_primary' => true,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_SERVER,
    ]);
    phase5b2Item($batch, $legacyIntake, ['item_meta_json' => []]);

    $phase3Intake = phase5b2Intake();
    $phase3Intake->field_resolution_json = phase5b2Envelope((int) $phase3Intake->id);
    $phase3Intake->save();
    phase5b2Item($batch, $phase3Intake->fresh(), [
        'item_meta_json' => ['ocr_ensemble_status' => 'ocr_ready'],
    ]);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('data-testid="bulk-ocr-ensemble-badges"', false)
        ->assertSee('data-ocr-badge="legacy_path"', false)
        ->assertSee('जुनी पद्धत', false)
        ->assertSee('data-ocr-badge="phase3_complete"', false)
        ->assertSee('फील्ड तयार', false)
        ->assertSee('data-ocr-badge="comparison_ready"', false)
        ->assertSee('तुलना तयार', false)
        ->assertSee('data-ocr-badge="ocr_complete"', false)
        ->assertSee('स्कॅन पूर्ण', false);
});
