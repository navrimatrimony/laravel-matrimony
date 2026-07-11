<?php

use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\IntakeWhatsAppSession;
use App\Models\MatrimonyProfile;
use App\Services\Intake\BulkIntakeRegistrationAccountSetupService;
use App\Services\Intake\BulkIntakeRegistrationService;
use App\Services\Intake\BulkIntakeWhatsAppRegistrationConversationService;
use App\Services\Intake\IntakePhotoCandidateCropService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require __DIR__.'/Support/BulkIntakeRegistrationHelpers.php';

function registrationSummarySession(BulkIntakeBatchItem $item): IntakeWhatsAppSession
{
    $admin = registrationAdmin();
    app(BulkIntakeRegistrationService::class)->sendRegistrationSummary($item, $admin);
    $sessionId = (int) data_get($item->fresh()->item_meta_json, 'registration.intake_whatsapp_session_id');

    return IntakeWhatsAppSession::query()->findOrFail($sessionId);
}

function registrationTinyJpegBytes(): string
{
    $image = imagecreatetruecolor(120, 120);
    ob_start();
    imagejpeg($image, null, 90);
    imagedestroy($image);

    return (string) ob_get_clean();
}

test('registration summary message uses professional whatsapp format with step label', function () {
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson());
    $message = app(BulkIntakeRegistrationService::class)->buildSummaryMessage($item);

    expect($message)->toContain('पायरी १/४')
        ->and($message)->toContain('✓ नाव:')
        ->and($message)->toContain('खालील बटणे निवडा')
        ->and($message)->toContain('रिकामा form')
        ->and($message)->not->toContain('नोंदणी पूर्ण करा');
});

test('summary ok advances whatsapp flow to photo step', function () {
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson());
    $session = registrationSummarySession($item);
    $conversation = app(BulkIntakeWhatsAppRegistrationConversationService::class);

    $result = $conversation->processInbound(
        $session,
        '',
        BulkIntakeWhatsAppRegistrationConversationService::BTN_SUMMARY_OK,
    );

    expect($result['processed'])->toBeTrue()
        ->and($result['step'])->toBe(BulkIntakeWhatsAppRegistrationConversationService::STEP_AWAITING_PHOTO);

    $step = data_get($item->fresh()->item_meta_json, 'registration.whatsapp_flow.step');
    expect($step)->toBe(BulkIntakeWhatsAppRegistrationConversationService::STEP_AWAITING_PHOTO);
});

test('whatsapp fast path completes registration after photo confirmation', function () {
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson());
    $session = registrationSummarySession($item);
    $conversation = app(BulkIntakeWhatsAppRegistrationConversationService::class);
    $intake = $item->biodataIntake;
    $photoService = app(IntakePhotoCandidateCropService::class);

    $conversation->processInbound($session, '', BulkIntakeWhatsAppRegistrationConversationService::BTN_SUMMARY_OK);
    $photoService->saveFromBinary($intake, registrationTinyJpegBytes());

    $result = $conversation->processInbound(
        $session,
        '',
        BulkIntakeWhatsAppRegistrationConversationService::BTN_PHOTO_USE,
    );

    expect($result['processed'])->toBeTrue()
        ->and($result['step'])->toBe(BulkIntakeWhatsAppRegistrationConversationService::STEP_COMPLETED)
        ->and(app(BulkIntakeRegistrationService::class)->registrationStatus($item->fresh()))
        ->toBe(BulkIntakeRegistrationService::STATUS_REGISTRATION_COMPLETE);

    expect(MatrimonyProfile::query()->count())->toBe(1);
});

test('full edit path sends web link when user taps edit on summary', function () {
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson());
    $session = registrationSummarySession($item);

    $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
    $meta['registration']['path'] = BulkIntakeRegistrationService::PATH_FULL;
    $item->forceFill(['item_meta_json' => $meta])->save();

    $conversation = app(BulkIntakeWhatsAppRegistrationConversationService::class);

    $result = $conversation->processInbound(
        $session,
        '',
        BulkIntakeWhatsAppRegistrationConversationService::BTN_SUMMARY_EDIT,
    );

    expect($result['processed'])->toBeTrue()
        ->and($result['step'])->toBe(BulkIntakeWhatsAppRegistrationConversationService::STEP_DEFERRED);

    $outbound = \App\Models\IntakeWhatsAppMessage::query()
        ->where('intake_whatsapp_session_id', $session->id)
        ->where('direction', \App\Models\IntakeWhatsAppMessage::DIRECTION_OUTBOUND)
        ->latest('id')
        ->first();

    expect($outbound?->text_body)->toContain('/register/biodata/');
});

test('blank form request starts field by field wizard', function () {
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson());
    $session = registrationSummarySession($item);
    $conversation = app(BulkIntakeWhatsAppRegistrationConversationService::class);

    $result = $conversation->processInbound(
        $session,
        'रिकामा form',
        BulkIntakeWhatsAppRegistrationConversationService::BTN_BLANK_FORM_REQUEST,
    );

    expect($result['processed'])->toBeTrue()
        ->and($result['step'])->toBe(BulkIntakeWhatsAppRegistrationConversationService::STEP_AWAITING_BLANK_FORM_VALUE);

    $flow = data_get($item->fresh()->item_meta_json, 'registration.whatsapp_flow');
    expect($flow['mode'] ?? null)->toBe('blank_form')
        ->and((int) ($flow['blank_form_index'] ?? -1))->toBe(0);
});

