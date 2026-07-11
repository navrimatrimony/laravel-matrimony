<?php

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\BulkIntakeIdentityHistory;
use App\Models\IntakeWhatsAppSession;
use App\Models\User;
use App\Services\Intake\BulkIntakeCandidateContactPlanService;
use App\Services\Intake\BulkIntakeEligibilityService;
use App\Services\Intake\BulkIntakeWhatsAppConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('contact plan prioritizes candidate then father and excludes suchak numbers', function () {
    $item = contactPlanFixtureItem([
        'raw_ocr_text' => implode("\n", [
            'वधूवर सूचक केंद्र',
            'राजेश पाटील',
            'कोल्हापूर',
            '9876543299',
            'मुलीचे नाव: शुभम',
            'वडिलांचे नाव: सुरेश',
        ]),
        'parsed_json' => [
            'core' => [
                'full_name' => 'Shubham',
                'primary_contact_number' => '9876543201',
                'father_contact_number' => '9876543202',
                'date_of_birth' => '1998-04-15',
                'gender' => 'male',
            ],
            'contacts' => [
                [
                    'relation' => 'सूचक',
                    'name' => 'Rajesh Patil',
                    'phone_number' => '9876543299',
                ],
            ],
        ],
    ]);

    $service = app(BulkIntakeCandidateContactPlanService::class);
    $plan = $service->syncForItem($item);
    $queueMobiles = array_column($plan['queue'], 'mobile');

    expect($queueMobiles)->toBe(['9876543201', '9876543202'])
        ->and($queueMobiles)->not->toContain('9876543299')
        ->and($service->activeMobile($item))->toBe('9876543201')
        ->and($plan['suchak_directory'])->not->toBeEmpty()
        ->and(collect($plan['suchak_directory'])->pluck('mobile')->all())->toContain('9876543299');
});

test('eligibility treats first consent queue mobile as usable when multiple numbers exist', function () {
    $item = contactPlanFixtureItem([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Multi Mobile Candidate',
                'all_contact_numbers' => ['9623456789', '9823456789', '9523456789'],
                'date_of_birth' => '1998-04-15',
                'gender' => 'male',
            ],
        ],
    ]);

    $pipeline = app(BulkIntakeEligibilityService::class)->eligibleForPipeline($item);

    expect($pipeline['reason_codes'])->not->toContain('missing_mobile')
        ->and(app(BulkIntakeCandidateContactPlanService::class)->hasUsableMobile($item))->toBeTrue();
});

test('wrong number reply advances to next consent contact', function () {
    $admin = contactPlanAdmin();
    $item = contactPlanFixtureItem([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Retry Candidate',
                'primary_contact_number' => '9876543201',
                'father_contact_number' => '9876543202',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
            ],
        ],
    ], [], contactPlanBatch($admin));

    $service = app(BulkIntakeWhatsAppConsentService::class);
    $result = $service->sendPermission($item, $admin);
    $session = IntakeWhatsAppSession::query()->findOrFail((int) $result['intake_whatsapp_session_id']);

    $processed = $service->processInboundReply(
        $session,
        'चुकीचा नंबर',
        BulkIntakeWhatsAppConsentService::REPLY_WRONG_NUMBER,
    );

    expect($processed['processed'])->toBeTrue()
        ->and($service->consentStatus($item->fresh()))->toBeNull()
        ->and(app(BulkIntakeCandidateContactPlanService::class)->activeMobile($item->fresh()))->toBe('9876543202')
        ->and($service->canSendPermission($item->fresh())['allowed'])->toBeTrue();
});

test('no response on last contact marks contacts exhausted', function () {
    $admin = contactPlanAdmin();
    $item = contactPlanFixtureItem([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Single Mobile Candidate',
                'primary_contact_number' => '9876543201',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
            ],
        ],
    ], [], contactPlanBatch($admin));

    $service = app(BulkIntakeWhatsAppConsentService::class);
    $service->sendPermission($item, $admin);
    $service->markNoResponse($item->fresh());

    $item->refresh();

    expect($service->consentStatus($item))->toBe(BulkIntakeWhatsAppConsentService::STATUS_CONTACTS_EXHAUSTED);

    $history = BulkIntakeIdentityHistory::query()
        ->where('reason_code', BulkIntakeIdentityHistory::REASON_NO_RESPONSE)
        ->where('normalized_mobile', '9876543201')
        ->first();

    expect($history)->not->toBeNull();
});

function contactPlanAdmin(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function contactPlanBatch(User $admin): BulkIntakeBatch
{
    return BulkIntakeBatch::create([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_name' => 'Contact plan batch',
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);
}

function contactPlanFixtureItem(array $intakeOverrides = [], array $itemOverrides = [], ?BulkIntakeBatch $batch = null): BulkIntakeBatchItem
{
    $admin = contactPlanAdmin();
    $batch ??= contactPlanBatch($admin);
    $intake = BiodataIntake::create(array_merge([
        'uploaded_by' => null,
        'raw_ocr_text' => 'Contact plan OCR',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $intakeOverrides));

    return BulkIntakeBatchItem::create(array_merge([
        'bulk_intake_batch_id' => $batch->id,
        'biodata_intake_id' => $intake->id,
        'item_sequence' => ((int) BulkIntakeBatchItem::where('bulk_intake_batch_id', $batch->id)->max('item_sequence')) + 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'contact-plan.pdf',
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ], $itemOverrides));
}
