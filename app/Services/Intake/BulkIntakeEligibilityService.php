<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatchItem;
use App\Support\MobileNumber;
use Illuminate\Support\Collection;

class BulkIntakeEligibilityService
{
    public const FILTER_ALL = 'all';

    public const FILTER_ELIGIBLE = 'eligible';

    public const FILTER_BLOCKED = 'blocked';

    public const FILTER_NEEDS_CHECK = 'needs_check';

    public const FILTER_READY = 'ready';

    /** @deprecated Use FILTER_BLOCKED — kept for legacy query params */
    public const FILTER_STOPPED = 'stopped';

    /** @deprecated Use FILTER_NEEDS_CHECK — kept for legacy query params */
    public const FILTER_NEEDS_REVIEW = 'needs_review';

    public const FILTER_ADVISOR = 'advisor';

    public const FILTER_MANUAL = 'manual';

    public function __construct(
        private readonly BulkIntakeCandidateDisplayService $candidateDisplayService,
        private readonly BulkIntakeCandidateContactPlanService $contactPlanService,
        private readonly BulkIntakeDuplicateHistoryHintService $duplicateHistoryHintService,
        private readonly BulkIntakeDuplicateGateService $duplicateGateService,
        private readonly IntakeDuplicateFieldMatchEvaluator $fieldMatchEvaluator,
        private readonly BulkIntakeCandidateScreeningAdvisorService $screeningAdvisorService,
        private readonly BulkIntakeCandidateScreeningReviewService $screeningReviewService,
    ) {}

    /**
     * Primary batch-page filters (Phase C — 3 buckets + All).
     *
     * @return array<string, string>
     */
    public function primaryScreeningFilters(): array
    {
        return [
            self::FILTER_ALL => 'All',
            self::FILTER_ELIGIBLE => 'Eligible',
            self::FILTER_BLOCKED => 'Blocked',
            self::FILTER_NEEDS_CHECK => 'Needs check',
        ];
    }

