<?php

namespace App\Services\Intake;

use App\Models\BulkIntakeBatchItem;
use App\Support\MobileNumber;

class BulkIntakeReadyForConsentService
{
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
        $candidate ??= [];
        $reasons = [];

        if (! $this->hasActiveManualReview($manualReview)) {
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

    public function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'manual_screening_required' => 'Manual screening required',
            'manual_screening_not_eligible' => 'Manual screening not eligible',
            'manual_duplicate' => 'Manual duplicate',
            'missing_mobile' => 'Missing mobile',
            'missing_identity' => 'Missing identity',
            default => str_replace('_', ' ', $reason),
        };
    }

    /**
     * @param  array<string, mixed>|null  $manualReview
     */
    private function hasActiveManualReview(?array $manualReview): bool
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
