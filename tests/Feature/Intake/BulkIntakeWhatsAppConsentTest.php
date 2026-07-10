<?php

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\BulkIntakeIdentityHistory;
use App\Models\IntakeWhatsAppSession;
use App\Models\User;
use App\Services\Intake\BulkIntakeEligibilityService;
use App\Services\Intake\BulkIntakeWhatsAppConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('admin can send whatsapp permission for eligible pipeline candidate', function () {
    $admin = whatsappConsentAdmin();
    $item = whatsappConsentEligibleItem();

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.send-whatsapp-permission', [
            'bulkIntakeBatch' => $item->bulk_intake_batch_id,
            'bulkIntakeBatchItem' => $item->id,
        ]))
        ->assertRedirect()
        ->assertSessionHas('success');

    $item->refresh();
    expect(app(BulkIntakeWhatsAppConsentService::class)->consentStatus($item))
        ->toBe(BulkIntakeWhatsAppConsentService::STATUS_PERMISSION_SENT);
});

test('send permission is blocked for non eligible pipeline candidate', function () {
    $admin = whatsappConsentAdmin();
    $item = whatsappConsentEligibleItem([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Missing Mobile Candidate',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
            ],
        ],
    ]);

    expect(fn () => app(BulkIntakeWhatsAppConsentService::class)->sendPermission($item, $admin))
        ->toThrow(ValidationException::class);
});

test('duplicate send is blocked while waiting for reply', function () {
    $admin = whatsappConsentAdmin();
    $item = whatsappConsentEligibleItem();
    $service = app(BulkIntakeWhatsAppConsentService::class);

    $service->sendPermission($item, $admin);

    expect(fn () => $service->sendPermission($item->fresh(), $admin))
        ->toThrow(ValidationException::class);
});

test('no reply marks no response history', function () {
    $admin = whatsappConsentAdmin();
    $item = whatsappConsentEligibleItem();
    $service = app(BulkIntakeWhatsAppConsentService::class);
    $service->sendPermission($item, $admin);

    $service->markNoResponse($item->fresh());

    $item->refresh();
    expect($service->consentStatus($item))->toBe(BulkIntakeWhatsAppConsentService::STATUS_NO_RESPONSE);

    $history = BulkIntakeIdentityHistory::query()
        ->where('reason_code', BulkIntakeIdentityHistory::REASON_NO_RESPONSE)
        ->where('normalized_mobile', '9876543201')
        ->first();

    expect($history)->not->toBeNull()
        ->and($history->source_type)->toBe(BulkIntakeIdentityHistory::SOURCE_WHATSAPP_REPLY);
});

test('not interested reply records history and finalizes consent', function () {
    $admin = whatsappConsentAdmin();
    $item = whatsappConsentEligibleItem();
    $service = app(BulkIntakeWhatsAppConsentService::class);
    $result = $service->sendPermission($item, $admin);
    $session = IntakeWhatsAppSession::query()->findOrFail((int) $result['intake_whatsapp_session_id']);

    $processed = $service->processInboundReply($session, 'नको', BulkIntakeWhatsAppConsentService::REPLY_NO);

    expect($processed['processed'])->toBeTrue()
        ->and($processed['status'])->toBe(BulkIntakeWhatsAppConsentService::STATUS_CONSENT_DENIED);

    $history = BulkIntakeIdentityHistory::query()
        ->where('reason_code', BulkIntakeIdentityHistory::REASON_NOT_INTERESTED)
        ->where('normalized_mobile', '9876543201')
        ->first();

    expect($history)->not->toBeNull();
});

test('already married reply records permanent history', function () {
    $admin = whatsappConsentAdmin();
    $item = whatsappConsentEligibleItem();
    $service = app(BulkIntakeWhatsAppConsentService::class);
    $result = $service->sendPermission($item, $admin);
    $session = IntakeWhatsAppSession::query()->findOrFail((int) $result['intake_whatsapp_session_id']);

    $processed = $service->processInboundReply($session, 'लग्न झाले', BulkIntakeWhatsAppConsentService::REPLY_ALREADY_MARRIED);

    expect($processed['processed'])->toBeTrue()
        ->and($processed['status'])->toBe(BulkIntakeWhatsAppConsentService::STATUS_ALREADY_MARRIED);

    expect(BulkIntakeIdentityHistory::query()
        ->where('reason_code', BulkIntakeIdentityHistory::REASON_ALREADY_MARRIED)
        ->where('normalized_mobile', '9876543201')
        ->exists())->toBeTrue();
});

test('yes reply marks consent received without blocking history', function () {
    $admin = whatsappConsentAdmin();
    $item = whatsappConsentEligibleItem();
    $service = app(BulkIntakeWhatsAppConsentService::class);
    $result = $service->sendPermission($item, $admin);
    $session = IntakeWhatsAppSession::query()->findOrFail((int) $result['intake_whatsapp_session_id']);

    $processed = $service->processInboundReply($session, 'हो', BulkIntakeWhatsAppConsentService::REPLY_YES);

    expect($processed['processed'])->toBeTrue()
        ->and($processed['status'])->toBe(BulkIntakeWhatsAppConsentService::STATUS_CONSENT_RECEIVED);

    expect(BulkIntakeIdentityHistory::query()->where('normalized_mobile', '9876543201')->count())->toBe(0);
});

