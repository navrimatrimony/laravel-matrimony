<?php

namespace App\Services\Intake;

use App\Contracts\Intake\BulkIntakeWhatsAppConsentSender;
use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatchItem;
use App\Models\IntakeWhatsAppMessage;
use App\Models\IntakeWhatsAppSession;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\Messaging\MetaWhatsAppCloudService;
use App\Support\HeightDisplay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * WhatsApp-first bulk registration conversation after summary is sent.
 */
class BulkIntakeWhatsAppRegistrationConversationService
{
    public const STEP_AWAITING_SUMMARY_CONFIRM = 'awaiting_summary_confirm';

    public const STEP_AWAITING_FIELD_PICK = 'awaiting_field_pick';

    public const STEP_AWAITING_FIELD_VALUE = 'awaiting_field_value';

    public const STEP_AWAITING_PHOTO = 'awaiting_photo';

    public const STEP_DEFERRED = 'deferred';

    public const STEP_COMPLETED = 'completed';

    public const BTN_SUMMARY_OK = 'reg_summary_ok';

    public const BTN_SUMMARY_EDIT = 'reg_summary_edit';

    public const BTN_SUMMARY_LATER = 'reg_summary_later';

    public const BTN_PHOTO_USE = 'reg_photo_use';

    public const BTN_PHOTO_NEW = 'reg_photo_new';

    public function __construct(
        private readonly BulkIntakeRegistrationService $registrationService,
        private readonly BulkIntakeWhatsAppConsentSender $whatsappSender,
        private readonly BulkIntakeRegistrationFormBridgeService $formBridge,
        private readonly BulkIntakeRegistrationProfileApplyService $profileApplyService,
        private readonly BulkIntakeRegistrationPreferencesBridgeService $preferencesBridge,
        private readonly IntakeHumanReviewSnapshotService $reviewSnapshotService,
        private readonly IntakePhotoCandidateCropService $photoCandidateCropService,
        private readonly BulkIntakeCandidateContactPlanService $contactPlanService,
        private readonly MetaWhatsAppCloudService $metaWhatsAppCloudService,
    ) {}

    /**
     * @return array{processed: bool, item_id: int|null, step: string|null}
     */
    public function processInbound(
        IntakeWhatsAppSession $session,
        string $replyText,
        ?string $buttonId = null,
        string $messageType = IntakeWhatsAppMessage::TYPE_TEXT,
        ?string $mediaId = null,
        ?string $mediaMimeType = null,
    ): array {
        $item = $this->pendingRegistrationItem($session);
        if ($item === null) {
            return ['processed' => false, 'item_id' => null, 'step' => null];
        }

        $step = $this->flowStep($item);
        if ($step === null || $step === self::STEP_COMPLETED) {
            return ['processed' => false, 'item_id' => (int) $item->id, 'step' => $step];
        }

        if ($step === self::STEP_DEFERRED) {
            $this->persistFlowStep($item, self::STEP_AWAITING_SUMMARY_CONFIRM);
            $step = self::STEP_AWAITING_SUMMARY_CONFIRM;
        }

        if ($messageType === IntakeWhatsAppMessage::TYPE_IMAGE && $mediaId !== null && $step === self::STEP_AWAITING_PHOTO) {
            return $this->handleInboundPhoto($item, $session, $mediaId, $mediaMimeType);
        }

        $choice = $this->resolveChoice($replyText, $buttonId, $step, $item);
        if ($choice === null && $step !== self::STEP_AWAITING_FIELD_VALUE) {
            return ['processed' => false, 'item_id' => (int) $item->id, 'step' => $step];
        }

        return DB::transaction(function () use ($item, $session, $choice, $replyText, $buttonId, $step): array {
            /** @var BulkIntakeBatchItem $locked */
            $locked = BulkIntakeBatchItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            return match ($step) {
                self::STEP_AWAITING_SUMMARY_CONFIRM => $this->handleSummaryConfirm($locked, $session, (string) $choice),
                self::STEP_AWAITING_FIELD_PICK => $this->handleFieldPick($locked, $session, (string) $choice),
                self::STEP_AWAITING_FIELD_VALUE => $this->handleFieldValue($locked, $session, trim($replyText)),
                self::STEP_AWAITING_PHOTO => $this->handlePhotoChoice($locked, $session, (string) $choice),
                default => ['processed' => false, 'item_id' => (int) $locked->id, 'step' => $step],
            };
        });
    }

    /**
     * @return array{processed: bool, item_id: int|null, step: string|null}
     */
    private function handleSummaryConfirm(BulkIntakeBatchItem $item, IntakeWhatsAppSession $session, string $choice): array
    {
        return match ($choice) {
            self::BTN_SUMMARY_OK => $this->advanceToPhotoStep($item, $session),
            self::BTN_SUMMARY_EDIT => $this->beginCorrectionFlow($item, $session),
            self::BTN_SUMMARY_LATER => $this->deferFlow($item, $session),
            default => ['processed' => false, 'item_id' => (int) $item->id, 'step' => self::STEP_AWAITING_SUMMARY_CONFIRM],
        };
    }

