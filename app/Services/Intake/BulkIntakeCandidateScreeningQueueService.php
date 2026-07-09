<?php

namespace App\Services\Intake;

use App\Models\BulkIntakeBatchItem;
use Illuminate\Support\Collection;

class BulkIntakeCandidateScreeningQueueService
{
    public const FILTER_ALL = 'all';

    public const FILTER_ELIGIBLE = 'eligible';

    public const FILTER_NEEDS_REVIEW = 'needs_review';

    public const FILTER_STOPPED = 'stopped';

    public const FILTER_ADVISOR = 'advisor';

    public const FILTER_MANUAL = 'manual';

    /**
     * @return array<string, string>
     */
    public function screeningFilters(): array
    {
        return [
            self::FILTER_ALL => 'All',
            self::FILTER_ELIGIBLE => 'Eligible for consent',
            self::FILTER_NEEDS_REVIEW => 'Needs review',
            self::FILTER_STOPPED => 'Stopped',
            self::FILTER_ADVISOR => 'Advisor',
            self::FILTER_MANUAL => 'Manual',
        ];
    }

    public function resolveScreeningFilter(string $value): string
    {
        return array_key_exists($value, $this->screeningFilters())
            ? $value
            : self::FILTER_ALL;
    }

    /**
     * @param  array<string, mixed>|null  $manualReview
     * @param  array<string, mixed>  $advisor
     * @return array{
     *     bucket: string,
     *     source: string,
     *     has_manual: bool
     * }
     */
    public function effectiveScreeningForItem(?array $manualReview, array $advisor): array
    {
        $hasManual = $this->hasActiveManualReview($manualReview);

        if ($hasManual) {
            return [
                'bucket' => $this->manualStatusToBucket((string) ($manualReview['status'] ?? '')),
                'source' => 'manual',
                'has_manual' => true,
            ];
        }

        return [
            'bucket' => $this->advisorDecisionToBucket($advisor),
            'source' => 'advisor',
            'has_manual' => false,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $manualReview
     * @param  array<string, mixed>  $advisor
     */
    public function itemMatchesFilter(string $filter, ?array $manualReview, array $advisor): bool
    {
        $filter = $this->resolveScreeningFilter($filter);
        if ($filter === self::FILTER_ALL) {
            return true;
        }

        $effective = $this->effectiveScreeningForItem($manualReview, $advisor);

        return match ($filter) {
            self::FILTER_ELIGIBLE => $effective['bucket'] === self::FILTER_ELIGIBLE,
            self::FILTER_NEEDS_REVIEW => $effective['bucket'] === self::FILTER_NEEDS_REVIEW,
            self::FILTER_STOPPED => $effective['bucket'] === self::FILTER_STOPPED,
            self::FILTER_ADVISOR => ! $effective['has_manual'],
            self::FILTER_MANUAL => $effective['has_manual'],
            default => true,
        };
    }

    /**
     * @param  Collection<int, BulkIntakeBatchItem>  $items
     * @param  callable(BulkIntakeBatchItem): array<string, mixed>  $advisorForItem
     * @param  callable(BulkIntakeBatchItem): array<string, mixed>|null  $manualReviewForItem
     * @return array<string, int>
     */
    public function countsForItems(Collection $items, callable $advisorForItem, callable $manualReviewForItem): array
    {
        $counts = [
            self::FILTER_ALL => $items->count(),
            self::FILTER_ELIGIBLE => 0,
            self::FILTER_NEEDS_REVIEW => 0,
            self::FILTER_STOPPED => 0,
            self::FILTER_ADVISOR => 0,
            self::FILTER_MANUAL => 0,
        ];

        foreach ($items as $item) {
            $effective = $this->effectiveScreeningForItem(
                $manualReviewForItem($item),
                $advisorForItem($item)
            );

            if (array_key_exists($effective['bucket'], $counts)) {
                $counts[$effective['bucket']]++;
            }

            if ($effective['has_manual']) {
                $counts[self::FILTER_MANUAL]++;
            } else {
                $counts[self::FILTER_ADVISOR]++;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>|null  $manualReview
     */
    public function hasActiveManualReview(?array $manualReview): bool
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
     * @param  array<string, mixed>  $advisor
     */
    private function advisorDecisionToBucket(array $advisor): string
    {
        return match ((string) ($advisor['decision'] ?? 'review')) {
            'eligible' => self::FILTER_ELIGIBLE,
            'stop' => self::FILTER_STOPPED,
            default => self::FILTER_NEEDS_REVIEW,
        };
    }

    private function manualStatusToBucket(string $status): string
    {
        return match ($status) {
            BulkIntakeCandidateScreeningReviewService::STATUS_ELIGIBLE => self::FILTER_ELIGIBLE,
            BulkIntakeCandidateScreeningReviewService::STATUS_STOPPED => self::FILTER_STOPPED,
            default => self::FILTER_NEEDS_REVIEW,
        };
    }
}