    /**
     * Legacy/advanced filters — still supported via query params.
     *
     * @return array<string, string>
     */
    public function legacyScreeningFilters(): array
    {
        return [
            self::FILTER_READY => 'Ready for consent',
            self::FILTER_ADVISOR => 'Advisor only',
            self::FILTER_MANUAL => 'Override set',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function allScreeningFilterLabels(): array
    {
        return array_merge($this->primaryScreeningFilters(), $this->legacyScreeningFilters());
    }

    public function resolveScreeningFilter(string $value): string
    {
        $value = match ($value) {
            self::FILTER_STOPPED => self::FILTER_BLOCKED,
            self::FILTER_NEEDS_REVIEW => self::FILTER_NEEDS_CHECK,
            default => $value,
        };

        return array_key_exists($value, $this->allScreeningFilterLabels())
            ? $value
            : self::FILTER_ALL;
    }

    /**
     * @param  array<string, mixed>|null  $candidate
     * @param  list<array<string, mixed>>|null  $duplicateHints
     * @return array{
     *     decision: string,
     *     label: string,
     *     reasons: list<array{code: string, label: string}>,
     *     reason_codes: list<string>,
     *     suggested_next_action: string
     * }
     */
    public function autoSuggestionForItem(
        BulkIntakeBatchItem $item,
        ?array $candidate = null,
        ?array $duplicateHints = null
    ): array {
        return $this->screeningAdvisorService->advisorForItem($item, $candidate, $duplicateHints);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeOverrideForItem(BulkIntakeBatchItem $item): ?array
    {
        return $this->screeningReviewService->activeReviewForItem($item);
    }

    /**
     * Phase C pipeline gate — single decision surface for batch filters and badges.
     *
     * @param  array<string, mixed>|null  $candidate
     * @param  list<array<string, mixed>>|null  $duplicateHints
     * @param  array<string, mixed>|null  $duplicateGate
     * @param  array<string, mixed>|null  $manualReview
     * @param  array<string, mixed>|null  $autoSuggestion
     * @return array{
     *     eligible: bool,
     *     bucket: string,
     *     bucket_label: string,
     *     source: string,
     *     has_override: bool,
     *     reasons: list<array{code: string, label: string}>,
     *     reason_codes: list<string>
     * }
     */
    public function eligibleForPipeline(
        BulkIntakeBatchItem $item,
        ?array $candidate = null,
        ?array $duplicateHints = null,
        ?array $duplicateGate = null,
        ?array $manualReview = null,
        ?array $autoSuggestion = null,
    ): array {
        $candidate ??= $this->candidateDisplayService->candidateForItem($item);
        $duplicateHints ??= $this->duplicateHistoryHintService->hintsForItem($item);
        $duplicateGate ??= $this->duplicateGateService->evaluateForItem($item, $duplicateHints);
        $manualReview ??= $this->screeningReviewService->activeReviewForItem($item);
        $autoSuggestion ??= $this->screeningAdvisorService->advisorForItem($item, $candidate, $duplicateHints);

        if ($this->hasActiveOverride($manualReview)) {
            $bucket = $this->overrideStatusToBucket((string) ($manualReview['status'] ?? ''));
            $reasonCodes = [(string) ($manualReview['reason_key'] ?? 'admin_override')];
            $eligible = $bucket === self::FILTER_ELIGIBLE
                && $this->contactPlanService->hasUsableMobile($item)
                && $this->hasBasicIdentity($candidate);

            if ($bucket === self::FILTER_ELIGIBLE && ! $eligible) {
                $bucket = self::FILTER_NEEDS_CHECK;
                $reasonCodes[] = 'override_missing_requirements';
            }

            return $this->pipelineResult(
                $eligible,
                $bucket,
                'override',
                true,
                $this->reasonsFromCodes($reasonCodes),
                $reasonCodes,
            );
        }

        if ((bool) ($duplicateGate['auto_blocked'] ?? false)) {
            $reasonCodes = array_values(array_filter(array_map(
                static fn (array $block): string => (string) ($block['code'] ?? ''),
                is_array($duplicateGate['blocks'] ?? null) ? $duplicateGate['blocks'] : []
            )));

            return $this->pipelineResult(
                false,
                self::FILTER_BLOCKED,
                'auto',
                false,
                $this->reasonsFromCodes($reasonCodes !== [] ? $reasonCodes : ['blocked_by_gate']),
                $reasonCodes !== [] ? $reasonCodes : ['blocked_by_gate'],
            );
        }

        if ($this->hasManualDuplicateMark($item)) {
            return $this->pipelineResult(
                false,
                self::FILTER_BLOCKED,
                'auto',
                false,
                $this->reasonsFromCodes(['manual_duplicate']),
                ['manual_duplicate'],
            );
        }

        if ((string) ($autoSuggestion['decision'] ?? '') === 'stop') {
            $reasonCodes = is_array($autoSuggestion['reason_codes'] ?? null)
                ? array_values(array_map('strval', $autoSuggestion['reason_codes']))
                : ['blocked_signal'];

            return $this->pipelineResult(
                false,
                self::FILTER_BLOCKED,
                'auto',
                false,
                is_array($autoSuggestion['reasons'] ?? null) ? $autoSuggestion['reasons'] : $this->reasonsFromCodes($reasonCodes),
                $reasonCodes,
            );
        }

        $needsCheckCodes = [];
        if (! $this->contactPlanService->hasUsableMobile($item)) {
            $needsCheckCodes[] = 'missing_mobile';
        }
        if (! $this->hasBasicIdentity($candidate)) {
            $needsCheckCodes[] = 'missing_identity';
        }
        if ($this->hasUnconfirmedNameDobDuplicate($item, $duplicateHints)) {
            $needsCheckCodes[] = 'name_dob_needs_confirmation';
        }

        $advisorReviewCodes = (string) ($autoSuggestion['decision'] ?? '') === 'review'
            ? (is_array($autoSuggestion['reason_codes'] ?? null) ? array_values(array_map('strval', $autoSuggestion['reason_codes'])) : [])
            : [];

        $needsCheckCodes = array_values(array_unique(array_merge($needsCheckCodes, $advisorReviewCodes)));

        if ($needsCheckCodes !== []) {
            return $this->pipelineResult(
                false,
                self::FILTER_NEEDS_CHECK,
                'auto',
                false,
                $this->reasonsFromCodes($needsCheckCodes),
                $needsCheckCodes,
            );
        }

        return $this->pipelineResult(
            true,
            self::FILTER_ELIGIBLE,
            'auto',
            false,
            $this->reasonsFromCodes(['pipeline_ready']),
            ['pipeline_ready'],
        );
    }

    public function bucketLabel(string $bucket): string
    {
        return match ($bucket) {
            self::FILTER_ELIGIBLE => 'Eligible',
            self::FILTER_BLOCKED => 'Blocked',
            self::FILTER_NEEDS_CHECK => 'Needs check',
            default => 'Unknown',
        };
    }

    /**
     * @param  array<string, mixed>|null  $manualReview
     * @param  array<string, mixed>  $autoSuggestion
     * @return array{
     *     bucket: string,
     *     source: string,
     *     has_override: bool
     * }
     */
    public function effectiveEligibilityForItem(?array $manualReview, array $autoSuggestion): array
    {
        if ($this->hasActiveOverride($manualReview)) {
            return [
                'bucket' => $this->overrideStatusToBucket((string) ($manualReview['status'] ?? '')),
                'source' => 'override',
                'has_override' => true,
            ];
        }

        return [
            'bucket' => $this->autoDecisionToBucket($autoSuggestion),
            'source' => 'auto',
            'has_override' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $pipeline
     */
    public function itemMatchesPipelineFilter(string $filter, array $pipeline, ?bool $readyForConsent = null): bool
    {
        $filter = $this->resolveScreeningFilter($filter);
        if ($filter === self::FILTER_ALL) {
            return true;
        }

        if ($filter === self::FILTER_READY) {
            return $readyForConsent === true;
        }

        if ($filter === self::FILTER_ADVISOR) {
            return ! ($pipeline['has_override'] ?? false);
        }

        if ($filter === self::FILTER_MANUAL) {
            return (bool) ($pipeline['has_override'] ?? false);
        }

        return ($pipeline['bucket'] ?? null) === $filter;
    }

    /**
     * @param  array<string, mixed>|null  $manualReview
     * @param  array<string, mixed>  $autoSuggestion
     */
    public function itemMatchesFilter(
        string $filter,
        ?array $manualReview,
        array $autoSuggestion,
        ?bool $readyForConsent = null
    ): bool {
        $filter = $this->resolveScreeningFilter($filter);
        if ($filter === self::FILTER_ALL) {
            return true;
        }

        if ($filter === self::FILTER_READY) {
            return $readyForConsent === true;
        }

        $effective = $this->effectiveEligibilityForItem($manualReview, $autoSuggestion);

        return match ($filter) {
            self::FILTER_ELIGIBLE => $effective['bucket'] === self::FILTER_ELIGIBLE,
            self::FILTER_BLOCKED, self::FILTER_STOPPED => $effective['bucket'] === self::FILTER_BLOCKED,
            self::FILTER_NEEDS_CHECK, self::FILTER_NEEDS_REVIEW => $effective['bucket'] === self::FILTER_NEEDS_CHECK,
            self::FILTER_ADVISOR => ! $effective['has_override'],
            self::FILTER_MANUAL => $effective['has_override'],
            default => true,
        };
    }

    /**
     * @param  Collection<int, BulkIntakeBatchItem>  $items
     * @param  array<int, array<string, mixed>>  $pipelineByItemId
     * @param  callable(BulkIntakeBatchItem): bool|null  $readyForConsentForItem
     * @return array<string, int>
     */
    public function countsFromPipeline(
        Collection $items,
        array $pipelineByItemId,
        ?callable $readyForConsentForItem = null
    ): array {
        $counts = [
            self::FILTER_ALL => $items->count(),
            self::FILTER_ELIGIBLE => 0,
            self::FILTER_BLOCKED => 0,
            self::FILTER_NEEDS_CHECK => 0,
            self::FILTER_READY => 0,
            self::FILTER_ADVISOR => 0,
            self::FILTER_MANUAL => 0,
            self::FILTER_STOPPED => 0,
            self::FILTER_NEEDS_REVIEW => 0,
        ];

        foreach ($items as $item) {
            $pipeline = is_array($pipelineByItemId[(int) $item->id] ?? null)
                ? $pipelineByItemId[(int) $item->id]
                : $this->eligibleForPipeline($item);

            $bucket = (string) ($pipeline['bucket'] ?? self::FILTER_NEEDS_CHECK);
            if ($bucket === self::FILTER_ELIGIBLE) {
                $counts[self::FILTER_ELIGIBLE]++;
            } elseif ($bucket === self::FILTER_BLOCKED) {
                $counts[self::FILTER_BLOCKED]++;
                $counts[self::FILTER_STOPPED]++;
            } elseif ($bucket === self::FILTER_NEEDS_CHECK) {
                $counts[self::FILTER_NEEDS_CHECK]++;
                $counts[self::FILTER_NEEDS_REVIEW]++;
            }

            if (($pipeline['has_override'] ?? false) === true) {
                $counts[self::FILTER_MANUAL]++;
            } else {
                $counts[self::FILTER_ADVISOR]++;
            }

            if ($readyForConsentForItem !== null && $readyForConsentForItem($item) === true) {
                $counts[self::FILTER_READY]++;
            }
        }

        return $counts;
    }

    /**
     * @param  Collection<int, BulkIntakeBatchItem>  $items
     * @param  callable(BulkIntakeBatchItem): array<string, mixed>  $autoSuggestionForItem
     * @param  callable(BulkIntakeBatchItem): array<string, mixed>|null  $overrideForItem
     * @param  callable(BulkIntakeBatchItem): bool|null  $readyForConsentForItem
     * @return array<string, int>
     */
    public function countsForItems(
        Collection $items,
        callable $autoSuggestionForItem,
        callable $overrideForItem,
        ?callable $readyForConsentForItem = null
    ): array {
        $pipelineByItemId = [];
        foreach ($items as $item) {
            $pipelineByItemId[(int) $item->id] = $this->eligibleForPipeline(
                $item,
                null,
                null,
                null,
                $overrideForItem($item),
                $autoSuggestionForItem($item),
            );
        }

        return $this->countsFromPipeline($items, $pipelineByItemId, $readyForConsentForItem);
    }

    /**
     * @param  array<string, mixed>|null  $manualReview
     * @param  array<string, mixed>|null  $candidate
     * @return array{ready: bool, reasons: list<string>}
     */
    public function readyForConsentForItem(
        BulkIntakeBatchItem $item,
        ?array $manualReview,
        ?array $candidate = null
    ): array {
        $candidate ??= $this->candidateDisplayService->candidateForItem($item);
        $reasons = [];

        if (! $this->hasActiveOverride($manualReview)) {
            $reasons[] = 'manual_screening_required';
        } elseif ((string) ($manualReview['status'] ?? '') !== BulkIntakeCandidateScreeningReviewService::STATUS_ELIGIBLE) {
            $reasons[] = 'manual_screening_not_eligible';
        }

        if ($this->hasManualDuplicateMark($item)) {
            $reasons[] = 'manual_duplicate';
        }

        if (! $this->contactPlanService->hasUsableMobile($item)) {
            $reasons[] = 'missing_mobile';
        }

        if (! $this->hasBasicIdentity($candidate)) {
            $reasons[] = 'missing_identity';
        }

        return [
            'ready' => $reasons === [],
            'reasons' => $reasons,
        ];
    }

    public function readyReasonLabel(string $reason): string
    {
        return match ($reason) {
            'manual_screening_required' => 'Admin override required',
            'manual_screening_not_eligible' => 'Admin override not eligible',
            'manual_duplicate' => 'Manual duplicate',
            'missing_mobile' => 'Missing mobile',
            'missing_identity' => 'Missing identity',
            default => str_replace('_', ' ', $reason),
        };
    }

    /**
     * @param  array<string, mixed>|null  $manualReview
     */
    public function hasActiveOverride(?array $manualReview): bool
    {
        if ($manualReview === null) {
            return false;
        }

        return in_array((string) ($manualReview['status'] ?? ''), [
            BulkIntakeCandidateScreeningReviewService::STATUS_ELIGIBLE,
            BulkIntakeCandidateScreeningReviewService::STATUS_NEEDS_REVIEW,
            BulkIntakeCandidateScreeningReviewService::STATUS_STOPPED,
        ], true);
    }

    /**
     * @param  list<array{code: string, label: string}>  $reasons
     * @param  list<string>  $reasonCodes
     * @return array{
     *     eligible: bool,
     *     bucket: string,
     *     bucket_label: string,
     *     source: string,
     *     has_override: bool,
     *     reasons: list<array{code: string, label: string}>,
     *     reason_codes: list<string>
     * }
     */
    private function pipelineResult(
        bool $eligible,
        string $bucket,
        string $source,
        bool $hasOverride,
        array $reasons,
        array $reasonCodes,
    ): array {
        return [
            'eligible' => $eligible,
            'bucket' => $bucket,
            'bucket_label' => $this->bucketLabel($bucket),
            'source' => $source,
            'has_override' => $hasOverride,
            'reasons' => $reasons,
            'reason_codes' => $reasonCodes,
        ];
    }

    /**
     * @param  list<string>  $codes
     * @return list<array{code: string, label: string}>
     */
    private function reasonsFromCodes(array $codes): array
    {
        return array_map(fn (string $code): array => [
            'code' => $code,
            'label' => $this->pipelineReasonLabel($code),
        ], $codes);
    }

    private function pipelineReasonLabel(string $code): string
    {
        return match ($code) {
            'pipeline_ready' => 'Ready for pipeline',
            'missing_mobile' => 'Mobile missing',
            'missing_identity' => 'Identity incomplete',
            'name_dob_needs_confirmation' => 'Same name + DOB — confirm other fields',
            'manual_duplicate' => 'Manual duplicate',
            'auto_duplicate_intake' => 'Already seen in intake',
            'duplicate_existing_profile' => 'Already on website',
            'already_married' => 'Already married',
            'not_interested' => 'Not interested',
            'wrong_number' => 'Wrong number',
            'do_not_suggest' => 'Do not suggest',
            'possible_duplicate' => 'Possible duplicate',
            'override_missing_requirements' => 'Override set but mobile/identity missing',
            'blocked_by_gate' => 'Blocked by duplicate/history gate',
            'blocked_signal' => 'Blocked by screening signal',
            default => str_replace('_', ' ', $code),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $duplicateHints
     */
    private function hasUnconfirmedNameDobDuplicate(BulkIntakeBatchItem $item, array $duplicateHints): bool
    {
        foreach ($duplicateHints as $hint) {
            if (! is_array($hint) || (string) ($hint['type'] ?? '') !== 'same_name_dob') {
                continue;
            }

            $matchedIntakeId = (int) ($hint['matched_intake_id'] ?? 0);
            if ($matchedIntakeId <= 0) {
                return true;
            }

            $current = $this->intakeForMatch($item);
            $reference = BiodataIntake::query()->find($matchedIntakeId, [
                'id',
                'parsed_json',
                'approval_snapshot_json',
            ]);
            if (! $current instanceof BiodataIntake || ! $reference instanceof BiodataIntake) {
                return true;
            }

            $fieldMatch = $this->fieldMatchEvaluator->evaluate($current, $reference);

            return ! $this->duplicateGateService->confirmsNameDobAsDuplicate($current, $reference, $fieldMatch);
        }

        return false;
    }

    private function intakeForMatch(BulkIntakeBatchItem $item): ?BiodataIntake
    {
        $loaded = $item->relationLoaded('biodataIntake') ? $item->biodataIntake : null;
        if ($loaded instanceof BiodataIntake) {
            return $loaded;
        }

        $intakeId = $item->biodata_intake_id ?? $loaded?->id;
        if ($intakeId === null) {
            return null;
        }

        return BiodataIntake::query()->find((int) $intakeId, [
            'id',
            'parsed_json',
            'approval_snapshot_json',
        ]);
    }

    /**
     * @param  array<string, mixed>  $autoSuggestion
     */
    private function autoDecisionToBucket(array $autoSuggestion): string
    {
        return match ((string) ($autoSuggestion['decision'] ?? 'review')) {
            'eligible' => self::FILTER_ELIGIBLE,
            'stop' => self::FILTER_BLOCKED,
            default => self::FILTER_NEEDS_CHECK,
        };
    }

    private function overrideStatusToBucket(string $status): string
    {
        return match ($status) {
            BulkIntakeCandidateScreeningReviewService::STATUS_ELIGIBLE => self::FILTER_ELIGIBLE,
            BulkIntakeCandidateScreeningReviewService::STATUS_STOPPED => self::FILTER_BLOCKED,
            default => self::FILTER_NEEDS_CHECK,
        };
    }

    private function hasManualDuplicateMark(BulkIntakeBatchItem $item): bool
    {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];

        return (string) data_get($meta, 'duplicate_review.status') === 'manual_duplicate';
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function hasBasicIdentity(array $candidate): bool
    {
        $name = trim((string) ($candidate['full_name'] ?? ''));
        if ($name === '') {
            return false;
        }

        $gender = strtolower(trim((string) ($candidate['gender'] ?? '')));
        if (! in_array($gender, ['male', 'female'], true)) {
            return false;
        }

        $hasDob = trim((string) ($candidate['date_of_birth'] ?? '')) !== '';
        $hasAge = is_int($candidate['age'] ?? null) || (
            is_string($candidate['age'] ?? null)
            && preg_match('/^\d+$/', trim((string) $candidate['age'])) === 1
        );

        return $hasDob || $hasAge;
    }
}
