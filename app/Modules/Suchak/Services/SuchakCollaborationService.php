<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakCommissionAgreement;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerPortalLink;
use App\Models\SuchakFeatureSuspension;
use App\Models\SuchakMarketplaceChallenge;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakCollaborationService
{
    /**
     * Which gate an ACCOUNT behind a representation must clear (H2). Three values, because the two
     * directions of the same engagement ask two different questions:
     *
     *  - OPERATE           the requester on the DIRECT path. He is acting on his own book.
     *  - PUBLIC_ROUTE      the target on the DIRECT path. He is being approached cold, so
     *                      SuchakAccessService::canPubliclyRoute() (VERIFIED + PUBLIC_ACTIVE) is
     *                      right: a Suchak who has taken himself out of the public directory is not
     *                      inviting strangers.
     *  - MARKETPLACE_BADGE both sides on the MARKETPLACE path (D18 / A10 — "visible to verified
     *                      Suchaks only", "tie marketplace participation to the verification
     *                      badge"). Applied to the REQUESTER it closes the under-gate: unreversed,
     *                      the requester needed only canOperate(), which admits a PENDING account
     *                      while the policy allows work before admin approval — precisely A10's
     *                      cheap second account. Applied to the TARGET it replaces PUBLIC_ROUTE,
     *                      which is not merely stricter but WRONG here: publishing a challenge
     *                      requires the badge alone (SuchakMarketplaceChallengeService::
     *                      assertPublisher), so keeping PUBLIC_ACTIVE in the gate would leave
     *                      legitimately published challenges that nobody on earth could answer. It
     *                      is the same spelling of the badge that service uses, so the marketplace
     *                      has one rule and not two.
     */
    private const ACCOUNT_GATE_OPERATE = 'operate';

    private const ACCOUNT_GATE_PUBLIC_ROUTE = 'public_route';

    private const ACCOUNT_GATE_MARKETPLACE_BADGE = 'marketplace_badge';

    public function __construct(
        private readonly SuchakActivityLogger $activityLogger,
        private readonly SuchakAccessService $accessService,
        private readonly SuchakLimitService $limitService,
        private readonly SuchakCandidateMaskingService $maskingService,
        private readonly SuchakQualityControlService $qualityControlService,
        private readonly SuchakMatchFitService $matchFitService,
    ) {
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function suggestedOpportunities(SuchakAccount $account, int $limit = 6): Collection
    {
        $account->refresh();
        if (! $this->accessService->canOperate($account)) {
            return collect();
        }

        $ownRepresentations = SuchakProfileRepresentation::query()
            ->with([
                'matrimonyProfile.gender',
                'matrimonyProfile.maritalStatus',
                'matrimonyProfile.religion',
                'matrimonyProfile.caste',
                'matrimonyProfile.visibilitySetting',
                'matrimonyProfile.location.parent.parent.parent',
                'matrimonyProfile.occupationMaster',
            ])
            ->where('suchak_account_id', $account->id)
            ->withValidConsent()
            ->whereHas('matrimonyProfile', fn (Builder $query) => $this->activeProfileQuery($query))
            ->orderBy('id')
            ->get();

        if ($ownRepresentations->isEmpty()) {
            return collect();
        }

        return SuchakProfileRepresentation::query()
            ->with([
                'suchakAccount.user',
                'matrimonyProfile.gender',
                'matrimonyProfile.maritalStatus',
                'matrimonyProfile.religion',
                'matrimonyProfile.caste',
                'matrimonyProfile.visibilitySetting',
                'matrimonyProfile.location.parent.parent.parent',
                'matrimonyProfile.occupationMaster',
            ])
            ->publiclyRoutable()
            ->where('suchak_account_id', '!=', $account->id)
            ->whereHas('matrimonyProfile', fn (Builder $query) => $this->activeProfileQuery($query))
            ->orderBy('id')
            ->limit(max($limit * 10, 30))
            ->get()
            ->map(function (SuchakProfileRepresentation $candidate) use ($account, $ownRepresentations): ?array {
                // Real engine score (SuchakMatchFitService -> MatchingService), not a caste/district guess.
                $match = $this->matchFitService->bestFitAmong($ownRepresentations, $candidate);
                if ($match === null) {
                    return null;
                }

                /** @var SuchakProfileRepresentation $ownRepresentation */
                $ownRepresentation = $match['own_representation'];
                if ($this->hasOpenCollaborationPair($ownRepresentation, $candidate)) {
                    return null;
                }

                if (! $ownRepresentation->matrimonyProfile instanceof MatrimonyProfile
                    || ! $candidate->matrimonyProfile instanceof MatrimonyProfile) {
                    return null;
                }

                $ownSummary = $this->maskingService->maskedSummary($ownRepresentation->matrimonyProfile, $ownRepresentation);
                $targetSummary = $this->maskingService->maskedSummary($candidate->matrimonyProfile, $candidate);
                $targetSuchakName = trim((string) ($candidate->suchakAccount?->suchak_name ?: 'Public Suchak'));
                $targetSuchakLabel = '#'.$candidate->suchak_account_id.' '.Str::limit($targetSuchakName, 80, '');

                return [
                    'requesting_representation_id' => (int) $ownRepresentation->id,
                    'target_representation_id' => (int) $candidate->id,
                    'requesting_candidate_reference' => $ownSummary['candidate_reference'] ?? null,
                    'target_candidate_reference' => $targetSummary['candidate_reference'] ?? null,
                    'requesting_summary' => $ownSummary,
                    'target_summary' => $targetSummary,
                    'reasons' => $match['reasons'],
                    'warnings' => $match['warnings'],
                    'fit_label' => $match['fit_label'],
                    'fit_summary' => $match['fit_summary'],
                    'reason' => $match['reason'],
                    'match_score' => $match['match_score'],
                    'match_base_score' => $match['match_base_score'],
                    'match_field_points' => $match['match_field_points'],
                    'target_suchak_label' => $targetSuchakLabel,
                    'collector_label' => $targetSuchakLabel,
                    'split_type' => SuchakCommissionAgreement::SPLIT_TO_BE_DISCUSSED,
                    'currency' => 'INR',
                ];
            })
            ->filter()
            ->sortByDesc(fn (array $row): int => (int) ($row['match_score'] ?? 0))
            ->values()
            ->take($limit);
    }

    /**
     * Create the ENGAGEMENT: a collaboration request plus its commission agreement, 1:1 (blueprint
     * 6.1). Two directions come through here and they are not symmetrical.
     *
     * DIRECT COLLABORATION (`$challenge === null`, the original path). The requester holds the
     * customer and reaches out to another Suchak's candidate. He types the commission terms, and
     * the target must be publicly routable because he is being approached cold.
     *
     * MARKETPLACE PROPOSAL (`$challenge` supplied, blueprint D7 / section 5.2's direction note).
     * The SAME pair, REVERSED: *"the Suchak answering a challenge becomes the requester — their
     * candidate is `requestingRepresentation`, the challenge's candidate is
     * `targetRepresentation`."* Four things the original direction hard-wired therefore change
     * meaning, and each is handled where it is wired rather than by a second copy of this method:
     *
     *  H1 `collector_suchak_account_id` is pinned to the TARGET side. Reversed, the target IS the
     *     publisher, who owns the customer, the customer agreement and the collection — so M1
     *     ("each customer pays only their own Suchak") lands correctly. It is verified rather than
     *     assumed: the same row now also names the customer-owning side explicitly, and a test pins
     *     collector == customerOwnerSuchakAccountId() in the reversed direction.
     *  H2 the account gate was TARGET-must-be-publicly-routable, REQUESTER-need-only-operate. In
     *     the marketplace that gates the publisher on a public-directory flag he never needed to
     *     publish, while letting an UNVERIFIED helper propose — the exact inverse of D18/A10, which
     *     tie marketplace participation to the verification badge on both sides. Both sides are
     *     therefore gated on the badge here, and the direct path keeps its original two gates
     *     untouched.
     *  H3 the open-request quota counts `requesting_suchak_account_id`. Reversed, the HELPER pays
     *     for each proposal and the publisher pays for none — which is DELIBERATELY LEFT ALONE:
     *     the quota's meaning is "work you initiated against other Suchaks", proposing is exactly
     *     that, and capping the receiving side would be D14's forbidden block ("may rank
     *     suggestions but may not block them") wearing an entitlement's clothes.
     *  H5 `updateCommissionTerms()` is requester-only. Reversed, that is the HELPER — the one party
     *     D4 says may never move the split. It is refused outright on a marketplace engagement; see
     *     that method.
     *
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
        ?SuchakMarketplaceChallenge $challenge = null,
    ): array {
        $requestingAccount->refresh();
        $requestingRepresentation->refresh()->loadMissing(['suchakAccount', 'matrimonyProfile.gender']);
        $targetRepresentation->refresh()->loadMissing(['suchakAccount', 'matrimonyProfile.gender']);

        $this->assertCanCreate($requestingAccount, $actor, $requestingRepresentation, $targetRepresentation, $challenge !== null);
        $this->qualityControlService->assertFeatureAvailable($requestingAccount, SuchakFeatureSuspension::FEATURE_COLLABORATION);
        $this->qualityControlService->assertFeatureAvailable($targetRepresentation->suchakAccount, SuchakFeatureSuspension::FEATURE_COLLABORATION);
        $this->limitService->assertCollaborationRequestAllowed($requestingAccount);

        return DB::transaction(function () use ($requestingAccount, $actor, $requestingRepresentation, $targetRepresentation, $attributes, $ipAddress, $userAgent, $challenge): array {
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
            $this->assertCanCreate($lockedRequestingAccount, $actor, $lockedRequestingRepresentation, $lockedTargetRepresentation, $challenge !== null);
            $this->qualityControlService->assertFeatureAvailable($lockedRequestingAccount, SuchakFeatureSuspension::FEATURE_COLLABORATION);
            $this->qualityControlService->assertFeatureAvailable($lockedTargetRepresentation->suchakAccount, SuchakFeatureSuspension::FEATURE_COLLABORATION);
            $this->limitService->assertCollaborationRequestAllowed($lockedRequestingAccount);
            $this->assertNoDuplicateOpenRequest($lockedRequestingRepresentation, $lockedTargetRepresentation, $challenge !== null);

            $requestedAt = now();
            $collaboration = SuchakCollaborationRequest::query()->create([
                'requesting_suchak_account_id' => $lockedRequestingAccount->id,
                'target_suchak_account_id' => $lockedTargetRepresentation->suchak_account_id,
                'requesting_matrimony_profile_id' => $lockedRequestingRepresentation->matrimony_profile_id,
                'target_matrimony_profile_id' => $lockedTargetRepresentation->matrimony_profile_id,
                'requesting_representation_id' => $lockedRequestingRepresentation->id,
                'target_representation_id' => $lockedTargetRepresentation->id,
                'marketplace_challenge_id' => $challenge?->id,
                'status' => SuchakCollaborationRequest::STATUS_PENDING,
                'message' => $this->nullableLimitedString($attributes['message'] ?? null, 2000),
                'requested_at' => $requestedAt,
                'expires_at' => $requestedAt->copy()->addDays($this->limitService->collaborationSlaDays()),
            ]);

            [$groomAccountId, $brideAccountId] = $this->agreementSideAccountIds(
                (int) $collaboration->requesting_suchak_account_id,
                (int) $collaboration->target_suchak_account_id,
                $lockedRequestingRepresentation,
                $lockedTargetRepresentation,
            );
            $requesterAckColumn = (int) $requestingAccount->id === $groomAccountId
                ? 'accepted_by_groom_suchak_at'
                : 'accepted_by_bride_suchak_at';

            // The direct path's terms are the requester's own words. The marketplace's are the
            // CHALLENGE's (D4 — declared upfront, not negotiable), and they can only be computed
            // once the groom/bride sides above are known: a declared share is one-directional
            // ("what I pay whoever brings the match") while a commission agreement stores two
            // shares named by SIDE, not by role.
            $commissionTerms = $challenge === null
                ? $this->normalizeCommissionTerms($attributes)
                : $this->challengeCommissionTerms($challenge, (int) $lockedRequestingAccount->id, $groomAccountId);

            $agreement = SuchakCommissionAgreement::query()->create([
                'collaboration_request_id' => $collaboration->id,
                'groom_side_suchak_account_id' => $groomAccountId,
                'bride_side_suchak_account_id' => $brideAccountId,
                'collector_suchak_account_id' => $lockedTargetRepresentation->suchak_account_id,
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

            if ($challenge !== null) {
                /*
                 * The role is a RECORDED FACT from the first row, not the `customer_owner_side`
                 * column default — which is exactly the forgery 00e92f98 refused to hang a money
                 * rule on. Here it is not a claim by either party: the challenge belongs to the
                 * publisher, names his own accepted agreement revision, and only he could have
                 * published it. The publisher is the TARGET in this reversed direction.
                 *
                 * Freezing the revision here is also what stops the HELPER from later calling
                 * linkCustomerAgreement() with an agreement of his own and appointing himself the
                 * customer-owning side: that method is write-once and this is the write.
                 *
                 * The role being a recorded fact still does not make the CANDIDATE one. The
                 * challenge's agreement is resolved from `representation->customerContext`, a
                 * hasOne on `representation_id` — which does not itself prove that context's
                 * `candidate_matrimony_profile_id` is the representation's profile. Same check as
                 * the direct path, for the same reason: a binding written wrong here is inherited
                 * by the clause, the attribution and every tranche after it.
                 */
                $challenge->loadMissing('customerAgreement');
                $challengeAgreement = $challenge->customerAgreement;

                if (! $challengeAgreement instanceof SuchakCustomerAgreement) {
                    throw new InvalidArgumentException('या आव्हानाला ग्राहकाचा करार जोडलेला नाही.');
                }

                $this->assertCustomerCandidateSitsOnSide(
                    $collaboration,
                    $challengeAgreement,
                    SuchakCollaborationRequest::SIDE_TARGET,
                );

                $this->bindCustomerAgreement(
                    $collaboration,
                    $agreement,
                    (int) $challenge->customer_agreement_id,
                    SuchakCollaborationRequest::SIDE_TARGET,
                );
                $collaboration = $collaboration->fresh() ?? $collaboration;
                $agreement = $agreement->fresh() ?? $agreement;
            }

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

    /**
     * The publisher (or, on the direct path, the approached Suchak) turns a pending request into a
     * live engagement. This is the moment the declared share becomes an obligation, so two guards
     * that do not apply to rejectRequest() apply here.
     *
     * assertMarketplaceEngagementBadge() — D18/A10 at the moment the obligation FORMS, not only at
     * the moment it was offered. assertChallengeStillUnanswered() — at most one accepted proposal
     * per challenge, which is M1.
     *
     * Neither is copied onto rejectRequest(), and that is a decision rather than an omission:
     * saying NO neither reveals a candidate nor creates a rupee of obligation, and a publisher who
     * could not reject would leave every rival helper's quota (H3) burned on a proposal nobody can
     * answer until the SLA expires it. The badge gates participation; it does not gate withdrawal
     * from participation.
     */
    public function acceptRequest(
        SuchakCollaborationRequest $collaboration,
        SuchakAccount $targetAccount,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCollaborationRequest {
        $targetAccount->refresh();
        $this->assertTargetActor($collaboration, $targetAccount, $actor);
        $this->assertMarketplaceEngagementBadge($collaboration, $targetAccount);
        $this->qualityControlService->assertFeatureAvailable($targetAccount, SuchakFeatureSuspension::FEATURE_COLLABORATION);

        return DB::transaction(function () use ($collaboration, $targetAccount, $actor, $ipAddress, $userAgent): SuchakCollaborationRequest {
            /** @var SuchakCollaborationRequest $locked */
            $locked = SuchakCollaborationRequest::query()
                ->whereKey($collaboration->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->loadMissing('commissionAgreement');
            $this->assertTargetActor($locked, $targetAccount, $actor);
            $this->assertMarketplaceEngagementBadge($locked, $targetAccount);
            $this->qualityControlService->assertFeatureAvailable($targetAccount, SuchakFeatureSuspension::FEATURE_COLLABORATION);
            $this->assertPendingAndNotExpired($locked);
            $this->assertChallengeStillUnanswered($locked);

            SuchakCollaborationRequest::query()
                ->whereKey($locked->id)
                ->update([
                    'status' => SuchakCollaborationRequest::STATUS_ACCEPTED,
                    'responded_at' => now(),
                ]);

            $agreement = $locked->commissionAgreement ?? $this->createMissingAgreement($locked);
            $this->acknowledgeAgreementForAccount($agreement, (int) $targetAccount->id);
            $this->fulfilAnsweredChallenge($locked);

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
     * Re-quote the split on a DIRECT collaboration. Requester-only, and terminally closed to the
     * marketplace (H5).
     *
     * The direction reversal is the whole reason: in the marketplace the requester is the HELPER,
     * so this method — reachable today at POST suchak/collaborations/{id}/commission — would have
     * handed the split to the one party D4 forbids from touching it. *"The challenge declares the
     * share the declarer will pay a helper, upfront. Accepting the challenge = accepting that
     * share. No negotiation afterwards."* A8 depends on the same immovability: a share that could
     * be edited after a candidate was suggested under it is a share that was never declared.
     *
     * The publisher cannot move it either, and does not need a separate refusal: he is the TARGET,
     * and assertRequestingActor() already refuses every actor but the requester. A marketplace
     * split is not re-quoted by anybody — it is republished as a new challenge, at which point the
     * candidates already suggested keep the old one.
     *
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
        $this->assertCommissionTermsAreNegotiable($collaboration);
        $this->qualityControlService->assertFeatureAvailable($requestingAccount, SuchakFeatureSuspension::FEATURE_COLLABORATION);
        $terms = $this->normalizeCommissionTerms($attributes);

        return DB::transaction(function () use ($collaboration, $requestingAccount, $actor, $terms, $ipAddress, $userAgent): SuchakCommissionAgreement {
            /** @var SuchakCollaborationRequest $locked */
            $locked = SuchakCollaborationRequest::query()
                ->whereKey($collaboration->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->loadMissing('commissionAgreement');
            $this->assertRequestingActor($locked, $requestingAccount, $actor);
            $this->assertCommissionTermsAreNegotiable($locked);
            $this->qualityControlService->assertFeatureAvailable($requestingAccount, SuchakFeatureSuspension::FEATURE_COLLABORATION);
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

    public function assertCanRecordCollaborationIncome(
        SuchakCollaborationRequest $collaboration,
        SuchakAccount $account,
        User $actor,
        string $paymentCollector,
    ): SuchakCommissionAgreement {
        $account->refresh();
        $collaboration->refresh()->loadMissing('commissionAgreement');
        $this->assertAcceptedParticipant($collaboration, $account, $actor);

        $agreement = $collaboration->commissionAgreement ?? $this->createMissingAgreement($collaboration);
        $collectorAccountId = $this->collectorAccountId($collaboration, $agreement);
        if ($agreement->collector_suchak_account_id === null) {
            SuchakCommissionAgreement::query()
                ->whereKey($agreement->id)
                ->update(['collector_suchak_account_id' => $collectorAccountId]);
            $agreement->refresh();
        }

        if ($paymentCollector !== SuchakPaymentContext::COLLECTOR_SUCHAK) {
            throw new InvalidArgumentException('Collaboration income must use the locked Suchak collector.');
        }

        if ((int) $account->id !== $collectorAccountId) {
            throw new InvalidArgumentException('Only the locked collector Suchak can record collaboration income for this request.');
        }

        $hasNonCollectorContext = SuchakPaymentContext::query()
            ->where('collaboration_request_id', $collaboration->id)
            ->where('source_owner', SuchakPaymentContext::SOURCE_COLLABORATION)
            ->where('payment_collector', SuchakPaymentContext::COLLECTOR_SUCHAK)
            ->where('context_status', SuchakPaymentContext::STATUS_ACTIVE)
            ->where('suchak_account_id', '<>', $collectorAccountId)
            ->exists();

        if ($hasNonCollectorContext) {
            throw new InvalidArgumentException('Collaboration income collector is already locked to another Suchak account.');
        }

        return $agreement->fresh(['collectorSuchakAccount', 'collaborationRequest']) ?? $agreement;
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

    /**
     * Name the customer-owning side of the engagement and freeze the customer agreement REVISION in
     * force (blueprint 6.1). The role is derived from whose agreement it is — it is never typed —
     * and the revision link is written once so a share stays claimable against the terms that applied.
     *
     * WHOSE AGREEMENT it is was the only question this asked, and it is not enough. An agreement
     * belonging to this Suchak, on an engagement this Suchak is a party to, could still be about a
     * completely different candidate — and every downstream fact reads the binding rather than
     * re-deriving it, so the clause (D11), the share attribution (6.2) and the tranche release
     * (D25) would all inherit the mismatch. assertCustomerCandidateSitsOnSide() closes it here too,
     * against the side this call is about to write, so the door is never handed a bad row to catch.
     */
    public function linkCustomerAgreement(
        SuchakCollaborationRequest $collaboration,
        SuchakAccount $account,
        User $actor,
        SuchakCustomerAgreement $customerAgreement,
    ): SuchakCollaborationRequest {
        $account->refresh();
        $collaboration->refresh()->loadMissing('commissionAgreement');
        $this->assertParticipantActor($collaboration, $account, $actor);

        if ((int) $customerAgreement->suchak_account_id !== (int) $account->id) {
            throw new InvalidArgumentException('Customer agreement belongs to another Suchak account.');
        }

        $ownerSide = $collaboration->sideForAccount((int) $account->id);
        if ($ownerSide === null) {
            throw new InvalidArgumentException('Suchak account is not part of this collaboration.');
        }

        return DB::transaction(function () use ($collaboration, $customerAgreement, $ownerSide): SuchakCollaborationRequest {
            /** @var SuchakCollaborationRequest $locked */
            $locked = SuchakCollaborationRequest::query()
                ->whereKey($collaboration->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->loadMissing('commissionAgreement');

            $agreement = $locked->commissionAgreement ?? $this->createMissingAgreement($locked);
            $linkedAgreementId = $agreement->customer_agreement_id === null
                ? null
                : (int) $agreement->customer_agreement_id;

            if ($linkedAgreementId !== null && $linkedAgreementId !== (int) $customerAgreement->id) {
                throw new InvalidArgumentException('This engagement is already bound to another customer agreement revision.');
            }

            // Under the row lock, against the side about to be written — the pair columns are
            // immutable, but the check belongs beside the write so no future caller can reach
            // bindCustomerAgreement() past it.
            $this->assertCustomerCandidateSitsOnSide($locked, $customerAgreement, $ownerSide);

            $this->bindCustomerAgreement($locked, $agreement, (int) $customerAgreement->id, $ownerSide);

            return $locked->fresh(['commissionAgreement']);
        });
    }

    /**
     * The ONE writer of the two columns that together name the customer-owning side (blueprint 6.1):
     * `suchak_commission_agreements.customer_agreement_id` and
     * `suchak_collaboration_requests.customer_owner_side`.
     *
     * Two guarded entrances reach it and they answer different questions. linkCustomerAgreement()
     * serves the direct path, where the owning Suchak proves the role by supplying his own
     * agreement. createRequest() serves the marketplace, where the challenge already proved it
     * before the engagement existed. They must not drift: every role-scoped ladder rung
     * (assertStageClaimant) refuses to be written until BOTH columns say the same thing, so a path
     * that wrote one without the other would produce an engagement whose rungs nobody may claim.
     *
     * The agreement id is written once and never moved; the caller owns the write-once check,
     * because "already bound" is only an error when the caller is proposing a different revision.
     */
    private function bindCustomerAgreement(
        SuchakCollaborationRequest $collaboration,
        SuchakCommissionAgreement $agreement,
        int $customerAgreementId,
        string $ownerSide,
    ): void {
        if (! in_array($ownerSide, SuchakCollaborationRequest::SIDES, true)) {
            throw new InvalidArgumentException('Unknown collaboration side: '.$ownerSide.'.');
        }

        if ($agreement->customer_agreement_id === null) {
            SuchakCommissionAgreement::query()
                ->whereKey($agreement->id)
                ->update(['customer_agreement_id' => $customerAgreementId]);
        }

        SuchakCollaborationRequest::query()
            ->whereKey($collaboration->id)
            ->update(['customer_owner_side' => $ownerSide]);
    }

    /**
     * Record a marketplace ladder stage on an ENGAGEMENT (blueprint 6a). Either Suchak may raise
     * the claim (D26).
     *
     * Stages outside CONFIRMABLE_STAGES settle on the claim; the last three (marriage settled,
     * engagement, marriage) wait for confirmStage(). The 7-day silent-then-dispute timer is Phase 3.
     *
     * The acceptance gate is a POSITION on STAGE_LADDER, never a second hand-written list. Section
     * 6a runs `profile_suggested` -> `viewed` -> `interested` before acceptance, and a marketplace
     * proposal is created `pending`; requiring acceptance for all of them made D11's 12-month
     * clause — which binds at `viewed` — unrecordable at the exact moment it is supposed to bind.
     *
     * Being a PARTICIPANT is not enough. Section 6a names an actor per rung and A7 turns one of
     * them into a money rule, so the actor is checked too — see assertStageClaimant(). Without it
     * one Suchak walked meeting_scheduled -> meeting_completed -> meeting_confirmed ->
     * share_settled alone and ended at `share_settled` with no act by anyone else, which is the
     * whole evidentiary trail the realized-vs-declared ratio is computed from.
     */
    public function claimStage(
        SuchakCollaborationRequest $collaboration,
        SuchakAccount $account,
        User $actor,
        string $stageKey,
        ?string $note = null,
    ): SuchakCollaborationStageEvent {
        $account->refresh();
        $collaboration->refresh()->loadMissing('commissionAgreement');
        $this->assertParticipantActor($collaboration, $account, $actor);
        $this->assertLadderStage($stageKey);

        $this->assertEngagementStateSupportsStage($collaboration, $stageKey);
        $this->assertStageClaimant($collaboration, $account, $stageKey);

        return DB::transaction(function () use ($collaboration, $account, $actor, $stageKey, $note): SuchakCollaborationStageEvent {
            /** @var SuchakCollaborationRequest $locked */
            $locked = SuchakCollaborationRequest::query()
                ->whereKey($collaboration->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = SuchakCollaborationStageEvent::query()
                ->where('collaboration_request_id', $locked->id)
                ->where('stage_key', $stageKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw new InvalidArgumentException('This marketplace stage is already recorded for the engagement.');
            }

            $event = $this->writeStageEvent(
                [SuchakCollaborationStageEvent::OWNER_COLUMN_COLLABORATION_REQUEST => $locked->id],
                $account,
                $actor,
                $stageKey,
                $note,
            );

            if (! SuchakCollaborationStageEvent::requiresConfirmation($stageKey)) {
                $this->advanceMarketplaceStage($locked, $stageKey);
            }

            return $event;
        });
    }

    /**
     * THE CUSTOMER'S DOOR — the three rungs of blueprint 6a that the family alone can know:
     * `viewed` (स्थळ पाहिले), `interested` (पसंती दर्शवली) and `meeting_confirmed` (भेटीला दुजोरा).
     *
     * Until this existed, STAGE_CLAIMANTS named the customer as the claimant of all three and
     * assertStageClaimant() refused every Suchak, so ZERO rows could exist for any of them. D11
     * attaches the 12-month anti-circumvention clause at `viewed`, and its anchor —
     * `suchak_collaboration_stage_events.claimed_at` — was declared, indexed and unwritable. M4's
     * "no fee falls due without the customer's confirmation" had the same problem from the other
     * end: both member-side doors require `$request->user()`, and section 2 says the customer is the
     * FAMILY and often has no login.
     *
     * ── WHY THE CUSTOMER PORTAL LINK, AND NOT A FIFTH TOKEN ──────────────────────────────────
     *
     * Four tokenised customer links already exist, all Str::random(64) with only the sha256 stored.
     * Three of them cannot carry this: the agreement-acceptance token and the consent token are
     * SINGLE USE and die on the first decision, and a payment-request token is one money artifact.
     * These three rungs are three separate acts spread over weeks — a link that closes after one
     * use cannot hold them.
     *
     * `suchak_customer_portal_links` is the only one that survives more than one visit
     * (`opened_at`), records WHO in the family is holding it (`claimed_name`,
     * `claimed_relationship_to_candidate`), can be revoked, and already carries its own append-only
     * timeline. It is issued in production today alongside every payment request
     * (SuchakPaymentRequestService::createAndSend), so it is the family's EXISTING durable identity
     * with this Suchak. Binding to it rather than minting a parallel token is the no-duplicate rule
     * applied to a token shape.
     *
     * ── WHAT THIS RECORDS, AND WHAT IT DOES NOT PROVE (D23, section 8) ───────────────────────
     *
     * Recorded: that somebody holding this link recorded this rung, at this time, from this IP and
     * user agent (the last two on `suchak_activity_logs`, which already owns them), plus whatever
     * name and relationship that person typed when they claimed the link.
     *
     * NOT recorded, and deliberately not implied: that the person was the candidate, the payer, or
     * any particular family member. OTP does not exist on production (section 10 S4), so no
     * `mobile_match`, no `*_verified`, no acceptance tier is written here. Section 8 names
     * `recordPublicConsentDecision()` writing `mobile_match => true` unchecked as the one fiction
     * already in this codebase; it is not repeated.
     *
     * The link is NOT re-authorised here — the caller opens it through
     * SuchakCustomerPortalService::openPortalLink(), which owns "is this token live" (revoked,
     * expired, Suchak able to operate) and writes the open event. What is checked here is the only
     * question that service cannot answer: whether this link governs THIS engagement.
     */
    public function recordCustomerStage(
        SuchakCollaborationRequest $collaboration,
        SuchakCustomerPortalLink $portalLink,
        string $stageKey,
        ?string $note = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        bool $priorAcquaintance = false,
    ): SuchakCollaborationStageEvent {
        $collaboration->refresh()->loadMissing('commissionAgreement.customerAgreement');
        $portalLink->refresh();
        $this->assertLadderStage($stageKey);

        if (! SuchakCollaborationStageEvent::isCustomerClaimedStage($stageKey)) {
            throw new InvalidArgumentException(
                '"'.SuchakCollaborationStageEvent::stageLabel($stageKey).'" हा टप्पा सूचक नोंदवतो, ग्राहक नाही.'
            );
        }

        // 9a A6, refused before the lock rather than inside it: "we already know them" releases the
        // 12-month clause, so it only means anything on the rung that creates it. The model repeats
        // this on `saving` because it is an invariant of the row, not of this path.
        if ($priorAcquaintance && $stageKey !== SuchakCollaborationStageEvent::CLAUSE_ANCHOR_STAGE) {
            throw new InvalidArgumentException(
                '"आम्ही या कुटुंबाला आधीपासून ओळखतो" ही नोंद फक्त "'
                .SuchakCollaborationStageEvent::stageLabel(SuchakCollaborationStageEvent::CLAUSE_ANCHOR_STAGE)
                .'" या टप्प्यावर करता येते.'
            );
        }

        $this->assertEngagementStateSupportsStage($collaboration, $stageKey);
        $customerAgreement = $this->assertPortalLinkGovernsEngagement($collaboration, $portalLink);
        $this->assertCustomerTermsSupportStage($customerAgreement, $stageKey);

        $event = DB::transaction(function () use ($collaboration, $portalLink, $stageKey, $note, $priorAcquaintance): SuchakCollaborationStageEvent {
            /** @var SuchakCollaborationRequest $locked */
            $locked = SuchakCollaborationRequest::query()
                ->whereKey($collaboration->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = SuchakCollaborationStageEvent::query()
                ->where('collaboration_request_id', $locked->id)
                ->where('stage_key', $stageKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw new InvalidArgumentException(
                    '"'.SuchakCollaborationStageEvent::stageLabel($stageKey).'" ही नोंद आधीच झाली आहे.'
                );
            }

            $written = $this->writeStageEvent(
                [SuchakCollaborationStageEvent::OWNER_COLUMN_COLLABORATION_REQUEST => $locked->id],
                null,
                null,
                $stageKey,
                $note,
                $portalLink,
                $priorAcquaintance,
            );

            // None of the three is confirmable (CONFIRMABLE_STAGES are the terminal rungs), so the
            // customer's act settles the rung and moves the ladder on its own.
            $this->advanceMarketplaceStage($locked, $stageKey);

            return $written;
        });

        $this->recordCustomerStageActivity($collaboration, $portalLink, $event, $ipAddress, $userAgent);

        return $event;
    }

    /**
     * The engagements a customer portal link may record a rung against, newest first.
     *
     * The join is the only one that exists and it is deliberately narrow: the link names a customer
     * context; a customer agreement revision names the same context; the engagement's commission
     * agreement names that agreement revision (blueprint 6.1 — the engagement IS
     * SuchakCollaborationRequest + SuchakCommissionAgreement). A proposal nobody linked to a
     * customer agreement is invisible here, which is correct — without that link there is no
     * recorded fact saying whose customer this is, and A2's forged-obligation attack is exactly
     * someone asserting that link informally.
     *
     * @return Collection<int, SuchakCollaborationRequest>
     */
    public function customerEngagementsForPortalLink(SuchakCustomerPortalLink $portalLink): Collection
    {
        if ($portalLink->customer_context_id === null) {
            return collect();
        }

        return SuchakCollaborationRequest::query()
            ->whereIn('status', SuchakCollaborationRequest::OPEN_STATUSES)
            ->whereHas(
                'commissionAgreement.customerAgreement',
                fn (Builder $query) => $query
                    ->where('customer_context_id', $portalLink->customer_context_id)
                    ->where('suchak_account_id', $portalLink->suchak_account_id),
            )
            ->with([
                'commissionAgreement.customerAgreement',
                'requestingMatrimonyProfile',
                'targetMatrimonyProfile',
                'stageEvents',
            ])
            ->orderByDesc('id')
            ->get()
            ->filter(fn (SuchakCollaborationRequest $collaboration): bool => $collaboration->isCustomerOwner(
                (int) $portalLink->suchak_account_id,
            ))
            ->values();
    }

    /**
     * Where the engagement must stand for a rung to be recordable at all — the same gate for the
     * Suchak path and the customer path, because it is a fact about the ENGAGEMENT and not about
     * who is asking.
     *
     * The acceptance line is a POSITION on STAGE_LADDER, never a second hand-written list. Section
     * 6a runs `profile_suggested` -> `viewed` -> `interested` BEFORE acceptance, and a marketplace
     * proposal is created `pending`; requiring acceptance for those made D11's clause — which binds
     * at `viewed` — unrecordable at the exact moment it is supposed to bind.
     */
    private function assertEngagementStateSupportsStage(
        SuchakCollaborationRequest $collaboration,
        string $stageKey,
    ): void {
        if (SuchakCollaborationStageEvent::isPreEngagementStage($stageKey)) {
            throw new InvalidArgumentException(
                'Marketplace stage "'.$stageKey.'" happens before any engagement exists; record it on the customer agreement.'
            );
        }

        if (SuchakCollaborationStageEvent::requiresAcceptedEngagement($stageKey)) {
            if ($collaboration->status !== SuchakCollaborationRequest::STATUS_ACCEPTED) {
                throw new InvalidArgumentException(
                    'Marketplace stage "'.$stageKey.'" can only be recorded on an accepted collaboration.'
                );
            }

            return;
        }

        if (! $collaboration->isOpen()) {
            throw new InvalidArgumentException('Marketplace stages can only be recorded on an open collaboration.');
        }
    }

    /**
     * Does THIS link belong to the family this engagement is about?
     *
     * THREE conditions, and all three are needed. The link must have been issued by the Suchak the
     * engagement records as the CUSTOMER-OWNING side (role, never direction — in the marketplace the
     * responder is the requester); the customer agreement revision in force on the engagement must
     * name the same customer context the link names; and that context's OWN candidate must be the
     * profile sitting on the customer-owning side of this engagement. Without the first, a helper
     * could hand his own customer a link and record the other family's rungs. Without the second,
     * one Suchak's link would reach every engagement he has, including other families'.
     *
     * Without the THIRD, a Suchak could bind his own agreement to an engagement about two people
     * neither of whom is his customer's candidate, hand the family their existing portal link, and
     * their `viewed` tap would attach D11's twelve-month success fee to a stranger — the clause
     * working against the family it exists to protect, on the largest sum in the system. Ownership
     * and context both passed in that attack; only presence on the pair refuses it.
     *
     * Returns the agreement revision so the caller can apply the terms gate to it — no meeting,
     * tranche or share exists under terms nobody accepted.
     */
    private function assertPortalLinkGovernsEngagement(
        SuchakCollaborationRequest $collaboration,
        SuchakCustomerPortalLink $portalLink,
    ): SuchakCustomerAgreement {
        $collaboration->loadMissing('commissionAgreement.customerAgreement');
        $customerAgreement = $collaboration->commissionAgreement?->customerAgreement;

        if (! $customerAgreement instanceof SuchakCustomerAgreement) {
            throw new InvalidArgumentException(
                'या सहकार्यात ग्राहकाचा करार अजून जोडलेला नाही, त्यामुळे ही नोंद करता येणार नाही.'
            );
        }

        if (! $collaboration->isCustomerOwner((int) $portalLink->suchak_account_id)) {
            throw new InvalidArgumentException('ही लिंक या स्थळाच्या नोंदीसाठी वैध नाही.');
        }

        if ($portalLink->customer_context_id === null
            || (int) $customerAgreement->customer_context_id !== (int) $portalLink->customer_context_id) {
            throw new InvalidArgumentException('ही लिंक या स्थळाच्या नोंदीसाठी वैध नाही.');
        }

        // Read from the ENGAGEMENT's stored role label, not from the link — the link has already
        // been proved to name the same context, and re-deriving the side from the account id here
        // would answer the question with the very thing under test.
        $this->assertCustomerCandidateSitsOnSide(
            $collaboration,
            $customerAgreement,
            (string) $collaboration->customer_owner_side,
        );

        return $customerAgreement;
    }

    /**
     * THE THIRD QUESTION, asked at both ends: is the customer's own candidate actually the profile
     * on the side of this engagement that owns them?
     *
     * The identity chain, and every hop is a recorded fact rather than a position:
     *
     *   customer agreement revision → `customer_context_id`
     *     → customer context      → `candidate_matrimony_profile_id`   (the family's own candidate)
     *   engagement                 → `customer_owner_side`             (which slot holds them)
     *     → that slot's            → `*_matrimony_profile_id`          (the profile there)
     *
     * The two must be the same person. Nothing here infers "the other family" positionally —
     * SuchakTwelveMonthClauseService::proposedCandidate() used to, and that is precisely how a
     * candidate on neither side resolved to the requesting profile and got a clause bound to them.
     *
     * A context with no candidate is refused rather than waved through: "this customer has no
     * candidate on file" cannot establish that they are on this pair, and a clause may not bind on
     * an unanswered question.
     */
    private function assertCustomerCandidateSitsOnSide(
        SuchakCollaborationRequest $collaboration,
        SuchakCustomerAgreement $customerAgreement,
        string $ownerSide,
    ): void {
        $customerAgreement->loadMissing('customerContext');
        $ownCandidateId = $customerAgreement->customerContext?->candidate_matrimony_profile_id;

        if ($ownCandidateId === null) {
            throw new InvalidArgumentException(
                'या ग्राहकाचे स्थळ अजून नोंदवलेले नाही, त्यामुळे ही नोंद करता येणार नाही.'
            );
        }

        if ($collaboration->matrimonyProfileIdForSide($ownerSide) !== (int) $ownCandidateId) {
            throw new InvalidArgumentException(
                'या नोंदीत तुमच्या कुटुंबाचे स्थळ नाही, त्यामुळे ही नोंद करता येणार नाही.'
            );
        }
    }

    /**
     * The IP, the user agent and the link the family acted through — recorded on
     * `suchak_activity_logs`, which already owns those two columns for every Suchak-domain act.
     * They are NOT copied onto the stage event: one fact, one home.
     *
     * `actor_type = user` with `actor_user_id = NULL` is the honest shape. A family with no login
     * has no user id, and inventing one — or filing the act under the Suchak — would be the exact
     * forgery this door exists to avoid.
     */
    private function recordCustomerStageActivity(
        SuchakCollaborationRequest $collaboration,
        SuchakCustomerPortalLink $portalLink,
        SuchakCollaborationStageEvent $event,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $this->activityLogger->record([
            'suchak_account_id' => $portalLink->suchak_account_id,
            'actor_user_id' => null,
            'actor_type' => SuchakActivityLog::ACTOR_USER,
            'action_type' => SuchakActivityLog::ACTION_COLLABORATION_STAGE_CUSTOMER_RECORDED,
            'target_type' => 'suchak_collaboration_stage_event',
            'target_id' => $event->id,
            'matrimony_profile_id' => $collaboration->target_matrimony_profile_id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 512, ''),
            'metadata_json' => [
                'context' => 'collaboration_stage_customer_recorded',
                'stage_key' => $event->stage_key,
                // 9a A6. On the `viewed` rung this is the difference between a live 12-month clause
                // and no clause at all, so the act that set it belongs in the trail, not only in the
                // row it set.
                'prior_acquaintance_declared' => (bool) $event->prior_acquaintance_declared,
                'collaboration_request_id' => $collaboration->id,
                'customer_portal_link_id' => $portalLink->id,
                'customer_context_id' => $portalLink->customer_context_id,
                'portal_status' => $portalLink->portal_status,
                'portal_claimed_name' => $portalLink->claimed_name,
                'portal_claimed_relationship_to_candidate' => $portalLink->claimed_relationship_to_candidate,
                // Stated in the record itself so nobody reading it later mistakes a link for a
                // verified identity. OTP is Phase 6 (D23); until it lands this is what we have.
                'identity_verified' => false,
                'verification_channel' => 'none',
            ],
        ]);
    }

    /**
     * Record one of the four PRE-ENGAGEMENT ladder stages (registration, agreement proposed,
     * agreement accepted, published to marketplace) against the customer agreement revision they
     * happened under.
     *
     * These stages have no engagement to hang off — `published_to_marketplace` is the act that
     * invites a counterparty, so by definition none exists yet. Section 4 already named their
     * owner: *"Publication attaches to whichever agreement is accepted at that moment."*
     *
     * Only the customer-owning Suchak may record them; there is no second party yet to disagree,
     * and none of the four is confirmable (CONFIRMABLE_STAGES are all terminal). No ladder position
     * is advanced either, because `marketplace_stage` lives on an engagement and there is not one.
     *
     * Which is exactly why the AGREEMENT'S OWN STATE is the gate here, and the only one available:
     * with no counter-signature and no correction path, a rung that contradicts the object it
     * describes is a forged record written by the only party with an interest in it. Against a
     * declined agreement this path used to write `agreement_accepted` and `published_to_marketplace`
     * and return 201 both times. From FIRST_STAGE_REQUIRING_SATISFIED_TERMS onward the rung is only
     * recordable when SuchakCustomerAgreement::isTermsSatisfied() actually says so.
     */
    public function claimCustomerStage(
        SuchakCustomerAgreement $customerAgreement,
        SuchakAccount $account,
        User $actor,
        string $stageKey,
        ?string $note = null,
    ): SuchakCollaborationStageEvent {
        $account->refresh();
        $customerAgreement->refresh();
        $this->assertCustomerAgreementActor($customerAgreement, $account, $actor);
        $this->assertLadderStage($stageKey);

        if (! SuchakCollaborationStageEvent::isPreEngagementStage($stageKey)) {
            throw new InvalidArgumentException(
                'Marketplace stage "'.$stageKey.'" belongs to an engagement, not to the customer agreement.'
            );
        }

        // Totality, not a second rule: the agreement path can only ever serve the customer-owning
        // Suchak (assertCustomerAgreementActor already proved the agreement is his). If a rung is
        // ever re-assigned to another actor while still sitting before FIRST_ENGAGEMENT_STAGE, this
        // fails closed instead of quietly letting the wrong party write it.
        if (SuchakCollaborationStageEvent::claimantFor($stageKey) !== SuchakCollaborationStageEvent::CLAIMANT_CUSTOMER_OWNER) {
            throw new InvalidArgumentException(
                'Marketplace stage "'.$stageKey.'" is not the customer-owning Suchak\'s to record.'
            );
        }

        return DB::transaction(function () use ($customerAgreement, $account, $actor, $stageKey, $note): SuchakCollaborationStageEvent {
            /** @var SuchakCustomerAgreement $locked */
            $locked = SuchakCustomerAgreement::query()
                ->whereKey($customerAgreement->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Read under the lock: terms_status is the fact the rung asserts, so it must be the
            // value as of the write, not as of the request.
            $this->assertCustomerTermsSupportStage($locked, $stageKey);

            $existing = SuchakCollaborationStageEvent::query()
                ->where('customer_agreement_id', $locked->id)
                ->where('stage_key', $stageKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw new InvalidArgumentException('This marketplace stage is already recorded for the customer agreement.');
            }

            return $this->writeStageEvent(
                [SuchakCollaborationStageEvent::OWNER_COLUMN_CUSTOMER_AGREEMENT => $locked->id],
                $account,
                $actor,
                $stageKey,
                $note,
            );
        });
    }

    /**
     * Confirm a claimed terminal stage (D26). The customer confirms; an admin may confirm in their place.
     * Neither participating Suchak's own user may confirm their side's claim.
     */
    public function confirmStage(
        SuchakCollaborationRequest $collaboration,
        User $confirmingUser,
        string $stageKey,
        ?string $note = null,
    ): SuchakCollaborationStageEvent {
        $collaboration->refresh();
        $this->assertLadderStage($stageKey);

        if (! SuchakCollaborationStageEvent::requiresConfirmation($stageKey)) {
            throw new InvalidArgumentException('This marketplace stage does not carry a confirmation.');
        }

        $actorType = $this->confirmationActorType($collaboration, $confirmingUser);

        return DB::transaction(function () use ($collaboration, $confirmingUser, $stageKey, $note, $actorType): SuchakCollaborationStageEvent {
            /** @var SuchakCollaborationRequest $locked */
            $locked = SuchakCollaborationRequest::query()
                ->whereKey($collaboration->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var SuchakCollaborationStageEvent|null $event */
            $event = SuchakCollaborationStageEvent::query()
                ->where('collaboration_request_id', $locked->id)
                ->where('stage_key', $stageKey)
                ->lockForUpdate()
                ->first();

            if ($event === null) {
                throw new InvalidArgumentException('This marketplace stage has not been claimed yet.');
            }

            if ($event->confirmed_at !== null) {
                throw new InvalidArgumentException('This marketplace stage is already confirmed.');
            }

            SuchakCollaborationStageEvent::query()
                ->whereKey($event->id)
                ->update([
                    'confirmed_by_actor_type' => $actorType,
                    'confirmed_by_user_id' => $confirmingUser->id,
                    'confirmed_at' => now(),
                    'event_note' => $this->nullableLimitedString($note, 2000) ?? $event->event_note,
                    'updated_at' => now(),
                ]);

            $this->advanceMarketplaceStage($locked, $stageKey);

            return $event->fresh() ?? $event;
        });
    }

    private function assertCanCreate(
        SuchakAccount $requestingAccount,
        User $actor,
        SuchakProfileRepresentation $requestingRepresentation,
        SuchakProfileRepresentation $targetRepresentation,
        bool $marketplace = false,
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

        if (! $this->representationIsUsable($requestingRepresentation, $marketplace
            ? self::ACCOUNT_GATE_MARKETPLACE_BADGE
            : self::ACCOUNT_GATE_OPERATE)) {
            throw new InvalidArgumentException($marketplace
                ? 'बाजारपेठेत स्थळ सुचवण्यासाठी पडताळणी झालेले सूचक खाते आणि वैध संमती असलेले सक्रिय स्थळ आवश्यक आहे.'
                : 'Requesting representation must be active with valid consent.');
        }

        if (! $this->representationIsUsable($targetRepresentation, $marketplace
            ? self::ACCOUNT_GATE_MARKETPLACE_BADGE
            : self::ACCOUNT_GATE_PUBLIC_ROUTE)) {
            throw new InvalidArgumentException($marketplace
                ? 'हे आव्हान प्रसिद्ध करणाऱ्या सूचकाची पडताळणी किंवा स्थळाची संमती आता वैध नाही.'
                : 'Target representation must be publicly routable.');
        }
    }

    /**
     * H5's gate, and the reason it is a method rather than an inline `if`: it runs twice, once
     * before the transaction and once under the row lock, exactly like every other rule in this
     * service that decides whether a write may happen.
     */
    private function assertCommissionTermsAreNegotiable(SuchakCollaborationRequest $collaboration): void
    {
        if (! $collaboration->isMarketplaceProposal()) {
            return;
        }

        throw new InvalidArgumentException(
            'बाजारपेठेतील वाटा आव्हानात आधीच जाहीर झाला आहे आणि तो बदलता येत नाही. '
            .'वेगळा वाटा द्यायचा असेल तर नवीन आव्हान प्रसिद्ध करावे लागेल.'
        );
    }

    /**
     * Acceptance closes the challenge it answers (SuchakMarketplaceChallenge::STATUS_FULFILLED).
     *
     * That status shipped in 9a597d1b with NO WRITER, and its own docblock named this exact moment
     * as the only honest one: *"when a proposal made against this challenge is accepted, which is
     * accept-by-proposing — the next slice."*
     *
     * Written from here rather than from SuchakMarketplaceChallengeService because that service
     * already depends on this one (it calls claimCustomerStage() to record publication); calling
     * back the other way would be a dependency cycle. The lifecycle POLICY — publish, withdraw,
     * expire, browse — stays entirely over there. This is one transition that is caused by an act
     * this service owns and cannot be observed from anywhere else.
     *
     * Silent when the challenge is no longer open: the publisher may accept a proposal on a
     * challenge he has since withdrawn, and refusing that would strand a proposal he himself
     * invited. Other PENDING proposals against the same challenge are deliberately left pending —
     * they are the publisher's to reject or let expire, and mass-rejecting them here would answer
     * on his behalf. That reasoning still holds and is unchanged; what it never covered is a SECOND
     * ACCEPTANCE, which assertChallengeStillUnanswered() now refuses.
     */
    private function fulfilAnsweredChallenge(SuchakCollaborationRequest $collaboration): void
    {
        $challenge = $this->lockedChallengeAnswered($collaboration);

        if ($challenge === null || ! $challenge->isOpen()) {
            return;
        }

        $challenge->forceFill([
            'status' => SuchakMarketplaceChallenge::STATUS_FULFILLED,
            'fulfilled_at' => now(),
        ])->save();
    }

    /**
     * M1: ONE accepted proposal per challenge, ever.
     *
     * The proven money bug this closes. A publisher declares 30% once, on one challenge, against
     * one customer's single ₹1,00,000 success fee. He accepts proposal A — the challenge becomes
     * `fulfilled`. Nothing then stopped him accepting proposal B: a second engagement formed, a
     * second commission agreement was written at the same declared 30%, and one declared share was
     * owed twice. `assertChallengeAcceptsProposals()` guards the PROPOSE leg only; acceptance had
     * no guard at all, which is why the second acceptance returned 200.
     *
     * The predicate is the FACT — "another proposal answering this challenge is already accepted" —
     * and not the challenge's status. Status is not enough, and the gap is reachable: on a
     * challenge the publisher WITHDREW, fulfilAnsweredChallenge() stays silent by design, so the
     * row never reaches `fulfilled` and every proposal made before the withdrawal would still have
     * been acceptable, one after another. Reading the sibling rows asks the question the money
     * actually depends on.
     *
     * Evaluated under the CHALLENGE's row lock, not the collaboration's. Two concurrent accepts of
     * two different proposals lock two different collaboration rows and would not exclude each
     * other; the challenge is the one row they share. That is also why this check exists only
     * inside the transaction — a race is not an authorisation, and evaluating it outside the lock
     * would prove nothing.
     *
     * The other pending proposals are still left pending (see fulfilAnsweredChallenge()): they are
     * the publisher's to reject, and the refusal below says so rather than answering for him.
     */
    private function assertChallengeStillUnanswered(SuchakCollaborationRequest $collaboration): void
    {
        $challenge = $this->lockedChallengeAnswered($collaboration);

        if ($challenge === null) {
            return;
        }

        $rival = SuchakCollaborationRequest::query()
            ->where('marketplace_challenge_id', $challenge->id)
            ->whereKeyNot($collaboration->id)
            ->where('status', SuchakCollaborationRequest::STATUS_ACCEPTED)
            ->exists();

        if ($rival) {
            throw new InvalidArgumentException(
                'या आव्हानासाठी एक स्थळ आधीच स्वीकारले आहे, त्यामुळे दुसरे स्वीकारता येणार नाही. '
                .'जाहीर केलेला वाटा एकाच जोडणीसाठी असतो. उरलेल्या सुचवणी नाकारता येतील.'
            );
        }
    }

    /**
     * The challenge this engagement answers, locked, or NULL when it is not a marketplace
     * engagement at all.
     *
     * One reader for both the guard and the fulfilment write, so they can never end up looking at
     * different rows or locking on different terms. Read through the model's own
     * `marketplaceChallenge()` relation rather than a hand-built query on the FK — the relation is
     * the declared owner of that join and had no caller until this method.
     */
    private function lockedChallengeAnswered(SuchakCollaborationRequest $collaboration): ?SuchakMarketplaceChallenge
    {
        if (! $collaboration->isMarketplaceProposal()) {
            return null;
        }

        /** @var SuchakMarketplaceChallenge|null $challenge */
        $challenge = $collaboration->marketplaceChallenge()->lockForUpdate()->first();

        return $challenge;
    }

    /**
     * D18 + A10 at the moment the engagement FORMS, on both sides.
     *
     * The badge gated the propose leg and nothing else, so a publisher whose verification had been
     * set back to `pending` was refused `GET /marketplace/challenges` (422) and then accepted a
     * proposal on the very same account (200), forming the engagement and the obligation.
     * acceptRequest() falls through to assertTargetActor() -> SuchakAccessService::canOperate(),
     * which BY DESIGN admits VERIFICATION_PENDING while the policy allows work before admin
     * approval — right for a Suchak building his own book, wrong for the marketplace, which D18
     * makes the stricter surface.
     *
     * The direct collaboration path is untouched: this returns immediately unless the engagement
     * names a challenge, so pending-with-policy still works exactly as it did everywhere else.
     *
     * BOTH accounts are checked. The helper held the badge when he proposed; if he has lost it
     * since, accepting would put an unverified account into a live marketplace engagement, which is
     * A10's cheap second account arriving one step later. `isVerified()` is the same spelling of
     * the badge that ACCOUNT_GATE_MARKETPLACE_BADGE and SuchakMarketplaceChallengeService use, so
     * the marketplace has one rule and not two.
     */
    private function assertMarketplaceEngagementBadge(
        SuchakCollaborationRequest $collaboration,
        SuchakAccount $targetAccount,
    ): void {
        if (! $collaboration->isMarketplaceProposal()) {
            return;
        }

        if (! $targetAccount->isVerified()) {
            throw new InvalidArgumentException('बाजारपेठ फक्त पडताळणी झालेल्या सूचकांना दिसते.');
        }

        $helper = SuchakAccount::query()
            ->whereKey($collaboration->requesting_suchak_account_id)
            ->first();

        if ($helper?->isVerified() !== true) {
            throw new InvalidArgumentException(
                'स्थळ सुचवणाऱ्या सूचकाची पडताळणी आता वैध नाही, त्यामुळे ही सुचवणी स्वीकारता येणार नाही.'
            );
        }
    }

    /**
     * The challenge's declared share, expressed in the commission agreement's own vocabulary (D4).
     *
     * Two translations happen here and nothing else does:
     *
     *  1. ONE-DIRECTIONAL → TWO-SIDED. A challenge says "I will pay the helper X". A commission
     *     agreement stores a groom-side and a bride-side share that must total 100, named by the
     *     CANDIDATE'S GENDER and not by role — so the helper's declared X lands on whichever side
     *     he is on, and the publisher keeps the remainder. Which side that is comes from
     *     agreementSideAccountIds(), the single owner of that rule, whose answer the caller passes
     *     in; computing it a second time here is how one Suchak's share becomes the other's.
     *  2. THE CURRENCY. Read through SuchakMarketplaceChallenge::declaredShareCurrency(), which
     *     reads the agreement the challenge froze. The helper never supplies it, and neither does
     *     this method.
     *
     * The result goes through normalizeCommissionTerms() rather than being assembled by hand, so a
     * declared share is validated by the identical rules a typed one is — including "the percentage
     * split must total 100", which is the arithmetic this translation could get wrong.
     *
     * @return array{split_type: string, groom_side_share: ?string, bride_side_share: ?string, fixed_amount: ?string, currency: string}
     */
    private function challengeCommissionTerms(
        SuchakMarketplaceChallenge $challenge,
        int $helperAccountId,
        int $groomAccountId,
    ): array {
        $currency = $challenge->declaredShareCurrency();

        if ($challenge->declared_share_type === SuchakCommissionAgreement::SPLIT_FIXED_AMOUNT) {
            return $this->normalizeCommissionTerms([
                'split_type' => SuchakCommissionAgreement::SPLIT_FIXED_AMOUNT,
                'fixed_amount' => $challenge->declared_share_amount,
                'currency' => $currency,
            ]);
        }

        $helperShare = (float) $challenge->declared_share_percent;
        $helperIsGroomSide = $helperAccountId === $groomAccountId;

        return $this->normalizeCommissionTerms([
            'split_type' => SuchakCommissionAgreement::SPLIT_CUSTOM_PERCENT,
            'groom_side_share' => $helperIsGroomSide ? $helperShare : 100.0 - $helperShare,
            'bride_side_share' => $helperIsGroomSide ? 100.0 - $helperShare : $helperShare,
            'currency' => $currency,
        ]);
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

    private function assertLadderStage(string $stageKey): void
    {
        if (! SuchakCollaborationStageEvent::isValidStage($stageKey)) {
            throw new InvalidArgumentException('Unknown marketplace stage key: '.$stageKey.'.');
        }
    }

    /**
     * The single writer of a stage-event row. All THREE claim paths come through here so the owner
     * column and the claim channel are the only things that differ between them — the stage, the
     * timestamp and the note are recorded identically, and the model's exactly-one-owner and
     * claim-channel guards are exercised on every one of them.
     *
     * A CUSTOMER claim (blueprint 6a's `viewed` / `interested` / `meeting_confirmed`) passes a
     * portal link and NO account and NO user: the customer is the family and usually has no login
     * at all (section 2). That is why `$account` and `$actor` are nullable here rather than a second
     * writer existing — a second writer is a second place for the actor rules to drift.
     *
     * `claimed_at` is D11's anchor: the 12-month anti-circumvention clause runs from the `viewed`
     * row's timestamp, so this is the one moment that value is set for that rung.
     *
     * `$priorAcquaintance` is 9a A6's release of that same clause, written in the SAME insert as the
     * binding it releases. It is a parameter here rather than a later update on purpose: A6 says
     * "at view time", and a release that could be applied afterwards would be a way to un-owe a
     * success fee once the marriage was already in sight.
     *
     * @param  array<string, int>  $owner  exactly one SuchakCollaborationStageEvent::OWNER_COLUMNS entry
     */
    private function writeStageEvent(
        array $owner,
        ?SuchakAccount $account,
        ?User $actor,
        string $stageKey,
        ?string $note,
        ?SuchakCustomerPortalLink $portalLink = null,
        bool $priorAcquaintance = false,
    ): SuchakCollaborationStageEvent {
        $event = SuchakCollaborationStageEvent::query()->create(array_merge($owner, [
            'stage_key' => $stageKey,
            'claimed_by_actor_type' => $portalLink instanceof SuchakCustomerPortalLink
                ? SuchakActivityLog::ACTOR_USER
                : SuchakActivityLog::ACTOR_SUCHAK,
            'claimed_by_suchak_account_id' => $account?->id,
            'claimed_by_user_id' => $actor?->id,
            'claimed_via_customer_portal_link_id' => $portalLink?->id,
            'prior_acquaintance_declared' => $priorAcquaintance,
            'claimed_at' => now(),
            'event_note' => $this->nullableLimitedString($note, 2000),
        ]));

        return $event->fresh() ?? $event;
    }

    /**
     * A pre-engagement stage has only one legitimate claimer: the Suchak whose customer agreement
     * it is. There is no counterparty yet to hold a competing view.
     */
    private function assertCustomerAgreementActor(
        SuchakCustomerAgreement $customerAgreement,
        SuchakAccount $account,
        User $actor,
    ): void {
        if ((int) $account->user_id !== (int) $actor->id) {
            throw new InvalidArgumentException('Only the Suchak account owner can record customer marketplace stages.');
        }

        if ((int) $customerAgreement->suchak_account_id !== (int) $account->id) {
            throw new InvalidArgumentException('Customer agreement belongs to another Suchak account.');
        }

        if (! $this->accessService->canOperate($account)) {
            throw new InvalidArgumentException('Only verified Suchak accounts can record marketplace stages.');
        }
    }

    /**
     * Section 6a's per-rung ACTOR rule, applied to an engagement claim.
     *
     * Read STAGE_CLAIMANTS for the derivation. Three things are enforced here, in this order:
     *
     *  1. A rung the FAMILY owns (`viewed`, `interested`, `meeting_confirmed`) is refused to every
     *     Suchak — still, and permanently. Letting a Suchak record it would not capture the
     *     customer's act, it would fake it (9a A2/A3). The rung is no longer unrecordable, though:
     *     recordCustomerStage() writes it from the family's own portal link, which is where D11's
     *     12-month clause finally gets its anchor timestamp.
     *  2. A role-scoped rung needs the role to be a RECORDED FACT. `customer_owner_side` DEFAULTS to
     *     `target`, so on an unlinked engagement "helper" is a column default, not a finding —
     *     hanging A7's money rule off a default is the same forgery one step removed. The proof the
     *     side was written deliberately is the customer agreement link that linkCustomerAgreement()
     *     writes in the same breath, and only the owning Suchak can supply his own agreement, so a
     *     Suchak cannot appoint himself the other role.
     *  3. Where the engagement already names the customer agreement in force, that agreement's own
     *     state must support the rung — the same gate the pre-engagement path applies, for the same
     *     reason: no meeting, tranche or share exists under terms nobody accepted.
     */
    private function assertStageClaimant(
        SuchakCollaborationRequest $collaboration,
        SuchakAccount $account,
        string $stageKey,
    ): void {
        $claimant = SuchakCollaborationStageEvent::claimantFor($stageKey);
        $label = SuchakCollaborationStageEvent::stageLabel($stageKey);

        if ($claimant === SuchakCollaborationStageEvent::CLAIMANT_CUSTOMER) {
            throw new InvalidArgumentException(
                '"'.$label.'" हा टप्पा ग्राहक स्वतः नोंदवतो, सूचक नाही. ग्राहकाला पाठवलेल्या पोर्टल लिंकवरून '
                .'ही नोंद होते.'
            );
        }

        $collaboration->loadMissing('commissionAgreement.customerAgreement');
        $commissionAgreement = $collaboration->commissionAgreement;
        $linkedCustomerAgreement = $commissionAgreement?->customerAgreement;

        if ($linkedCustomerAgreement instanceof SuchakCustomerAgreement) {
            $this->assertCustomerTermsSupportStage($linkedCustomerAgreement, $stageKey);
        }

        if ($claimant === SuchakCollaborationStageEvent::CLAIMANT_EITHER_SUCHAK) {
            return;
        }

        if ($commissionAgreement?->customer_agreement_id === null) {
            throw new InvalidArgumentException(
                'या सहकार्यात ग्राहकाचा सूचक कोण हे अजून नोंदवलेले नाही. आधी ग्राहक करार या सहकार्याशी जोडा, '
                .'मग "'.$label.'" हा टप्पा नोंदवता येईल.'
            );
        }

        if ($claimant === SuchakCollaborationStageEvent::CLAIMANT_CUSTOMER_OWNER
            && ! $collaboration->isCustomerOwner((int) $account->id)) {
            throw new InvalidArgumentException(
                '"'.$label.'" हा टप्पा फक्त ग्राहकाचा स्वतःचा सूचक नोंदवू शकतो.'
            );
        }

        if ($claimant === SuchakCollaborationStageEvent::CLAIMANT_HELPER
            && ! $collaboration->isHelpingSuchak((int) $account->id)) {
            throw new InvalidArgumentException(
                '"'.$label.'" हा टप्पा फक्त मदत करणारा सूचक नोंदवू शकतो. ही नोंद समोरच्या बाजूकडून येणे हाच तिचा पुरावा आहे.'
            );
        }
    }

    /**
     * A rung may not contradict the object it describes. From FIRST_STAGE_REQUIRING_SATISFIED_TERMS
     * onward the customer agreement must actually be in force, read through the existing owner of
     * that question — SuchakCustomerAgreement::isTermsSatisfied(). The status list is never restated.
     */
    private function assertCustomerTermsSupportStage(SuchakCustomerAgreement $agreement, string $stageKey): void
    {
        if (! SuchakCollaborationStageEvent::requiresSatisfiedCustomerTerms($stageKey)) {
            return;
        }

        if ($agreement->isTermsSatisfied()) {
            return;
        }

        throw new InvalidArgumentException(
            '"'.SuchakCollaborationStageEvent::stageLabel($stageKey).'" ही नोंद करता येणार नाही — '
            .$this->customerAgreementStateReason($agreement).'.'
        );
    }

    /**
     * Phrases the refusal only — the GATE is isTermsSatisfied() and nothing else. These read the
     * agreement's own timestamps rather than translating the status enum, so no second list of
     * statuses is created here that could drift away from the one that decides.
     */
    private function customerAgreementStateReason(SuchakCustomerAgreement $agreement): string
    {
        if ($agreement->declined_at !== null) {
            return 'ग्राहकाने हा करार नाकारला आहे';
        }

        if ($agreement->superseded_at !== null) {
            return 'या कराराच्या जागी नवीन आवृत्ती आली आहे';
        }

        if ($agreement->expired_at !== null) {
            return 'या कराराची मुदत संपली आहे';
        }

        return 'ग्राहकाने हा करार अजून स्वीकारलेला नाही';
    }

    /**
     * The customer confirms; an admin may stand in. A participating Suchak's own user may not.
     */
    private function confirmationActorType(SuchakCollaborationRequest $collaboration, User $confirmingUser): string
    {
        if ($this->accessService->isAdmin($confirmingUser)) {
            return SuchakActivityLog::ACTOR_ADMIN;
        }

        $participantUserIds = SuchakAccount::query()
            ->whereKey([
                $collaboration->requesting_suchak_account_id,
                $collaboration->target_suchak_account_id,
            ])
            ->pluck('user_id')
            ->map(fn ($userId): int => (int) $userId)
            ->all();

        if (in_array((int) $confirmingUser->id, $participantUserIds, true)) {
            throw new InvalidArgumentException('A participating Suchak cannot confirm their own marketplace stage claim.');
        }

        return SuchakActivityLog::ACTOR_USER;
    }

    /**
     * The ladder only ever moves forward — a later stage never rewinds the engagement's position.
     */
    private function advanceMarketplaceStage(SuchakCollaborationRequest $collaboration, string $stageKey): void
    {
        if (! SuchakCollaborationStageEvent::isStageAfter($stageKey, $collaboration->marketplace_stage)) {
            return;
        }

        SuchakCollaborationRequest::query()
            ->whereKey($collaboration->id)
            ->update(['marketplace_stage' => $stageKey]);
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

    /**
     * M1: one candidate pair, one open engagement — IN EITHER DIRECTION.
     *
     * The guard matched requesting==requesting AND target==target and never looked at the mirrored
     * row. Before the marketplace the reversed direction was an accident nobody took; accept-by-
     * proposing makes it the standard path (5.2's direction note), so it became trivially
     * reachable, and the proven result is the M1 break itself: two open engagements on the same two
     * candidates, two commission agreements, two ladders — and two different
     * `collector_suchak_account_id`, which is the exact invariant "each customer pays only their
     * own Suchak" denies. A pair whose money owner depends on which of the two Suchaks pressed
     * first is a pair with no money owner.
     *
     * DIRECTION-blind, not account-blind. The accounts are still required to be the same two — as
     * an unordered pair, like the profiles — so the one thing this refuses that it did not refuse
     * before is the mirror of a pair that already has an open engagement. A DIFFERENT candidate on
     * either side is a different pair and is still allowed, which is the whole marketplace: a
     * publisher may hold several live proposals from several helpers, and a helper holding two
     * hundred candidates may propose two of them.
     *
     * Going further and dropping the accounts would also refuse a second helper proposing the SAME
     * candidate — a candidate two Suchaks both represent — to one challenge. That is legitimate
     * competition, D14 forbids blocking it, and its money risk is not here anyway: it is closed at
     * ACCEPTANCE by assertChallengeStillUnanswered(), where one challenge admits one accepted
     * proposal and no more.
     */
    private function assertNoDuplicateOpenRequest(
        SuchakProfileRepresentation $requestingRepresentation,
        SuchakProfileRepresentation $targetRepresentation,
        bool $marketplace = false,
    ): void {
        if (! $this->hasOpenEngagementForPair($requestingRepresentation, $targetRepresentation)) {
            return;
        }

        throw new InvalidArgumentException($marketplace
            ? 'या दोन स्थळांमध्ये आधीच एक सुरू असलेली जोडणी आहे. दिशा कोणतीही असो, एका जोडीसाठी एकच जोडणी असते.'
            : 'An open collaboration request already exists for this Suchak/profile pair.');
    }

    /**
     * Is there an OPEN engagement holding these two candidates, whichever of them was named first?
     *
     * The one owner of that question. Two callers ask it and they used to ask it in two different
     * ways: assertNoDuplicateOpenRequest() matched one direction on profile ids, and
     * hasOpenCollaborationPair() — which feeds suggestedOpportunities() — matched both directions
     * on representation ids. Two spellings of "this pair is already engaged" is how one of them
     * ends up wrong, and one of them was.
     *
     * Matched on `matrimony_profile_id`, not on `representation_id`: the pair is two PEOPLE. A
     * candidate may be represented by more than one Suchak (`suchak_profile_representations` is
     * unique on account+profile, not on profile), so a representation-id match would let the same
     * two people hold two open engagements through two different rows.
     */
    private function hasOpenEngagementForPair(
        SuchakProfileRepresentation $one,
        SuchakProfileRepresentation $other,
    ): bool {
        $oneAccount = (int) $one->suchak_account_id;
        $otherAccount = (int) $other->suchak_account_id;
        $oneProfile = (int) $one->matrimony_profile_id;
        $otherProfile = (int) $other->matrimony_profile_id;

        return SuchakCollaborationRequest::query()
            ->whereIn('status', SuchakCollaborationRequest::OPEN_STATUSES)
            ->where(function (Builder $query) use ($oneAccount, $otherAccount, $oneProfile, $otherProfile): void {
                $query
                    ->where(function (Builder $query) use ($oneAccount, $otherAccount, $oneProfile, $otherProfile): void {
                        $query
                            ->where('requesting_suchak_account_id', $oneAccount)
                            ->where('target_suchak_account_id', $otherAccount)
                            ->where('requesting_matrimony_profile_id', $oneProfile)
                            ->where('target_matrimony_profile_id', $otherProfile);
                    })
                    // The mirror. This half is the defect: without it the same two candidates form
                    // a second engagement the moment the other Suchak names them first.
                    ->orWhere(function (Builder $query) use ($oneAccount, $otherAccount, $oneProfile, $otherProfile): void {
                        $query
                            ->where('requesting_suchak_account_id', $otherAccount)
                            ->where('target_suchak_account_id', $oneAccount)
                            ->where('requesting_matrimony_profile_id', $otherProfile)
                            ->where('target_matrimony_profile_id', $oneProfile);
                    });
            })
            ->exists();
    }

    /**
     * The candidate is usable, and the account behind it clears the gate this direction requires.
     * The candidate half never varies; only the ACCOUNT_GATE_* half does. See the constants.
     */
    private function representationIsUsable(SuchakProfileRepresentation $representation, string $accountGate): bool
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

        $account = $representation->suchakAccount;

        return match ($accountGate) {
            self::ACCOUNT_GATE_PUBLIC_ROUTE => $this->accessService->canPubliclyRoute($account),
            self::ACCOUNT_GATE_MARKETPLACE_BADGE => $account?->isVerified() === true,
            default => $this->accessService->canOperate($account),
        };
    }

    private function activeProfileQuery(Builder $query): Builder
    {
        return $query
            ->where('lifecycle_state', 'active')
            ->where('is_suspended', false);
    }

    /**
     * Do not suggest an opportunity that is already an open engagement. Same question as
     * assertNoDuplicateOpenRequest()'s, so it is the same predicate — a pair the suggester would
     * offer and the creator would then refuse is a suggestion that exists only to fail.
     */
    private function hasOpenCollaborationPair(
        SuchakProfileRepresentation $ownRepresentation,
        SuchakProfileRepresentation $candidate,
    ): bool {
        return $this->hasOpenEngagementForPair($ownRepresentation, $candidate);
    }

    private function collectorAccountId(SuchakCollaborationRequest $collaboration, SuchakCommissionAgreement $agreement): int
    {
        return (int) (
            $agreement->collector_suchak_account_id
            ?? $collaboration->target_suchak_account_id
            ?? $agreement->bride_side_suchak_account_id
            ?? $agreement->groom_side_suchak_account_id
        );
    }

    /**
     * Single owner of the groom/bride side rule. The candidate's gender decides which Suchak is
     * labelled the groom side; the two account ids are supplied by the caller so an agreement can
     * only ever name accounts that are actually party to the collaboration. Every path that
     * writes groom_side_suchak_account_id / bride_side_suchak_account_id must come through here —
     * a second implementation would silently record one Suchak's acceptance as the other's.
     *
     * @return array{0: int, 1: int}
     */
    private function agreementSideAccountIds(
        int $requestingAccountId,
        int $targetAccountId,
        ?SuchakProfileRepresentation $requestingRepresentation,
        ?SuchakProfileRepresentation $targetRepresentation,
    ): array {
        $requestingGender = $requestingRepresentation?->matrimonyProfile?->gender?->key;
        $targetGender = $targetRepresentation?->matrimonyProfile?->gender?->key;

        if ($requestingGender === 'female' && $targetGender === 'male') {
            return [$targetAccountId, $requestingAccountId];
        }

        return [$requestingAccountId, $targetAccountId];
    }

    private function createMissingAgreement(SuchakCollaborationRequest $collaboration): SuchakCommissionAgreement
    {
        $collaboration->loadMissing([
            'requestingRepresentation.matrimonyProfile.gender',
            'targetRepresentation.matrimonyProfile.gender',
        ]);

        [$groomAccountId, $brideAccountId] = $this->agreementSideAccountIds(
            (int) $collaboration->requesting_suchak_account_id,
            (int) $collaboration->target_suchak_account_id,
            $collaboration->requestingRepresentation,
            $collaboration->targetRepresentation,
        );

        return SuchakCommissionAgreement::query()->create([
            'collaboration_request_id' => $collaboration->id,
            'groom_side_suchak_account_id' => $groomAccountId,
            'bride_side_suchak_account_id' => $brideAccountId,
            'collector_suchak_account_id' => $collaboration->target_suchak_account_id,
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