test('batch show renders send permission action and consent badge after send', function () {
    $admin = whatsappConsentAdmin();
    $item = whatsappConsentEligibleItem();
    $batch = BulkIntakeBatch::query()->findOrFail((int) $item->bulk_intake_batch_id);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('data-testid="bulk-send-whatsapp-permission"', false)
        ->assertSee('data-testid="bulk-send-whatsapp-permission-batch"', false)
        ->assertSee('data-testid="bulk-whatsapp-manual-test-banner"', false)
        ->assertSee('data-testid="bulk-open-whatsapp-manual-test"', false);

    app(BulkIntakeWhatsAppConsentService::class)->sendPermission($item, $admin);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('data-testid="bulk-whatsapp-consent-badge"', false)
        ->assertSee('Permission sent', false)
        ->assertSee('data-testid="bulk-open-whatsapp-manual-test"', false)
        ->assertSee('data-testid="bulk-simulate-whatsapp-yes"', false)
        ->assertSee('data-testid="bulk-simulate-whatsapp-no"', false)
        ->assertDontSee('data-testid="bulk-send-whatsapp-permission"', false);
});

test('admin can simulate whatsapp user reply from batch show', function () {
    $admin = whatsappConsentAdmin();
    $item = whatsappConsentEligibleItem();
    $service = app(BulkIntakeWhatsAppConsentService::class);
    $service->sendPermission($item, $admin);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.simulate-whatsapp-consent-reply', [
            'bulkIntakeBatch' => $item->bulk_intake_batch_id,
            'bulkIntakeBatchItem' => $item->id,
        ]), [
            'reply_choice' => BulkIntakeWhatsAppConsentService::REPLY_YES,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($service->consentStatus($item->fresh()))
        ->toBe(BulkIntakeWhatsAppConsentService::STATUS_CONSENT_RECEIVED);
});

test('manual whatsapp share url contains permission message text', function () {
    $item = whatsappConsentEligibleItem();
    $service = app(BulkIntakeWhatsAppConsentService::class);
    $url = $service->buildManualTestWhatsAppShareUrl($item);

    expect($url)->toContain('api.whatsapp.com/send')
        ->and($url)->toContain(urlencode('नवरी मिळे नवऱ्याला विवाहसंस्था'))
        ->and($url)->toContain(urlencode('१. हो, जरूर पाठवा'));
});

test('not interested reply blocks future eligible item with same mobile in pipeline', function () {
    $admin = whatsappConsentAdmin();
    $batch = whatsappConsentBatch($admin);
    $firstItem = whatsappConsentEligibleItem([], [], $batch);
    $service = app(BulkIntakeWhatsAppConsentService::class);
    $result = $service->sendPermission($firstItem, $admin);
    $session = IntakeWhatsAppSession::query()->findOrFail((int) $result['intake_whatsapp_session_id']);
    $service->processInboundReply($session, 'नको', BulkIntakeWhatsAppConsentService::REPLY_NO);

    $secondIntake = whatsappConsentIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Future Same Mobile Candidate',
                'primary_contact_number' => '9876543201',
                'date_of_birth' => '2000-02-02',
                'gender' => 'female',
            ],
        ],
    ]);
    $secondItem = whatsappConsentItem($batch, $secondIntake);

    $pipeline = app(BulkIntakeEligibilityService::class)->eligibleForPipeline($secondItem);

    expect($pipeline['bucket'])->toBe(BulkIntakeEligibilityService::FILTER_BLOCKED);
});

function whatsappConsentAdmin(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function whatsappConsentBatch(User $admin): BulkIntakeBatch
{
    return BulkIntakeBatch::create([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_name' => 'WhatsApp consent batch',
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);
}

function whatsappConsentIntake(array $overrides = []): BiodataIntake
{
    return BiodataIntake::create(array_merge([
        'uploaded_by' => null,
        'raw_ocr_text' => 'WhatsApp consent OCR',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}

function whatsappConsentItem(BulkIntakeBatch $batch, BiodataIntake $intake, array $overrides = []): BulkIntakeBatchItem
{
    return BulkIntakeBatchItem::create(array_merge([
        'bulk_intake_batch_id' => $batch->id,
        'biodata_intake_id' => $intake->id,
        'item_sequence' => ((int) BulkIntakeBatchItem::where('bulk_intake_batch_id', $batch->id)->max('item_sequence')) + 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'whatsapp-consent.pdf',
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ], $overrides));
}

function whatsappConsentEligibleItem(array $intakeOverrides = [], array $itemOverrides = [], ?BulkIntakeBatch $batch = null): BulkIntakeBatchItem
{
    $admin = whatsappConsentAdmin();
    $batch ??= whatsappConsentBatch($admin);
    $intake = whatsappConsentIntake(array_merge([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Eligible WhatsApp Candidate',
                'primary_contact_number' => '9876543201',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
            ],
        ],
    ], $intakeOverrides));

    return whatsappConsentItem($batch, $intake, $itemOverrides);
}