    /**
     * @return array{processed: bool, item_id: int|null, step: string|null}
     */
    private function beginCorrectionFlow(BulkIntakeBatchItem $item, IntakeWhatsAppSession $session): array
    {
        $summary = $this->registrationService->summaryForItem($item);
        $path = trim((string) data_get($item->item_meta_json, 'registration.path', ''));
        if ($path === '') {
            $path = (string) ($summary['path'] ?? '');
        }

        if ($path === BulkIntakeRegistrationService::PATH_FULL) {
            $url = (string) ($summary['public_url'] ?? '');
            $this->sendText(
                $session,
                $item,
                "या बायोडाट्यात अनेक माहिती तपासणीची गरज आहे.\n\nकृपया वेबवर सर्व माहिती एकाच ठिकाणी पहा/बदला:\n".$url,
                'registration_full_edit_link',
            );
            $this->persistFlowStep($item, self::STEP_DEFERRED, ['deferred_reason' => 'full_edit_web']);

            return ['processed' => true, 'item_id' => (int) $item->id, 'step' => self::STEP_DEFERRED];
        }

        $warningFields = $this->registrationService->warningFieldsForItem($item);
        $fields = $warningFields !== [] ? $warningFields : $summary['whatsapp_fields'];

        $this->sendFieldPickList($session, $item, $fields);
        $this->persistFlowStep($item, self::STEP_AWAITING_FIELD_PICK);

        return ['processed' => true, 'item_id' => (int) $item->id, 'step' => self::STEP_AWAITING_FIELD_PICK];
    }

    /**
     * @return array{processed: bool, item_id: int|null, step: string|null}
     */
    private function handleFieldPick(BulkIntakeBatchItem $item, IntakeWhatsAppSession $session, string $choice): array
    {
        if (! str_starts_with($choice, 'reg_field_')) {
            return ['processed' => false, 'item_id' => (int) $item->id, 'step' => self::STEP_AWAITING_FIELD_PICK];
        }

        $fieldKey = substr($choice, strlen('reg_field_'));
        $label = BulkIntakeRegistrationFieldCatalog::label($fieldKey);

        $this->sendText(
            $session,
            $item,
            "✏️ {$label} — योग्य माहिती एका संदेशात लिहून पाठवा.",
            'registration_field_value_prompt',
            ['field_key' => $fieldKey],
        );

        $this->persistFlowStep($item, self::STEP_AWAITING_FIELD_VALUE, ['editing_field_key' => $fieldKey]);

        return ['processed' => true, 'item_id' => (int) $item->id, 'step' => self::STEP_AWAITING_FIELD_VALUE];
    }

    /**
     * @return array{processed: bool, item_id: int|null, step: string|null}
     */
    private function handleFieldValue(BulkIntakeBatchItem $item, IntakeWhatsAppSession $session, string $value): array
    {
        if ($value === '') {
            return ['processed' => false, 'item_id' => (int) $item->id, 'step' => self::STEP_AWAITING_FIELD_VALUE];
        }

        $flow = $this->flowMeta($item);
        $fieldKey = (string) ($flow['editing_field_key'] ?? '');
        if ($fieldKey === '') {
            return ['processed' => false, 'item_id' => (int) $item->id, 'step' => self::STEP_AWAITING_FIELD_VALUE];
        }

        $intake = $this->intakeForItem($item);
        if (! $intake instanceof BiodataIntake) {
            return ['processed' => false, 'item_id' => (int) $item->id, 'step' => self::STEP_AWAITING_FIELD_VALUE];
        }

        $snapshot = $this->sourceSnapshot($intake);
        $snapshot = $this->formBridge->mergeCandidatePreviewIntoSnapshot($snapshot, $intake);
        if (! is_array($snapshot['core'] ?? null)) {
            $snapshot['core'] = [];
        }

        $this->applyFieldCorrectionToSnapshot($snapshot['core'], $fieldKey, $value);

        $this->reviewSnapshotService->saveReviewedSnapshot($intake, $snapshot, [
            'reviewed_by_user_id' => null,
            'review_actor_type' => IntakeHumanReviewSnapshotService::ACTOR_PROFILE_USER,
            'review_surface' => IntakeHumanReviewSnapshotService::SURFACE_API,
            'approval_policy' => IntakeHumanReviewSnapshotService::POLICY_PHASE2C_PROFILE_USER_REVIEW_V1,
            'approval_status' => IntakeHumanReviewSnapshotService::STATUS_REVIEWED,
        ]);

        $label = BulkIntakeRegistrationFieldCatalog::label($fieldKey);
        $this->sendText(
            $session,
            $item,
            "✅ {$label} अपडेट झाले.\n\nआता फोटो पाठवूया — पायरी २/४",
            'registration_field_saved',
        );

        return $this->advanceToPhotoStep($item->fresh() ?? $item, $session);
    }

