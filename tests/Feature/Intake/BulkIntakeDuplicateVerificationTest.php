<?php

use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\BiodataIntake;
use App\Models\BulkIntakeIdentityHistory;
use App\Models\User;
use App\Services\Intake\BulkIntakeDuplicateVerificationService;
use App\Services\Intake\BulkIntakeRegistrationService;
use App\Services\Intake\BulkIntakeWhatsAppConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function duplicateVerifyAdmin(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function duplicateVerifyBatch(User $admin): BulkIntakeBatch
{
    return BulkIntakeBatch::create([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_name' => 'Duplicate verify batch',
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);
}

function duplicateVerifyIntake(array $overrides = []): BiodataIntake
{
    return BiodataIntake::create(array_merge([
        'uploaded_by' => null,
        'raw_ocr_text' => 'Duplicate verify OCR',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}

function duplicateVerifyItem(BulkIntakeBatch $batch, BiodataIntake $intake, array $overrides = []): BulkIntakeBatchItem
{
    return BulkIntakeBatchItem::create(array_merge([
        'bulk_intake_batch_id' => $batch->id,
        'biodata_intake_id' => $intake->id,
        'item_sequence' => ((int) BulkIntakeBatchItem::where('bulk_intake_batch_id', $batch->id)->max('item_sequence')) + 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'duplicate-verify.pdf',
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ], $overrides));
}

test('duplicate verification enriches same mobile hint with journey and links', function () {
    $admin = duplicateVerifyAdmin();
    $oldBatch = duplicateVerifyBatch($admin);
    $oldIntake = duplicateVerifyIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Existing Candidate',
                'primary_contact_number' => '9876543210',
                'date_of_birth' => '1995-08-04',
            ],
        ],
    ]);
    duplicateVerifyItem($oldBatch, $oldIntake);

    $newBatch = duplicateVerifyBatch($admin);
    $newIntake = duplicateVerifyIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Current Candidate',
                'primary_contact_number' => '9876543210',
            ],
        ],
    ]);
    $newItem = duplicateVerifyItem($newBatch, $newIntake);

    $verification = app(BulkIntakeDuplicateVerificationService::class)->verificationForItem($newItem);

    expect($verification['has_hints'])->toBeTrue()
        ->and($verification['primary']['reason_label_mr'] ?? null)->toBe('हाच मोबाईल नंबर आधी आला')
        ->and($verification['primary']['matched']['intake_id'] ?? null)->toBe($oldIntake->id)
        ->and($verification['primary']['matched']['journey_stage'] ?? null)->toBe(BulkIntakeDuplicateVerificationService::STAGE_INTAKE_ONLY)
        ->and($verification['primary']['matched']['links']['intake'] ?? null)->not->toBeNull()
        ->and($verification['primary']['matched']['links']['batch'] ?? null)->toContain('/admin/bulk-intakes/'.$oldBatch->id);
});

test('duplicate verification marks intake only stale upload as proceed ok', function () {
    $admin = duplicateVerifyAdmin();
    $oldBatch = duplicateVerifyBatch($admin);
    $hash = hash('sha256', 'same-biodata-content');
    $oldIntake = duplicateVerifyIntake([
        'content_hash' => $hash,
        'parsed_json' => ['core' => ['full_name' => 'Stale Upload']],
    ]);
    duplicateVerifyItem($oldBatch, $oldIntake, ['file_hash' => $hash]);

    $newBatch = duplicateVerifyBatch($admin);
    $newIntake = duplicateVerifyIntake([
        'content_hash' => $hash,
        'parsed_json' => ['core' => ['full_name' => 'Stale Upload Again']],
    ]);
    $newItem = duplicateVerifyItem($newBatch, $newIntake, ['file_hash' => $hash]);

    $verification = app(BulkIntakeDuplicateVerificationService::class)->verificationForItem($newItem);

    expect($verification['primary']['recommended_action'] ?? null)
        ->toBe(BulkIntakeDuplicateVerificationService::ACTION_PROCEED_OK)
        ->and($verification['primary']['matched']['journey_stage'] ?? null)
        ->toBe(BulkIntakeDuplicateVerificationService::STAGE_INTAKE_ONLY);
});