test('blank form wizard validates mobile and advances on valid reply', function () {
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson());
    $session = registrationSummarySession($item);
    $conversation = app(BulkIntakeWhatsAppRegistrationConversationService::class);

    $conversation->processInbound($session, '', BulkIntakeWhatsAppRegistrationConversationService::BTN_BLANK_FORM_REQUEST);

    $bad = $conversation->processInbound($session, 'अ');
    expect($bad['processed'])->toBeTrue()
        ->and($bad['step'])->toBe(BulkIntakeWhatsAppRegistrationConversationService::STEP_AWAITING_BLANK_FORM_VALUE);
    expect((int) data_get($item->fresh()->item_meta_json, 'registration.whatsapp_flow.blank_form_index'))->toBe(0);

    $conversation->processInbound($session, 'सचिन रामदास शिंदे');
    expect((int) data_get($item->fresh()->item_meta_json, 'registration.whatsapp_flow.blank_form_index'))->toBe(1);

    $conversation->processInbound($session, '9664444909');
    expect((int) data_get($item->fresh()->item_meta_json, 'registration.whatsapp_flow.blank_form_index'))->toBe(2);
});

test('blank form wizard completes all required fields and reaches photo step', function () {
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson());
    $session = registrationSummarySession($item);
    $conversation = app(BulkIntakeWhatsAppRegistrationConversationService::class);

    $conversation->processInbound($session, '', BulkIntakeWhatsAppRegistrationConversationService::BTN_BLANK_FORM_REQUEST);

    $values = [
        'सचिन रामदास शिंदे',
        '9664444909',
        '09-09-1987',
        '5 ft 8 in',
        'पुरुष',
        'मराठी',
        'अविवाहित',
        'हिंदू',
        'मराठा',
        'Pimpalgaon Siddhanath, Junnar, Pune',
        'B.Com.',
        'खाजगी नोकरी',
        'Met Operator',
    ];

    foreach ($values as $value) {
        $conversation->processInbound($session, $value);
    }

    expect(data_get($item->fresh()->item_meta_json, 'registration.whatsapp_flow.step'))
        ->toBe(BulkIntakeWhatsAppRegistrationConversationService::STEP_AWAITING_PHOTO);

    $core = $item->fresh()->biodataIntake?->approval_snapshot_json['core'] ?? [];
    expect($core['full_name'] ?? null)->toBe('सचिन रामदास शिंदे')
        ->and($core['primary_contact_number'] ?? null)->toBe('9664444909')
        ->and($core['occupation'] ?? null)->toBe('Met Operator');
});

test('registration manual share preview includes text button lines', function () {
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson());
    $preview = app(BulkIntakeRegistrationService::class)->buildManualTestPreview($item);

    expect($preview['share_text'])->toContain('पायरी १/४')
        ->and($preview['share_text'])->toContain('[✅ १. हो, बरोबर]')
        ->and($preview['share_text'])->toContain('[✏️ २. चुकीचे]')
        ->and($preview['share_text'])->toContain('[⏰ ३. नंतर]')
        ->and($preview['share_text'])->toContain('रिकामा form');
});

test('admin can simulate registration summary yes reply from batch show', function () {
    $admin = registrationAdmin();
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson());
    $batch = \App\Models\BulkIntakeBatch::query()->findOrFail((int) $item->bulk_intake_batch_id);
    app(BulkIntakeRegistrationService::class)->sendRegistrationSummary($item, $admin);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.simulate-registration-reply', [
            'bulkIntakeBatch' => $batch->id,
            'bulkIntakeBatchItem' => $item->id,
        ]), [
            'reply_choice' => BulkIntakeWhatsAppRegistrationConversationService::BTN_SUMMARY_OK,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(data_get($item->fresh()->item_meta_json, 'registration.whatsapp_flow.step'))
        ->toBe(BulkIntakeWhatsAppRegistrationConversationService::STEP_AWAITING_PHOTO);
});

test('admin can simulate registration photo and complete flow', function () {
    $admin = registrationAdmin();
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson());
    $batch = BulkIntakeBatch::query()->findOrFail((int) $item->bulk_intake_batch_id);
    app(BulkIntakeRegistrationService::class)->sendRegistrationSummary($item, $admin);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.simulate-registration-reply', [
            'bulkIntakeBatch' => $batch->id,
            'bulkIntakeBatchItem' => $item->id,
        ]), [
            'reply_choice' => BulkIntakeWhatsAppRegistrationConversationService::BTN_SUMMARY_OK,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.simulate-registration-photo', [
            'bulkIntakeBatch' => $batch->id,
            'bulkIntakeBatchItem' => $item->id,
        ]))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(app(BulkIntakeRegistrationService::class)->registrationStatus($item->fresh()))
        ->toBe(BulkIntakeRegistrationService::STATUS_REGISTRATION_COMPLETE);
});

test('bulk registration does not replace logged-in admin session', function () {
    $admin = registrationAdmin();
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson());
    $session = registrationSummarySession($item);
    $conversation = app(BulkIntakeWhatsAppRegistrationConversationService::class);
    $intake = $item->biodataIntake;

    $conversation->processInbound($session, '', BulkIntakeWhatsAppRegistrationConversationService::BTN_SUMMARY_OK);
    app(IntakePhotoCandidateCropService::class)->saveFromBinary($intake, registrationTinyJpegBytes());
    $conversation->processInbound($session, '', BulkIntakeWhatsAppRegistrationConversationService::BTN_PHOTO_USE);

    $this->actingAs($admin);
    app(BulkIntakeRegistrationAccountSetupService::class)->ensureAuthenticated($item->fresh());

    expect((int) auth()->id())->toBe((int) $admin->id);
});