    /**
     * @return array{processed: bool, item_id: int|null, step: string|null}
     */
    private function advanceToPhotoStep(BulkIntakeBatchItem $item, IntakeWhatsAppSession $session): array
    {
        $intake = $this->intakeForItem($item);
        $hasBiodataImage = $intake instanceof BiodataIntake && $this->photoCandidateCropService->isImageIntake($intake);
        $hasCrop = $intake instanceof BiodataIntake && $this->photoCandidateCropService->exists($intake);

        if ($hasCrop || $hasBiodataImage) {
            $body = "पायरी २/४: प्रोफाइल फोटो 📷\n\n";
            if ($hasCrop) {
                $body .= "बायोडाट्यातील फोटो वापरायचा आहे का?\nखालील बटणे निवडा 👇";
            } else {
                $body .= "बायोडाट्यातील फोटो वापरू किंवा नवीन फोटो पाठवा.\nखालील बटणे निवडा 👇";
            }

            $this->sendButtons($session, $item, $body, $this->photoButtons(), 'registration_photo_choice');
        } else {
            $this->sendText(
                $session,
                $item,
                "पायरी २/४: प्रोफाइल फोटो 📷\n\nकृपया एक स्पष्ट फोटो या चॅटमध्ये पाठवा.",
                'registration_photo_request',
            );
        }

        $this->persistFlowStep($item, self::STEP_AWAITING_PHOTO);

        return ['processed' => true, 'item_id' => (int) $item->id, 'step' => self::STEP_AWAITING_PHOTO];
    }

    /**
     * @return array{processed: bool, item_id: int|null, step: string|null}
     */
    private function handlePhotoChoice(BulkIntakeBatchItem $item, IntakeWhatsAppSession $session, string $choice): array
    {
        $intake = $this->intakeForItem($item);
        if (! $intake instanceof BiodataIntake) {
            return ['processed' => false, 'item_id' => (int) $item->id, 'step' => self::STEP_AWAITING_PHOTO];
        }

        if ($choice === self::BTN_PHOTO_NEW) {
            $this->sendText(
                $session,
                $item,
                "ठीक आहे 👍\nकृपया नवीन प्रोफाइल फोटो या चॅटमध्ये पाठवा.",
                'registration_photo_new_prompt',
            );

            return ['processed' => true, 'item_id' => (int) $item->id, 'step' => self::STEP_AWAITING_PHOTO];
        }

        if ($choice !== self::BTN_PHOTO_USE) {
            return ['processed' => false, 'item_id' => (int) $item->id, 'step' => self::STEP_AWAITING_PHOTO];
        }

        try {
            if (! $this->photoCandidateCropService->exists($intake) && $this->photoCandidateCropService->isImageIntake($intake)) {
                $this->seedCenterCropFromBiodataImage($intake);
            }
        } catch (\Throwable) {
            $this->sendText(
                $session,
                $item,
                "बायोडाट्यातील फोटो वापरता आला नाही.\nकृपया नवीन फोटो पाठवा.",
                'registration_photo_use_failed',
            );

            return $this->processedResult(
                $item,
                self::STEP_AWAITING_PHOTO,
                'बायोडाट्यातील फोटो वापरता आला नाही. नवीन फोटो पाठवा किंवा “Simulate photo sent” वापरा.',
            );
        }

        if (! $this->photoCandidateCropService->exists($intake)) {
            $this->sendText(
                $session,
                $item,
                "बायोडाट्यात फोटो सापडला नाही.\nकृपया फोटो या चॅटमध्ये पाठवा.",
                'registration_photo_missing',
            );

            return $this->processedResult(
                $item,
                self::STEP_AWAITING_PHOTO,
                'बायोडाट्यात वापरण्यासाठी फोटो नाही. Admin test साठी “Simulate photo sent” वापरा किंवा “नवीन पाठवा” निवडा.',
            );
        }

        return $this->finalizeRegistration($item, $session);
    }

    /**
     * @return array{processed: bool, item_id: int|null, step: string|null}
     */
    private function handleInboundPhoto(
        BulkIntakeBatchItem $item,
        IntakeWhatsAppSession $session,
        string $mediaId,
        ?string $mediaMimeType,
    ): array {
        $intake = $this->intakeForItem($item);
        if (! $intake instanceof BiodataIntake) {
            return ['processed' => false, 'item_id' => (int) $item->id, 'step' => self::STEP_AWAITING_PHOTO];
        }

        $binary = $this->metaWhatsAppCloudService->downloadMediaBinary($mediaId);
        if ($binary === null || $binary === '') {
            $this->sendText(
                $session,
                $item,
                "फोटो डाउनलोड होऊ शकला नाही. कृपया पुन्हा पाठवा.",
                'registration_photo_download_failed',
            );

            return ['processed' => true, 'item_id' => (int) $item->id, 'step' => self::STEP_AWAITING_PHOTO];
        }

        try {
            $this->photoCandidateCropService->saveFromBinary($intake, $binary, $mediaMimeType);
        } catch (\Throwable) {
            $this->sendText(
                $session,
                $item,
                "फोटो स्वीकारता आला नाही. कृपया JPG/PNG फोटो पाठवा.",
                'registration_photo_invalid',
            );

            return ['processed' => true, 'item_id' => (int) $item->id, 'step' => self::STEP_AWAITING_PHOTO];
        }

        return $this->finalizeRegistration($item, $session);
    }

