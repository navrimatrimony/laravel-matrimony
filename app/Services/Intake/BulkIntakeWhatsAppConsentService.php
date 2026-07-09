<?php

namespace App\Services\Intake;

use App\Contracts\Intake\BulkIntakeWhatsAppConsentSender;
use App\Models\BulkIntakeBatchItem;
use App\Models\BulkIntakeIdentityHistory;
use App\Models\IntakeWhatsAppMessage;
use App\Models\IntakeWhatsAppSession;
use App\Models\User;
use App\Support\MobileNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BulkIntakeWhatsAppConsentService
{
    public const STATUS_PERMISSION_SENT = 'permission_sent';

    public const STATUS_CONSENT_RECEIVED = 'consent_received';

    public const STATUS_CONSENT_DENIED = 'consent_denied';

    public const STATUS_ALREADY_MARRIED = 'already_married';

    public const STATUS_WRONG_NUMBER = 'wrong_number';

    public const STATUS_NO_RESPONSE = 'no_response';

    public const REPLY_YES = 'yes';

    public const REPLY_NO = 'no';

    public const REPLY_ALREADY_MARRIED = 'already_married';

    public const REPLY_WRONG_NUMBER = 'wrong_number';

    /**
     * @var list<string>
     */
    public const TERMINAL_STATUSES = [
        self::STATUS_CONSENT_RECEIVED,
        self::STATUS_CONSENT_DENIED,
        self::STATUS_ALREADY_MARRIED,
        self::STATUS_WRONG_NUMBER,
        self::STATUS_NO_RESPONSE,
    ];

    public function __construct(
        private readonly BulkIntakeEligibilityService $eligibilityService,
        private readonly BulkIntakeCandidateDisplayService $candidateDisplayService,
        private readonly BulkIntakeIdentityHistoryService $identityHistoryService,
        private readonly BulkIntakeWhatsAppConsentSender $sender,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function consentPayload(BulkIntakeBatchItem $item): ?array
    {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $consent = data_get($meta, 'whatsapp_consent');

        return is_array($consent) ? $consent : null;
    }

    public function consentStatus(BulkIntakeBatchItem $item): ?string
    {
        $status = (string) ($this->consentPayload($item)['status'] ?? '');

        return $status !== '' ? $status : null;
    }

    /**
     * @param  array<string, mixed>|null  $pipeline
     * @return array{allowed: bool, reasons: list<string>}
     */
    public function canSendPermission(BulkIntakeBatchItem $item, ?array $pipeline = null): array
    {
        $reasons = [];
        $pipeline ??= $this->eligibilityService->eligibleForPipeline($item);

        if (($pipeline['bucket'] ?? '') !== BulkIntakeEligibilityService::FILTER_ELIGIBLE) {
            $reasons[] = 'not_pipeline_eligible';
        }

        $status = $this->consentStatus($item);
        if ($status === self::STATUS_PERMISSION_SENT) {
            $reasons[] = 'permission_already_sent';
        } elseif ($status !== null && in_array($status, self::TERMINAL_STATUSES, true)) {
            $reasons[] = 'consent_already_finalized';
        }

        $mobile = $this->recipientMobile($item);
        if ($mobile === null) {
            $reasons[] = 'missing_mobile';
        }

        return [
            'allowed' => $reasons === [],
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $pipeline
     * @return array{
     *     success: bool,
     *     status: string,
     *     intake_whatsapp_session_id: int|null,
     *     intake_whatsapp_message_id: int|null,
     *     error_code: string|null,
     *     error_message: string|null
     * }
     */
    public function sendPermission(BulkIntakeBatchItem $item, User $actor, ?array $pipeline = null): array
    {
        $gate = $this->canSendPermission($item, $pipeline);
        if (! $gate['allowed']) {
            throw ValidationException::withMessages([
                'whatsapp_consent' => $this->cannotSendMessage($gate['reasons']),
            ]);
        }

        $mobile = $this->recipientMobile($item);
        if ($mobile === null) {
            throw ValidationException::withMessages([
                'whatsapp_consent' => 'Candidate mobile is missing.',
            ]);
        }

        $body = $this->buildPermissionMessage($item);
        $buttons = $this->permissionButtons();
        $sendResult = $this->sender->sendPermissionMessage($mobile, $body, $buttons, [
            'bulk_intake_batch_item_id' => (int) $item->id,
            'bulk_intake_batch_id' => (int) $item->bulk_intake_batch_id,
            'biodata_intake_id' => $item->biodata_intake_id ? (int) $item->biodata_intake_id : null,
            'sent_by_user_id' => (int) $actor->id,
        ]);

        if (! $sendResult->success) {
            $this->persistConsentFailure($item, $actor, $sendResult->errorCode, $sendResult->errorMessage);

            return [
                'success' => false,
                'status' => $this->consentStatus($item) ?? 'failed',
                'intake_whatsapp_session_id' => null,
                'intake_whatsapp_message_id' => null,
                'error_code' => $sendResult->errorCode,
                'error_message' => $sendResult->errorMessage,
            ];
        }

        $raw = is_array($sendResult->rawResponse) ? $sendResult->rawResponse : [];
        $sessionId = isset($raw['intake_whatsapp_session_id']) ? (int) $raw['intake_whatsapp_session_id'] : null;
        $messageId = isset($raw['intake_whatsapp_message_id']) ? (int) $raw['intake_whatsapp_message_id'] : null;

        $this->persistPermissionSent($item, $actor, $sessionId, $messageId, $sendResult->providerMessageId);

        return [
            'success' => true,
            'status' => self::STATUS_PERMISSION_SENT,
            'intake_whatsapp_session_id' => $sessionId,
            'intake_whatsapp_message_id' => $messageId,
            'error_code' => null,
            'error_message' => null,
        ];
    }

    /**
     * @return array{processed: bool, item_id: int|null, status: string|null}
     */
    public function processInboundReply(IntakeWhatsAppSession $session, string $replyText, ?string $buttonId = null): array
    {
        $item = $this->pendingItemForSession($session);
        if ($item === null) {
            return ['processed' => false, 'item_id' => null, 'status' => null];
        }

        $choice = $this->resolveReplyChoice($replyText, $buttonId);
        if ($choice === null) {
            return ['processed' => false, 'item_id' => (int) $item->id, 'status' => $this->consentStatus($item)];
        }

        return DB::transaction(function () use ($item, $session, $choice, $replyText, $buttonId): array {
            /** @var BulkIntakeBatchItem $locked */
            $locked = BulkIntakeBatchItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($this->consentStatus($locked) !== self::STATUS_PERMISSION_SENT) {
                return [
                    'processed' => false,
                    'item_id' => (int) $locked->id,
                    'status' => $this->consentStatus($locked),
                ];
            }

            $status = $this->applyReplyChoice($locked, $choice, $replyText, $buttonId, $session);

            return [
                'processed' => true,
                'item_id' => (int) $locked->id,
                'status' => $status,
            ];
        });
    }

    public function markNoResponse(BulkIntakeBatchItem $item, ?User $actor = null): void
    {
        if ($this->consentStatus($item) !== self::STATUS_PERMISSION_SENT) {
            return;
        }

        $this->persistConsentStatus(
            $item,
            self::STATUS_NO_RESPONSE,
            null,
            null,
            'No WhatsApp response before expiry.'
        );

        $this->identityHistoryService->recordForItem(
            $item->fresh(),
            BulkIntakeIdentityHistory::REASON_NO_RESPONSE,
            BulkIntakeIdentityHistory::SOURCE_WHATSAPP_REPLY,
            $actor,
            'WhatsApp permission message had no response.'
        );
    }

    /**
     * @return list<array{id: string, title: string}>
     */
    public function permissionButtons(): array
    {
        return [
            ['id' => self::REPLY_YES, 'title' => 'हो'],
            ['id' => self::REPLY_NO, 'title' => 'नको'],
            ['id' => self::REPLY_ALREADY_MARRIED, 'title' => 'लग्न झाले'],
            ['id' => self::REPLY_WRONG_NUMBER, 'title' => 'चुकीचा नंबर'],
        ];
    }

    public function buildPermissionMessage(BulkIntakeBatchItem $item): string
    {
        $candidate = $this->candidateDisplayService->candidateForItem($item);
        $relativeLabel = $this->relativeLabel((string) ($candidate['gender'] ?? ''));

        return 'नमस्कार, आम्ही नवरी-नवरा मॅट्रिमोनी आहोत. तुमच्या '.$relativeLabel.' चा biodata मिळाला. योग्य स्थळे सुचवू का? परवानगी द्या.';
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_PERMISSION_SENT => 'Permission sent',
            self::STATUS_CONSENT_RECEIVED => 'Consent received',
            self::STATUS_CONSENT_DENIED => 'Consent denied',
            self::STATUS_ALREADY_MARRIED => 'Already married',
            self::STATUS_WRONG_NUMBER => 'Wrong number',
            self::STATUS_NO_RESPONSE => 'No response',
            default => str_replace('_', ' ', $status),
        };
    }

    public function resolveReplyChoice(string $replyText, ?string $buttonId = null): ?string
    {
        $buttonId = $this->normalizeReplyToken($buttonId);
        if ($buttonId !== null) {
            return match ($buttonId) {
                self::REPLY_YES, 'ho', 'yes' => self::REPLY_YES,
                self::REPLY_NO, 'nako', 'no' => self::REPLY_NO,
                self::REPLY_ALREADY_MARRIED, 'already_married', 'married' => self::REPLY_ALREADY_MARRIED,
                self::REPLY_WRONG_NUMBER, 'wrong_number', 'wrong' => self::REPLY_WRONG_NUMBER,
                default => null,
            };
        }

        $text = $this->normalizeReplyToken($replyText);
        if ($text === null) {
            return null;
        }

        return match ($text) {
            'हो', 'ho', 'yes', 'y' => self::REPLY_YES,
            'नको', 'nako', 'no', 'n' => self::REPLY_NO,
            'लग्न झाले', 'लग्न झालं', 'already married', 'married', 'already_married' => self::REPLY_ALREADY_MARRIED,
            'चुकीचा नंबर', 'चुकीचा नंबर आहे', 'wrong number', 'wrong_number', 'wrong' => self::REPLY_WRONG_NUMBER,
            default => null,
        };
    }

    /**
     * @param  list<string>  $reasons
     */
    public function cannotSendMessage(array $reasons): string
    {
        $labels = array_map(fn (string $reason): string => match ($reason) {
            'not_pipeline_eligible' => 'Candidate is not in the Eligible pipeline bucket.',
            'permission_already_sent' => 'Permission message was already sent. Wait for reply before sending again.',
            'consent_already_finalized' => 'WhatsApp consent is already finalized for this candidate.',
            'missing_mobile' => 'Candidate mobile is missing.',
            default => str_replace('_', ' ', $reason),
        }, $reasons);

        return implode(' ', $labels);
    }

    private function applyReplyChoice(
        BulkIntakeBatchItem $item,
        string $choice,
        string $replyText,
        ?string $buttonId,
        IntakeWhatsAppSession $session
    ): string {
        $note = trim($replyText) !== '' ? trim($replyText) : ($buttonId ?? $choice);

        return match ($choice) {
            self::REPLY_YES => tap(self::STATUS_CONSENT_RECEIVED, function () use ($item, $note, $session): void {
                $this->persistConsentStatus($item, self::STATUS_CONSENT_RECEIVED, self::REPLY_YES, $session, $note);
                $this->markSessionConsent($session, IntakeWhatsAppSession::CONSENT_GRANTED);
            }),
            self::REPLY_NO => tap(self::STATUS_CONSENT_DENIED, function () use ($item, $note, $session): void {
                $this->persistConsentStatus($item, self::STATUS_CONSENT_DENIED, self::REPLY_NO, $session, $note);
                $this->recordBlockingReplyHistory($item, BulkIntakeIdentityHistory::REASON_NOT_INTERESTED, $note);
                $this->markSessionConsent($session, IntakeWhatsAppSession::CONSENT_DENIED);
            }),
            self::REPLY_ALREADY_MARRIED => tap(self::STATUS_ALREADY_MARRIED, function () use ($item, $note, $session): void {
                $this->persistConsentStatus($item, self::STATUS_ALREADY_MARRIED, self::REPLY_ALREADY_MARRIED, $session, $note);
                $this->recordBlockingReplyHistory($item, BulkIntakeIdentityHistory::REASON_ALREADY_MARRIED, $note);
                $this->markSessionConsent($session, IntakeWhatsAppSession::CONSENT_DENIED);
            }),
            self::REPLY_WRONG_NUMBER => tap(self::STATUS_WRONG_NUMBER, function () use ($item, $note, $session): void {
                $this->persistConsentStatus($item, self::STATUS_WRONG_NUMBER, self::REPLY_WRONG_NUMBER, $session, $note);
                $this->recordBlockingReplyHistory($item, BulkIntakeIdentityHistory::REASON_WRONG_NUMBER, $note);
                $this->markSessionConsent($session, IntakeWhatsAppSession::CONSENT_DENIED);
            }),
            default => $this->consentStatus($item) ?? self::STATUS_PERMISSION_SENT,
        };
    }

    private function recordBlockingReplyHistory(BulkIntakeBatchItem $item, string $reasonCode, string $note): void
    {
        $this->identityHistoryService->recordForItem(
            $item,
            $reasonCode,
            BulkIntakeIdentityHistory::SOURCE_WHATSAPP_REPLY,
            null,
            $note
        );
    }

    private function pendingItemForSession(IntakeWhatsAppSession $session): ?BulkIntakeBatchItem
    {
        $sessionMeta = is_array($session->session_meta_json) ? $session->session_meta_json : [];
        $itemId = isset($sessionMeta['bulk_intake_batch_item_id']) ? (int) $sessionMeta['bulk_intake_batch_item_id'] : null;
        if ($itemId !== null && $itemId > 0) {
            $item = BulkIntakeBatchItem::query()->find($itemId);
            if ($item instanceof BulkIntakeBatchItem && $this->consentStatus($item) === self::STATUS_PERMISSION_SENT) {
                return $item;
            }
        }

        return BulkIntakeBatchItem::query()
            ->where('item_meta_json->whatsapp_consent->status', self::STATUS_PERMISSION_SENT)
            ->where('item_meta_json->whatsapp_consent->intake_whatsapp_session_id', (int) $session->id)
            ->orderByDesc('id')
            ->first();
    }

    private function persistPermissionSent(
        BulkIntakeBatchItem $item,
        User $actor,
        ?int $sessionId,
        ?int $messageId,
        ?string $providerMessageId
    ): void {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $meta['whatsapp_consent'] = [
            'status' => self::STATUS_PERMISSION_SENT,
            'sent_at' => now()->toISOString(),
            'sent_by_user_id' => (int) $actor->id,
            'reply_at' => null,
            'reply_choice' => null,
            'reply_text' => null,
            'intake_whatsapp_session_id' => $sessionId,
            'intake_whatsapp_message_id' => $messageId,
            'provider_message_id' => $providerMessageId,
            'last_error' => null,
        ];

        $item->forceFill(['item_meta_json' => $meta])->save();
    }

    private function persistConsentFailure(
        BulkIntakeBatchItem $item,
        User $actor,
        ?string $errorCode,
        ?string $errorMessage
    ): void {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $existing = is_array($meta['whatsapp_consent'] ?? null) ? $meta['whatsapp_consent'] : [];
        $meta['whatsapp_consent'] = array_merge($existing, [
            'last_error' => trim((string) $errorMessage) !== '' ? trim((string) $errorMessage) : $errorCode,
            'last_attempt_at' => now()->toISOString(),
            'last_attempt_by_user_id' => (int) $actor->id,
        ]);

        $item->forceFill(['item_meta_json' => $meta])->save();
    }

    private function persistConsentStatus(
        BulkIntakeBatchItem $item,
        string $status,
        ?string $replyChoice,
        ?IntakeWhatsAppSession $session,
        ?string $replyText
    ): void {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $existing = is_array($meta['whatsapp_consent'] ?? null) ? $meta['whatsapp_consent'] : [];

        $meta['whatsapp_consent'] = array_merge($existing, [
            'status' => $status,
            'reply_at' => now()->toISOString(),
            'reply_choice' => $replyChoice,
            'reply_text' => $replyText,
            'intake_whatsapp_session_id' => $session?->id ?? ($existing['intake_whatsapp_session_id'] ?? null),
        ]);

        $item->forceFill(['item_meta_json' => $meta])->save();
    }

    private function markSessionConsent(IntakeWhatsAppSession $session, string $consentStatus): void
    {
        $session->forceFill([
            'consent_status' => $consentStatus,
            'session_status' => $consentStatus === IntakeWhatsAppSession::CONSENT_GRANTED
                ? IntakeWhatsAppSession::STATUS_COLLECTING_BIODATA
                : IntakeWhatsAppSession::STATUS_CLOSED,
            'closed_at' => $consentStatus === IntakeWhatsAppSession::CONSENT_GRANTED ? null : now(),
            'last_message_at' => now(),
        ])->save();
    }

    private function recipientMobile(BulkIntakeBatchItem $item): ?string
    {
        $candidate = $this->candidateDisplayService->candidateForItem($item);
        $mobile = MobileNumber::normalize((string) ($candidate['mobile'] ?? ''));

        return $mobile;
    }

    private function relativeLabel(string $gender): string
    {
        return match (strtolower(trim($gender))) {
            'male', 'पुरुष', 'm' => 'मुलाचा',
            'female', 'स्त्री', 'f' => 'मुलीचा',
            default => 'नातेवाईकाचा',
        };
    }

    private function normalizeReplyToken(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(mb_strtolower($value, 'UTF-8'));

        return $value !== '' ? $value : null;
    }
}
