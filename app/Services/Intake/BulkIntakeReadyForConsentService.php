<?php

namespace App\Services\Intake;

use App\Models\BulkIntakeBatchItem;

/**
 * @deprecated Use BulkIntakeEligibilityService. Kept for backward compatibility.
 */
class BulkIntakeReadyForConsentService
{
    public function __construct(
        private readonly BulkIntakeEligibilityService $eligibilityService,
    ) {}

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
        return $this->eligibilityService->readyForConsentForItem($item, $manualReview, $candidate);
    }

    public function reasonLabel(string $reason): string
    {
        return $this->eligibilityService->readyReasonLabel($reason);
    }
}
