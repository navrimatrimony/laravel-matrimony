<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatchItem;
use App\Models\MatrimonyProfile;

class BulkIntakeDuplicateVerificationService
{
    public const STAGE_LIVE_PROFILE = 'live_profile';

    public const STAGE_HISTORY_BLOCKED = 'history_blocked';

    public const STAGE_INTAKE_ONLY = 'intake_only';

    public const STAGE_CONSENT_PENDING = 'consent_pending';

    public const STAGE_CONSENT_STOPPED = 'consent_stopped';

    public const STAGE_CONSENT_NO_REGISTRATION = 'consent_no_registration';

    public const STAGE_REGISTRATION_IN_PROGRESS = 'registration_in_progress';

    public const STAGE_REGISTRATION_COMPLETE = 'registration_complete';

    public const ACTION_BLOCK = 'block';

    public const ACTION_REVIEW = 'review';

    public const ACTION_PROCEED_OK = 'proceed_ok';

    public function __construct(
        private readonly BulkIntakeDuplicateHistoryHintService $duplicateHistoryHintService,
        private readonly BulkIntakeIdentityHistoryService $identityHistoryService,
        private readonly BulkIntakeWhatsAppConsentService $whatsappConsentService,
        private readonly BulkIntakeRegistrationService $registrationService,
        private readonly BulkIntakeCandidateDisplayService $candidateDisplayService,
    ) {}

    /**
     * @return array{
     *     has_hints: bool,
     *     hint_count: int,
     *     hints: list<array<string, mixed>>,
     *     primary: array<string, mixed>|null,
     *     history_blocks: list<array<string, mixed>>
     * }
     */
    public function verificationForItem(BulkIntakeBatchItem $item): array
    {
        $rawHints = $this->duplicateHistoryHintService->hintsForItem($item);
        $historyBlocks = $this->identityHistoryService->blockingHistoriesForItem($item);
        $hints = array_values(array_map(
            fn (array $hint): array => $this->enrichHint($item, $hint),
            $rawHints
        ));

        return [
            'has_hints' => $hints !== [],
            'hint_count' => count($hints),
            'hints' => $hints,
            'primary' => $hints[0] ?? null,
            'history_blocks' => $historyBlocks,
        ];
    }

    /**
     * @param  array<string, mixed>  $hint
     * @return array<string, mixed>
     */
    private function enrichHint(BulkIntakeBatchItem $currentItem, array $hint): array
    {
        $type = (string) ($hint['type'] ?? '');
        $matchedIntakeId = (int) ($hint['matched_intake_id'] ?? 0);
        $matchedProfileId = (int) ($hint['matched_profile_id'] ?? 0);

        $matchedIntake = $matchedIntakeId > 0
            ? BiodataIntake::query()->find($matchedIntakeId, [
                'id',
                'matrimony_profile_id',
                'parsed_json',
                'approval_snapshot_json',
                'created_at',
                'parsed_at',
                'reviewed_at',
            ])
            : null;

        if ($matchedProfileId <= 0 && $matchedIntake?->matrimony_profile_id) {
            $matchedProfileId = (int) $matchedIntake->matrimony_profile_id;
        }

        $matchedBatchItem = $matchedIntake instanceof BiodataIntake
            ? $this->latestBatchItemForIntake((int) $matchedIntake->id)
            : null;

        $matchedCandidate = $matchedBatchItem instanceof BulkIntakeBatchItem
            ? $this->candidateDisplayService->candidateForItem($matchedBatchItem)
            : ($matchedIntake instanceof BiodataIntake
                ? $this->candidateDisplayService->candidateForIntake($matchedIntake)
                : []);

        $historyBlocks = $matchedBatchItem instanceof BulkIntakeBatchItem
            ? $this->identityHistoryService->blockingHistoriesForItem($matchedBatchItem, includeSelfSourcedHistories: true)
            : [];

        $journey = $this->resolveJourney(
            $matchedBatchItem,
            $matchedIntake,
            $matchedProfileId > 0 ? $matchedProfileId : null,
            $historyBlocks
        );

        $recommended = $this->recommendedAction($type, $journey['stage'], (string) ($hint['confidence'] ?? ''));

        return array_merge($hint, [
            'reason_label_mr' => $this->reasonLabelMr($type, (string) ($hint['reason'] ?? '')),
            'matched' => [
                'intake_id' => $matchedIntake?->id,
                'batch_item_id' => $matchedBatchItem?->id,
                'batch_id' => $matchedBatchItem?->bulk_intake_batch_id,
                'batch_name' => $matchedBatchItem?->batch?->batch_name,
                'item_sequence' => $matchedBatchItem?->item_sequence,
                'uploaded_at' => $this->formatTimestamp($matchedBatchItem?->created_at ?? $matchedIntake?->created_at),
                'full_name' => $this->displayOrNull($matchedCandidate['full_name'] ?? null),
                'mobile' => $this->displayOrNull($matchedCandidate['mobile'] ?? null),
                'date_of_birth' => $this->displayOrNull($matchedCandidate['date_of_birth'] ?? null),
                'journey_stage' => $journey['stage'],
                'journey_label' => $journey['label'],
                'journey_detail' => $journey['detail'],
                'consent_status' => $journey['consent_status'],
                'consent_status_label' => $journey['consent_status_label'],
                'registration_status' => $journey['registration_status'],
                'registration_status_label' => $journey['registration_status_label'],
                'profile_id' => $matchedProfileId > 0 ? $matchedProfileId : null,
                'history_flags' => array_values(array_map(
                    fn (array $block): array => [
                        'code' => (string) ($block['reason_code'] ?? ''),
                        'label' => (string) ($block['label'] ?? ''),
                        'recorded_at' => (string) ($block['recorded_at'] ?? ''),
                    ],
                    $historyBlocks
                )),
                'links' => $this->buildLinks($matchedBatchItem, $matchedIntake, $matchedProfileId > 0 ? $matchedProfileId : null),
            ],
            'recommended_action' => $recommended['action'],
            'recommended_label_mr' => $recommended['label'],
        ]);
    }

