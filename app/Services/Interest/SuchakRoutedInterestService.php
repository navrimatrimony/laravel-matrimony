<?php

namespace App\Services\Interest;

use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileRequest;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakRequestPipelineService;
use App\Modules\Suchak\Services\SuchakRequestPresenter;
use App\Support\Suchak\SuchakContactRouting;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The ONE bridge between a member's plain INTEREST (the heart) and the Suchak
 * request pipeline.
 *
 * Why this exists: the heart is the primary action on every card and profile,
 * while the Suchak-request CTA lives further down inside the contact card. In
 * practice almost every member taps the heart — so before this, an approach to a
 * Suchak-managed profile created an `interests` row that NOBODY could act on:
 * the Suchak's inbox only ever listed `suchak_profile_requests`, and the member
 * was shown a confident "sent" that reached no one.
 *
 * Design rules this class obeys (frozen no-duplicate rule):
 *  - It creates NO second inbox and NO second pipeline. A routed interest becomes
 *    an ordinary {@see SuchakProfileRequest} through the existing
 *    {@see SuchakRequestPipelineService::createRequest()}, so the Suchak's list,
 *    detail, reply, forward, decision, SLA sweep and chat all work unchanged.
 *  - It creates NO second thing the member sees. The member still has exactly one
 *    `interests` row; this class only supplies the truthful routed status for it.
 *  - It writes NO link column. `interests` is unique on
 *    (sender_profile_id, receiver_profile_id), and a request already stores
 *    (requesting_matrimony_profile_id, target_matrimony_profile_id) — the same
 *    pair. The mapping is therefore derived, never stored, and cannot drift.
 *    No migration is required.
 *  - It owns NO routing predicate. "Is this profile Suchak-routed?" stays
 *    {@see SuchakContactRouting::isRouted()} — the single definition.
 *  - It owns NO consent rule. Consent is enforced twice by code that already
 *    exists: `isRouted()` only sees publicly routable (valid-consent)
 *    representations, and every Suchak action re-checks via
 *    `assertSuchakMayActOnRequest()`. A customer without valid consent is
 *    therefore simply never routed, and an existing request becomes unanswerable
 *    the moment consent lapses.
 *
 * QUOTA (deliberate decision): routing consumes NOTHING extra from the member.
 * The heart already charged `interest_send_limit` inside
 * {@see InterestActionService::send()}, so this class deliberately does NOT
 * consume `FeatureUsageService::FEATURE_CHAT_SEND_LIMIT` the way the explicit
 * Suchak-request CTA does — that would bill one approach twice. The Suchak-side
 * `SuchakLimitService::assertLeadRequestAllowed()` inside `createRequest()` is
 * deliberately LEFT IN PLACE: it is not a member charge at all, it is the
 * Suchak's own paid plan capacity, and bypassing it would silently let interests
 * blow past a limit the Suchak paid for.
 */
class SuchakRoutedInterestService
{
    /**
     * Origin marker written to the existing `request_reason` column so a request
     * born from the heart is distinguishable in the audit trail / backfill from
     * one the member raised through the contact-card CTA. Behaviour never keys on
     * it — the profile pair is the mapping — so it can never drift into a second
     * source of truth.
     */
    public const REQUEST_REASON = 'member_interest';

    public function __construct(
        private readonly SuchakRequestPipelineService $pipelineService,
        private readonly SuchakRequestPresenter $presenter,
    ) {
    }

    // -------------------------------------------------------------------------
    // Forward: interest → pipeline
    // -------------------------------------------------------------------------

