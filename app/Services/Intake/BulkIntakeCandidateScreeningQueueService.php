<?php

namespace App\Services\Intake;

use App\Models\BulkIntakeBatchItem;
use Illuminate\Support\Collection;

/**
 * @deprecated Use BulkIntakeEligibilityService. Kept for backward compatibility.
 */
class BulkIntakeCandidateScreeningQueueService
{
    public const FILTER_ALL = BulkIntakeEligibilityService::FILTER_ALL;

    public const FILTER_ELIGIBLE = BulkIntakeEligibilityService::FILTER_ELIGIBLE;

    public const FILTER_NEEDS_REVIEW = BulkIntakeEligibilityService::FILTER_NEEDS_REVIEW;

    public const FILTER_STOPPED = BulkIntakeEligibilityService::FILTER_STOPPED;

    public const FILTER_ADVISOR = BulkIntakeEligibilityService::FILTER_ADVISOR;

    public const FILTER_MANUAL = BulkIntakeEligibilityService::FILTER_MANUAL;

    public const FILTER_READY = BulkIntakeEligibilityService::FILTER_READY;

    public function __construct(
        private readonly BulkIntakeEligibilityService $eligibilityService,
    ) {}

    /**
     * @return array<string, string>
     */
    public function screeningFilters(): array
    {
        return $this->eligibilityService->allScreeningFilterLabels();
    }

    public function resolveScreeningFilter(string $value): string
    {
        return $this->eligibilityService->resolveScreeningFilter($value);
    }

    /**
     * @param  array<string, mixed>|null  $manualReview
     * @param  array<string, mixed>  $advisor
     * @return array{bucket: string, source: string, has_manual: bool}
     */
    public function effectiveScreeningForItem(?array $manualReview, array $advisor): array
    {
        $effective = $this->eligibilityService->effectiveEligibilityForItem($manualReview, $advisor);

        return [
            'bucket' => $this->legacyBucketKey((string) $effective['bucket']),
            'source' => $effective['source'] === 'override' ? 'manual' : 'advisor',
            'has_manual' => $effective['has_override'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $manualReview
     * @param  array<string, mixed>  $advisor
     */
    public function itemMatchesFilter(
        string $filter,
        ?array $manualReview,
        array $advisor,
        ?bool $readyForConsent = null
    ): bool {
        return $this->eligibilityService->itemMatchesFilter($filter, $manualReview, $advisor, $readyForConsent);
    }

    /**
     * @param  Collection<int, BulkIntakeBatchItem>  $items
     * @param  callable(BulkIntakeBatchItem): array<string, mixed>  $advisorForItem
     * @param  callable(BulkIntakeBatchItem): array<string, mixed>|null  $manualReviewForItem
     * @return array<string, int>
     */
    public function countsForItems(
        Collection $items,
        callable $advisorForItem,
        callable $manualReviewForItem,
        ?callable $readyForConsentForItem = null
    ): array {
        return $this->eligibilityService->countsForItems(
            $items,
            $advisorForItem,
            $manualReviewForItem,
            $readyForConsentForItem
        );
    }

    /**
     * @param  array<string, mixed>|null  $manualReview
     */
    public function hasActiveManualReview(?array $manualReview): bool
    {
        return $this->eligibilityService->hasActiveOverride($manualReview);
    }

    private function legacyBucketKey(string $bucket): string
    {
        return match ($bucket) {
            BulkIntakeEligibilityService::FILTER_BLOCKED => self::FILTER_STOPPED,
            BulkIntakeEligibilityService::FILTER_NEEDS_CHECK => self::FILTER_NEEDS_REVIEW,
            default => $bucket,
        };
    }
}
