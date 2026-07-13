<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatchItem;
use App\Support\MobileNumber;
use Illuminate\Support\Carbon;

/**
 * @deprecated Phase R — internal advisor module for BulkIntakeEligibilityService only.
 */
class BulkIntakeCandidateScreeningAdvisorService
{
    public function __construct(
        private readonly BulkIntakeCandidateDisplayService $candidateDisplayService,
        private readonly BulkIntakeDuplicateHistoryHintService $duplicateHistoryHintService,
        private readonly BulkIntakeDuplicateGateService $duplicateGateService,
        private readonly BulkIntakeCandidateContactPlanService $contactPlanService,
    ) {}

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
    public function advisorForItem(BulkIntakeBatchItem $item, ?array $candidate = null, ?array $duplicateHints = null): array
    {
        $intake = $this->intakeForScreening($item);
        $candidate ??= $this->candidateDisplayService->candidateForItem($item);
        $duplicateHints ??= $this->duplicateHistoryHintService->hintsForItem($item);
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $snapshot = $this->snapshotData($intake);

        $stopCodes = [];
        $reviewCodes = [];
        $eligibleCodes = [];

        $manualDuplicateActive = (string) data_get($meta, 'duplicate_review.status') === 'manual_duplicate';
        if ($manualDuplicateActive) {
            $stopCodes[] = 'manual_duplicate';
        } else {
            $eligibleCodes[] = 'no_manual_duplicate';
        }

        $gate = $this->duplicateGateService->evaluateForItem($item, $duplicateHints);
        foreach ($gate['blocks'] as $block) {
            $code = (string) ($block['code'] ?? '');
            if ($code !== '' && ! in_array($code, $stopCodes, true)) {
                $stopCodes[] = $code;
            }
        }

        $duplicateStopCodes = ['duplicate_existing_profile', 'auto_duplicate_intake', 'manual_duplicate'];
        $hasDuplicateStop = array_intersect($stopCodes, $duplicateStopCodes) !== [];
        if (! $hasDuplicateStop) {
            if ($duplicateHints !== [] && ! (bool) ($gate['override_active'] ?? false)) {
                $reviewCodes[] = 'possible_duplicate';
            } elseif ($duplicateHints === []) {
                $eligibleCodes[] = 'no_duplicate_hint';
            } else {
                $eligibleCodes[] = 'duplicate_verified_proceed';
            }
        }

        if ($this->metadataFlag($meta, 'wrong_number')) {
            $stopCodes[] = 'wrong_number';
        }
        if ($this->metadataFlag($meta, 'already_married')) {
            $stopCodes[] = 'already_married';
        }

        $mobile = $this->snapshotMobile($snapshot);
        if ($mobile['raw'] === null) {
            if ($this->contactPlanService->hasUsableMobile($item)) {
                $eligibleCodes[] = 'valid_mobile';
            } else {
                $reviewCodes[] = 'missing_mobile';
            }
        } elseif ($mobile['normalized'] === null) {
            $stopCodes[] = 'invalid_mobile';
        } else {
            $eligibleCodes[] = 'valid_mobile';
        }

        if (! $this->hasName($snapshot, $candidate)) {
            $reviewCodes[] = 'missing_name';
        }

        if (! $this->hasGender($snapshot, $candidate)) {
            $reviewCodes[] = 'missing_gender';
        }

        $age = $this->ageSignal($snapshot);
        if ($age['status'] === 'below_18') {
            $reviewCodes[] = 'age_below_18';
        } elseif ($age['status'] === 'above_75') {
            $reviewCodes[] = 'age_above_75';
        } elseif ($age['status'] === 'invalid') {
            $reviewCodes[] = 'invalid_or_unparseable_dob';
        } else {
            $eligibleCodes[] = 'age_in_range_or_dob_missing_but_not_blocked';
        }

        if ((string) ($intake?->parse_status ?? '') === 'error') {
            $reviewCodes[] = 'parse_error';
        }

        if (! $this->hasUsableSnapshot($intake)) {
            $reviewCodes[] = 'parsed_json_missing';
        }

        if ($item->item_status === BulkIntakeBatchItem::STATUS_NEEDS_REVIEW || $item->failure_code || $item->failure_message) {
            $reviewCodes[] = 'needs_review_item_status';
        }

        if ($this->basicFieldsPresent($snapshot, $candidate, $mobile['normalized'])) {
            $eligibleCodes[] = 'basic_fields_present';
        }

        $decision = $stopCodes !== [] ? 'stop' : ($reviewCodes !== [] ? 'review' : 'eligible');
        $reasonCodes = $decision === 'stop'
            ? $stopCodes
            : ($decision === 'review' ? $reviewCodes : $eligibleCodes);
        $reasonCodes = array_values(array_unique($reasonCodes));

        return [
            'decision' => $decision,
            'label' => $this->decisionLabel($decision),
            'reasons' => array_map(fn (string $code): array => [
                'code' => $code,
                'label' => $this->reasonLabel($code),
            ], $reasonCodes),
            'reason_codes' => $reasonCodes,
            'suggested_next_action' => $this->suggestedNextAction($decision, $reasonCodes[0] ?? null),
        ];
    }

