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
    private const TIMEOUT_DAYS = 7;

    public function __construct(
        private readonly SuchakActivityLogger $activityLogger,
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

        return DB::transaction(function () use ($requestingAccount, $actor, $requestingRepresentation, $targetRepresentation, $attributes, $ipAddress, $userAgent): array {
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
            $this->assertCanCreate($requestingAccount, $actor, $lockedRequestingRepresentation, $lockedTargetRepresentation);
            $this->assertNoDuplicateOpenRequest($lockedRequestingRepresentation, $lockedTargetRepresentation);

            $requestedAt = now();
            $collaboration = SuchakCollaborationRequest::query()->create([
                'requesting_suchak_account_id' => $requestingAccount->id,
                'target_suchak_account_id' => $lockedTargetRepresentation->suchak_account_id,
                'requesting_matrimony_profile_id' => $lockedRequestingRepresentation->matrimony_profile_id,
                'target_matrimony_profile_id' => $lockedTargetRepresentation->matrimony_profile_id,
                'requesting_representation_id' => $lockedRequestingRepresentation->id,
                'target_representation_id' => $lockedTargetRepresentation->id,
                'status' => SuchakCollaborationRequest::STATUS_PENDING,
                'message' => $this->nullableLimitedString($attributes['message'] ?? null, 2000),
                'requested_at' => $requestedAt,
                'expires_at' => $requestedAt->copy()->addDays(self::TIMEOUT_DAYS),
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
                'split_type' => SuchakCommissionAgreement::SPLIT_TO_BE_DISCUSSED,
                'currency' => 'INR',
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
                null,
                null,
                null,
                ['context' => 'collaboration_request_expired'],
            );

            return $expired;
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

        if (! $requestingAccount->isVerified()) {
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

        if (! $targetAccount->isVerified()) {
            throw new InvalidArgumentException('Only verified Suchak accounts can respond to collaboration requests.');
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
            return $representation->suchakAccount?->isPubliclyVisible() === true;
        }

        return $representation->suchakAccount?->isVerified() === true;
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