    /**
     * @return array{processed: bool, item_id: int|null, step: string|null}
     */
    private function finalizeRegistration(BulkIntakeBatchItem $item, IntakeWhatsAppSession $session): array
    {
        $intake = $this->intakeForItem($item);
        if (! $intake instanceof BiodataIntake) {
            return ['processed' => false, 'item_id' => (int) $item->id, 'step' => self::STEP_AWAITING_PHOTO];
        }

        $mobile = $this->contactPlanService->activeMobile($item);
        if ($mobile === null) {
            $this->sendText($session, $item, 'मोबाईल क्रमांक सापडला नाही. कृपया आमच्या प्रतिनिधीशी संपर्क साधा.', 'registration_finalize_missing_mobile');

            return ['processed' => true, 'item_id' => (int) $item->id, 'step' => self::STEP_AWAITING_PHOTO];
        }

        $snapshot = $this->sourceSnapshot($intake);
        $snapshot = $this->formBridge->mergeCandidatePreviewIntoSnapshot($snapshot, $intake);
        $snapshot = $this->formBridge->prepareDisplaySnapshot($snapshot, $intake);
        $core = is_array($snapshot['core'] ?? null) ? $snapshot['core'] : [];
        $motherTongueId = $this->formBridge->resolveMotherTongueId($intake, $core);
        if ($motherTongueId !== null) {
            $core['mother_tongue_id'] = $motherTongueId;
            $snapshot['core'] = $core;
        }

        if (! is_array($intake->approval_snapshot_json) || $intake->approval_snapshot_json === []) {
            $intake = $this->reviewSnapshotService->saveReviewedSnapshot($intake, $snapshot, [
                'reviewed_by_user_id' => null,
                'review_actor_type' => IntakeHumanReviewSnapshotService::ACTOR_PROFILE_USER,
                'review_surface' => IntakeHumanReviewSnapshotService::SURFACE_API,
                'approval_policy' => IntakeHumanReviewSnapshotService::POLICY_PHASE2C_PROFILE_USER_REVIEW_V1,
                'approval_status' => IntakeHumanReviewSnapshotService::STATUS_REVIEWED,
            ]);
        }

        $applySnapshot = $this->formBridge->registrationApplySnapshot($snapshot);
        $result = $this->profileApplyService->applyFormRegistration($item, $intake, $applySnapshot, $mobile);
        $profile = $result['profile'];
        $item = $item->fresh() ?? $item;
        $intake = $intake->refresh();

        if ($this->photoCandidateCropService->exists($intake)) {
            $this->profileApplyService->applyRegistrationPhoto($intake, $item);
        }

        $prefsSnapshot = $this->preferencesBridge->defaultPreferencesSnapshot($profile);
        $this->profileApplyService->applyRegistrationPreferences(
            $item,
            $prefsSnapshot,
            (int) ($profile->user_id ?? 0),
        );

        $this->persistRegistrationMilestones($item);
        $this->registrationService->markRegistrationCompleteViaWhatsApp($item);
        $this->persistFlowStep($item, self::STEP_COMPLETED, ['completed_at' => now()->toISOString()]);

        $name = trim((string) ($core['full_name'] ?? ''));
        $greeting = $name !== '' ? $name.' जी' : 'आपण';
        $summary = $this->registrationService->summaryForItem($item);
        $webUrl = trim((string) ($summary['public_url'] ?? ''));

        $lines = [
            "🎉 धन्यवाद {$greeting}!",
            'तुमची नोंदणी पूर्ण झाली.',
            'आमचा प्रतिनिधी लवकरच तुमच्याशी संपर्क साधेल.',
        ];
        if ($webUrl !== '') {
            $lines[] = '';
            $lines[] = 'वेबवर प्रोफाइल पाहण्यासाठी:';
            $lines[] = $webUrl;
        }

        $this->sendText($session, $item, implode("\n", $lines), 'registration_complete');

        return $this->processedResult($item, self::STEP_COMPLETED, '🎉 नोंदणी पूर्ण झाली. Profile तयार झाला.');
    }

    /**
     * @return array{processed: bool, item_id: int|null, step: string|null}
     */
    private function deferFlow(BulkIntakeBatchItem $item, IntakeWhatsAppSession $session): array
    {
        $this->sendText(
            $session,
            $item,
            "ठीक आहे 👍\nजेव्हा वेळ मिळेल तेव्हा पुन्हा या संदेशावर उत्तर द्या.",
            'registration_deferred',
        );
        $this->persistFlowStep($item, self::STEP_DEFERRED, ['deferred_at' => now()->toISOString()]);

        return ['processed' => true, 'item_id' => (int) $item->id, 'step' => self::STEP_DEFERRED];
    }

    /**
     * @param  list<array{key: string, label: string, value: string|null, status: string, icon: string}>  $fields
     */
    private function sendFieldPickList(IntakeWhatsAppSession $session, BulkIntakeBatchItem $item, array $fields): void
    {
        $rows = [];
        foreach (array_slice($fields, 0, 10) as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $rows[] = [
                'id' => 'reg_field_'.$key,
                'title' => mb_substr((string) ($field['label'] ?? $key), 0, 24),
                'description' => mb_substr(trim((string) ($field['value'] ?? '—')), 0, 72),
            ];
        }

        if ($rows === []) {
            $this->sendText($session, $item, 'बदलासाठी कोणतीही माहिती सापडली नाही.', 'registration_field_pick_empty');

            return;
        }

        $this->sendList(
            $session,
            $item,
            "कोणती माहिती बदलायची आहे?\nखालील यादीतून निवडा 👇",
            'माहिती निवडा',
            $rows,
            'registration_field_pick',
        );
    }