    private function intakeForScreening(BulkIntakeBatchItem $item): ?BiodataIntake
    {
        $loaded = $item->relationLoaded('biodataIntake') ? $item->biodataIntake : null;
        if ($loaded instanceof BiodataIntake) {
            $attributes = $loaded->getAttributes();
            if (array_key_exists('parsed_json', $attributes) && array_key_exists('approval_snapshot_json', $attributes)) {
                return $loaded;
            }
        }

        $intakeId = $item->biodata_intake_id ?? $loaded?->id;
        if ($intakeId === null) {
            return null;
        }

        return BiodataIntake::query()->find((int) $intakeId, [
            'id',
            'parse_status',
            'parsed_json',
            'approval_snapshot_json',
            'last_error',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotData(?BiodataIntake $intake): array
    {
        $approval = is_array($intake?->approval_snapshot_json) ? $intake->approval_snapshot_json : [];
        if ($approval !== []) {
            return $approval;
        }

        return is_array($intake?->parsed_json) ? $intake->parsed_json : [];
    }

    private function hasUsableSnapshot(?BiodataIntake $intake): bool
    {
        return (is_array($intake?->approval_snapshot_json) && $intake->approval_snapshot_json !== [])
            || (is_array($intake?->parsed_json) && $intake->parsed_json !== []);
    }

    /**
     * @param  list<array<string, mixed>>  $duplicateHints
     */
    private function hasDuplicateExistingProfile(array $duplicateHints): bool
    {
        foreach ($duplicateHints as $hint) {
            if (! is_array($hint)) {
                continue;
            }
            if (! empty($hint['matched_profile_id']) || (string) ($hint['type'] ?? '') === 'same_profile_mobile') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function metadataFlag(array $meta, string $flag): bool
    {
        foreach ([
            $flag,
            'screening.'.$flag,
            'candidate_screening.'.$flag,
            'review_flags.'.$flag,
        ] as $path) {
            $value = data_get($meta, $path);
            if ($value === true || $value === 1) {
                return true;
            }
            if (is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'yes', $flag], true)) {
                return true;
            }
        }

        foreach (['screening.status', 'candidate_screening.status', 'review_status'] as $path) {
            if ((string) data_get($meta, $path) === $flag) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{raw: string|null, normalized: string|null}
     */
    private function snapshotMobile(array $snapshot): array
    {
        $raw = $this->firstString($snapshot, [
            'core.primary_contact_number',
            'core.mobile',
            'core.mobile_number',
            'core.user_contact_1',
            'core.contact_number',
            'primary_contact_number',
            'mobile',
            'mobile_number',
            'candidate.primary_contact_number',
        ]);

        if ($raw === null) {
            $contacts = data_get($snapshot, 'contacts');
            if (is_array($contacts)) {
                foreach ($contacts as $contact) {
                    if (! is_array($contact)) {
                        continue;
                    }
                    $raw = $this->firstString($contact, ['phone_number', 'number', 'mobile', 'contact_number']);
                    if ($raw !== null) {
                        break;
                    }
                }
            }
        }

        return [
            'raw' => $raw,
            'normalized' => MobileNumber::normalize($raw),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $candidate
     */
    private function hasName(array $snapshot, array $candidate): bool
    {
        return $this->firstString($snapshot, [
            'core.full_name',
            'full_name',
            'candidate.full_name',
            'candidate_name',
            'name',
        ]) !== null || $this->stringOrNull($candidate['full_name'] ?? null) !== null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $candidate
     */
    private function hasGender(array $snapshot, array $candidate): bool
    {
        $gender = strtolower((string) ($this->firstString($snapshot, ['core.gender', 'gender', 'candidate.gender']) ?? $candidate['gender'] ?? ''));

        return in_array($gender, ['male', 'female'], true);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{status: string}
     */
    private function ageSignal(array $snapshot): array
    {
        $dob = $this->firstString($snapshot, [
            'core.date_of_birth',
            'core.dob',
            'date_of_birth',
            'dob',
            'candidate.date_of_birth',
            'candidate.dob',
        ]);

        if ($dob !== null) {
            $age = $this->ageFromDate($dob);
            if ($age === null) {
                return ['status' => 'invalid'];
            }

            return ['status' => $this->ageRangeStatus($age)];
        }

        $age = $this->intValue(data_get($snapshot, 'core.age') ?? data_get($snapshot, 'age') ?? data_get($snapshot, 'candidate.age'));
        if ($age === null) {
            return ['status' => 'missing_not_blocked'];
        }

        return ['status' => $this->ageRangeStatus($age)];
    }

    private function ageFromDate(string $date): ?int
    {
        $date = trim($date);
        $normalized = null;

        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $date, $matches) === 1) {
            $normalized = sprintf('%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
        } elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $date, $matches) === 1) {
            $normalized = sprintf('%04d-%02d-%02d', (int) $matches[3], (int) $matches[2], (int) $matches[1]);
        }

        if ($normalized === null) {
            return null;
        }

        try {
            [$year, $month, $day] = array_map('intval', explode('-', $normalized));
            if (! checkdate($month, $day, $year)) {
                return null;
            }

            return Carbon::parse($normalized)->age;
        } catch (\Throwable) {
            return null;
        }
    }

    private function ageRangeStatus(int $age): string
    {
        if ($age < 18) {
            return 'below_18';
        }

        if ($age > 75) {
            return 'above_75';
        }

        return 'in_range';
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $candidate
     */
    private function basicFieldsPresent(array $snapshot, array $candidate, ?string $normalizedMobile): bool
    {
        return $normalizedMobile !== null
            && $this->hasName($snapshot, $candidate)
            && $this->hasGender($snapshot, $candidate);
    }

    private function decisionLabel(string $decision): string
    {
        return match ($decision) {
            'stop' => 'Stop',
            'review' => 'Needs review',
            default => 'Eligible',
        };
    }

    private function reasonLabel(string $code): string
    {
        return match ($code) {
            'manual_duplicate' => 'Manual duplicate',
            'duplicate_existing_profile' => 'Existing profile match',
            'auto_duplicate_intake' => 'Already seen in intake',
            'not_interested' => 'Not interested',
            'do_not_suggest' => 'Do not suggest',
            'no_response' => 'No response',
            'invalid_mobile' => 'Invalid mobile',
            'wrong_number' => 'Wrong number',
            'already_married' => 'Already married',
            'missing_mobile' => 'Mobile missing',
            'invalid_or_unparseable_dob' => 'DOB invalid',
            'age_below_18' => 'Age below 18',
            'age_above_75' => 'Age above 75',
            'missing_gender' => 'Gender missing',
            'missing_name' => 'Name missing',
            'possible_duplicate' => 'Possible duplicate',
            'parse_error' => 'Parse error',
            'parsed_json_missing' => 'Parsed JSON missing',
            'needs_review_item_status' => 'Needs review flag',
            'valid_mobile' => 'Valid mobile',
            'basic_fields_present' => 'Basic fields present',
            'no_duplicate_hint' => 'No duplicate hint',
            'no_manual_duplicate' => 'No manual duplicate',
            'age_in_range_or_dob_missing_but_not_blocked' => 'Age ok or not blocking',
            default => str_replace('_', ' ', $code),
        };
    }

    private function suggestedNextAction(string $decision, ?string $firstReason): string
    {
        if ($decision === 'stop') {
            return match ($firstReason) {
                'manual_duplicate' => 'Stop: Manual duplicate marked.',
                'duplicate_existing_profile' => 'Stop: Existing profile or mobile match found.',
                'auto_duplicate_intake' => 'Stop: Same candidate already seen in another intake.',
                'not_interested', 'do_not_suggest', 'no_response' => 'Stop: Identity history blocks this candidate.',
                'invalid_mobile' => 'Stop: Correct mobile before consent.',
                'wrong_number' => 'Stop: Number is already marked wrong.',
                'already_married' => 'Stop: Candidate is already marked married.',
                default => 'Stop: Resolve blocking screening signal before consent.',
            };
        }

        if ($decision === 'review') {
            return match ($firstReason) {
                'missing_mobile' => 'Review: Mobile missing. Correct mobile before consent.',
                'invalid_or_unparseable_dob' => 'Review: DOB is invalid. Correct DOB or confirm manually.',
                'age_below_18' => 'Review: Age is below 18.',
                'age_above_75' => 'Review: Age is above 75.',
                'possible_duplicate' => 'Review: Possible duplicate found. Confirm before consent.',
                'parse_error', 'parsed_json_missing' => 'Review: Parser output is not ready.',
                'needs_review_item_status' => 'Review: Item is marked needs review.',
                default => 'Review: Check candidate fields before consent.',
            };
        }

        return 'Eligible: Basic fields look ready for consent phase.';
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $paths
     */
    private function firstString(array $source, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = $this->stringOrNull(data_get($source, $path));
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }
}