    private function latestBatchItemForIntake(int $intakeId): ?BulkIntakeBatchItem
    {
        return BulkIntakeBatchItem::query()
            ->with('batch:id,batch_name')
            ->where('biodata_intake_id', $intakeId)
            ->orderByDesc('id')
            ->first([
                'id',
                'bulk_intake_batch_id',
                'biodata_intake_id',
                'item_sequence',
                'item_meta_json',
                'created_at',
            ]);
    }

    /**
     * @param  list<array<string, mixed>>  $historyBlocks
     * @return array{
     *     stage: string,
     *     label: string,
     *     detail: string,
     *     consent_status: string|null,
     *     consent_status_label: string|null,
     *     registration_status: string|null,
     *     registration_status_label: string|null
     * }
     */
    private function resolveJourney(
        ?BulkIntakeBatchItem $matchedBatchItem,
        ?BiodataIntake $matchedIntake,
        ?int $profileId,
        array $historyBlocks,
    ): array {
        if ($profileId !== null && $profileId > 0) {
            return [
                'stage' => self::STAGE_LIVE_PROFILE,
                'label' => 'Live profile — website वर user आहे',
                'detail' => 'Profile #'.$profileId.' — हा खरा registered user असू शकतो.',
                'consent_status' => null,
                'consent_status_label' => null,
                'registration_status' => null,
                'registration_status_label' => null,
            ];
        }

        if ($historyBlocks !== []) {
            $labels = implode(', ', array_map(
                fn (array $block): string => (string) ($block['label'] ?? ''),
                array_slice($historyBlocks, 0, 3)
            ));

            return [
                'stage' => self::STAGE_HISTORY_BLOCKED,
                'label' => 'Identity history — आधीच निर्णय झाला',
                'detail' => $labels !== '' ? $labels : 'Blocking history record आहे.',
                'consent_status' => null,
                'consent_status_label' => null,
                'registration_status' => null,
                'registration_status_label' => null,
            ];
        }

        if (! $matchedBatchItem instanceof BulkIntakeBatchItem) {
            return [
                'stage' => self::STAGE_INTAKE_ONLY,
                'label' => 'फक्त intake data',
                'detail' => 'जुना batch item सापडला नाही — फक्त intake record आहे.',
                'consent_status' => null,
                'consent_status_label' => null,
                'registration_status' => null,
                'registration_status_label' => null,
            ];
        }

        $consentStatus = $this->whatsappConsentService->consentStatus($matchedBatchItem);
        $consentLabel = $consentStatus !== null
            ? $this->whatsappConsentService->statusLabel($consentStatus)
            : null;
        $registrationStatus = $this->registrationService->registrationStatus($matchedBatchItem);
        $registrationLabel = $registrationStatus !== null
            ? $this->registrationService->statusLabel($registrationStatus)
            : null;

        if ($consentStatus === null || $consentStatus === '') {
            return [
                'stage' => self::STAGE_INTAKE_ONLY,
                'label' => 'फक्त intake — process सुरू झाला नाही',
                'detail' => 'WhatsApp consent पाठवले नाही / user reply नाही. Registration देखील नाही.',
                'consent_status' => null,
                'consent_status_label' => null,
                'registration_status' => $registrationStatus,
                'registration_status_label' => $registrationLabel,
            ];
        }

        if (in_array($consentStatus, [
            BulkIntakeWhatsAppConsentService::STATUS_CONSENT_DENIED,
            BulkIntakeWhatsAppConsentService::STATUS_ALREADY_MARRIED,
            BulkIntakeWhatsAppConsentService::STATUS_WRONG_NUMBER,
            BulkIntakeWhatsAppConsentService::STATUS_CONTACTS_EXHAUSTED,
            BulkIntakeWhatsAppConsentService::STATUS_NO_RESPONSE,
        ], true)) {
            return [
                'stage' => self::STAGE_CONSENT_STOPPED,
                'label' => 'Consent थांबला / नकार',
                'detail' => $consentLabel ?? $consentStatus,
                'consent_status' => $consentStatus,
                'consent_status_label' => $consentLabel,
                'registration_status' => $registrationStatus,
                'registration_status_label' => $registrationLabel,
            ];
        }

        if ($consentStatus === BulkIntakeWhatsAppConsentService::STATUS_PERMISSION_SENT) {
            return [
                'stage' => self::STAGE_CONSENT_PENDING,
                'label' => 'Consent प्रतीक्षेत',
                'detail' => 'Permission message पाठवले — user ने अजून हो/नको उत्तर दिले नाही.',
                'consent_status' => $consentStatus,
                'consent_status_label' => $consentLabel,
                'registration_status' => $registrationStatus,
                'registration_status_label' => $registrationLabel,
            ];
        }

        if ($consentStatus === BulkIntakeWhatsAppConsentService::STATUS_CONSENT_RECEIVED) {
            if ($registrationStatus === BulkIntakeRegistrationService::STATUS_REGISTRATION_COMPLETE) {
                return [
                    'stage' => self::STAGE_REGISTRATION_COMPLETE,
                    'label' => 'Registration complete — profile तपासा',
                    'detail' => 'नोंदणी पूर्ण झाली पण live profile link नाही — profile अस्तित्वात आहे का तपासा.',
                    'consent_status' => $consentStatus,
                    'consent_status_label' => $consentLabel,
                    'registration_status' => $registrationStatus,
                    'registration_status_label' => $registrationLabel,
                ];
            }

            if ($registrationStatus === BulkIntakeRegistrationService::STATUS_SUMMARY_SENT) {
                return [
                    'stage' => self::STAGE_REGISTRATION_IN_PROGRESS,
                    'label' => 'Registration सुरू — अपूर्ण',
                    'detail' => 'Consent मिळाले, summary पाठवले — user ने पूर्ण केले नाही.',
                    'consent_status' => $consentStatus,
                    'consent_status_label' => $consentLabel,
                    'registration_status' => $registrationStatus,
                    'registration_status_label' => $registrationLabel,
                ];
            }

            return [
                'stage' => self::STAGE_CONSENT_NO_REGISTRATION,
                'label' => 'Consent मिळाले — registration सुरू नाही',
                'detail' => 'User हो म्हणाला पण registration summary अजून पाठवले नाही / सुरू झाले नाही.',
                'consent_status' => $consentStatus,
                'consent_status_label' => $consentLabel,
                'registration_status' => $registrationStatus,
                'registration_status_label' => $registrationLabel,
            ];
        }

        return [
            'stage' => self::STAGE_INTAKE_ONLY,
            'label' => 'Intake data — पुढचा stage unclear',
            'detail' => $consentLabel ?? 'Review करा.',
            'consent_status' => $consentStatus,
            'consent_status_label' => $consentLabel,
            'registration_status' => $registrationStatus,
            'registration_status_label' => $registrationLabel,
        ];
    }

