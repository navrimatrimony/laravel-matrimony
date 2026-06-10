<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileUpdateSuggestion;
use App\Models\User;
use App\Services\MutationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakProfileUpdateSuggestionService
{
    private const DAY_15_ALLOWED_CORE_FIELDS = [
        'highest_education',
        'highest_education_other',
        'specialization',
        'company_name',
        'occupation',
        'father_occupation',
        'mother_occupation',
        'annual_income',
        'family_income',
    ];

    public function __construct(
        private readonly SuchakActivityLogger $activityLogger,
        private readonly MutationService $mutationService,
        private readonly SuchakAccessService $accessService,
    ) {
    }

    public function createCoreFieldSuggestion(
        SuchakAccount $account,
        User $actor,
        SuchakProfileRepresentation $representation,
        string $fieldKey,
        mixed $suggestedValue,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakProfileUpdateSuggestion {
        $fieldKey = trim($fieldKey);
        $suggested = $this->scalarSuggestionString($suggestedValue);

        $account->refresh();
        $representation->refresh()->loadMissing(['suchakAccount', 'matrimonyProfile']);

        $this->assertSuchakCanSuggest($account, $actor, $representation);
        $this->assertAllowedField($fieldKey);
        $this->assertSuggestedValue($suggested);

        $profile = $representation->matrimonyProfile;
        $oldValue = $this->currentValueString($profile, $fieldKey);
        if ($this->normalizeComparable($oldValue) === $this->normalizeComparable($suggested)) {
            throw new InvalidArgumentException('Suggested value must differ from current profile value.');
        }

        return DB::transaction(function () use ($account, $actor, $representation, $profile, $fieldKey, $oldValue, $suggested, $ipAddress, $userAgent): SuchakProfileUpdateSuggestion {
            /** @var SuchakProfileRepresentation $lockedRepresentation */
            $lockedRepresentation = SuchakProfileRepresentation::query()
                ->whereKey($representation->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedRepresentation->loadMissing(['suchakAccount', 'matrimonyProfile']);

            $this->assertSuchakCanSuggest($account, $actor, $lockedRepresentation);

            $suggestion = SuchakProfileUpdateSuggestion::query()->create([
                'suchak_account_id' => $account->id,
                'matrimony_profile_id' => $profile->id,
                'representation_id' => $lockedRepresentation->id,
                'field_key' => $fieldKey,
                'old_value' => $oldValue,
                'suggested_value' => $suggested,
                'suggestion_status' => SuchakProfileUpdateSuggestion::STATUS_PENDING_CANDIDATE_CONFIRMATION,
                'otp_attempts' => 0,
            ]);

            $this->recordActivity(
                SuchakActivityLog::ACTION_PROFILE_UPDATE_SUGGESTION_CREATED,
                'profile_update_suggestion_created',
                $suggestion,
                $actor,
                $ipAddress,
                $userAgent,
            );

            return $suggestion->fresh(['suchakAccount', 'matrimonyProfile', 'representation']);
        });
    }

    public function recordOtpSent(
        SuchakProfileUpdateSuggestion $suggestion,
        string $otp,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakProfileUpdateSuggestion {
        $suggestion->refresh()->loadMissing('suchakAccount');
        $this->assertSuchakOwnsSuggestion($suggestion, $actor);
        $this->assertPendingCandidateConfirmation($suggestion);
        $this->assertOtpFormat($otp);

        SuchakProfileUpdateSuggestion::query()
            ->whereKey($suggestion->id)
            ->update([
                'otp_hash' => Hash::make($otp),
                'last_otp_sent_at' => now(),
            ]);

        $updated = $suggestion->fresh(['suchakAccount', 'matrimonyProfile', 'representation']);
        $this->recordActivity(
            SuchakActivityLog::ACTION_PROFILE_UPDATE_SUGGESTION_STATUS_CHANGED,
            'profile_update_suggestion_otp_sent',
            $updated,
            $actor,
            $ipAddress,
            $userAgent,
        );

        return $updated;
    }

    public function verifyCandidateOtpAndApply(
        SuchakProfileUpdateSuggestion $suggestion,
        string $otp,
        User $candidateActor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakProfileUpdateSuggestion {
        $this->assertOtpFormat($otp);

        $invalidOtp = false;
        $result = DB::transaction(function () use ($suggestion, $otp, $candidateActor, $ipAddress, $userAgent, &$invalidOtp): ?SuchakProfileUpdateSuggestion {
            /** @var SuchakProfileUpdateSuggestion $locked */
            $locked = SuchakProfileUpdateSuggestion::query()
                ->whereKey($suggestion->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->loadMissing(['matrimonyProfile', 'suchakAccount', 'representation']);

            $this->assertCandidateOwnsSuggestionProfile($locked, $candidateActor);
            $this->assertPendingCandidateConfirmation($locked);
            $this->assertOtpCanBeVerified($locked);

            if (! Hash::check($otp, (string) $locked->otp_hash)) {
                SuchakProfileUpdateSuggestion::query()
                    ->whereKey($locked->id)
                    ->update(['otp_attempts' => $locked->otp_attempts + 1]);

                $invalidOtp = true;

                return null;
            }

            $profile = $locked->matrimonyProfile->fresh();
            $currentValue = $this->currentValueString($profile, $locked->field_key);
            if ($this->normalizeComparable($currentValue) !== $this->normalizeComparable($locked->old_value)) {
                return $this->markAdminReviewRequired(
                    $locked,
                    $candidateActor,
                    'candidate_verified_stale_current',
                    $ipAddress,
                    $userAgent,
                );
            }

            SuchakProfileUpdateSuggestion::query()
                ->whereKey($locked->id)
                ->update([
                    'suggestion_status' => SuchakProfileUpdateSuggestion::STATUS_APPROVED_BY_CANDIDATE,
                    'candidate_verified_at' => now(),
                ]);

            $applied = $this->applyThroughMutationService($profile, $locked, $candidateActor);
            if (! $applied) {
                return $this->markAdminReviewRequired(
                    $locked->fresh(['matrimonyProfile', 'suchakAccount', 'representation']),
                    $candidateActor,
                    'candidate_verified_mutation_not_applied',
                    $ipAddress,
                    $userAgent,
                );
            }

            SuchakProfileUpdateSuggestion::query()
                ->whereKey($locked->id)
                ->update([
                    'suggestion_status' => SuchakProfileUpdateSuggestion::STATUS_APPLIED,
                    'applied_at' => now(),
                ]);

            $updated = $locked->fresh(['suchakAccount', 'matrimonyProfile', 'representation']);
            $this->recordActivity(
                SuchakActivityLog::ACTION_PROFILE_UPDATE_SUGGESTION_APPLIED,
                'profile_update_suggestion_applied',
                $updated,
                $candidateActor,
                $ipAddress,
                $userAgent,
            );

            return $updated;
        });

        if ($invalidOtp) {
            throw new InvalidArgumentException('Invalid OTP for Suchak profile update suggestion.');
        }

        if (! $result instanceof SuchakProfileUpdateSuggestion) {
            throw new InvalidArgumentException('Suchak update suggestion could not be applied.');
        }

        return $result;
    }

    public function rejectByCandidate(
        SuchakProfileUpdateSuggestion $suggestion,
        User $candidateActor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakProfileUpdateSuggestion {
        $suggestion->refresh()->loadMissing(['matrimonyProfile', 'suchakAccount', 'representation']);
        $this->assertCandidateOwnsSuggestionProfile($suggestion, $candidateActor);
        $this->assertPendingCandidateConfirmation($suggestion);

        SuchakProfileUpdateSuggestion::query()
            ->whereKey($suggestion->id)
            ->update([
                'suggestion_status' => SuchakProfileUpdateSuggestion::STATUS_REJECTED_BY_CANDIDATE,
                'candidate_verified_at' => now(),
            ]);

        $updated = $suggestion->fresh(['suchakAccount', 'matrimonyProfile', 'representation']);
        $this->recordActivity(
            SuchakActivityLog::ACTION_PROFILE_UPDATE_SUGGESTION_STATUS_CHANGED,
            'profile_update_suggestion_rejected_by_candidate',
            $updated,
            $candidateActor,
            $ipAddress,
            $userAgent,
        );

        return $updated;
    }

    /**
     * @return array<int, string>
     */
    public function allowedCoreFieldKeys(): array
    {
        $mutationAllowed = $this->mutationService->coreFieldKeysAllowedForIntakeSuggestionApply();

        return array_values(array_filter(
            self::DAY_15_ALLOWED_CORE_FIELDS,
            fn (string $fieldKey): bool => in_array($fieldKey, $mutationAllowed, true)
                && Schema::hasColumn('matrimony_profiles', $fieldKey),
        ));
    }

    private function applyThroughMutationService(
        MatrimonyProfile $profile,
        SuchakProfileUpdateSuggestion $suggestion,
        User $candidateActor,
    ): bool {
        try {
            $result = $this->mutationService->applyManualSnapshot(
                $profile,
                [
                    'snapshot_schema_version' => 1,
                    'core' => [
                        $suggestion->field_key => $suggestion->suggested_value,
                    ],
                ],
                (int) $candidateActor->id,
                'manual',
            );
        } catch (\Throwable) {
            return false;
        }

        if (($result['mutation_success'] ?? false) !== true || ($result['conflict_detected'] ?? false) === true) {
            return false;
        }

        $profile->refresh();

        return $this->normalizeComparable($this->currentValueString($profile, $suggestion->field_key))
            === $this->normalizeComparable($suggestion->suggested_value);
    }

    private function markAdminReviewRequired(
        SuchakProfileUpdateSuggestion $suggestion,
        User $actor,
        string $context,
        ?string $ipAddress,
        ?string $userAgent,
    ): SuchakProfileUpdateSuggestion {
        SuchakProfileUpdateSuggestion::query()
            ->whereKey($suggestion->id)
            ->update([
                'suggestion_status' => SuchakProfileUpdateSuggestion::STATUS_ADMIN_REVIEW_REQUIRED,
                'candidate_verified_at' => $suggestion->candidate_verified_at ?? now(),
            ]);

        $updated = $suggestion->fresh(['suchakAccount', 'matrimonyProfile', 'representation']);
        $this->recordActivity(
            SuchakActivityLog::ACTION_PROFILE_UPDATE_SUGGESTION_STATUS_CHANGED,
            $context,
            $updated,
            $actor,
            $ipAddress,
            $userAgent,
        );

        return $updated;
    }

    private function assertSuchakCanSuggest(
        SuchakAccount $account,
        User $actor,
        SuchakProfileRepresentation $representation,
    ): void {
        if ((int) $account->user_id !== (int) $actor->id) {
            throw new InvalidArgumentException('Only the owning Suchak account can suggest profile updates.');
        }

        if (! $this->accessService->canOperate($account)) {
            throw new InvalidArgumentException('Only verified Suchak accounts can suggest profile updates.');
        }

        if ((int) $representation->suchak_account_id !== (int) $account->id) {
            throw new InvalidArgumentException('Update suggestion representation must belong to the Suchak account.');
        }

        if (! $representation->hasValidConsent() || $representation->representation_status !== SuchakProfileRepresentation::STATUS_ACTIVE) {
            throw new InvalidArgumentException('Profile update suggestions require active representation with valid consent.');
        }

        if (! $this->profileIsActive($representation->matrimonyProfile)) {
            throw new InvalidArgumentException('Profile update suggestions require an active candidate profile.');
        }
    }

    private function assertSuchakOwnsSuggestion(SuchakProfileUpdateSuggestion $suggestion, User $actor): void
    {
        if ((int) $suggestion->suchakAccount?->user_id !== (int) $actor->id) {
            throw new InvalidArgumentException('Only the owning Suchak account can manage this update suggestion.');
        }

        if (! $this->accessService->canOperate($suggestion->suchakAccount)) {
            throw new InvalidArgumentException('Only verified Suchak accounts can manage profile update suggestions.');
        }
    }

    private function assertCandidateOwnsSuggestionProfile(SuchakProfileUpdateSuggestion $suggestion, User $actor): void
    {
        if ((int) $suggestion->matrimonyProfile?->user_id !== (int) $actor->id) {
            throw new InvalidArgumentException('Only the candidate profile owner can confirm this Suchak update suggestion.');
        }
    }

    private function assertPendingCandidateConfirmation(SuchakProfileUpdateSuggestion $suggestion): void
    {
        if (! $suggestion->isOpenForCandidateConfirmation()) {
            throw new InvalidArgumentException('Suchak update suggestion is not pending candidate confirmation.');
        }
    }

    private function assertOtpCanBeVerified(SuchakProfileUpdateSuggestion $suggestion): void
    {
        if ($suggestion->otp_hash === null) {
            throw new InvalidArgumentException('Suchak update suggestion OTP has not been sent.');
        }

        if ($suggestion->otp_attempts >= SuchakProfileUpdateSuggestion::MAX_OTP_ATTEMPTS) {
            throw new InvalidArgumentException('OTP attempt limit exceeded for Suchak update suggestion.');
        }
    }

    private function assertAllowedField(string $fieldKey): void
    {
        if (! in_array($fieldKey, $this->allowedCoreFieldKeys(), true)) {
            throw new InvalidArgumentException('Field is not allowed for Day-15 Suchak profile update suggestions.');
        }
    }

    private function assertSuggestedValue(string $value): void
    {
        if ($value === '') {
            throw new InvalidArgumentException('Suggested value is required.');
        }

        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value) === 1
            || preg_match('/(?<!\d)(?:\+?91[\s-]*)?[6-9]\d(?:[\s-]?\d){8}(?!\d)/', $value) === 1) {
            throw new InvalidArgumentException('Suchak profile update suggestions must not store private contact details.');
        }
    }

    private function assertOtpFormat(string $otp): void
    {
        if (! preg_match('/^[0-9]{6}$/', $otp)) {
            throw new InvalidArgumentException('Suchak update suggestion OTP must be a six digit code.');
        }
    }

    private function profileIsActive(?MatrimonyProfile $profile): bool
    {
        return $profile !== null
            && ($profile->lifecycle_state ?? null) === 'active'
            && (bool) ($profile->is_suspended ?? false) === false;
    }

    private function currentValueString(MatrimonyProfile $profile, string $fieldKey): ?string
    {
        $value = $profile->getAttribute($fieldKey);
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return null;
    }

    private function scalarSuggestionString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (! is_scalar($value)) {
            throw new InvalidArgumentException('Suggested value must be scalar.');
        }

        return Str::limit(trim((string) $value), 4000, '');
    }

    private function normalizeComparable(?string $value): string
    {
        return trim(mb_strtolower((string) ($value ?? '')));
    }

    private function recordActivity(
        string $actionType,
        string $context,
        SuchakProfileUpdateSuggestion $suggestion,
        ?User $actor,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $this->activityLogger->record([
            'suchak_account_id' => $suggestion->suchak_account_id,
            'actor_user_id' => $actor?->id,
            'actor_type' => $actor === null ? SuchakActivityLog::ACTOR_SYSTEM : (
                (int) $suggestion->suchakAccount?->user_id === (int) $actor->id
                    ? SuchakActivityLog::ACTOR_SUCHAK
                    : SuchakActivityLog::ACTOR_USER
            ),
            'action_type' => $actionType,
            'target_type' => 'suchak_profile_update_suggestion',
            'target_id' => $suggestion->id,
            'matrimony_profile_id' => $suggestion->matrimony_profile_id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 512, ''),
            'metadata_json' => [
                'context' => $context,
                'representation_id' => $suggestion->representation_id,
                'field_key' => $suggestion->field_key,
                'suggestion_status' => $suggestion->suggestion_status,
                'has_candidate_verified_at' => $suggestion->candidate_verified_at !== null,
                'has_admin_reviewed_at' => $suggestion->admin_reviewed_at !== null,
                'has_applied_at' => $suggestion->applied_at !== null,
            ],
        ]);
    }
}