    /**
     * @return list<array{id: string, title: string, meta_title: string}>
     */
    private function photoButtons(): array
    {
        return [
            [
                'id' => self::BTN_PHOTO_USE,
                'title' => '✅ १. हो वापरा',
                'meta_title' => 'हो वापरा',
            ],
            [
                'id' => self::BTN_PHOTO_NEW,
                'title' => '📷 २. नवीन पाठवा',
                'meta_title' => 'नवीन पाठवा',
            ],
        ];
    }

    /**
     * @param  list<array{id: string, title: string, meta_title: string}>  $buttons
     */
    private function sendButtons(
        IntakeWhatsAppSession $session,
        BulkIntakeBatchItem $item,
        string $body,
        array $buttons,
        string $messageKind,
    ): void {
        $mobile = $this->contactPlanService->activeMobile($item);
        if ($mobile === null) {
            return;
        }

        $this->whatsappSender->sendPermissionMessage($mobile, $body, $buttons, $this->outboundContext($item, $session, $messageKind));
    }

    /**
     * @param  list<array{id: string, title: string, description?: string}>  $rows
     */
    private function sendList(
        IntakeWhatsAppSession $session,
        BulkIntakeBatchItem $item,
        string $body,
        string $buttonLabel,
        array $rows,
        string $messageKind,
    ): void {
        $mobile = $this->contactPlanService->activeMobile($item);
        if ($mobile === null) {
            return;
        }

        if ($this->isLiveMetaSendingEnabled()) {
            $this->metaWhatsAppCloudService->sendInteractiveList($mobile, $body, $buttonLabel, $rows);

            return;
        }

        $this->logOutboundMessage($session, $item, $body, IntakeWhatsAppMessage::TYPE_INTERACTIVE, $messageKind, [
            'list_button' => $buttonLabel,
            'list_rows' => $rows,
        ]);
    }

