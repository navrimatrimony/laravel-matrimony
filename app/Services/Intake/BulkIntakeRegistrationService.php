<?php

namespace App\Services\Intake;

use App\Contracts\Intake\BulkIntakeWhatsAppConsentSender;
use App\Models\BulkIntakeBatchItem;
use App\Models\Caste;
use App\Models\MasterGender;
use App\Models\MasterMaritalStatus;
use App\Models\MasterMotherTongue;
use App\Models\Religion;
use App\Models\User;
use App\Support\HeightDisplay;
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

    private function publicRegistrationService(): BulkIntakePublicRegistrationService
    {
        return app(BulkIntakePublicRegistrationService::class);
    }

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

    public function publicRegistrationUrl(BulkIntakeBatchItem $item): string
    {
        return $this->publicRegistrationService()->publicUrl($item);
    }

    /**
     * @return array{
     *     fields: list<array{key: string, label: string, value: string|null, status: string, icon: string}>,
     *     registration_fields: list<array{key: string, label: string, value: string|null, status: string, icon: string}>,
     *     path: string,
     *     path_label: string,
     *     warning_count: int,
     *     reviewed_snapshot_present: bool,
     *     display_source: string,
     *     public_url: string
     * }
     */
    public function summaryForItem(BulkIntakeBatchItem $item): array
    {
        $candidate = $this->candidateDisplayService->candidateForItem($item);
        $reviewed = (bool) ($candidate['reviewed_snapshot_present'] ?? false);
        $registrationFields = $this->registrationFieldsForItem($item, $candidate, $reviewed);
        $previewKeys = BulkIntakeRegistrationFieldCatalog::SUMMARY_PREVIEW_KEYS;
        $fields = array_values(array_filter(
            $registrationFields,
            fn (array $field): bool => in_array((string) ($field['key'] ?? ''), $previewKeys, true)
        ));

        $warningCount = collect($registrationFields)
            ->filter(fn (array $field): bool => in_array((string) ($field['status'] ?? ''), [self::FIELD_NEEDS_CHECK, self::FIELD_MISSING], true))
            ->count();
        $path = $this->pathForWarningCount($warningCount);

        return [
            'fields' => $fields,
            'whatsapp_fields' => $this->whatsappSummaryFields($item, $registrationFields),
            'registration_fields' => $registrationFields,
            'path' => $path,
            'path_label' => $this->pathLabel($path),
            'warning_count' => $warningCount,
            'reviewed_snapshot_present' => $reviewed,
            'display_source' => (string) ($candidate['display_source'] ?? 'parsed_json'),
            'public_url' => $this->publicRegistrationUrl($item),
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
        $candidate = $this->candidateDisplayService->candidateForItem($item);
        $name = trim((string) ($candidate['full_name'] ?? ''));
        $greeting = $name !== '' ? 'नमस्कार '.$name.' जी 🙏' : 'नमस्कार 🙏';

        $lines = [
            $greeting,
            'नवरी मिळे नवऱ्याला — पायरी १/४: बायोडाटा सारांश',
            '',
        ];

        foreach ($summary['whatsapp_fields'] as $field) {
            $value = trim((string) ($field['value'] ?? ''));
            $lines[] = ($field['icon'] ?? '⚠').' '.($field['label'] ?? '').': '.($value !== '' ? $value : '—');
        }

        $lines[] = '';
        $lines[] = 'हे बरोबर आहे का?';
        $lines[] = 'खालील बटणे निवडा 👇';
        $lines[] = '';
        $lines[] = $this->buildSummaryEscapeFooter($item);

        return implode("\n", $lines);
    }

    public function buildSummaryEscapeFooter(BulkIntakeBatchItem $item): string
    {
        $summary = $this->summaryForItem($item);
        $publicUrl = trim((string) ($summary['public_url'] ?? ''));

        $lines = [
            'इतर पर्याय:',
        ];

        if ($publicUrl !== '') {
            $lines[] = '• वेबवर सर्व edit: '.$publicUrl;
        }

        $lines[] = '• रिकामा form WhatsApp वर — “रिकामा form” असे उत्तर द्या';

        return implode("\n", $lines);
    }

    /**
     * @return list<array{id: string, title: string, meta_title: string}>
     */
    public function summaryInteractiveButtons(): array
    {
        return [
            [
                'id' => BulkIntakeWhatsAppRegistrationConversationService::BTN_SUMMARY_OK,
                'title' => '✅ १. हो, बरोबर',
                'meta_title' => 'हो, बरोबर',
            ],
            [
                'id' => BulkIntakeWhatsAppRegistrationConversationService::BTN_SUMMARY_EDIT,
                'title' => '✏️ २. चुकीचे',
                'meta_title' => 'काही चुकीचे',
            ],
            [
                'id' => BulkIntakeWhatsAppRegistrationConversationService::BTN_SUMMARY_LATER,
                'title' => '⏰ ३. नंतर',
                'meta_title' => 'नंतर करेन',
            ],
        ];
    }

    /**
     * @param  list<array{key: string, label: string, value: string|null, status: string, icon: string}>  $registrationFields
     * @return list<array{key: string, label: string, value: string|null, status: string, icon: string}>
     */
    public function whatsappSummaryFields(BulkIntakeBatchItem $item, array $registrationFields): array
    {
        $keys = BulkIntakeRegistrationFieldCatalog::WHATSAPP_SUMMARY_KEYS;
        $fields = array_values(array_filter(
            $registrationFields,
            fn (array $field): bool => in_array((string) ($field['key'] ?? ''), $keys, true)
        ));

        $activeMobile = app(BulkIntakeCandidateContactPlanService::class)->activeMobile($item);
        if ($activeMobile !== null) {
            foreach ($fields as $index => $field) {
                if (($field['key'] ?? '') === 'mobile') {
                    $fields[$index]['value'] = $activeMobile;
                }
            }
        }

        return $fields;
    }

    /**
     * @return list<array{key: string, label: string, value: string|null, status: string, icon: string}>
     */
    public function warningFieldsForItem(BulkIntakeBatchItem $item): array
    {
        $summary = $this->summaryForItem($item);

        return array_values(array_filter(
            $summary['registration_fields'],
            fn (array $field): bool => in_array((string) ($field['status'] ?? ''), [self::FIELD_NEEDS_CHECK, self::FIELD_MISSING], true)
        ));
    }

    public function markRegistrationCompleteViaWhatsApp(BulkIntakeBatchItem $item): void
    {
        DB::transaction(function () use ($item): void {
            /** @var BulkIntakeBatchItem $locked */
            $locked = BulkIntakeBatchItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $meta = is_array($locked->item_meta_json) ? $locked->item_meta_json : [];
            $existing = is_array($meta['registration'] ?? null) ? $meta['registration'] : [];

            $meta['registration'] = array_merge($existing, [
                'status' => self::STATUS_REGISTRATION_COMPLETE,
                'completed_at' => now()->toISOString(),
                'completed_via' => 'whatsapp_flow',
            ]);

            $locked->forceFill(['item_meta_json' => $meta])->save();
        });
    }

    public function buildManualTestWhatsAppShareUrl(BulkIntakeBatchItem $item): string
    {
        $preview = $this->buildManualTestPreview($item);

        return 'https://api.whatsapp.com/send?'.http_build_query([
            'text' => $preview['share_text'],
        ]);
    }

    /**
     * Manual WhatsApp share preview — body plus text button lines for phone UI preview.
     *
     * @return array{
     *     body: string,
     *     buttons: list<array{id: string, title: string, meta_title: string}>,
     *     button_line: string,
     *     share_text: string
     * }
     */
    public function buildManualTestPreview(BulkIntakeBatchItem $item): array
    {
        $body = $this->buildSummaryMessage($item);
        $buttons = $this->summaryInteractiveButtons();
        $optionLines = array_map(
            static fn (array $button): string => '['.trim((string) ($button['title'] ?? '')).']',
            $buttons
        );
        $buttonBlock = implode("\n", $optionLines);
        $shareText = $body."\n\n".$buttonBlock;

        return [
            'body' => $body,
            'buttons' => $buttons,
            'button_line' => $buttonBlock,
            'share_text' => $shareText,
        ];
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
        $sendResult = $this->whatsappSender->sendPermissionMessage($mobile, $body, $this->summaryInteractiveButtons(), [
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
        $existing = is_array($meta['registration'] ?? null) ? $meta['registration'] : [];
        $publicToken = trim((string) ($existing['public_token'] ?? ''));
        if ($publicToken === '') {
            $publicToken = $this->publicRegistrationService()->ensureToken($item);
        }
        $meta['registration'] = array_merge($existing, [
            'status' => self::STATUS_SUMMARY_SENT,
            'path' => (string) $summary['path'],
            'warning_count' => (int) $summary['warning_count'],
            'display_source' => (string) $summary['display_source'],
            'summary_sent_at' => now()->toISOString(),
            'summary_sent_by_user_id' => (int) $actor->id,
            'intake_whatsapp_session_id' => $sessionId,
            'whatsapp_flow' => [
                'step' => BulkIntakeWhatsAppRegistrationConversationService::STEP_AWAITING_SUMMARY_CONFIRM,
                'started_at' => now()->toISOString(),
            ],
            'completed_at' => null,
            'completed_via' => null,
            'public_token' => $publicToken,
        ]);

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

    /**
     * @param  array<string, mixed>  $candidate
     * @return list<array{key: string, label: string, value: string|null, status: string, icon: string}>
     */
    private function registrationFieldsForItem(BulkIntakeBatchItem $item, array $candidate, bool $reviewed): array
    {
        $core = $this->snapshotCore($item);
        $notWorking = in_array((string) ($core['working_with'] ?? ''), ['not_working', 'unemployed', 'home_maker', 'retired'], true);

        $rows = [
            $this->summaryField('full_name', BulkIntakeRegistrationFieldCatalog::label('full_name'), $candidate['full_name'] ?? null, (bool) ($candidate['name_needs_review'] ?? false), $reviewed),
            $this->summaryField('mobile', BulkIntakeRegistrationFieldCatalog::label('mobile'), $candidate['mobile'] ?? null, false, $reviewed),
            $this->summaryField('date_of_birth', BulkIntakeRegistrationFieldCatalog::label('date_of_birth'), $this->dobDisplay($candidate), (bool) ($candidate['dob_needs_review'] ?? false), $reviewed),
            $this->summaryField('height_cm', BulkIntakeRegistrationFieldCatalog::label('height_cm'), $this->heightDisplayValue($core, $candidate), (bool) ($candidate['height_needs_review'] ?? false), $reviewed),
            $this->summaryField('gender', BulkIntakeRegistrationFieldCatalog::label('gender'), $candidate['gender'] ?? null, false, $reviewed),
            $this->summaryField('mother_tongue', BulkIntakeRegistrationFieldCatalog::label('mother_tongue'), $this->motherTongueLabel($core), false, $reviewed),
            $this->summaryField('marital_status', BulkIntakeRegistrationFieldCatalog::label('marital_status'), $this->maritalStatusLabel($core), false, $reviewed),
            $this->summaryField('religion', BulkIntakeRegistrationFieldCatalog::label('religion'), $this->religionLabel($core), false, $reviewed),
            $this->summaryField('caste', BulkIntakeRegistrationFieldCatalog::label('caste'), $this->casteLabel($core), false, $reviewed),
            $this->summaryField('location', BulkIntakeRegistrationFieldCatalog::label('location'), $candidate['city'] ?? null, false, $reviewed),
            $this->summaryField('education', BulkIntakeRegistrationFieldCatalog::label('education'), $candidate['education'] ?? null, (bool) ($candidate['education_needs_review'] ?? false), $reviewed),
            $this->summaryField('working_with', BulkIntakeRegistrationFieldCatalog::label('working_with'), $this->workingWithLabel($core), false, $reviewed),
            $this->summaryField('occupation', BulkIntakeRegistrationFieldCatalog::label('occupation'), $notWorking ? '—' : ($candidate['occupation'] ?? null), (bool) ($candidate['occupation_needs_review'] ?? false), $reviewed, $notWorking),
        ];

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $core
     * @param  array<string, mixed>  $candidate
     */
    private function heightDisplayValue(array $core, array $candidate): ?string
    {
        $heightCm = $core['height_cm'] ?? null;
        if (is_numeric($heightCm)) {
            $cm = (int) round((float) $heightCm);
            if ($cm >= 120 && $cm <= 220) {
                return HeightDisplay::formatFeetInches($cm);
            }
        }

        $text = is_string($candidate['height'] ?? null) ? trim($candidate['height']) : null;

        return $text !== '' ? $text : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotCore(BulkIntakeBatchItem $item): array
    {
        $item->loadMissing('biodataIntake');
        $intake = $item->biodataIntake;
        if (! $intake) {
            return [];
        }

        $snapshot = is_array($intake->approval_snapshot_json) && $intake->approval_snapshot_json !== []
            ? $intake->approval_snapshot_json
            : (is_array($intake->parsed_json) ? $intake->parsed_json : []);

        return is_array($snapshot['core'] ?? null) ? $snapshot['core'] : [];
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function motherTongueLabel(array $core): ?string
    {
        $id = $core['mother_tongue_id'] ?? null;
        if (! is_numeric($id)) {
            return null;
        }
        $row = MasterMotherTongue::query()->find((int) $id);

        return $row ? (string) ($row->label_mr ?: $row->label_en ?: $row->label) : null;
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function maritalStatusLabel(array $core): ?string
    {
        $id = $core['marital_status_id'] ?? null;
        if (! is_numeric($id)) {
            return null;
        }
        $row = MasterMaritalStatus::query()->find((int) $id);

        return $row ? (string) ($row->label_mr ?: $row->label_en ?: $row->label ?: $row->key) : null;
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function religionLabel(array $core): ?string
    {
        $id = $core['religion_id'] ?? null;
        if (! is_numeric($id)) {
            return null;
        }
        $row = Religion::query()->find((int) $id);

        return $row ? (string) ($row->label_mr ?: $row->label_en ?: $row->label) : null;
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function casteLabel(array $core): ?string
    {
        $id = $core['caste_id'] ?? null;
        if (! is_numeric($id)) {
            return null;
        }
        $row = Caste::query()->find((int) $id);

        return $row ? (string) ($row->label_mr ?: $row->label_en ?: $row->label) : null;
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function workingWithLabel(array $core): ?string
    {
        $value = trim((string) ($core['working_with'] ?? ''));

        return match ($value) {
            'private_company' => 'खाजगी नोकरी',
            'government' => 'शासकीय नोकरी',
            'business' => 'व्यवसाय',
            'self_employed' => 'स्वयंरोजगार',
            'not_working' => 'काम करत नाही',
            '' => null,
            default => $value,
        };
    }

    private function summaryField(
        string $key,
        string $label,
        mixed $value,
        bool $needsReview,
        bool $reviewedSnapshotPresent,
        bool $optionalWhenPresent = false
    ): array {
        $value = is_string($value) ? trim($value) : null;
        if ($value === '') {
            $value = null;
        }

        $status = match (true) {
            $optionalWhenPresent => self::FIELD_OK,
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
