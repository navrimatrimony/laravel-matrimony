<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCommissionAgreement;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakCollaborationService
{
    public function __construct(
        private readonly SuchakActivityLogger $activityLogger,
        private readonly SuchakAccessService $accessService,
        private readonly SuchakLimitService $limitService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{request: SuchakCollaborationRequest, agreement: SuchakCommissionAgreement}
     */
    public function createRequest(
        SuchakAccount $requestingAccount,
        User $actor,
        SuchakProfileRepresentation $requestingRepresentation,
        SuchakProfileRepresentation $targetRepresentation,
        array $attributes = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $requestingAccount->refresh();
        $requestingRepresentation->refresh()->loadMissing(['suchakAccount', 'matrimonyProfile.gender']);
        $targetRepresentation->refresh()->loadMissing(['suchakAccount', 'matrimonyProfile.gender']);

        $this->assertCanCreate($requestingAccount, $actor, $requestingRepresentation, $targetRepresentation);
        $this->limitService->assertCollaborationRequestAllowed($requestingAccount);

        return DB::transaction(function () use ($requestingAccount, $actor, $requestingRepresentation, $targetRepresentation, $attributes, $ipAddress, $userAgent): array {
            /** @var SuchakAccount $lockedRequestingAccount */
            $lockedRequestingAccount = SuchakAccount::query()
                ->whereKey($requestingAccount->id)
                ->lockForUpdate()
                ->firstOrFail();
            /** @var SuchakProfileRepresentation $lockedRequestingRepresentation */
            $lockedRequestingRepresentation = SuchakProfileRepresentation::query()
                ->whereKey($requestingRepresentation->id)
                ->lockForUpdate()
                ->firstOrFail();
            /** @var SuchakProfileRepresentation $lockedTargetRepresentation */
            $lockedTargetRepresentation = SuchakProfileRepresentation::query()
                ->whereKey($targetRepresentation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedRequestingRepresentation->loadMissing(['suchakAccount', 'matrimonyProfile.gender']);
            $lockedTargetRepresentation->loadMissing(['suchakAccount', 'matrimonyProfile.gender']);
            $this->assertCanCreate($lockedRequestingAccount, $actor, $lockedRequestingRepresentation, $lockedTargetRepresentation);
            $this->limitService->assertCollaborationRequestAllowed($lockedRequestingAccount);
            $this->assertNoDuplicateOpenRequest($lockedRequestingRepresentation, $lockedTargetRepresentation);
            $commissionTerms = $this->normalizeCommissionTerms($attributes);

            $requestedAt = now();
            $collaboration = SuchakCollaborationRequest::query()->create([
                'requesting_suchak_account_id' => $lockedRequestingAccount->id,
                'target_suchak_account_id' => $lockedTargetRepresentation->suchak_account_id,
                'requesting_matrimony_profile_id' => $lockedRequestingRepresentation->matrimony_profile_id,
                'target_matrimony_profile_id' => $lockedTargetRepresentation->matrimony_profile_id,
                'requesting_representation_id' => $lockedRequestingRepresentation->id,
                'target_representation_id' => $lockedTargetRepresentation->id,
                'status' => SuchakCollaborationRequest::STATUS_PENDING,
                'message' => $this->nullableLimitedString($attributes['message'] ?? null, 2000),
                'requested_at' => $requestedAt,
                'expires_at' => $requestedAt->copy()->addDays($this->limitService->collaborationSlaDays()),
            ]);

            [$groomAccountId, $brideAccountId] = $this->agreementSideAccountIds(
                $lockedRequestingRepresentation,
                $lockedTargetRepresentation,
            );
            $requesterAckColumn = $requestingAccount->id === $groomAccountId
                ? 'accepted_by_groom_suchak_at'
                : 'accepted_by_bride_suchak_at';

            $agreement = SuchakCommissionAgreement::query()->create([
                'collaboration_request_id' => $collaboration->id,
                'groom_side_suchak_account_id' => $groomAccountId,
                'bride_side_suchak_account_id' => $brideAccountId,
                'agreement_type' => SuchakCommissionAgreement::TYPE_COLLABORATION_ACK,
                'split_type' => $commissionTerms['split_type'],
                'groom_side_share' => $commissionTerms['groom_side_share'],
                'bride_side_share' => $commissionTerms['bride_side_share'],
                'fixed_amount' => $commissionTerms['fixed_amount'],
                'currency' => $commissionTerms['currency'],
                'agreement_text_snapshot' => SuchakCommissionAgreement::MVP_ACK_TEXT,
                $requesterAckColumn => $requestedAt,
                'agreement_status' => SuchakCommissionAgreement::STATUS_PENDING,
            ]);

            $this->recordActivity(
                SuchakActivityLog::ACTION_COLLABORATION_REQUEST_CREATED,
                $collaboration,
                $actor,
                $ipAddress,
                $userAgent,
                ['context' => 'collaboration_request_created'],
            );

            return [
                'request' => $collaboration->fresh(['commissionAgreement']),
                'agreement' => $agreement->fresh(['collaborationRequest']),
            ];
        });
    }

    public function acceptRequest(
        SuchakCollaborationRequest $collaboration,
        SuchakAccount $targetAccount,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCollaborationRequest {
        $targetAccount->refresh();
        $this->assertTargetActor($collaboration, $targetAccount, $actor);

        return DB::transaction(function () use ($collaboration, $targetAccount, $actor, $ipAddress, $userAgent): SuchakCollaborationRequest {
            /** @var SuchakCollaborationRequest $locked */
            $locked = SuchakCollaborationRequest::query()
                ->whereKey($collaboration->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->loadMissing('commissionAgreement');
            $this->assertTargetActor($locked, $targetAccount, $actor);
            $this->assertPendingAndNotExpired($locked);

            SuchakCollaborationRequest::query()
                ->whereKey($locked->id)
                ->update([
                    'status' => SuchakCollaborationRequest::STATUS_ACCEPTED,
                    'responded_at' => now(),
                ]);

            $agreement = $locked->commissionAgreement ?? $this->createMissingAgreement($locked);
            $this->acknowledgeAgreementForAccount($agreement, (int) $targetAccount->id);

            $accepted = $locked->fresh(['commissionAgreement']);
            $this->recordActivity(
                SuchakActivityLog::ACTION_COLLABORATION_REQUEST_ACCEPTED,
                $accepted,
                $actor,
                $ipAddress,
                $userAgent,
                ['context' => 'collaboration_request_accepted'],
            );

            return $accepted;
        });
    }

    public function rejectRequest(
        SuchakCollaborationRequest $collaboration,
        SuchakAccount $targetAccount,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCollaborationRequest {
        $targetAccount->refresh();
        $this->assertTargetActor($collaboration, $targetAccount, $actor);

        return DB::transaction(function () use ($collaboration, $targetAccount, $actor, $ipAddress, $userAgent): SuchakCollaborationRequest {
            /** @var SuchakCollaborationRequest $locked */
            $locked = SuchakCollaborationRequest::query()
                ->whereKey($collaboration->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->loadMissing('commissionAgreement');
            $this->assertTargetActor($locked, $targetAccount, $actor);
            $this->assertPendingAndNotExpired($locked);

            SuchakCollaborationRequest::query()
                ->whereKey($locked->id)
                ->update([
                    'status' => SuchakCollaborationRequest::STATUS_REJECTED,
                    'responded_at' => now(),
                ]);

            if ($locked->commissionAgreement) {
                SuchakCommissionAgreement::query()
                    ->whereKey($locked->commissionAgreement->id)
                    ->update(['agreement_status' => SuchakCommissionAgreement::STATUS_REJECTED]);
            }

            $rejected = $locked->fresh(['commissionAgreement']);
            $this->recordActivity(
                SuchakActivityLog::ACTION_COLLABORATION_REQUEST_REJECTED,
                $rejected,
                $actor,
                $ipAddress,
                $userAgent,
                ['context' => 'collaboration_request_rejected'],
            );

            return $rejected;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateCommissionTerms(
        SuchakCollaborationRequest $collaboration,
        SuchakAccount $requestingAccount,
        User $actor,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCommissionAgreement {
        $requestingAccount->refresh();
        $this->assertRequestingActor($collaboration, $requestingAccount, $actor);
        $terms = $this->normalizeCommissionTerms($attributes);

        return DB::transaction(function () use ($collaboration, $requestingAccount, $actor, $terms, $ipAddress, $userAgent): SuchakCommissionAgreement {
            /** @var SuchakCollaborationRequest $locked */
            $locked = SuchakCollaborationRequest::query()
                ->whereKey($collaboration->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->loadMissing('commissionAgreement');
            $this->assertRequestingActor($locked, $requestingAccount, $actor);
            $this->assertPendingAndNotExpired($locked);

            $agreement = $locked->commissionAgreement ?? $this->createMissingAgreement($locked);
            $requesterAckColumn = (int) $requestingAccount->id === (int) $agreement->groom_side_suchak_account_id
                ? 'accepted_by_groom_suchak_at'
                : 'accepted_by_bride_suchak_at';

            $updates = [
                'split_type' => $terms['split_type'],
                'groom_side_share' => $terms['groom_side_share'],
                'bride_side_share' => $terms['bride_side_share'],
                'fixed_amount' => $terms['fixed_amount'],
                'currency' => $terms['currency'],
                'accepted_by_groom_suchak_at' => null,
                'accepted_by_bride_suchak_at' => null,
                'agreement_status' => SuchakCommissionAgreement::STATUS_PENDING,
            ];
            $updates[$requesterAckColumn] = now();

            SuchakCommissionAgreement::query()
                ->whereKey($agreement->id)
                ->update($updates);

            $updated = $agreement->fresh(['collaborationRequest']);
            $this->recordActivity(
                SuchakActivityLog::ACTION_COMMISSION_AGREEMENT_UPDATED,
                $locked->fresh(['commissionAgreement']),
                $actor,
                $ipAddress,
                $userAgent,
                [
                    'context' => 'commission_agreement_updated',
                    'split_type' => $updated->split_type,
                    'has_fixed_amount' => $updated->fixed_amount !== null,
                    'has_percent_split' => $updated->groom_side_share !== null || $updated->bride_side_share !== null,
                ],
            );

            return $updated;
        });
    }

    public function expireForAccount(
        SuchakCollaborationRequest $collaboration,
        SuchakAccount $account,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCollaborationRequest {
        $account->refresh();
        $this->assertParticipantActor($collaboration, $account, $actor);

        return DB::transaction(function () use ($collaboration, $account, $actor, $ipAddress, $userAgent): SuchakCollaborationRequest {
            /** @var SuchakCollaborationRequest $locked */
            $locked = SuchakCollaborationRequest::query()
                ->whereKey($collaboration->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->loadMissing('commissionAgreement');
            $this->assertParticipantActor($locked, $account, $actor);

            if ($locked->status !== SuchakCollaborationRequest::STATUS_PENDING) {
                throw new InvalidArgumentException('Only pending collaboration requests can be expired.');
            }

            if ($locked->expires_at === null || $locked->expires_at->isFuture()) {
                throw new InvalidArgumentException('Collaboration request is not past its policy timeout.');
            }

            return $this->expireLockedCollaboration($locked, $actor, $ipAddress, $userAgent);
        });
    }

    public function assertAcceptedParticipant(
        SuchakCollaborationRequest $collaboration,
        SuchakAccount $account,
        User $actor,
    ): void {
        $account->refresh();
        $collaboration->refresh()->loadMissing('commissionAgreement');
        $this->assertParticipantActor($collaboration, $account, $actor);

        if (! $this->canExchangeContact($collaboration)) {
            throw new InvalidArgumentException('Collaboration must be accepted with commission acknowledgement before ledger linkage.');
        }
    }

    public function expireIfPastDue(SuchakCollaborationRequest $collaboration): SuchakCollaborationRequest
    {
        $collaboration->refresh()->loadMissing('commissionAgreement');
        if ($collaboration->status !== SuchakCollaborationRequest::STATUS_PENDING || $collaboration->expires_at?->isFuture()) {
            return $collaboration;
        }

        return DB::transaction(function () use ($collaboration): SuchakCollaborationRequest {
            /** @var SuchakCollaborationRequest $locked */
            $locked = SuchakCollaborationRequest::query()
                ->whereKey($collaboration->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->loadMissing('commissionAgreement');

            if ($locked->status !== SuchakCollaborationRequest::STATUS_PENDING || $locked->expires_at?->isFuture()) {
                return $locked;
            }

            return $this->expireLockedCollaboration($locked, null, null, null);
        });
    }

    public function canExchangeContact(SuchakCollaborationRequest $collaboration): bool
    {
        $collaboration->loadMissing('commissionAgreement');

        return $collaboration->status === SuchakCollaborationRequest::STATUS_ACCEPTED
            && $collaboration->commissionAgreement?->isAcceptedByBothSides() === true;
    }

    private function assertCanCreate(
        SuchakAccount $requestingAccount,
        User $actor,
        SuchakProfileRepresentation $requestingRepresentation,
        SuchakProfileRepresentation $targetRepresentation,
    ): void {
        if ((int) $requestingAccount->user_id !== (int) $actor->id) {
            throw new InvalidArgumentException('Only the requesting Suchak account owner can create collaboration requests.');
        }

        if (! $this->accessService->canOperate($requestingAccount)) {
            throw new InvalidArgumentException('Only verified Suchak accounts can create collaboration requests.');
        }

        if ((int) $requestingRepresentation->suchak_account_id !== (int) $requestingAccount->id) {
            throw new InvalidArgumentException('Requesting representation must belong to the requesting Suchak account.');
        }

        if ((int) $targetRepresentation->suchak_account_id === (int) $requestingAccount->id) {
            throw new InvalidArgumentException('Cross-Suchak collaboration requires another Suchak account.');
        }

        if ((int) $requestingRepresentation->matrimony_profile_id === (int) $targetRepresentation->matrimony_profile_id) {
            throw new InvalidArgumentException('Collaboration requires two different candidate profiles.');
        }

        if (! $this->representationIsUsable($requestingRepresentation, requirePublicAccount: false)) {
            throw new InvalidArgumentException('Requesting representation must be active with valid consent.');
        }

        if (! $this->representationIsUsable($targetRepresentation, requirePublicAccount: true)) {
            throw new InvalidArgumentException('Target representation must be publicly routable.');
        }
    }

    private function assertTargetActor(
        SuchakCollaborationRequest $collaboration,
        SuchakAccount $targetAccount,
        User $actor,
    ): void {
        if ((int) $targetAccount->user_id !== (int) $actor->id) {
            throw new InvalidArgumentException('Only the target Suchak account owner can respond to collaboration requests.');
        }

        if ((int) $collaboration->target_suchak_account_id !== (int) $targetAccount->id) {
            throw new InvalidArgumentException('Collaboration request is not assigned to this Suchak account.');
        }

        if (! $this->accessService->canOperate($targetAccount)) {
            throw new InvalidArgumentException('Only verified Suchak accounts can respond to collaboration requests.');
        }
    }

    private function assertRequestingActor(
        SuchakCollaborationRequest $collaboration,
        SuchakAccount $requestingAccount,
        User $actor,
    ): void {
        if ((int) $requestingAccount->user_id !== (int) $actor->id) {
            throw new InvalidArgumentException('Only the requesting Suchak account owner can change commission terms.');
        }

        if ((int) $collaboration->requesting_suchak_account_id !== (int) $requestingAccount->id) {
            throw new InvalidArgumentException('Only the requesting Suchak account can change commission terms.');
        }

        if (! $this->accessService->canOperate($requestingAccount)) {
            throw new InvalidArgumentException('Only verified Suchak accounts can change commission terms.');
        }
    }

    private function assertParticipantActor(
        SuchakCollaborationRequest $collaboration,
        SuchakAccount $account,
        User $actor,
    ): void {
        if ((int) $account->user_id !== (int) $actor->id) {
            throw new InvalidArgumentException('Only a participating Suchak account owner can use this collaboration.');
        }

        if (! in_array((int) $account->id, [
            (int) $collaboration->requesting_suchak_account_id,
            (int) $collaboration->target_suchak_account_id,
        ], true)) {
            throw new InvalidArgumentException('Suchak account is not part of this collaboration.');
        }

        if (! $this->accessService->canOperate($account)) {
            throw new InvalidArgumentException('Only verified Suchak accounts can use collaboration actions.');
        }
    }

    private function assertPendingAndNotExpired(SuchakCollaborationRequest $collaboration): void
    {
        if ($collaboration->status !== SuchakCollaborationRequest::STATUS_PENDING) {
            throw new InvalidArgumentException('Only pending collaboration requests can be changed.');
        }

        if ($collaboration->expires_at !== null && $collaboration->expires_at->isPast()) {
            throw new InvalidArgumentException('Collaboration request has expired.');
        }
    }

    private function expireLockedCollaboration(
        SuchakCollaborationRequest $locked,
        ?User $actor,
        ?string $ipAddress,
        ?string $userAgent,
    ): SuchakCollaborationRequest {
        SuchakCollaborationRequest::query()
            ->whereKey($locked->id)
            ->update(['status' => SuchakCollaborationRequest::STATUS_EXPIRED]);

        if ($locked->commissionAgreement) {
            SuchakCommissionAgreement::query()
                ->whereKey($locked->commissionAgreement->id)
                ->update(['agreement_status' => SuchakCommissionAgreement::STATUS_CANCELLED]);
        }

        $expired = $locked->fresh(['commissionAgreement']);
        $this->recordActivity(
            SuchakActivityLog::ACTION_COLLABORATION_REQUEST_EXPIRED,
            $expired,
            $actor,
            $ipAddress,
            $userAgent,
            ['context' => 'collaboration_request_expired'],
        );

        return $expired;
    }

    private function assertNoDuplicateOpenRequest(
        SuchakProfileRepresentation $requestingRepresentation,
        SuchakProfileRepresentation $targetRepresentation,
    ): void {
        $duplicate = SuchakCollaborationRequest::query()
            ->where('requesting_suchak_account_id', $requestingRepresentation->suchak_account_id)
            ->where('target_suchak_account_id', $targetRepresentation->suchak_account_id)
            ->where('requesting_matrimony_profile_id', $requestingRepresentation->matrimony_profile_id)
            ->where('target_matrimony_profile_id', $targetRepresentation->matrimony_profile_id)
            ->whereIn('status', SuchakCollaborationRequest::OPEN_STATUSES)
            ->exists();

        if ($duplicate) {
            throw new InvalidArgumentException('An open collaboration request already exists for this Suchak/profile pair.');
        }
    }

    private function representationIsUsable(SuchakProfileRepresentation $representation, bool $requirePublicAccount): bool
    {
        $profile = $representation->matrimonyProfile;
        if (! $profile instanceof MatrimonyProfile
            || ($profile->lifecycle_state ?? null) !== 'active'
            || (bool) ($profile->is_suspended ?? false) === true) {
            return false;
        }

        if ($representation->representation_status !== SuchakProfileRepresentation::STATUS_ACTIVE || ! $representation->hasValidConsent()) {
            return false;
        }

        if ($requirePublicAccount) {
            return $this->accessService->canPubliclyRoute($representation->suchakAccount);
        }

        return $this->accessService->canOperate($representation->suchakAccount);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function agreementSideAccountIds(
        SuchakProfileRepresentation $requestingRepresentation,
        SuchakProfileRepresentation $targetRepresentation,
    ): array {
        $requestingGender = $requestingRepresentation->matrimonyProfile?->gender?->key;
        $targetGender = $targetRepresentation->matrimonyProfile?->gender?->key;

        if ($requestingGender === 'female' && $targetGender === 'male') {
            return [
                (int) $targetRepresentation->suchak_account_id,
                (int) $requestingRepresentation->suchak_account_id,
            ];
        }

        return [
            (int) $requestingRepresentation->suchak_account_id,
            (int) $targetRepresentation->suchak_account_id,
        ];
    }

    private function createMissingAgreement(SuchakCollaborationRequest $collaboration): SuchakCommissionAgreement
    {
        return SuchakCommissionAgreement::query()->create([
            'collaboration_request_id' => $collaboration->id,
            'groom_side_suchak_account_id' => $collaboration->requesting_suchak_account_id,
            'bride_side_suchak_account_id' => $collaboration->target_suchak_account_id,
            'agreement_type' => SuchakCommissionAgreement::TYPE_COLLABORATION_ACK,
            'split_type' => SuchakCommissionAgreement::SPLIT_TO_BE_DISCUSSED,
            'currency' => 'INR',
            'agreement_text_snapshot' => SuchakCommissionAgreement::MVP_ACK_TEXT,
            'agreement_status' => SuchakCommissionAgreement::STATUS_PENDING,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{split_type: string, groom_side_share: ?string, bride_side_share: ?string, fixed_amount: ?string, currency: string}
     */
    private function normalizeCommissionTerms(array $attributes): array
    {
        $splitType = trim((string) ($attributes['split_type'] ?? SuchakCommissionAgreement::SPLIT_TO_BE_DISCUSSED));
        if (! in_array($splitType, SuchakCommissionAgreement::SPLIT_TYPES, true)) {
            throw new InvalidArgumentException('Invalid Suchak commission split type.');
        }

        $currency = strtoupper(trim((string) ($attributes['currency'] ?? 'INR')));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Commission currency must be a three-letter code.');
        }

        if ($splitType === SuchakCommissionAgreement::SPLIT_EQUAL_PERCENT) {
            return [
                'split_type' => $splitType,
                'groom_side_share' => '50.00',
                'bride_side_share' => '50.00',
                'fixed_amount' => null,
                'currency' => $currency,
            ];
        }

        if ($splitType === SuchakCommissionAgreement::SPLIT_CUSTOM_PERCENT) {
            $groomShare = $this->percentage($attributes['groom_side_share'] ?? null, 'Groom-side commission share is required.');
            $brideShare = $this->percentage($attributes['bride_side_share'] ?? null, 'Bride-side commission share is required.');
            if (abs(((float) $groomShare + (float) $brideShare) - 100.0) > 0.01) {
                throw new InvalidArgumentException('Suchak commission percentage split must total 100.');
            }

            return [
                'split_type' => $splitType,
                'groom_side_share' => $groomShare,
                'bride_side_share' => $brideShare,
                'fixed_amount' => null,
                'currency' => $currency,
            ];
        }

        if ($splitType === SuchakCommissionAgreement::SPLIT_FIXED_AMOUNT) {
            return [
                'split_type' => $splitType,
                'groom_side_share' => null,
                'bride_side_share' => null,
                'fixed_amount' => $this->positiveAmount($attributes['fixed_amount'] ?? null),
                'currency' => $currency,
            ];
        }

        return [
            'split_type' => SuchakCommissionAgreement::SPLIT_TO_BE_DISCUSSED,
            'groom_side_share' => null,
            'bride_side_share' => null,
            'fixed_amount' => null,
            'currency' => $currency,
        ];
    }

    private function percentage(mixed $value, string $message): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            throw new InvalidArgumentException($message);
        }

        $percent = (float) $value;
        if ($percent < 0 || $percent > 100) {
            throw new InvalidArgumentException('Suchak commission percentage must be between 0 and 100.');
        }

        return number_format($percent, 2, '.', '');
    }

    private function positiveAmount(mixed $value): string
    {
        if ($value === null || $value === '' || ! is_numeric($value) || (float) $value <= 0) {
            throw new InvalidArgumentException('Fixed commission amount must be greater than zero.');
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function acknowledgeAgreementForAccount(SuchakCommissionAgreement $agreement, int $accountId): void
    {
        $updates = [];
        if ((int) $agreement->groom_side_suchak_account_id === $accountId) {
            $updates['accepted_by_groom_suchak_at'] = $agreement->accepted_by_groom_suchak_at ?? now();
        } elseif ((int) $agreement->bride_side_suchak_account_id === $accountId) {
            $updates['accepted_by_bride_suchak_at'] = $agreement->accepted_by_bride_suchak_at ?? now();
        } else {
            throw new InvalidArgumentException('Suchak account is not part of this commission agreement.');
        }

        $groomAck = $updates['accepted_by_groom_suchak_at'] ?? $agreement->accepted_by_groom_suchak_at;
        $brideAck = $updates['accepted_by_bride_suchak_at'] ?? $agreement->accepted_by_bride_suchak_at;
        if ($groomAck !== null && $brideAck !== null) {
            $updates['agreement_status'] = SuchakCommissionAgreement::STATUS_ACCEPTED;
        }

        SuchakCommissionAgreement::query()
            ->whereKey($agreement->id)
            ->update($updates);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordActivity(
        string $actionType,
        SuchakCollaborationRequest $collaboration,
        ?User $actor,
        ?string $ipAddress,
        ?string $userAgent,
        array $metadata,
    ): void {
        $this->activityLogger->record([
            'suchak_account_id' => $collaboration->requesting_suchak_account_id,
            'actor_user_id' => $actor?->id,
            'actor_type' => $actor === null ? SuchakActivityLog::ACTOR_SYSTEM : SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => $actionType,
            'target_type' => 'suchak_collaboration_request',
            'target_id' => $collaboration->id,
            'matrimony_profile_id' => $collaboration->target_matrimony_profile_id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 512, ''),
            'metadata_json' => array_merge($metadata, [
                'requesting_suchak_account_id' => $collaboration->requesting_suchak_account_id,
                'target_suchak_account_id' => $collaboration->target_suchak_account_id,
                'requesting_matrimony_profile_id' => $collaboration->requesting_matrimony_profile_id,
                'target_matrimony_profile_id' => $collaboration->target_matrimony_profile_id,
                'status' => $collaboration->status,
                'expires_at' => $collaboration->expires_at?->toIso8601String(),
            ]),
        ]);
    }

    private function nullableLimitedString(mixed $value, int $limit): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === ''
            ? null
            : Str::limit($normalized, $limit, '');
    }
}