    private function sendText(IntakeWhatsAppSession $session, BulkIntakeBatchItem $item, string $body, string $messageKind, array $extra = []): void
    {
        $mobile = $this->contactPlanService->activeMobile($item);
        if ($mobile === null) {
            return;
        }

        if ($this->isLiveMetaSendingEnabled()) {
            $this->metaWhatsAppCloudService->sendTextMessage($mobile, $body);

            return;
        }

        $this->logOutboundMessage($session, $item, $body, IntakeWhatsAppMessage::TYPE_TEXT, $messageKind, $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function logOutboundMessage(
        IntakeWhatsAppSession $session,
        BulkIntakeBatchItem $item,
        string $body,
        string $messageType,
        string $messageKind,
        array $extra = [],
    ): void {
        IntakeWhatsAppMessage::query()->create([
            'intake_whatsapp_session_id' => $session->id,
            'biodata_intake_id' => $item->biodata_intake_id ? (int) $item->biodata_intake_id : null,
            'direction' => IntakeWhatsAppMessage::DIRECTION_OUTBOUND,
            'wa_message_id' => 'bulk-reg-'.Str::uuid()->toString(),
            'message_type' => $messageType,
            'text_body' => $body,
            'processing_status' => IntakeWhatsAppMessage::STATUS_PROCESSED,
            'webhook_payload_json' => array_merge([
                'driver' => 'log',
                'message_kind' => $messageKind,
                'bulk_intake_batch_item_id' => (int) $item->id,
            ], $extra),
            'sent_at' => now(),
            'processed_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function outboundContext(BulkIntakeBatchItem $item, IntakeWhatsAppSession $session, string $messageKind): array
    {
        return [
            'bulk_intake_batch_item_id' => (int) $item->id,
            'bulk_intake_batch_id' => (int) $item->bulk_intake_batch_id,
            'biodata_intake_id' => $item->biodata_intake_id ? (int) $item->biodata_intake_id : null,
            'intake_whatsapp_session_id' => (int) $session->id,
            'message_kind' => $messageKind,
        ];
    }

    private function seedCenterCropFromBiodataImage(BiodataIntake $intake): void
    {
        $sourcePath = storage_path('app/private/'.trim((string) $intake->file_path));
        $info = @getimagesize($sourcePath);
        if (! is_array($info)) {
            throw new \InvalidArgumentException('candidate_source_invalid_image');
        }

        $sourceWidth = (int) ($info[0] ?? 0);
        $sourceHeight = (int) ($info[1] ?? 0);
        $size = min($sourceWidth, $sourceHeight);
        if ($size < 80) {
            throw new \InvalidArgumentException('candidate_box_invalid');
        }

        $x = (int) (($sourceWidth - $size) / 2);
        $y = (int) (($sourceHeight - $size) / 2);

        $this->photoCandidateCropService->saveFromOriginalBox($intake, [
            'x' => $x,
            'y' => $y,
            'width' => $size,
            'height' => $size,
        ]);
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function applyFieldCorrectionToSnapshot(array &$core, string $fieldKey, string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }

        match ($fieldKey) {
            'full_name' => $core['full_name'] = $value,
            'mobile' => $core['primary_contact_number'] = $value,
            'date_of_birth' => $core['date_of_birth'] = $value,
            'height_cm' => $this->applyHeightCorrection($core, $value),
            'gender' => $core['gender'] = strtolower($value),
            'location' => $this->applyLocationCorrection($core, $value),
            'education' => $core['highest_education'] = $value,
            'religion' => $core['religion'] = $value,
            'caste' => $core['caste'] = $value,
            'occupation' => $core['occupation'] = $value,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function applyHeightCorrection(array &$core, string $value): void
    {
        $parsed = HeightDisplay::parseFeetInchesString($value);
        if ($parsed !== null) {
            $core['height_cm'] = $parsed;

            return;
        }

        $digits = preg_replace('/\D+/', '', $value);
        if (is_string($digits) && $digits !== '' && (int) $digits >= 120 && (int) $digits <= 220) {
            $core['height_cm'] = (int) $digits;

            return;
        }

        $core['height'] = $value;
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function applyLocationCorrection(array &$core, string $value): void
    {
        $core['city_text'] = $value;
        $core['city'] = $value;
        $core['address_line'] = $value;
    }

    private function persistRegistrationMilestones(BulkIntakeBatchItem $item): void
    {
        DB::transaction(function () use ($item): void {
            /** @var BulkIntakeBatchItem $locked */
            $locked = BulkIntakeBatchItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $meta = is_array($locked->item_meta_json) ? $locked->item_meta_json : [];
            $existing = is_array($meta['registration'] ?? null) ? $meta['registration'] : [];
            $now = now()->toISOString();

            $meta['registration'] = array_merge($existing, [
                'photo_completed_at' => $now,
                'photo_completed_via' => 'whatsapp_flow',
                'preferences_completed_at' => $now,
                'preferences_completed_via' => 'whatsapp_flow',
            ]);

            $locked->forceFill(['item_meta_json' => $meta])->save();
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function persistFlowStep(BulkIntakeBatchItem $item, string $step, array $extra = []): void
    {
        /** @var BulkIntakeBatchItem $fresh */
        $fresh = BulkIntakeBatchItem::query()->whereKey($item->id)->firstOrFail();
        $meta = is_array($fresh->item_meta_json) ? $fresh->item_meta_json : [];
        $registration = is_array($meta['registration'] ?? null) ? $meta['registration'] : [];
        $flow = is_array($registration['whatsapp_flow'] ?? null) ? $registration['whatsapp_flow'] : [];

        $registration['whatsapp_flow'] = array_merge($flow, array_merge(['step' => $step], $extra));
        $meta['registration'] = $registration;
        $fresh->forceFill(['item_meta_json' => $meta])->save();
    }

    private function pendingRegistrationItem(IntakeWhatsAppSession $session): ?BulkIntakeBatchItem
    {
        $sessionMeta = is_array($session->session_meta_json) ? $session->session_meta_json : [];
        $itemId = isset($sessionMeta['bulk_intake_batch_item_id']) ? (int) $sessionMeta['bulk_intake_batch_item_id'] : 0;

        if ($itemId > 0) {
            $item = BulkIntakeBatchItem::query()->find($itemId);
            if ($item instanceof BulkIntakeBatchItem
                && $this->registrationService->registrationStatus($item) === BulkIntakeRegistrationService::STATUS_SUMMARY_SENT) {
                return $item;
            }
        }

        return BulkIntakeBatchItem::query()
            ->where('item_meta_json->registration->status', BulkIntakeRegistrationService::STATUS_SUMMARY_SENT)
            ->where('item_meta_json->registration->intake_whatsapp_session_id', (int) $session->id)
            ->orderByDesc('id')
            ->first();
    }

    private function flowStep(BulkIntakeBatchItem $item): ?string
    {
        $flow = $this->flowMeta($item);
        $step = trim((string) ($flow['step'] ?? ''));

        return $step !== '' ? $step : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function flowMeta(BulkIntakeBatchItem $item): array
    {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $registration = is_array($meta['registration'] ?? null) ? $meta['registration'] : [];
        $flow = $registration['whatsapp_flow'] ?? null;

        return is_array($flow) ? $flow : [];
    }

    private function resolveChoice(string $replyText, ?string $buttonId, string $step, BulkIntakeBatchItem $item): ?string
    {
        $buttonId = $this->normalizeToken($buttonId);
        if ($buttonId !== null) {
            return $buttonId;
        }

        if ($step === self::STEP_AWAITING_FIELD_PICK) {
            $token = $this->normalizeToken($replyText);
            if ($token !== null && str_starts_with($token, 'reg_field_')) {
                return $token;
            }
        }

        $text = $this->normalizeToken($replyText);
        if ($text === null) {
            return null;
        }

        return match ($text) {
            'हो, बरोबर', 'हो बरोबर', 'बरोबर', 'yes', 'ok', '१. हो, बरोबर', '✅ १. हो, बरोबर' => self::BTN_SUMMARY_OK,
            'काही चुकीचे', 'चुकीचे', 'edit', '२. चुकीचे', '✏️ २. चुकीचे' => self::BTN_SUMMARY_EDIT,
            'नंतर', 'नंतर करेन', 'later', '३. नंतर', '⏰ ३. नंतर' => self::BTN_SUMMARY_LATER,
            'हो वापरा', 'वापरा', '१. हो वापरा', '✅ १. हो वापरा' => self::BTN_PHOTO_USE,
            'नवीन पाठवा', 'नवीन फोटो', '२. नवीन पाठवा', '📷 २. नवीन पाठवा' => self::BTN_PHOTO_NEW,
            default => null,
        };
    }

    private function normalizeToken(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $token = trim(mb_strtolower($value));

        return $token === '' ? null : $token;
    }

    private function intakeForItem(BulkIntakeBatchItem $item): ?BiodataIntake
    {
        $item->loadMissing('biodataIntake');

        return $item->biodataIntake;
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceSnapshot(BiodataIntake $intake): array
    {
        if (is_array($intake->approval_snapshot_json) && $intake->approval_snapshot_json !== []) {
            return $intake->approval_snapshot_json;
        }

        if (is_array($intake->parsed_json) && $intake->parsed_json !== []) {
            return $intake->parsed_json;
        }

        return [];
    }

    private function isLiveMetaSendingEnabled(): bool
    {
        return (bool) config('whatsapp.bulk_consent_live_enabled', false);
    }

    public function canSimulatePhotoReceived(BulkIntakeBatchItem $item): bool
    {
        if (! $this->canSimulateReply($item)) {
            return false;
        }

        if ($this->activeFlowStep($item) !== self::STEP_AWAITING_PHOTO) {
            return false;
        }

        $intake = $this->intakeForItem($item);

        return $intake instanceof BiodataIntake;
    }

    /**
     * @return array{processed: bool, item_id: int|null, step: string|null, notice?: string}
     */
    public function simulatePhotoReceived(BulkIntakeBatchItem $item, User $actor): array
    {
        if (! $this->canSimulatePhotoReceived($item)) {
            throw ValidationException::withMessages([
                'registration' => 'Photo simulation is only available during the photo step.',
            ]);
        }

        $session = IntakeWhatsAppSession::query()->findOrFail($this->registrationSessionId($item));
        $intake = $this->intakeForItem($item);
        if (! $intake instanceof BiodataIntake) {
            throw ValidationException::withMessages([
                'registration' => 'Linked biodata intake is missing.',
            ]);
        }

        $this->photoCandidateCropService->saveFromBinary($intake, $this->testJpegBytes());
        $result = $this->finalizeRegistration($item, $session);

        $freshItem = $item->fresh();
        $meta = is_array($freshItem?->item_meta_json) ? $freshItem->item_meta_json : [];
        $registration = is_array($meta['registration'] ?? null) ? $meta['registration'] : [];
        $flow = is_array($registration['whatsapp_flow'] ?? null) ? $registration['whatsapp_flow'] : [];
        $registration['whatsapp_flow'] = array_merge($flow, [
            'simulated_photo_by_user_id' => (int) $actor->id,
            'simulated_photo_at' => now()->toISOString(),
        ]);
        $meta['registration'] = $registration;
        $freshItem?->forceFill(['item_meta_json' => $meta])->save();

        return $result;
    }

    public function flashMessageForResult(array $result): string
    {
        $notice = trim((string) ($result['notice'] ?? ''));
        if ($notice !== '') {
            return $notice;
        }

        return match ((string) ($result['step'] ?? '')) {
            self::STEP_COMPLETED => 'नोंदणी पूर्ण झाली.',
            self::STEP_AWAITING_PHOTO => 'पुढची पायरी: फोटो.',
            self::STEP_AWAITING_FIELD_PICK => 'पुढची पायरी: बदलायचे field निवडा.',
            self::STEP_AWAITING_FIELD_VALUE => 'पुढची पायरी: योग्य माहिती लिहा.',
            self::STEP_DEFERRED => 'नोंदणी नंतरासाठी थांबवली.',
            default => 'प्रतिसाद नोंदवला.',
        };
    }

    /**
     * @return array{processed: bool, item_id: int|null, step: string|null, notice?: string}
     */
    private function processedResult(BulkIntakeBatchItem $item, string $step, ?string $notice = null): array
    {
        $result = [
            'processed' => true,
            'item_id' => (int) $item->id,
            'step' => $step,
        ];
        if ($notice !== null && trim($notice) !== '') {
            $result['notice'] = trim($notice);
        }

        return $result;
    }

    private function testJpegBytes(): string
    {
        $image = imagecreatetruecolor(120, 120);
        ob_start();
        imagejpeg($image, null, 90);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    public function isManualTestEnabled(): bool
    {
        return $this->registrationService->isManualWhatsAppTestEnabled();
    }

    public function canSimulateReply(BulkIntakeBatchItem $item): bool
    {
        if (! $this->isManualTestEnabled()) {
            return false;
        }

        if ($this->registrationService->registrationStatus($item) === BulkIntakeRegistrationService::STATUS_REGISTRATION_COMPLETE) {
            return false;
        }

        $status = $this->registrationService->registrationStatus($item);
        if (! in_array($status, [BulkIntakeRegistrationService::STATUS_SUMMARY_SENT], true)) {
            return false;
        }

        return $this->registrationSessionId($item) > 0;
    }

    public function needsFieldValueText(BulkIntakeBatchItem $item): bool
    {
        return $this->activeFlowStep($item) === self::STEP_AWAITING_FIELD_VALUE;
    }

    public function flowStepLabel(BulkIntakeBatchItem $item): string
    {
        return match ($this->activeFlowStep($item)) {
            self::STEP_AWAITING_SUMMARY_CONFIRM => 'Summary confirm',
            self::STEP_AWAITING_FIELD_PICK => 'Pick field to correct',
            self::STEP_AWAITING_FIELD_VALUE => 'Type corrected value',
            self::STEP_AWAITING_PHOTO => 'Photo step',
            self::STEP_DEFERRED => 'Deferred (resume)',
            self::STEP_COMPLETED => 'Completed',
            default => 'Not started',
        };
    }

    /**
     * @return list<array{id: string, title: string, meta_title?: string}>
     */
    public function simulateButtonsForItem(BulkIntakeBatchItem $item): array
    {
        if (! $this->canSimulateReply($item)) {
            return [];
        }

        $step = $this->activeFlowStep($item);

        if (in_array($step, [self::STEP_AWAITING_SUMMARY_CONFIRM, self::STEP_DEFERRED], true)) {
            return $this->registrationService->summaryInteractiveButtons();
        }

        if ($step === self::STEP_AWAITING_FIELD_PICK) {
            return $this->fieldPickSimulateButtons($item);
        }

        if ($step === self::STEP_AWAITING_PHOTO) {
            return $this->photoButtons();
        }

        return [];
    }

    /**
     * @return array{processed: bool, item_id: int|null, step: string|null}
     */
    public function simulateReply(BulkIntakeBatchItem $item, string $replyChoice, User $actor, ?string $replyText = null): array
    {
        if (! $this->isManualTestEnabled()) {
            throw ValidationException::withMessages([
                'registration' => 'Manual WhatsApp registration simulation is disabled while live Meta sending is active.',
            ]);
        }

        if (! $this->canSimulateReply($item)) {
            throw ValidationException::withMessages([
                'registration' => 'Registration summary must be sent before simulating user replies.',
            ]);
        }

        $session = IntakeWhatsAppSession::query()->findOrFail($this->registrationSessionId($item));
        $step = $this->activeFlowStep($item);

        if ($step === self::STEP_AWAITING_FIELD_VALUE) {
            $text = trim((string) $replyText);
            if ($text === '') {
                throw ValidationException::withMessages([
                    'reply_text' => 'Enter the corrected field value to simulate the WhatsApp text reply.',
                ]);
            }

            $result = $this->processInbound($session, $text, null);
        } else {
            $allowedIds = array_map(
                fn (array $button): string => (string) ($button['id'] ?? ''),
                $this->simulateButtonsForItem($item)
            );
            if (! in_array($replyChoice, $allowedIds, true)) {
                throw ValidationException::withMessages([
                    'reply_choice' => 'Invalid simulated registration reply choice.',
                ]);
            }

            $button = collect($this->simulateButtonsForItem($item))->firstWhere('id', $replyChoice);
            $buttonTitle = is_array($button) ? (string) ($button['meta_title'] ?? $button['title'] ?? $replyChoice) : $replyChoice;
            $result = $this->processInbound($session, $buttonTitle, $replyChoice);
        }

        if (! $result['processed']) {
            throw ValidationException::withMessages([
                'registration' => 'Simulated registration WhatsApp reply could not be processed.',
            ]);
        }

        $freshItem = $item->fresh();
        $meta = is_array($freshItem?->item_meta_json) ? $freshItem->item_meta_json : [];
        $registration = is_array($meta['registration'] ?? null) ? $meta['registration'] : [];
        $flow = is_array($registration['whatsapp_flow'] ?? null) ? $registration['whatsapp_flow'] : [];
        $registration['whatsapp_flow'] = array_merge($flow, [
            'simulated_reply_by_user_id' => (int) $actor->id,
            'simulated_reply_at' => now()->toISOString(),
            'simulated_reply_choice' => $replyChoice !== '' ? $replyChoice : 'text_reply',
        ]);
        $meta['registration'] = $registration;
        $freshItem?->forceFill(['item_meta_json' => $meta])->save();

        return $result;
    }

    private function activeFlowStep(BulkIntakeBatchItem $item): string
    {
        $step = $this->flowStep($item);

        return $step ?? self::STEP_AWAITING_SUMMARY_CONFIRM;
    }

    private function registrationSessionId(BulkIntakeBatchItem $item): int
    {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $registration = is_array($meta['registration'] ?? null) ? $meta['registration'] : [];

        return (int) ($registration['intake_whatsapp_session_id'] ?? 0);
    }

    /**
     * @return list<array{id: string, title: string, meta_title: string}>
     */
    private function fieldPickSimulateButtons(BulkIntakeBatchItem $item): array
    {
        $warningFields = $this->registrationService->warningFieldsForItem($item);
        $fields = $warningFields !== [] ? $warningFields : $this->registrationService->summaryForItem($item)['whatsapp_fields'];
        $buttons = [];

        foreach (array_slice($fields, 0, 10) as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $label = (string) ($field['label'] ?? $key);
            $buttons[] = [
                'id' => 'reg_field_'.$key,
                'title' => '✏️ '.$label.' बदला',
                'meta_title' => $label.' बदला',
            ];
        }

        return $buttons;
    }
}