test('duplicate verification shows consent received without registration on matched item', function () {
    $admin = duplicateVerifyAdmin();
    $oldBatch = duplicateVerifyBatch($admin);
    $oldIntake = duplicateVerifyIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Consent Only',
                'primary_contact_number' => '9111222333',
            ],
        ],
    ]);
    $oldItem = duplicateVerifyItem($oldBatch, $oldIntake, [
        'item_meta_json' => [
            'whatsapp_consent' => [
                'status' => BulkIntakeWhatsAppConsentService::STATUS_CONSENT_RECEIVED,
            ],
        ],
    ]);

    $newBatch = duplicateVerifyBatch($admin);
    $newIntake = duplicateVerifyIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Consent Only Copy',
                'primary_contact_number' => '9111222333',
            ],
        ],
    ]);
    $newItem = duplicateVerifyItem($newBatch, $newIntake);

    $verification = app(BulkIntakeDuplicateVerificationService::class)->verificationForItem($newItem);

    expect($verification['primary']['matched']['journey_stage'] ?? null)
        ->toBe(BulkIntakeDuplicateVerificationService::STAGE_CONSENT_NO_REGISTRATION)
        ->and($verification['primary']['matched']['consent_status'] ?? null)
        ->toBe(BulkIntakeWhatsAppConsentService::STATUS_CONSENT_RECEIVED);
});

test('duplicate verification surfaces history block on matched item', function () {
    $admin = duplicateVerifyAdmin();
    $oldBatch = duplicateVerifyBatch($admin);
    $oldIntake = duplicateVerifyIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Married Person',
                'primary_contact_number' => '9000000001',
            ],
        ],
    ]);
    $oldItem = duplicateVerifyItem($oldBatch, $oldIntake);

    BulkIntakeIdentityHistory::query()->create([
        'reason_code' => BulkIntakeIdentityHistory::REASON_ALREADY_MARRIED,
        'normalized_mobile' => '9000000001',
        'source_type' => BulkIntakeIdentityHistory::SOURCE_ADMIN_SCREENING,
        'source_bulk_intake_batch_item_id' => $oldItem->id,
        'source_biodata_intake_id' => $oldIntake->id,
        'recorded_by_user_id' => $admin->id,
    ]);

    $newBatch = duplicateVerifyBatch($admin);
    $newIntake = duplicateVerifyIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Married Person Again',
                'primary_contact_number' => '9000000001',
            ],
        ],
    ]);
    $newItem = duplicateVerifyItem($newBatch, $newIntake);

    $verification = app(BulkIntakeDuplicateVerificationService::class)->verificationForItem($newItem);

    expect($verification['primary']['matched']['journey_stage'] ?? null)
        ->toBe(BulkIntakeDuplicateVerificationService::STAGE_HISTORY_BLOCKED)
        ->and($verification['primary']['recommended_action'] ?? null)
        ->toBe(BulkIntakeDuplicateVerificationService::ACTION_BLOCK)
        ->and($verification['primary']['matched']['history_flags'] ?? [])->not->toBeEmpty();
});

test('bulk list shows duplicate verify panel with journey and links', function () {
    $admin = duplicateVerifyAdmin();
    $oldBatch = duplicateVerifyBatch($admin);
    $oldIntake = duplicateVerifyIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Existing Candidate',
                'primary_contact_number' => '9876543210',
            ],
        ],
    ]);
    duplicateVerifyItem($oldBatch, $oldIntake);

    $batch = duplicateVerifyBatch($admin);
    $intake = duplicateVerifyIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Current Candidate',
                'primary_contact_number' => '9876543210',
            ],
        ],
    ]);
    duplicateVerifyItem($batch, $intake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('data-testid="bulk-open-duplicate-verify-panel"', false)
        ->assertSee('Verify duplicate', false)
        ->assertSee('data-testid="bulk-duplicate-link-intake"', false)
        ->assertSee('फक्त intake — process सुरू झाला नाही', false)
        ->assertSee('हाच मोबाईल नंबर आधी आला', false);
});