    /**
     * Put a just-sent (or re-tapped) interest in front of the Suchak who manages
     * the receiving profile.
     *
     * Best-effort by design: the interest is already committed and valid on its
     * own. If the pipeline refuses (consent gone, Suchak lead limit full,
     * profile no longer active) the member keeps a perfectly ordinary interest —
     * we log and move on rather than failing a send that already succeeded.
     */
    public function routeInterest(
        User $senderUser,
        MatrimonyProfile $senderProfile,
        MatrimonyProfile $receiverProfile,
        Interest $interest,
    ): ?SuchakProfileRequest {
        // Only a live approach is routable. An already accepted/rejected interest
        // must not re-open an approach — that is the ordinary member-to-member
        // rule and it stays identical here.
        if ($interest->status !== 'pending') {
            return null;
        }

        if (! SuchakContactRouting::isRouted($receiverProfile)) {
            return null;
        }

        // Reuse the existing SLA sweep first: a request whose window ran out must
        // close before the duplicate-open check, otherwise a dead approach would
        // block the fresh one the member is entitled to.
        $this->pipelineService->expireDuePipelinesForRequestingProfile($senderProfile);

        // Idempotent: one open approach per pair, whatever created it (heart or
        // contact-card CTA). Re-tapping the heart while one is open is a no-op.
        if ($this->openRequestForPair($senderProfile->id, $receiverProfile->id) !== null) {
            return null;
        }

        $representation = SuchakContactRouting::routableRepresentationFor($receiverProfile);
        if (! $representation instanceof SuchakProfileRepresentation) {
            return null;
        }

        try {
            $result = $this->pipelineService->createRequest(
                $senderUser,
                $senderProfile,
                $representation,
                [
                    'request_reason' => self::REQUEST_REASON,
                    // No message: the heart is not a message. Leaving this null is
                    // what keeps createRequest() from injecting a chat line the
                    // member never wrote.
                    'message' => null,
                ],
                $this->currentIp(),
                $this->currentUserAgent(),
            );

            return $result['request'];
        } catch (Throwable $exception) {
            Log::warning('Suchak routing of a member interest failed; interest stands unrouted.', [
                'interest_id' => $interest->id,
                'sender_profile_id' => $senderProfile->id,
                'receiver_profile_id' => $receiverProfile->id,
                'representation_id' => $representation->id,
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Reverse: pipeline decision → interest
    // -------------------------------------------------------------------------

    /**
     * The single authoritative transition that keeps the two states from
     * drifting.
     *
     * Called from inside {@see SuchakRequestPipelineService::recordCandidateDecision()}'s
     * transaction — the ONE place that settles the first-answer-wins race — so
     * the request status and the interest status commit together or not at all.
     * There is deliberately no second listener, no queued job and no controller
     * copy of this mapping.
     *
     *   candidate_interested     → interest accepted (+ contact visibility grant,
     *                              sender notification — the existing accept side
     *                              effects, reused, never re-implemented)
     *   candidate_not_interested → interest rejected (+ sender notification)
     *
     * A Suchak *reply* is deliberately NOT a decision: it means "I have your
     * approach and I am talking to you", so the interest correctly stays pending
     * until the family actually answers. An SLA expiry likewise leaves the
     * interest pending — that is what makes the re-send in
     * {@see routeInterest()} possible.
     */
    public function applyRequestDecisionToInterest(
        SuchakProfileRequest $request,
        string $decision,
        ?User $actor,
    ): ?Interest {
        $interest = Interest::query()
            ->where('sender_profile_id', $request->requesting_matrimony_profile_id)
            ->where('receiver_profile_id', $request->target_matrimony_profile_id)
            ->where('status', 'pending')
            ->first();

        if (! $interest instanceof Interest) {
            return null;
        }

        $request->loadMissing('targetMatrimonyProfile');
        $receiverProfile = $request->targetMatrimonyProfile;

        if (! $receiverProfile instanceof MatrimonyProfile) {
            return null;
        }

        $interestActions = app(InterestActionService::class);

        if ($decision === SuchakRequestPipelineService::DECISION_INTERESTED) {
            $interestActions->applyAcceptEffects($interest, $receiverProfile, $actor);
        } else {
            $interestActions->applyRejectEffects($interest, $receiverProfile, $actor);
        }

        return $interest->fresh();
    }

    // -------------------------------------------------------------------------
    // Member-facing truth for the sent list
    // -------------------------------------------------------------------------

    /**
     * The routed status block for one interest in the member's SENT list, or null
     * when the receiving profile is not Suchak-routed (the overwhelming majority
     * — ordinary member-to-member interests are untouched and pay nothing here).
     *
     * Every string comes from {@see SuchakRequestPresenter}, so the sent list, the
     * profile contact card and the Suchak app can never describe the same
     * approach differently.
     *
     * @return array<string, mixed>|null
     */
    public function sentListRoutingPayload(Interest $interest): ?array
    {
        $receiverProfile = $interest->receiverProfile;

        if (! $receiverProfile instanceof MatrimonyProfile
            || ! SuchakContactRouting::isRouted($receiverProfile)) {
            return null;
        }

        $representation = SuchakContactRouting::routableRepresentationFor($receiverProfile);
        if (! $representation instanceof SuchakProfileRepresentation) {
            return null;
        }

        $request = $this->latestRequestForPair(
            (int) $interest->sender_profile_id,
            (int) $interest->receiver_profile_id,
        );

        $state = $this->presenter->contactStateFor($representation, $request);

        return [
            'is_suchak_routed' => true,
            'state' => $state['state'],
            // "तुमची विनंती <सूचक> यांच्याकडे आहे…" — the truth the member needs,
            // instead of a bare "pending" that reads as if nobody has it.
            'message' => $state['message'],
            'status' => $request?->request_status,
            'status_label' => $this->presenter->statusLabel($request?->request_status),
            'suchak' => $this->presenter->suchakBlock($representation),
            'request' => $request !== null
                ? $this->presenter->memberRequestPayload($request)
                : null,
        ];
    }

    /**
     * Same block for a whole page of sent interests, keyed by interest id.
     * Shared by the mobile sent list and the web sent panel so both surfaces
     * render one identical truth.
     *
     * @param  iterable<int, Interest>  $interests
     * @return array<int, array<string, mixed>>
     */
    public function sentListRoutingMap(iterable $interests): array
    {
        $map = [];

        foreach ($interests as $interest) {
            $payload = $this->sentListRoutingPayload($interest);

            if ($payload !== null) {
                $map[(int) $interest->id] = $payload;
            }
        }

        return $map;
    }

    // -------------------------------------------------------------------------
    // Backfill support
    // -------------------------------------------------------------------------

    /**
     * Pending interests whose receiver is Suchak-routed and that currently have
     * no open request — i.e. approaches that are invisible to the Suchak today.
     *
     * @return \Illuminate\Support\Collection<int, Interest>
     */
    public function unroutedPendingInterests(int $limit = 500)
    {
        return Interest::query()
            ->with(['senderProfile.user', 'receiverProfile'])
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->filter(function (Interest $interest): bool {
                $receiver = $interest->receiverProfile;
                $sender = $interest->senderProfile;

                if (! $receiver instanceof MatrimonyProfile || ! $sender instanceof MatrimonyProfile) {
                    return false;
                }

                if (! SuchakContactRouting::isRouted($receiver)) {
                    return false;
                }

                return $this->openRequestForPair((int) $sender->id, (int) $receiver->id) === null;
            })
            ->values();
    }

    /**
     * Idempotent backfill. Safe to run repeatedly: anything already carrying an
     * open request is skipped, nothing is deleted and nothing is overwritten.
     *
     * @return array{scanned: int, routed: int, skipped: int}
     */
    public function backfillUnroutedPendingInterests(bool $dryRun = true, int $limit = 500): array
    {
        $candidates = $this->unroutedPendingInterests($limit);
        $routed = 0;
        $skipped = 0;

        foreach ($candidates as $interest) {
            $senderProfile = $interest->senderProfile;
            $senderUser = $senderProfile?->user;
            $receiverProfile = $interest->receiverProfile;

            if (! $senderUser instanceof User
                || ! $senderProfile instanceof MatrimonyProfile
                || ! $receiverProfile instanceof MatrimonyProfile) {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $routed++;

                continue;
            }

            $request = $this->routeInterest($senderUser, $senderProfile, $receiverProfile, $interest);

            if ($request === null) {
                $skipped++;

                continue;
            }

            $routed++;
        }

        return [
            'scanned' => $candidates->count(),
            'routed' => $routed,
            'skipped' => $skipped,
        ];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * An open approach for this profile pair, regardless of which surface created
     * it. Deliberately origin-blind: a member who used the contact-card CTA and
     * then tapped the heart has made ONE approach, and must not generate two.
     */
    public function openRequestForPair(int|string $requestingProfileId, int|string $targetProfileId): ?SuchakProfileRequest
    {
        return SuchakProfileRequest::query()
            ->where('requesting_matrimony_profile_id', $requestingProfileId)
            ->where('target_matrimony_profile_id', $targetProfileId)
            ->whereIn('request_status', SuchakProfileRequest::OPEN_STATUSES)
            ->orderByDesc('id')
            ->first();
    }

    private function latestRequestForPair(int $requestingProfileId, int $targetProfileId): ?SuchakProfileRequest
    {
        return SuchakProfileRequest::query()
            ->with(['pipeline', 'representation.suchakAccount.contactNumbers', 'targetMatrimonyProfile'])
            ->where('requesting_matrimony_profile_id', $requestingProfileId)
            ->where('target_matrimony_profile_id', $targetProfileId)
            ->orderByDesc('id')
            ->first();
    }

    private function currentIp(): ?string
    {
        try {
            return request()?->ip();
        } catch (Throwable) {
            return null;
        }
    }

    private function currentUserAgent(): ?string
    {
        try {
            return request()?->userAgent();
        } catch (Throwable) {
            return null;
        }
    }
}
