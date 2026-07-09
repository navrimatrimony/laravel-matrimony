<?php

namespace App\Services\Intake;

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
        private readonly BulkIntakeDuplicateHistoryHintService $duplicateHistoryHintService,
        private readonly BulkIntakeCandidateScreeningAdvisorService $screeningAdvisorService,
        private readonly BulkIntakeCandidateScreeningReviewService $screeningReviewService,
    ) {}

    /**
     * Primary batch-page filters (simplified UI).
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
            self::FILTER_READY => 'Ready',
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

        if (! $this->hasUsableMobile($candidate)) {
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
            $effective = $this->effectiveEligibilityForItem(
                $overrideForItem($item),
                $autoSuggestionForItem($item)
            );

            if ($effective['bucket'] === self::FILTER_ELIGIBLE) {
                $counts[self::FILTER_ELIGIBLE]++;
            } elseif ($effective['bucket'] === self::FILTER_BLOCKED) {
                $counts[self::FILTER_BLOCKED]++;
                $counts[self::FILTER_STOPPED]++;
            } elseif ($effective['bucket'] === self::FILTER_NEEDS_CHECK) {
                $counts[self::FILTER_NEEDS_CHECK]++;
                $counts[self::FILTER_NEEDS_REVIEW]++;
            }

            if ($effective['has_override']) {
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
    private function hasUsableMobile(array $candidate): bool
    {
        return MobileNumber::normalize($candidate['mobile'] ?? null) !== null;
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
