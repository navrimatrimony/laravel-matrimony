<?php

use App\Models\BulkIntakeBatchItem;
use App\Models\IntakeWhatsAppSession;
use App\Models\MatrimonyProfile;
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
        ->and($message)->not->toContain('नोंदणी पूर्ण करा')
        ->and($message)->not->toContain('/register/biodata/');
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
