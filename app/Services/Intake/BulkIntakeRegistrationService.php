<?php

namespace App\Services\Intake;

use App\Contracts\Intake\BulkIntakeWhatsAppConsentSender;
use App\Models\BulkIntakeBatchItem;
use App\Models\User;
use App\Support\MobileNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BulkIntakeRegistrationService
{
    public const STATUS_PENDING_SEND = 'pending_send';

    public const STATUS_SUMMARY_SENT = 'summary_sent';

    public const STATUS_REGISTRATION_COMPLETE = 'registration_complete';

    public const PATH_FAST = 'fast';

    public const PATH_TARGETED = 'targeted';

    public const PATH_FULL = 'full';

    public const FIELD_OK = 'ok';

    public const FIELD_NEEDS_CHECK = 'needs_check';

    public const FIELD_MISSING = 'missing';

    public function __construct(
        private readonly BulkIntakeCandidateDisplayService $candidateDisplayService,
        private readonly BulkIntakeWhatsAppConsentService $whatsappConsentService,
        private readonly BulkIntakeWhatsAppConsentSender $whatsappSender,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function registrationPayload(BulkIntakeBatchItem $item): ?array
    {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $registration = data_get($meta, 'registration');

        return is_array($registration) ? $registration : null;
    }

    public function registrationStatus(BulkIntakeBatchItem $item): ?string
    {
        $status = (string) ($this->registrationPayload($item)['status'] ?? '');

        return $status !== '' ? $status : null;
    }

    public function isManualWhatsAppTestEnabled(): bool
    {
        return $this->whatsappConsentService->isManualWhatsAppTestEnabled();
    }

    /**
     * @return array{allowed: bool, reasons: list<string>}
     */
    public function canSendRegistrationSummary(BulkIntakeBatchItem $item): array
    {
        $reasons = [];

        if ($this->whatsappConsentService->consentStatus($item) !== BulkIntakeWhatsAppConsentService::STATUS_CONSENT_RECEIVED) {
            $reasons[] = 'consent_not_received';
        }

        $status = $this->registrationStatus($item);
        if ($status === self::STATUS_SUMMARY_SENT) {
            $reasons[] = 'summary_already_sent';
        } elseif ($status === self::STATUS_REGISTRATION_COMPLETE) {
            $reasons[] = 'registration_already_complete';
        }

        if ($this->recipientMobile($item) === null) {
            $reasons[] = 'missing_mobile';
        }

        return [
            'allowed' => $reasons === [],
            'reasons' => $reasons,
        ];
    }

    /**
     * @return array{
     *     fields: list<array{key: string, label: string, value: string|null, status: string, icon: string}>,
     *     path: string,
     *     path_label: string,
     *     warning_count: int,
     *     reviewed_snapshot_present: bool,
     *     display_source: string
     * }
     */
    public function summaryForItem(BulkIntakeBatchItem $item): array
    {
        $candidate = $this->candidateDisplayService->candidateForItem($item);
        $reviewed = (bool) ($candidate['reviewed_snapshot_present'] ?? false);
        $fields = [
            $this->summaryField('full_name', 'नाव', $candidate['full_name'] ?? null, (bool) ($candidate['name_needs_review'] ?? false), $reviewed),
            $this->summaryField('gender', 'लिंग', $candidate['gender'] ?? null, false, $reviewed),
            $this->summaryField('date_of_birth', 'जन्मतारीख', $this->dobDisplay($candidate), (bool) ($candidate['dob_needs_review'] ?? false), $reviewed),
            $this->summaryField('height', 'उंची', $candidate['height'] ?? null, (bool) ($candidate['height_needs_review'] ?? false), $reviewed),
            $this->summaryField('education', 'शिक्षण', $candidate['education'] ?? null, (bool) ($candidate['education_needs_review'] ?? false), $reviewed),
            $this->summaryField('city', 'ठिकाण', $candidate['city'] ?? null, false, $reviewed),
            $this->summaryField('mobile', 'मोबाईल', $candidate['mobile'] ?? null, false, $reviewed),
        ];

        $warningCount = collect($fields)
            ->filter(fn (array $field): bool => in_array((string) ($field['status'] ?? ''), [self::FIELD_NEEDS_CHECK, self::FIELD_MISSING], true))
            ->count();
        $path = $this->pathForWarningCount($warningCount);

        return [
            'fields' => $fields,
            'path' => $path,
            'path_label' => $this->pathLabel($path),
            'warning_count' => $warningCount,
            'reviewed_snapshot_present' => $reviewed,
            'display_source' => (string) ($candidate['display_source'] ?? 'parsed_json'),
        ];
    }

    public function pathForWarningCount(int $warningCount): string
    {
        return match (true) {
            $warningCount === 0 => self::PATH_FAST,
            $warningCount <= 3 => self::PATH_TARGETED,
            default => self::PATH_FULL,
        };
    }

    public function buildSummaryMessage(BulkIntakeBatchItem $item): string
    {
        $summary = $this->summaryForItem($item);
        $lines = [
            'धन्यवाद! तुमची परवानगी मिळाली. ✨',
            'खाली बायोडाटा सारांश आहे — कृपया तपासा:',
            '',
        ];

        foreach ($summary['fields'] as $field) {
            $value = trim((string) ($field['value'] ?? ''));
            $lines[] = ($field['icon'] ?? '⚠').' '.($field['label'] ?? '').': '.($value !== '' ? $value : '—');
        }

        $lines[] = '';
        $lines[] = $this->pathInstructionLine((string) $summary['path']);
        $lines[] = '';
        $lines[] = 'इतर पर्याय:';
        $lines[] = '• वेबवर सर्व edit करा';
        $lines[] = '• App/Website वरून नोंदणी';
        $lines[] = '• रिकामा form WhatsApp वर मागवा';

        return implode("\n", $lines);
    }

    public function buildManualTestWhatsAppShareUrl(BulkIntakeBatchItem $item): string
    {
        return 'https://api.whatsapp.com/send?'.http_build_query([
            'text' => $this->buildSummaryMessage($item),
        ]);
    }

    /**
     * @return array{
     *     success: bool,
     *     status: string,
     *     path: string,
     *     intake_whatsapp_session_id: int|null,
     *     error_message: string|null
     * }
     */
    public function sendRegistrationSummary(BulkIntakeBatchItem $item, User $actor): array
    {
        $gate = $this->canSendRegistrationSummary($item);
        if (! $gate['allowed']) {
            throw ValidationException::withMessages([
                'registration' => $this->cannotSendMessage($gate['reasons']),
            ]);
        }

        $mobile = $this->recipientMobile($item);
        if ($mobile === null) {
            throw ValidationException::withMessages([
                'registration' => 'Candidate mobile is missing.',
            ]);
        }

        $summary = $this->summaryForItem($item);
        $body = $this->buildSummaryMessage($item);
        $sendResult = $this->whatsappSender->sendPermissionMessage($mobile, $body, [], [
            'bulk_intake_batch_item_id' => (int) $item->id,
            'bulk_intake_batch_id' => (int) $item->bulk_intake_batch_id,
            'biodata_intake_id' => $item->biodata_intake_id ? (int) $item->biodata_intake_id : null,
            'sent_by_user_id' => (int) $actor->id,
            'message_kind' => 'registration_summary',
        ]);

        if (! $sendResult->success) {
            return [
                'success' => false,
                'status' => $this->registrationStatus($item) ?? 'failed',
                'path' => (string) $summary['path'],
                'intake_whatsapp_session_id' => null,
                'error_message' => $sendResult->errorMessage,
            ];
        }

        $raw = is_array($sendResult->rawResponse) ? $sendResult->rawResponse : [];
        $sessionId = isset($raw['intake_whatsapp_session_id']) ? (int) $raw['intake_whatsapp_session_id'] : null;
        $this->persistSummarySent($item, $actor, $summary, $sessionId);

        return [
            'success' => true,
            'status' => self::STATUS_SUMMARY_SENT,
            'path' => (string) $summary['path'],
            'intake_whatsapp_session_id' => $sessionId,
            'error_message' => null,
        ];
    }

    public function canSimulateRegistrationComplete(BulkIntakeBatchItem $item): bool
    {
        if (! $this->isManualWhatsAppTestEnabled()) {
            return false;
        }

        return $this->registrationStatus($item) === self::STATUS_SUMMARY_SENT
            && $this->summaryForItem($item)['path'] === self::PATH_FAST;
    }

    public function simulateRegistrationComplete(BulkIntakeBatchItem $item, User $actor): void
    {
        if (! $this->canSimulateRegistrationComplete($item)) {
            throw ValidationException::withMessages([
                'registration' => 'Fast-path registration completion can only be simulated after summary is sent.',
            ]);
        }

        DB::transaction(function () use ($item, $actor): void {
            /** @var BulkIntakeBatchItem $locked */
            $locked = BulkIntakeBatchItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $meta = is_array($locked->item_meta_json) ? $locked->item_meta_json : [];
            $existing = is_array($meta['registration'] ?? null) ? $meta['registration'] : [];

            $meta['registration'] = array_merge($existing, [
                'status' => self::STATUS_REGISTRATION_COMPLETE,
                'completed_at' => now()->toISOString(),
                'completed_via' => 'whatsapp_simulate',
                'completed_by_user_id' => (int) $actor->id,
            ]);

            $locked->forceFill(['item_meta_json' => $meta])->save();
        });
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_SUMMARY_SENT => 'Registration summary sent',
            self::STATUS_REGISTRATION_COMPLETE => 'Registration complete',
            default => str_replace('_', ' ', $status),
        };
    }

    public function pathLabel(string $path): string
    {
        return match ($path) {
            self::PATH_FAST => 'Fast',
            self::PATH_TARGETED => 'Targeted',
            self::PATH_FULL => 'Full edit',
            default => str_replace('_', ' ', $path),
        };
    }

    /**
     * @param  list<string>  $reasons
     */
    public function cannotSendMessage(array $reasons): string
    {
        $labels = array_map(fn (string $reason): string => match ($reason) {
            'consent_not_received' => 'WhatsApp consent has not been received yet.',
            'summary_already_sent' => 'Registration summary was already sent.',
            'registration_already_complete' => 'Registration is already complete.',
            'missing_mobile' => 'Candidate mobile is missing.',
            default => str_replace('_', ' ', $reason),
        }, $reasons);

        return implode(' ', $labels);
    }

    /**
     * @param  array{path: string, fields: list<array<string, mixed>>, warning_count: int, reviewed_snapshot_present: bool, display_source: string}  $summary
     */
    private function persistSummarySent(BulkIntakeBatchItem $item, User $actor, array $summary, ?int $sessionId): void
    {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $meta['registration'] = [
            'status' => self::STATUS_SUMMARY_SENT,
            'path' => (string) $summary['path'],
            'warning_count' => (int) $summary['warning_count'],
            'display_source' => (string) $summary['display_source'],
            'summary_sent_at' => now()->toISOString(),
            'summary_sent_by_user_id' => (int) $actor->id,
            'intake_whatsapp_session_id' => $sessionId,
            'completed_at' => null,
            'completed_via' => null,
        ];

        $item->forceFill(['item_meta_json' => $meta])->save();
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function dobDisplay(array $candidate): ?string
    {
        $dob = $candidate['date_of_birth'] ?? null;
        $age = $candidate['age'] ?? null;
        if (is_string($dob) && trim($dob) !== '') {
            return trim($dob);
        }
        if (is_int($age)) {
            return 'वय '.$age;
        }

        return null;
    }

    private function summaryField(
        string $key,
        string $label,
        mixed $value,
        bool $needsReview,
        bool $reviewedSnapshotPresent
    ): array {
        $value = is_string($value) ? trim($value) : null;
        if ($value === '') {
            $value = null;
        }

        $status = match (true) {
            $value === null => self::FIELD_MISSING,
            $needsReview && ! $reviewedSnapshotPresent => self::FIELD_NEEDS_CHECK,
            default => self::FIELD_OK,
        };

        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'status' => $status,
            'icon' => $status === self::FIELD_OK ? '✓' : '⚠',
        ];
    }

    private function pathInstructionLine(string $path): string
    {
        return match ($path) {
            self::PATH_FAST => '🟢 सर्व माहिती बरोबर दिसत असेल तर: [ नोंदणी पूर्ण करा ]',
            self::PATH_TARGETED => '🟡 काही माहिती तपासा — चुकीच्या ओळीवर [ बदल ] आणि नंतर [ उरलेली बरोबर — पुढे जा ]',
            default => '🔴 अधिक माहिती तपासणी हवी — [ सर्व एकाच वेळी बदला ] (वेब form)',
        };
    }

    private function recipientMobile(BulkIntakeBatchItem $item): ?string
    {
        $candidate = $this->candidateDisplayService->candidateForItem($item);

        return MobileNumber::normalize((string) ($candidate['mobile'] ?? ''));
    }
}
