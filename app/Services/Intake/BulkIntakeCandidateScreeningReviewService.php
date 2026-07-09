<?php

namespace App\Services\Intake;

use App\Models\BulkIntakeBatchItem;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BulkIntakeCandidateScreeningReviewService
{
    public const STATUS_ELIGIBLE = 'eligible_for_consent';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_STOPPED = 'stopped';

    public const STATUS_CLEARED = 'cleared';

    public function __construct(
        private readonly BulkIntakeIdentityHistoryService $identityHistoryService,
    ) {}

    /**
     * @var array<string, list<string>>
     */
    public const REASON_KEYS_BY_STATUS = [
        self::STATUS_ELIGIBLE => [
            'corrected_basic_fields',
            'valid_mobile_ready',
            'admin_verified',
        ],
        self::STATUS_NEEDS_REVIEW => [
            'missing_mobile',
            'invalid_mobile',
            'dob_unclear',
            'age_issue',
            'gender_unclear',
            'possible_duplicate',
            'unclear_biodata',
            'admin_followup_needed',
        ],
        self::STATUS_STOPPED => [
            'manual_duplicate',
            'duplicate_existing_profile',
            'already_married',
            'not_interested',
            'wrong_number',
            'do_not_suggest',
            'no_response',
            'blocked_or_complaint',
            'invalid_candidate',
        ],
    ];

    /**
     * @return array<string, mixed>|null
     */
    public function activeReviewForItem(BulkIntakeBatchItem $item): ?array
    {
        $review = $this->reviewPayload($item);
        if ($review === null) {
            return null;
        }

        $status = (string) ($review['status'] ?? '');
        if (! in_array($status, [self::STATUS_ELIGIBLE, self::STATUS_NEEDS_REVIEW, self::STATUS_STOPPED], true)) {
            return null;
        }

        return $review;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function reviewPayload(BulkIntakeBatchItem $item): ?array
    {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $review = data_get($meta, 'screening_review');

        return is_array($review) ? $review : null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function saveReview(BulkIntakeBatchItem $item, User $actor, array $input): void
    {
        $validated = $this->validateSaveInput($input);

        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $meta['screening_review'] = [
            'status' => $validated['status'],
            'reason_key' => $validated['reason_key'] ?? null,
            'note' => $validated['note'] ?? null,
            'reviewed_by_user_id' => (int) $actor->id,
            'reviewed_at' => now()->toISOString(),
            'cleared_by_user_id' => null,
            'cleared_at' => null,
        ];

        $item->forceFill(['item_meta_json' => $meta])->save();

        if ($validated['status'] === self::STATUS_STOPPED && is_string($validated['reason_key'] ?? null)) {
            $this->identityHistoryService->recordFromScreeningReview(
                $item,
                $actor,
                $validated['reason_key'],
                $validated['note'] ?? null
            );
        }
    }

    public function clearReview(BulkIntakeBatchItem $item, User $actor): void
    {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $existing = is_array($meta['screening_review'] ?? null) ? $meta['screening_review'] : [];

        $meta['screening_review'] = [
            'status' => self::STATUS_CLEARED,
            'reason_key' => is_string($existing['reason_key'] ?? null) && trim($existing['reason_key']) !== ''
                ? trim($existing['reason_key'])
                : null,
            'note' => is_string($existing['note'] ?? null) && trim($existing['note']) !== ''
                ? trim($existing['note'])
                : null,
            'reviewed_by_user_id' => isset($existing['reviewed_by_user_id']) ? (int) $existing['reviewed_by_user_id'] : null,
            'reviewed_at' => is_string($existing['reviewed_at'] ?? null) ? $existing['reviewed_at'] : null,
            'cleared_by_user_id' => (int) $actor->id,
            'cleared_at' => now()->toISOString(),
        ];

        $item->forceFill(['item_meta_json' => $meta])->save();
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_ELIGIBLE => 'Eligible for consent',
            self::STATUS_NEEDS_REVIEW => 'Needs review',
            self::STATUS_STOPPED => 'Stopped',
            self::STATUS_CLEARED => 'Cleared',
            default => str_replace('_', ' ', $status),
        };
    }

    public function reasonKeyLabel(string $reasonKey): string
    {
        return match ($reasonKey) {
            'corrected_basic_fields' => 'Corrected basic fields',
            'valid_mobile_ready' => 'Valid mobile ready',
            'admin_verified' => 'Admin verified',
            'missing_mobile' => 'Missing mobile',
            'invalid_mobile' => 'Invalid mobile',
            'dob_unclear' => 'DOB unclear',
            'age_issue' => 'Age issue',
            'gender_unclear' => 'Gender unclear',
            'possible_duplicate' => 'Possible duplicate',
            'unclear_biodata' => 'Unclear biodata',
            'admin_followup_needed' => 'Admin follow-up needed',
            'manual_duplicate' => 'Manual duplicate',
            'duplicate_existing_profile' => 'Duplicate existing profile',
            'already_married' => 'Already married',
            'not_interested' => 'Not interested',
            'wrong_number' => 'Wrong number',
            'do_not_suggest' => 'Do not suggest',
            'no_response' => 'No response',
            'blocked_or_complaint' => 'Blocked or complaint',
            'invalid_candidate' => 'Invalid candidate',
            default => str_replace('_', ' ', $reasonKey),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSaveInput(array $input): array
    {
        $status = (string) ($input['status'] ?? '');

        $validator = Validator::make($input, [
            'status' => ['required', 'string', Rule::in([
                self::STATUS_ELIGIBLE,
                self::STATUS_NEEDS_REVIEW,
                self::STATUS_STOPPED,
            ])],
            'reason_key' => [
                Rule::requiredIf(in_array($status, [self::STATUS_NEEDS_REVIEW, self::STATUS_STOPPED], true)),
                'nullable',
                'string',
                'max:100',
            ],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $validator->after(function ($validator) use ($input, $status): void {
            $reasonKey = trim((string) ($input['reason_key'] ?? ''));
            if ($reasonKey === '') {
                return;
            }

            $allowedReasonKeys = self::REASON_KEYS_BY_STATUS[$status] ?? [];
            if (! in_array($reasonKey, $allowedReasonKeys, true)) {
                $validator->errors()->add('reason_key', 'The selected reason key is invalid for this screening status.');
            }
        });

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $reasonKey = isset($validated['reason_key']) ? trim((string) $validated['reason_key']) : '';
        $note = isset($validated['note']) ? trim((string) $validated['note']) : '';

        return [
            'status' => $validated['status'],
            'reason_key' => $reasonKey !== '' ? $reasonKey : null,
            'note' => $note !== '' ? $note : null,
        ];
    }
}