    /**
     * @return array{action: string, label: string}
     */
    private function recommendedAction(string $type, string $stage, string $confidence): array
    {
        if (in_array($stage, [self::STAGE_LIVE_PROFILE, self::STAGE_HISTORY_BLOCKED], true)) {
            return [
                'action' => self::ACTION_BLOCK,
                'label' => 'थांबवा — जुना record live किंवा history block आहे.',
            ];
        }

        if ($stage === self::STAGE_REGISTRATION_COMPLETE) {
            return [
                'action' => self::ACTION_BLOCK,
                'label' => 'थांबवा — जुना registration complete आहे.',
            ];
        }

        if ($stage === self::STAGE_INTAKE_ONLY && in_array($type, ['content_hash', 'same_file_hash', 'same_raw_text_hash'], true)) {
            return [
                'action' => self::ACTION_PROCEED_OK,
                'label' => 'जुना upload फक्त data मध्ये होता — नवीन process चालू करता येईल (verify करून).',
            ];
        }

        if ($type === 'same_profile_mobile') {
            return [
                'action' => self::ACTION_BLOCK,
                'label' => 'थांबवा — मोबाईल आधीच live profile वर आहे.',
            ];
        }

        if ($confidence === 'high' && in_array($type, ['same_mobile', 'same_name_dob'], true)) {
            return [
                'action' => self::ACTION_REVIEW,
                'label' => 'तपासा — same identity; mark duplicate किंवा override.',
            ];
        }

        return [
            'action' => self::ACTION_REVIEW,
            'label' => 'तपासा — जुना record उघडून compare करा.',
        ];
    }

    private function reasonLabelMr(string $type, string $reason): string
    {
        return match ($type) {
            'content_hash', 'exact_content_hash' => 'तोच biodata content आधी upload झाला',
            'same_file_hash' => 'तोच फाइल hash आधी आला',
            'same_raw_text_hash' => 'OCR text hash जुळते',
            'ocr_hash' => 'OCR/photo hash जवळजवळ सारखे',
            'same_mobile' => 'हाच मोबाईल नंबर आधी आला',
            'same_profile_mobile' => 'हा मोबाईल आधीच live profile वर आहे',
            'same_name_dob' => 'नाव + जन्मतारीख जुळते',
            default => $reason !== '' ? str_replace('_', ' ', $reason) : 'Possible duplicate',
        };
    }

    /**
     * @return array<string, string|null>
     */
    private function buildLinks(
        ?BulkIntakeBatchItem $matchedBatchItem,
        ?BiodataIntake $matchedIntake,
        ?int $profileId,
    ): array {
        $intakeUrl = $matchedIntake instanceof BiodataIntake
            ? route('admin.biodata-intakes.show', $matchedIntake)
            : null;

        $batchUrl = ($matchedBatchItem instanceof BulkIntakeBatchItem && $matchedBatchItem->bulk_intake_batch_id)
            ? route('admin.bulk-intakes.show', ['bulkIntakeBatch' => (int) $matchedBatchItem->bulk_intake_batch_id])
            : null;

        $correctCandidateUrl = ($matchedBatchItem instanceof BulkIntakeBatchItem && $matchedBatchItem->bulk_intake_batch_id)
            ? route('admin.bulk-intakes.items.correct-candidate', [
                'bulkIntakeBatch' => (int) $matchedBatchItem->bulk_intake_batch_id,
                'bulkIntakeBatchItem' => (int) $matchedBatchItem->id,
            ])
            : null;

        $profileUrl = null;
        if ($profileId !== null && $profileId > 0 && MatrimonyProfile::query()->whereKey($profileId)->exists()) {
            $profileUrl = route('admin.profiles.show', ['id' => $profileId]);
        }

        return [
            'intake' => $intakeUrl,
            'batch' => $batchUrl,
            'correct_candidate' => $correctCandidateUrl,
            'profile' => $profileUrl,
        ];
    }

    private function displayOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function formatTimestamp(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('d-m-Y H:i');
        }

        return null;
    }
}
