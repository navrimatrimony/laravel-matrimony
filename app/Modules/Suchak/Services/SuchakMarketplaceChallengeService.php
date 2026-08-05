<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakCommissionAgreement;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerPlan;
use App\Models\SuchakMarketplaceChallenge;
use App\Models\MatrimonyProfile;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Notifications\MarketplaceProposalReceivedNotification;
use App\Services\Image\ProfilePhotoUrlService;
use App\Services\IncomeEngineService;
use App\Support\MoneyFormat;
use App\Support\SafeNotifier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Publishing, withdrawing, expiring and browsing challenges (blueprint D4 / D18 / D19a, phase 2).
 *
 * Four things this service refuses to own, each already having exactly one owner elsewhere:
 *
 *  - The candidate's visible facts. Every read goes through SuchakCandidateMaskingService::
 *    maskedSummary, so D19a's four defaults and the originating Suchak's per-candidate reveals
 *    apply here the instant he changes them.
 *  - The ladder row for publication. SuchakCollaborationService::claimCustomerStage() is the one
 *    writer of a pre-engagement stage event; this service calls it rather than inserting a second
 *    time in a second way.
 *  - The success fee. It lives on suchak_service_packages.post_marriage_fee_amount, frozen into the
 *    agreement snapshot. A percent share is read AGAINST it and never copied beside it.
 *  - The currency of that money. suchak_service_packages.currency owns it and
 *    suchak_customer_agreements.currency is its frozen snapshot. A first draft gave the challenge a
 *    `share_currency` column, which let a publisher render his own INR success fee to every browsing
 *    Suchak as dollars. It is read through SuchakMarketplaceChallenge::declaredShareCurrency().
 */
class SuchakMarketplaceChallengeService
{
    /**
     * The currency inputs the marketplace refuses, on BOTH legs.
     *
     * The share is a slice of the success fee the customer agreement froze, so it is spent in that
     * agreement's money and no caller may name it (SuchakMarketplaceChallenge::
     * declaredShareCurrency()). The proven attack: an INR agreement with a ₹1,00,000 success fee,
     * published with `share_currency=USD`, rendered "USD 1,00,000" to every browsing Suchak.
     *
     * @var list<string>
     */
    public const CURRENCY_INPUTS = [
        'share_currency',
        'currency',
    ];

    /**
     * The share inputs a PROPOSAL refuses (D4 — the share is declared in the challenge, upfront,
     * and is not negotiable).
     *
     * ONE list, read by both the HTTP validator and the service guard. It was written out twice —
     * once as `prohibited` rules in SuchakMarketplaceChallengeApiController::propose() and once in
     * assertNoDeclaredTerms() — which meant a tenth share field added to one left the other
     * permissive. A caller who skips the route must meet the same list as one who does not, so the
     * list has one home and both entrances read it.
     *
     * @var list<string>
     */
    public const DECLARED_TERMS_INPUTS = [
        'split_type',
        'groom_side_share',
        'bride_side_share',
        'fixed_amount',
        'declared_share_type',
        'declared_share_percent',
        'declared_share_amount',
        ...self::CURRENCY_INPUTS,
    ];

    /** The one refusal sentence for a typed share. Read by the validator and by the guard. */
    public const REFUSAL_SHARE_ALREADY_DECLARED = 'वाटा आव्हानात आधीच जाहीर झाला आहे; तो इथे देता येत नाही.';

    /** The one refusal sentence for a typed currency. Read by the validator and by the guard. */
    public const REFUSAL_CURRENCY_IS_THE_AGREEMENTS = 'वाट्याचे चलन ग्राहकाच्या करारातून येते; ते वेगळे देता येत नाही.';

    /**
     * D7a: "a working Suchak may hold two hundred candidates." The ranked set is capped at well over
     * twice that, and the cap exists to bound the scoring loop, NOT to slice the corpus the way
     * suggestedOpportunities() does — see ownCandidatesFor() for why the difference matters.
     */
    public const MAX_RANKED_OWN_CANDIDATES = 500;

    public function __construct(
        private readonly SuchakActivityLogger $activityLogger,
        private readonly SuchakCandidateMaskingService $maskingService,
        private readonly SuchakCollaborationService $collaborationService,
        private readonly SuchakCrossSearchService $crossSearchService,
        private readonly SuchakMatchFitService $matchFitService,
        private readonly IncomeEngineService $incomeEngine,
        private readonly SuchakClaimSilenceService $claimSilenceService,
    ) {
    }

    // ── Publishing ────────────────────────────────────────────────────────────────────────────

    /**
     * Publish one candidate to the marketplace with a declared share.
     *
     * `$representation` is the publisher's ONE input. The customer agreement is NOT an input:
     * section 4 says "publication attaches to whichever agreement is accepted at that moment", so
     * it is resolved here and frozen onto the row. That is what makes A8 enforceable — a later
     * revision cannot retro-price a share published under an earlier one, because a rate change is
     * a new agreement row and never an edit.
     *
     * @param  array<string, mixed>  $input  declared_share_type, declared_share_percent |
     *                                       declared_share_amount, expires_at, publisher_note
     *
     * There is deliberately NO currency input. The share is a slice of the success fee on the
     * package this agreement froze, so its currency is the agreement's — read, never asserted. See
     * SuchakMarketplaceChallenge::declaredShareCurrency().
     */
    public function publish(
        SuchakAccount $account,
        User $actor,
        SuchakProfileRepresentation $representation,
        array $input,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakMarketplaceChallenge {
        $account->refresh();
        $representation->refresh();

        $this->assertMarketplaceActor($account, $actor);
        $this->assertMarketplaceCandidate($account, $representation);

        $agreement = $this->acceptedAgreementFor($account, $representation);
        $terms = $this->normalizeDeclaredShare($input);
        $this->assertShareHasABase($agreement, $terms);

        $expiresAt = $this->normalizeExpiry($input['expires_at'] ?? null);
        $note = $this->nullableLimitedString($input['publisher_note'] ?? null, 2000);

        $challenge = DB::transaction(function () use (
            $account,
            $actor,
            $representation,
            $agreement,
            $terms,
            $expiresAt,
            $note,
        ): SuchakMarketplaceChallenge {
            // At most one OPEN challenge per candidate. Two live challenges on one candidate at two
            // different shares is A8's escape hatch: suggest under the generous one, pay under the
            // mean one. No portable partial unique index exists, so the row lock is the guard.
            $open = SuchakMarketplaceChallenge::query()
                ->where('representation_id', $representation->id)
                ->where('status', SuchakMarketplaceChallenge::STATUS_OPEN)
                ->lockForUpdate()
                ->first();

            if ($open !== null) {
                throw new InvalidArgumentException('या स्थळासाठी आधीच एक खुले आव्हान प्रसिद्ध आहे.');
            }

            /** @var SuchakMarketplaceChallenge $challenge */
            $challenge = SuchakMarketplaceChallenge::query()->create([
                'suchak_account_id' => $account->id,
                'representation_id' => $representation->id,
                'customer_agreement_id' => $agreement->id,
                'declared_share_type' => $terms['declared_share_type'],
                'declared_share_percent' => $terms['declared_share_percent'],
                'declared_share_amount' => $terms['declared_share_amount'],
                'audience' => SuchakMarketplaceChallenge::AUDIENCE_VERIFIED_SUCHAKS,
                'status' => SuchakMarketplaceChallenge::STATUS_OPEN,
                'publisher_note' => $note,
                'published_by_user_id' => $actor->id,
                'published_at' => now(),
                'expires_at' => $expiresAt,
            ]);

            $this->recordPublicationStage($agreement, $account, $actor);

            return $challenge;
        });

        $this->recordActivity(
            SuchakActivityLog::ACTION_MARKETPLACE_CHALLENGE_PUBLISHED,
            $challenge,
            $actor,
            $ipAddress,
            $userAgent,
            ['customer_agreement_id' => (int) $agreement->id],
        );

        return $challenge->fresh() ?? $challenge;
    }

    /**
     * Record `published_to_marketplace` on the ladder (section 6a).
     *
     * The ONE writer of a pre-engagement stage event is SuchakCollaborationService::
     * claimCustomerStage(), and it is called rather than reimplemented. It throws when the stage is
     * already recorded, which for publication is not an error: `unique(customer_agreement_id,
     * stage_key)` deliberately makes the stage recordable ONCE PER AGREEMENT REVISION, so a
     * re-publication at the same rate cannot count twice on the ladder. "Times published" is this
     * table's own count(*) (A12), not a second ladder row.
     *
     * The already-recorded case is decided by RE-READING, never by matching the exception's text:
     * a message is not an interface, and a guard that turns into a silent no-op the day someone
     * rewords a string is worse than no guard.
     */
    private function recordPublicationStage(
        SuchakCustomerAgreement $agreement,
        SuchakAccount $account,
        User $actor,
    ): void {
        if ($this->publicationStageExists($agreement)) {
            return;
        }

        try {
            $this->collaborationService->claimCustomerStage(
                $agreement,
                $account,
                $actor,
                SuchakCollaborationStageEvent::STAGE_PUBLISHED_TO_MARKETPLACE,
            );
        } catch (InvalidArgumentException $exception) {
            if (! $this->publicationStageExists($agreement)) {
                throw $exception;
            }
        }
    }

    private function publicationStageExists(SuchakCustomerAgreement $agreement): bool
    {
        return SuchakCollaborationStageEvent::query()
            ->where('customer_agreement_id', $agreement->id)
            ->where('stage_key', SuchakCollaborationStageEvent::STAGE_PUBLISHED_TO_MARKETPLACE)
            ->exists();
    }

    // ── Accept by proposing (D7 / D7a / section 6.1) ──────────────────────────────────────────

    /**
     * A helping Suchak answers a challenge by NAMING one of his own candidates.
     *
     * D7 is the whole rule: *"A helping Suchak cannot press a bare 'accept'. They must select a
     * specific candidate profile to propose."* There is no bare-accept entry point on this service
     * and there must never be one — the act and the named candidate are the same act.
     *
     * WHAT THIS CREATES IS NOT A NEW OBJECT. Section 6.1: the engagement already exists as
     * `suchak_collaboration_requests` + `suchak_commission_agreements`, and section 5.2's direction
     * note says the responder becomes the requester. So this method guards, then hands the writing
     * to SuchakCollaborationService::createRequest() with the challenge attached. Everything the
     * reversal changes lives there, beside the wiring it changes.
     *
     * WHAT IT ADDS ON TOP, and why each is here rather than there:
     *  - the challenge must still be answerable (open, unexpired, its candidate still consented).
     *    That is challenge lifecycle, which this service owns.
     *  - A2: a Suchak may not answer his own challenge. createRequest() already refuses same-account
     *    pairing in English as a structural rule; this refuses it first, in Marathi, as a rule about
     *    the marketplace.
     *  - A10: the same candidate may not be proposed to the same challenge twice, in any status.
     *  - the ladder rung, which is the point of the whole act (below).
     *
     * @param  array<string, mixed>  $input  `message` only. Share and currency are the CHALLENGE's.
     * @return array{request: SuchakCollaborationRequest, agreement: SuchakCommissionAgreement, stage_event: SuchakCollaborationStageEvent}
     */
    public function proposeCandidate(
        SuchakMarketplaceChallenge $challenge,
        SuchakAccount $helperAccount,
        User $actor,
        SuchakProfileRepresentation $helperRepresentation,
        array $input = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $helperAccount->refresh();
        $challenge->refresh();
        $helperRepresentation->refresh();

        $this->assertMarketplaceActor($helperAccount, $actor);
        $this->assertNotOwnChallenge($challenge, $helperAccount);
        $this->assertMarketplaceCandidate($helperAccount, $helperRepresentation);
        $this->assertNoDeclaredTerms($input);

        /*
         * THE STOP-LOSS (§7.2 clause 3). "A helper may not accept a new challenge from the same
         * originating Suchak while 2 claims, or ₹5,000, sit past their window."
         *
         * D7 makes proposing a candidate the ONLY way to accept a challenge — there is no bare
         * accept on this service — so this is the one door the rule needs, and it is enforced here
         * rather than in a controller for the same reason every other guard is.
         *
         * Deliberately BEFORE the transaction: the gate sweeps the originating Suchak's overdue
         * claims first (there may be no scheduler on this production), and that sweep writes rows
         * of its own. Running it inside the challenge lock would nest one write transaction inside
         * another for no benefit and would take the visit-row locks in a new order.
         *
         * It is a protection FOR the helper, not a punishment of him — which is why the refusal
         * names the other Suchak's numbers rather than his own.
         */
        $this->claimSilenceService->assertHelperMayAcceptChallengeFrom($this->publisherAccount($challenge));

        $message = $this->nullableLimitedString($input['message'] ?? null, 2000);

        $proposal = DB::transaction(function () use (
            $challenge,
            $helperAccount,
            $actor,
            $helperRepresentation,
            $message,
            $ipAddress,
            $userAgent,
        ): array {
            /** @var SuchakMarketplaceChallenge $locked */
            $locked = SuchakMarketplaceChallenge::query()
                ->whereKey($challenge->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertChallengeAcceptsProposals($locked, $helperAccount);
            $this->assertNotAlreadyProposed($locked, $helperRepresentation);

            $created = $this->collaborationService->createRequest(
                $helperAccount,
                $actor,
                $helperRepresentation,
                $this->challengeRepresentation($locked),
                ['message' => $message],
                $ipAddress,
                $userAgent,
                $locked,
            );

            /** @var SuchakCollaborationRequest $request */
            $request = $created['request'];

            /*
             * THE RUNG (section 6a). `profile_suggested` is the helper's — "helper names their
             * candidate" — and this is the moment it happens, so it is written here and not left to
             * a separate call the app might never make.
             *
             * Written through claimStage(), the ONE writer of an engagement-owned stage event.
             * Nothing about the rung is special-cased for the marketplace, and everything the ladder
             * already enforces applies unchanged: the actor must be the HELPER
             * (STAGE_CLAIMANTS), the role must be a recorded fact rather than the
             * `customer_owner_side` default (which createRequest() has just written from the
             * challenge), the frozen customer agreement must actually be in force, and the rung
             * must be claimable on a PENDING engagement — which it is, because
             * FIRST_STAGE_REQUIRING_ACCEPTED_ENGAGEMENT sits at `meeting_scheduled`.
             *
             * NO NOTE IS PASSED, deliberately. The helper's message has ONE owner —
             * `suchak_collaboration_requests.message`, which createRequest() has just written and
             * which proposalPayload() and the collaborations list both read. Passing the same
             * string here as well put one sentence in two columns for one act, which is the frozen
             * no-duplicate rule broken exactly as the rule describes it: one fact, one input, one
             * destination. `event_note` is the wrong home besides being the second one — it is the
             * note ABOUT a rung, and confirmStage() overwrites it with the confirming party's note,
             * so a copy kept there is a copy that silently disappears.
             */
            $stageEvent = $this->collaborationService->claimStage(
                $request,
                $helperAccount,
                $actor,
                SuchakCollaborationStageEvent::STAGE_PROFILE_SUGGESTED,
                null,
            );

            return $created + ['stage_event' => $stageEvent];
        });

        /** @var SuchakCollaborationRequest $request */
        $request = $proposal['request'];

        // Filed under the ORIGINATING Suchak. The collaboration log row createRequest() writes is
        // filed under the requester — the helper — so without this the publisher would have no
        // record of what happened to his own published candidate (D18).
        $this->recordActivity(
            SuchakActivityLog::ACTION_MARKETPLACE_PROPOSAL_RECEIVED,
            $challenge->fresh() ?? $challenge,
            $actor,
            $ipAddress,
            $userAgent,
            [
                'proposing_suchak_account_id' => (int) $helperAccount->id,
                'collaboration_request_id' => (int) $request->id,
                'proposed_representation_id' => (int) $helperRepresentation->id,
            ],
        );

        // U12: tell the publisher a proposal arrived (RT-11 fires where the activity is logged).
        $this->notifyPublisherProposalReceived($challenge, $helperAccount);

        return $proposal;
    }

    /**
     * U12: database+push to the publishing Suchak only — never the proposer (RT-4/5/14).
     */
    private function notifyPublisherProposalReceived(
        SuchakMarketplaceChallenge $challenge,
        SuchakAccount $helperAccount,
    ): void {
        $publisher = $this->publisherAccount($challenge);
        $publisher->loadMissing('user');
        $user = $publisher->user;
        if (! $user instanceof User) {
            return;
        }

        // Proposer must never be notified of their own action.
        if ((int) $publisher->id === (int) $helperAccount->id) {
            return;
        }

        SafeNotifier::notify(
            $user,
            new MarketplaceProposalReceivedNotification(
                (int) $challenge->id,
                (string) ($helperAccount->suchak_name ?: 'Suchak'),
            ),
        );
    }

    /**
     * The proposals standing against ONE of the publisher's challenges — the door he accepts or
     * rejects from.
     *
     * Without it accept-by-proposing has a blind door: `GET /suchak/collaborations` gives him an id
     * and a Suchak name, and D19's reasoning cuts both ways — a commitment made on partial
     * information is a bad one. The proposed candidate is presented through
     * SuchakCandidateMaskingService like every other cross-Suchak read, so the helper's own D19a
     * defaults protect his candidate exactly as the publisher's protect his.
     *
     * The declared share is NOT repeated per proposal: it is the challenge's, one per listing, and
     * printing it on every row would invite the two to disagree.
     *
     * BOTH gates run, and ownership alone is not one of them. This read returns another Suchak's
     * candidate through the same masked payload browse() and openListing() return, so D18 — "the
     * marketplace is visible to verified Suchaks only" — applies to it exactly as it applies to
     * them. Owning the challenge is not a substitute for holding the badge: a publisher whose
     * verification has been withdrawn or set back to pending is refused the whole marketplace,
     * including the door into his own listing's proposals (proven: `GET /marketplace/challenges`
     * 422 while `GET /marketplace/challenges/{id}/proposals` returned 200 with the full masked
     * payload of another Suchak's candidate).
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function proposalsFor(
        SuchakMarketplaceChallenge $challenge,
        SuchakAccount $account,
        int $perPage = 20,
    ): LengthAwarePaginator {
        $account->refresh();
        $this->assertMarketplaceViewer($account);

        if ((int) $challenge->suchak_account_id !== (int) $account->id) {
            throw new InvalidArgumentException('हे आव्हान तुमच्या खात्याचे नाही.');
        }

        return SuchakCollaborationRequest::query()
            ->where('marketplace_challenge_id', $challenge->id)
            ->with([
                'requestingSuchakAccount',
                'requestingRepresentation.matrimonyProfile',
                'commissionAgreement',
            ])
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (SuchakCollaborationRequest $request): array => $this->proposalPayload($request));
    }

    /**
     * @return array<string, mixed>
     */
    public function proposalPayload(SuchakCollaborationRequest $request): array
    {
        $representation = $request->requestingRepresentation;
        $profile = $representation?->matrimonyProfile;

        return [
            'collaboration_id' => (int) $request->id,
            'challenge_id' => $request->marketplace_challenge_id === null
                ? null
                : (int) $request->marketplace_challenge_id,
            'status' => $request->status,
            'marketplace_stage' => $request->marketplace_stage,
            'message' => $request->message,
            'requested_at' => $request->requested_at?->toIso8601String(),
            'expires_at' => $request->expires_at?->toIso8601String(),
            'proposing_suchak' => [
                'suchak_account_id' => (int) $request->requesting_suchak_account_id,
                'suchak_name' => $request->requestingSuchakAccount?->suchak_name,
                'is_verified' => $request->requestingSuchakAccount?->isVerified() === true,
            ],
            'proposed_candidate' => $profile === null
                ? null
                : $this->maskingService->maskedSummary($profile, $representation),
        ];
    }

    // ── The helper's own candidates, searchable and ranked (D7a) ──────────────────────────────

    /**
     * WHICH OF MY CANDIDATES SHOULD ANSWER THIS CHALLENGE — the read D7 has no meaning without.
     *
     * D7 says a helper must select a specific candidate to propose. D7a says that selection needs
     * search and filters, not a list, because a working Suchak may hold two hundred candidates and
     * scrolling is not a selection mechanism. Until this method existed, both readers of a Suchak's
     * own candidates took NO filters at all, so proposing meant scrolling two hundred names beside a
     * challenge whose candidate you had to keep in your head.
     *
     * FOUR things this method refuses to own:
     *
     *  - The filters. SuchakCrossSearchService::ownRepresentationsQuery() applies them through the
     *    one filter owner, so `age_min` means the same thing here and on /suchak/search, and the
     *    three D7a filters this feature added (name, location, income) landed there rather than as a
     *    private marketplace copy.
     *  - The score. SuchakMatchFitService::fit() is the real engine (→ MatchingService), the same one
     *    suggestedOpportunities() and the member feed use. No second matcher.
     *  - The badge. assertMarketplaceViewer(), spelled in exactly one place across the marketplace.
     *  - Answerability. assertChallengeAcceptsProposals() — the SAME gate proposeCandidate() runs, so
     *    a candidate this list offers is a candidate the propose call will accept. A list that can
     *    show you a choice the next request refuses is worse than no list.
     *
     * THE RANKING SLAB, and why it does not bite here. suggestedOpportunities() pre-slices with
     * `limit(max($limit * 10, 30))` ordered by id and then ranks what it drew, so it ranks a slab
     * rather than the corpus — a real limitation, in a method whose corpus is every other Suchak's
     * candidate on the platform and therefore unbounded. It is not inherited, because this method
     * does not call it: what is reused is the RANKING (fit()), which is the part suggestedOpportunities()
     * itself delegates, and the slab belongs to that method's own corpus query. Here the corpus is
     * ONE Suchak's own book, already narrowed by the filters, and D7a's stated worst case is two
     * hundred — so the whole filtered set is scored and the page is taken AFTER sorting, never
     * before. Ranking a page instead of the corpus is exactly the bug that would put the best match
     * on page four. MAX_RANKED_OWN_CANDIDATES bounds the loop at 500; a Suchak past it must filter,
     * and the filters are the point of this endpoint.
     *
     * MASKING: NONE, and that is deliberate. Masking is what one Suchak may see of ANOTHER Suchak's
     * candidate (D19a). These are the caller's own — his own names, his own villages, his own phone
     * numbers, already on his own customer list. No cross-Suchak row can enter the list either:
     * ownRepresentationsQuery() is scoped `where suchak_account_id = <caller>` and the challenge's
     * own candidate belongs, by assertNotOwnChallenge(), to a different account.
     *
     * @param  array<string, mixed>  $filters  see SuchakCrossSearchService::applyProfileFilters()
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function ownCandidatesFor(
        SuchakMarketplaceChallenge $challenge,
        SuchakAccount $helperAccount,
        array $filters = [],
        int $perPage = 20,
        int $page = 1,
    ): LengthAwarePaginator {
        $helperAccount->refresh();
        $challenge->refresh();

        $this->assertMarketplaceViewer($helperAccount);
        $this->assertNotOwnChallenge($challenge, $helperAccount);
        $this->assertChallengeAcceptsProposals($challenge, $helperAccount);

        $challengeRepresentation = $this->challengeRepresentation($challenge);
        $challengeRepresentation->loadMissing([
            'matrimonyProfile.gender',
            'matrimonyProfile.maritalStatus',
            'matrimonyProfile.religion',
            'matrimonyProfile.caste',
            'matrimonyProfile.location.parent.parent.parent',
            'matrimonyProfile.occupationMaster',
        ]);
        $challengeProfile = $challengeRepresentation->matrimonyProfile;

        if (! $challengeProfile instanceof MatrimonyProfile) {
            throw new InvalidArgumentException('या आव्हानाचे स्थळ सापडले नाही.');
        }

        $alreadyProposed = $this->alreadyProposedRepresentationIds($challenge);

        $ranked = $this->crossSearchService
            ->ownRepresentationsQuery($helperAccount, $filters)
            ->limit(self::MAX_RANKED_OWN_CANDIDATES)
            ->get()
            ->map(function (SuchakProfileRepresentation $representation) use (
                $challengeProfile,
                $challengeRepresentation,
                $alreadyProposed,
            ): ?array {
                $profile = $representation->matrimonyProfile;

                if (! $profile instanceof MatrimonyProfile) {
                    return null;
                }

                /*
                 * SEEKER = the challenge's candidate, CANDIDATE = the row being ranked. The score is
                 * directional, and the question this screen asks is the publisher's: "how well does
                 * this candidate of mine answer his need?" It is also the shape every existing call
                 * already uses — the row being ranked is always fit()'s second argument, in
                 * SuchakCrossSearchService::fitSummary() and in bestFitAmong() alike.
                 *
                 * A null fit (ineligible pair — same gender, self, a hard preference conflict — or a
                 * score under the surfacing floor) is scored 0 and KEPT, never dropped. The propose
                 * call applies no fit floor, so dropping the row would hide a candidate the Suchak is
                 * entitled to propose behind a filter he was never shown and cannot turn off.
                 */
                /*
                 * THE MASKED SIDE HERE IS THE SEEKER, not the candidate — the inverse of every other
                 * fit() call, and the reason the argument names the masked side rather than "the
                 * candidate". The rows being ranked are the helper's OWN (unmasked, see above); it is
                 * the CHALLENGE's candidate that belongs to another Suchak. Without this the picker
                 * was the cheapest oracle of the three: the helper's own candidates already sit in
                 * villages he chose, so reading which of them scored "same city" instead of "same
                 * taluka" named the challenge candidate's village in a single page load, no probing.
                 */
                $fit = $this->matchFitService->fit($challengeProfile, $profile, $challengeRepresentation);

                return $this->ownCandidatePayload(
                    $representation,
                    $profile,
                    $fit,
                    isset($alreadyProposed[(int) $representation->id]),
                );
            })
            ->filter()
            /*
             * Best first, then representation id ascending so equal scores have ONE order and page 2
             * never repeats a row from page 1.
             *
             * An explicit COMPARATOR, not sortBy([...]). Collection::sortBy() with an array treats a
             * callable element as a two-argument comparator rather than as a key extractor, so a
             * one-argument `fn ($row) => -$row['match_score']` is silently called as `$fn($a, $b)`
             * and its return value used as the comparison — which is not antisymmetric and produced
             * an order that depended on which row uasort() happened to pass first. It put a 31 above
             * a 57. Caught by the ranking test.
             */
            ->sort(static fn (array $a, array $b): int => [
                (int) $b['match_score'],
                (int) $a['representation_id'],
            ] <=> [
                (int) $a['match_score'],
                (int) $b['representation_id'],
            ])
            ->values();

        return $this->paginateRanked($ranked, $perPage, $page);
    }

    /**
     * The representations already standing against this challenge, in ONE query.
     *
     * Status-blind, exactly like assertNotAlreadyProposed(), because that guard is what the flag
     * exists to predict: a rejected proposal is still refused if re-sent, so a card that showed it as
     * available would be lying about what the next request will do. D7a's whole point is that the
     * Suchak picks rather than discovers by failing.
     *
     * @return array<int, true>
     */
    private function alreadyProposedRepresentationIds(SuchakMarketplaceChallenge $challenge): array
    {
        return SuchakCollaborationRequest::query()
            ->where('marketplace_challenge_id', $challenge->id)
            ->pluck('requesting_representation_id')
            ->filter()
            ->mapWithKeys(static fn ($id): array => [(int) $id => true])
            ->all();
    }

    /**
     * One own-candidate row. NOT SuchakCandidateMaskingService::maskedSummary() — see
     * ownCandidatesFor() on why, and note that the three shared reads it does use (age, the location
     * walk, the master label) are that service's, so the two presentations state the same facts the
     * same way.
     *
     * `income_display` and `annual_income` are the SAME figure, resolved by
     * IncomeEngineService::comparableAnnualAmount() — the read half of the rule the income filter
     * compares on. A card that printed one column while the filter compared another would send a
     * Suchak to argue with his own search.
     *
     * @param  array<string, mixed>|null  $fit
     * @return array<string, mixed>
     */
    private function ownCandidatePayload(
        SuchakProfileRepresentation $representation,
        MatrimonyProfile $profile,
        ?array $fit,
        bool $alreadyProposed,
    ): array {
        $income = $this->incomeEngine->comparableAnnualAmount($profile);
        $photoPath = trim((string) ($profile->profile_photo ?? ''));

        return [
            'representation_id' => (int) $representation->id,
            'candidate_profile_id' => (int) $profile->id,
            'display_name' => trim((string) ($profile->full_name ?? '')) ?: null,
            'age' => $this->maskingService->ageYears($profile->date_of_birth),
            'gender' => $this->maskingService->masterLabel($profile->gender),
            'district' => $this->maskingService->locationNameOfType($profile->location, 'district'),
            'taluka' => $this->maskingService->locationNameOfType($profile->location, 'taluka'),
            'education' => trim((string) ($profile->highest_education ?? '')) ?: null,
            'annual_income' => $income,
            // MoneyFormat is the ONE money formatter: Latin digits, Indian grouping, null in / null
            // out so an undisclosed income never prints as ₹0. The currency is the candidate's own
            // (matrimony_profiles.income_currency_id), never assumed — a ₹ glyph in front of a USD
            // figure is the same class of defect as the challenge's refused `share_currency`.
            'income_display' => MoneyFormat::amount(
                $income,
                strtoupper(trim((string) ($profile->incomeCurrency?->code ?? ''))) ?: 'INR',
            ),
            // NULL when there is no photograph, rather than a placeholder URL: the client picks its
            // own placeholder, and "no photo" is a fact a Suchak acts on (D19a — a matchmaker who
            // cannot see a face cannot propose a match).
            'photo_url' => $photoPath === ''
                ? null
                : app(ProfilePhotoUrlService::class)->publicUrl($photoPath, $profile),
            'match_score' => (int) ($fit['match_score'] ?? 0),
            'fit_label' => $fit['fit_label'] ?? __('matching.suchak_fit_none'),
            'reasons' => array_values($fit['reasons'] ?? []),
            'already_proposed' => $alreadyProposed,
        ];
    }

    /**
     * Page a ranked, in-memory list. The sort is over the whole filtered corpus and the slice is
     * taken from it — the ordering is never re-decided per page.
     *
     * @param  Collection<int, array<string, mixed>>  $ranked
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginateRanked(Collection $ranked, int $perPage, int $page): LengthAwarePaginator
    {
        $perPage = max(1, $perPage);
        $page = max(1, $page);

        return new Paginator(
            $ranked->slice(($page - 1) * $perPage, $perPage)->values()->all(),
            $ranked->count(),
            $perPage,
            $page,
        );
    }

    /**
     * The challenge's own candidate, as the TARGET of the reversed engagement.
     *
     * Loaded from the challenge rather than accepted as a parameter, so a helper can never name
     * which candidate he is answering: he answers the one the challenge is for, or nothing.
     */
    /**
     * The ORIGINATING Suchak behind a challenge — the party §7.2 says must answer helper claims.
     *
     * Loaded from the row rather than accepted as a parameter, for the same reason
     * challengeRepresentation() is: a caller must never be able to name which Suchak's record the
     * stop-loss is checked against.
     */
    private function publisherAccount(SuchakMarketplaceChallenge $challenge): SuchakAccount
    {
        $challenge->loadMissing('suchakAccount');
        $account = $challenge->suchakAccount;

        if (! $account instanceof SuchakAccount) {
            throw new InvalidArgumentException('या आव्हानाचा सूचक सापडला नाही.');
        }

        return $account;
    }

    private function challengeRepresentation(SuchakMarketplaceChallenge $challenge): SuchakProfileRepresentation
    {
        $challenge->loadMissing('representation');
        $representation = $challenge->representation;

        if (! $representation instanceof SuchakProfileRepresentation) {
            throw new InvalidArgumentException('या आव्हानाचे स्थळ सापडले नाही.');
        }

        return $representation;
    }

    // ── Withdrawing ───────────────────────────────────────────────────────────────────────────

    /**
     * The publisher pulls his own live challenge.
     *
     * The row stays. A7 (realized-vs-declared) and A8 (the share sticks to candidates already
     * suggested under it for twelve months) both read declarations a publisher would prefer gone,
     * so withdrawal changes the status and records the stated reason — it never deletes.
     */
    public function withdraw(
        SuchakMarketplaceChallenge $challenge,
        SuchakAccount $account,
        User $actor,
        ?string $reason = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakMarketplaceChallenge {
        $account->refresh();
        $this->assertMarketplaceActor($account, $actor);

        if ((int) $challenge->suchak_account_id !== (int) $account->id) {
            throw new InvalidArgumentException('हे आव्हान तुमच्या खात्याचे नाही.');
        }

        $withdrawn = DB::transaction(function () use ($challenge, $actor, $reason): SuchakMarketplaceChallenge {
            /** @var SuchakMarketplaceChallenge $locked */
            $locked = SuchakMarketplaceChallenge::query()
                ->whereKey($challenge->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isOpen()) {
                throw new InvalidArgumentException('फक्त खुले आव्हान मागे घेता येते.');
            }

            $locked->forceFill([
                'status' => SuchakMarketplaceChallenge::STATUS_WITHDRAWN,
                'withdrawn_by_user_id' => $actor->id,
                'withdrawn_at' => now(),
                'withdrawn_reason' => $this->nullableLimitedString($reason, 2000),
            ])->save();

            return $locked;
        });

        $this->recordActivity(
            SuchakActivityLog::ACTION_MARKETPLACE_CHALLENGE_WITHDRAWN,
            $withdrawn,
            $actor,
            $ipAddress,
            $userAgent,
            ['withdrawn_reason' => $withdrawn->withdrawn_reason],
        );

        return $withdrawn;
    }

    // ── Expiring ──────────────────────────────────────────────────────────────────────────────

    /**
     * Close every open challenge whose publisher-chosen expiry has passed.
     *
     * Scoped to one account when given one, so the sweep can run on the Suchak's own read path the
     * way SuchakCollaborationService::expireForAccount() does, instead of waiting for a scheduler
     * that may not be running. A NULL expiry is never swept: it means "open until I withdraw it".
     *
     * @return int number of challenges expired
     */
    public function expireDue(?SuchakAccount $account = null): int
    {
        $due = SuchakMarketplaceChallenge::query()
            ->where('status', SuchakMarketplaceChallenge::STATUS_OPEN)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->when($account !== null, fn (Builder $query): Builder => $query->where('suchak_account_id', $account->id))
            ->orderBy('id')
            ->get();

        $expired = 0;

        foreach ($due as $challenge) {
            $closed = DB::transaction(function () use ($challenge): ?SuchakMarketplaceChallenge {
                /** @var SuchakMarketplaceChallenge $locked */
                $locked = SuchakMarketplaceChallenge::query()
                    ->whereKey($challenge->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $locked->isOpen() || ! $locked->isPastExpiry()) {
                    return null;
                }

                $locked->forceFill(['status' => SuchakMarketplaceChallenge::STATUS_EXPIRED])->save();

                return $locked;
            });

            if ($closed === null) {
                continue;
            }

            // Actor `system`: nobody acted, a date arrived.
            $this->recordActivity(
                SuchakActivityLog::ACTION_MARKETPLACE_CHALLENGE_EXPIRED,
                $closed,
                null,
                null,
                null,
                [],
            );

            $expired++;
        }

        return $expired;
    }

    // ── The listing read (D18 / D19a) ─────────────────────────────────────────────────────────

    /**
     * What a VERIFIED Suchak browsing the marketplace sees.
     *
     * Own challenges are excluded: a publisher reading his own listing is not market discovery, and
     * counting him as a viewer would poison the read log D18 shows him. Candidates whose consent
     * has lapsed drop out through the representation's own scopeWithValidConsent(), so there is one
     * definition of "consent is good right now" rather than a second one written here.
     *
     * Deliberately NOT logged per card. D18 logs a listing OPEN — twelve rows per scroll would bury
     * the signal it exists to give the originating Suchak. openListing() is the logged read.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function browse(SuchakAccount $viewer, int $perPage = 12): LengthAwarePaginator
    {
        $viewer->refresh();
        $this->assertMarketplaceViewer($viewer);
        $this->expireDue();

        return SuchakMarketplaceChallenge::query()
            ->live()
            ->whereIn('audience', $this->audiencesAdmitting($viewer))
            ->where('suchak_account_id', '!=', $viewer->id)
            ->whereHas('representation', fn (Builder $query) => $query->withValidConsent())
            ->with($this->listingRelations())
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (SuchakMarketplaceChallenge $challenge): array => $this->listingPayload($challenge));
    }

    /**
     * Open ONE listing. D18: "every listing open is logged and shown to the originating Suchak."
     *
     * The log row's `suchak_account_id` is the ORIGINATING Suchak because the log is shown to him;
     * the viewer travels as `actor_user_id` plus `viewer_suchak_account_id` in the metadata.
     */
    public function openListing(
        SuchakMarketplaceChallenge $challenge,
        SuchakAccount $viewer,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $viewer->refresh();
        $this->assertMarketplaceViewer($viewer);

        if ((int) $challenge->suchak_account_id === (int) $viewer->id) {
            throw new InvalidArgumentException('स्वतःचे आव्हान बाजारपेठेतून उघडता येत नाही.');
        }

        $challenge->loadMissing($this->listingRelations());

        if (! $challenge->isBrowsableBy($viewer)) {
            throw new InvalidArgumentException('हे आव्हान आता खुले नाही.');
        }

        if ($challenge->representation?->hasValidConsent() !== true) {
            throw new InvalidArgumentException('या स्थळाची संमती आता वैध नाही.');
        }

        $this->recordActivity(
            SuchakActivityLog::ACTION_MARKETPLACE_LISTING_OPENED,
            $challenge,
            $actor,
            $ipAddress,
            $userAgent,
            ['viewer_suchak_account_id' => (int) $viewer->id],
        );

        /*
         * §7.2 clause 2 — "publish an immediate, raw counter on his card: '6 helper claims
         * unanswered, oldest 91 days'".
         *
         * On THIS read and not on browse(): this is the single-listing open a helper performs
         * before deciding (D19 — a commitment made on partial information is a bad one), and
         * clause 3 will refuse him here if the counter is over. Putting it on the twelve-card
         * browse would be twelve extra counts per scroll for a figure nobody is acting on yet.
         *
         * It is a fact about the publisher, computed from records, never typed by anyone — the
         * same rule D20 sets for customer history.
         */
        return $this->listingPayload($challenge) + [
            'unanswered_claims' => $this->claimSilenceService->unansweredClaimSummaryAfterSweep(
                $this->publisherAccount($challenge),
            ),
        ];
    }

    /**
     * The publisher's own challenges — the door through which he finds the id he withdraws.
     *
     * ── HIS OWN CANDIDATE IS NOT MASKED FROM HIM ─────────────────────────────────────────────
     *
     * This list used to hand every row to {@see self::listingPayload()}, whose `candidate` block is
     * {@see SuchakCandidateMaskingService::maskedSummary()} — the CROSS-Suchak presenter, whose entire
     * purpose is D19a's four defaults, "what one Suchak may see of ANOTHER Suchak's candidate". The
     * viewer here is the publisher. So the "My challenges" tab printed his own customer as
     * "राजश्री ग." with her village withheld: he could not tell two of his own candidates apart when
     * they share a first name, and the platform was hiding a family's village from the man who
     * visited their house.
     *
     * The fix is which rows go through the mask, not the mask. `browse()`, `openListing()`,
     * `proposalPayload()` and every other cross-Suchak read still call `listingPayload()` unchanged,
     * and `maskedSummary()` itself is untouched — it remains the single cross-Suchak presenter, with
     * exactly the same four defaults and the same per-candidate reveals on top.
     *
     * The precedent is the proposal INBOX, which is this same situation one screen along: it
     * publishes the OWNER's candidate unmasked ("MY candidate, unmasked, because he is mine") while
     * masking every candidate proposed TO him, and strips the masked copy out of the listing payload
     * rather than printing both. {@see self::ownCandidateSummary()} is that rule applied here.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function published(SuchakAccount $account, int $perPage = 20): LengthAwarePaginator
    {
        $account->refresh();
        $this->expireDue($account);

        // His OWN counter, computed once for the whole page — every row on it belongs to this one
        // account, so calling it inside the closure would be the same query N times. He sees what
        // a helper opening his listing sees, which is the point: §7.2 clause 3 will stop his next
        // helper, and a Suchak who cannot see why cannot fix it.
        $unansweredClaims = $this->claimSilenceService->unansweredClaimSummaryAfterSweep($account);

        return SuchakMarketplaceChallenge::query()
            ->where('suchak_account_id', $account->id)
            ->with($this->listingRelations())
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            /*
             * `['candidate' => …]` FIRST, because `+` keeps the left operand's value on a duplicate
             * key: the row is the listing verbatim with exactly one block replaced. Nothing else
             * about the payload moves, so the client that renders this tab keeps every key it reads.
             */
            ->through(fn (SuchakMarketplaceChallenge $challenge): array => [
                'candidate' => $this->ownCandidateSummary($challenge),
            ] + $this->listingPayload($challenge) + [
                'withdrawn_at' => $challenge->withdrawn_at?->toIso8601String(),
                'withdrawn_reason' => $challenge->withdrawn_reason,
                'fulfilled_at' => $challenge->fulfilled_at?->toIso8601String(),
                'unanswered_claims' => $unansweredClaims,
            ]);
    }

    /**
     * The publisher's OWN candidate, in the listing's own shape, with D19a's hidden facts restored.
     *
     * ── WHY THIS COMPOSES RATHER THAN REBUILDS ───────────────────────────────────────────────
     *
     * D19a hides exactly four things from ANOTHER Suchak: name, village, detailed address and
     * mobile. Every OTHER fact on the card — age, height, religion, caste, education, occupation,
     * representation state, photograph — is identical whoever is reading, and
     * {@see SuchakCandidateMaskingService::maskedSummary()} is the one place that states them. A
     * second full presenter here would be forty lines of the same shape, free to drift key by key,
     * which is precisely the duplicate the frozen rule names. So the shared facts are read from the
     * one presenter and only the hidden ones are answered for an OWNER:
     *
     *  - `display_name` — his own customer's real name. `CandidateNameMask` and the typed
     *    `shared_display_name` are answers to "what may ANOTHER Suchak call her"; neither is an
     *    answer to what she is called.
     *  - `location` — the village he visited, the exact address, and `is_broad` reporting FALSE
     *    because that flag must always say what was actually sent (§10 S5: a flag claiming "broad"
     *    while carrying a village is a bug whatever the policy is).
     *
     * ── WHAT IS DELIBERATELY *NOT* CHANGED HERE ──────────────────────────────────────────────
     *
     * `contact` stays masked. D19a's fourth default is the mobile, and `shares_mobile` has no reader
     * anywhere in this codebase today — the number is never released to any Suchak through this
     * presenter, own candidate or not. Wiring the contact-number model into a marketplace listing is
     * a different question with its own owner, and this defect does not ask it.
     *
     * @return array<string, mixed>|null
     */
    private function ownCandidateSummary(SuchakMarketplaceChallenge $challenge): ?array
    {
        $challenge->loadMissing($this->listingRelations());
        $representation = $challenge->representation;
        $profile = $representation?->matrimonyProfile;

        if (! $profile instanceof MatrimonyProfile) {
            return null;
        }

        $summary = $this->maskingService->maskedSummary($profile, $representation);

        $summary['display_name'] = trim((string) ($profile->full_name ?? '')) ?: $summary['display_name'];
        $summary['location'] = [
            'city' => $this->maskingService->locationNameForCitySlot($profile->location),
            'district' => $this->maskingService->locationNameOfType($profile->location, 'district'),
            'is_broad' => false,
            'exact_address' => trim((string) ($profile->address_line ?? '')) ?: null,
        ];

        return $summary;
    }

    /**
     * One listing: the masked candidate, the declared share, the expiry.
     *
     * `candidate` is SuchakCandidateMaskingService's output verbatim. It is not re-shaped, not
     * trimmed and not augmented, because the moment this method starts deciding what another Suchak
     * may see there are two masking rules in the codebase and D19a is enforced by whichever one the
     * caller happened to reach.
     *
     * @return array<string, mixed>
     */
    public function listingPayload(SuchakMarketplaceChallenge $challenge): array
    {
        $challenge->loadMissing($this->listingRelations());
        $representation = $challenge->representation;
        $profile = $representation?->matrimonyProfile;

        return [
            'challenge_id' => (int) $challenge->id,
            'status' => $challenge->status,
            'audience' => $challenge->audience,
            'published_at' => $challenge->published_at?->toIso8601String(),
            'expires_at' => $challenge->expires_at?->toIso8601String(),
            // NULL expiry is a decision, not an omission, and the client must not print "—" for it.
            'expires_never' => $challenge->expires_at === null,
            'publisher_note' => $challenge->publisher_note,
            'publisher' => [
                'suchak_account_id' => (int) $challenge->suchak_account_id,
                'suchak_name' => $challenge->suchakAccount?->suchak_name,
                'is_verified' => $challenge->suchakAccount?->isVerified() === true,
            ],
            'declared_share' => $this->declaredSharePayload($challenge),
            'candidate' => $profile === null
                ? null
                : $this->maskingService->maskedSummary($profile, $representation),
        ];
    }

    /**
     * The declaration, plus the base it is a share OF.
     *
     * A percent without its base is not a declaration — "30%" tells a helper nothing about whether
     * the work is worth doing. Section 9's visibility matrix explicitly allows another customer's
     * fees to other verified Suchaks ("market economics"), and D19's reasoning is the same one: a
     * commitment made on partial information is a bad one. The base is READ from
     * suchak_service_packages.post_marriage_fee_amount, the fee's one owner, and `estimated_amount`
     * is arithmetic performed here rather than a second stored figure that could drift from it.
     *
     * The CURRENCY is read the same way, and for the same reason. Every string below is one number
     * plus one label, and the label is not this row's to choose: a share is a slice of the money the
     * agreement froze, so it is spent in that money. `currency` stays in the payload because the
     * client cannot render without it — but it is a read of the agreement, not a field of the row.
     *
     * @return array<string, mixed>
     */
    private function declaredSharePayload(SuchakMarketplaceChallenge $challenge): array
    {
        $package = $challenge->customerAgreement?->servicePackage;
        $currency = $challenge->declaredShareCurrency();

        $successFee = $package?->post_marriage_fee_mode === SuchakCustomerPlan::MODE_FIXED
            && $package?->post_marriage_fee_amount !== null
            ? (float) $package->post_marriage_fee_amount
            : null;

        $isPercent = $challenge->declared_share_type === SuchakCommissionAgreement::SPLIT_CUSTOM_PERCENT;
        $percent = $challenge->declared_share_percent === null ? null : (float) $challenge->declared_share_percent;
        $amount = $challenge->declared_share_amount === null ? null : (float) $challenge->declared_share_amount;

        // The rupee value of the declaration has ONE owner (2026-08-04): the arithmetic used to sit
        // inline here, and the cross-Suchak obligation needs the identical answer to freeze — a
        // listing quoting ₹30,000 and a debt quoting anything else is one promise with two numbers.
        $estimated = $challenge->declaredShareTotal();

        return [
            'type' => $challenge->declared_share_type,
            'currency' => $currency,
            // Latin digits by construction: no locale-aware formatter touches either number.
            'percent' => $percent === null ? null : rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.'),
            'amount' => $amount === null ? null : (string) $amount,
            'display' => $isPercent && $percent !== null
                ? rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.').'%'
                : MoneyFormat::amount($amount, $currency),
            'success_fee_amount' => $successFee === null ? null : (string) $successFee,
            'success_fee_display' => MoneyFormat::amount($successFee, $currency),
            'estimated_share_display' => MoneyFormat::amount($estimated, $currency),
        ];
    }

    /** @return list<string> */
    private function listingRelations(): array
    {
        return [
            'suchakAccount',
            'representation.matrimonyProfile',
            'customerAgreement.servicePackage',
        ];
    }

    /**
     * The audience values this viewer is admitted to, computed from the model's own rule so the
     * SQL filter and audienceAdmits() can never disagree.
     *
     * PUBLIC since phase 5 (2026-08-05) for the same reason assertMarketplaceViewer() is: the
     * market economics view has to count exactly the listings browse() would show this viewer, and
     * a second `whereIn('audience', …)` written from the constant list would stop agreeing with
     * audienceAdmits() the day a second audience exists.
     *
     * @return list<string>
     */
    public function audiencesAdmitting(SuchakAccount $viewer): array
    {
        $probe = new SuchakMarketplaceChallenge;

        return array_values(array_filter(
            SuchakMarketplaceChallenge::AUDIENCES,
            static function (string $audience) use ($probe, $viewer): bool {
                $probe->audience = $audience;

                return $probe->audienceAdmits($viewer);
            },
        ));
    }

    // ── Guards ────────────────────────────────────────────────────────────────────────────────

    /**
     * D18 + A10: marketplace participation is tied to the verification badge, whoever is acting.
     *
     * ONE method for publishing and for proposing. It was two — assertPublisher() and
     * assertProposer() — byte-for-byte identical down to both refusal strings, which is the frozen
     * no-duplicate rule broken by copy: two spellings of one rule diverge the first time one of them
     * is edited, and the marketplace's whole claim is that it has one participation rule and not
     * two. The badge half is assertMarketplaceViewer(), so the badge is spelled in exactly one place
     * across publishing, browsing, opening, proposing and reading proposals.
     *
     * Strictly stronger than SuchakAccessService::canOperate(), which admits a PENDING account when
     * the policy allows work before admin approval. That allowance is right for a Suchak building
     * his own book and wrong here — A10's attack is one person running two accounts and colluding,
     * and an unverified account is exactly the cheap second account. Being stronger, it also
     * satisfies claimCustomerStage()'s own canOperate() check by construction.
     */
    private function assertMarketplaceActor(SuchakAccount $account, User $actor): void
    {
        if ((int) $account->user_id !== (int) $actor->id) {
            throw new InvalidArgumentException('फक्त सूचक खात्याचा मालक हे करू शकतो.');
        }

        $this->assertMarketplaceViewer($account);
    }

    /**
     * The badge, and nothing else. Every marketplace surface that shows another Suchak's candidate
     * or forms an obligation against one passes through here: browse(), openListing(),
     * proposalsFor(), and — via assertMarketplaceActor() — publish() and proposeCandidate().
     *
     * PUBLIC since phase 5 (2026-08-05), and public rather than copied. The per-candidate proposal
     * inbox ({@see SuchakCandidateProposalInboxService}) returns other Suchaks' candidates through
     * the same masked payload proposalsFor() returns, and the market economics view
     * ({@see SuchakMarketEconomicsService}) publishes the figure §9's visibility matrix grants to
     * "other verified Suchaks" and to nobody else — so both need exactly this gate and neither may
     * spell it a second time. The docblock above says the badge is spelled in one place; widening
     * the visibility is how that stays true when a reader lands outside this class.
     */
    public function assertMarketplaceViewer(SuchakAccount $viewer): void
    {
        if (! $viewer->isVerified()) {
            throw new InvalidArgumentException('बाजारपेठ फक्त पडताळणी झालेल्या सूचकांना दिसते.');
        }
    }

    /**
     * A2: one person running two Suchak accounts and colluding. Same-account pairing is already
     * blocked structurally inside createRequest(); this refuses it as a marketplace rule, first and
     * in Marathi, exactly as openListing() refuses reading one's own listing.
     */
    private function assertNotOwnChallenge(SuchakMarketplaceChallenge $challenge, SuchakAccount $account): void
    {
        if ((int) $challenge->suchak_account_id === (int) $account->id) {
            throw new InvalidArgumentException('स्वतःच्या आव्हानाला स्वतःच स्थळ सुचवता येत नाही.');
        }
    }

    /**
     * The candidate must actually be this Suchak's, active, and consented — whether he is
     * PUBLISHING that candidate or PROPOSING him against someone else's challenge.
     *
     * One predicate for both acts, because it is one question. Consent is the load-bearing part.
     * Section 15 records why cross-Suchak sharing is legitimate at all: the consent the candidate
     * signed says the profile may be "forwarded to suitable and appropriate matches". Putting a
     * candidate whose consent has lapsed in front of another Suchak — as a listing or as a proposal
     * — is the one thing that sentence does not cover. D8 is the same rule from the other end: only
     * registered, consented profiles may be proposed.
     */
    private function assertMarketplaceCandidate(
        SuchakAccount $account,
        SuchakProfileRepresentation $representation,
    ): void {
        if ((int) $representation->suchak_account_id !== (int) $account->id) {
            throw new InvalidArgumentException('हे स्थळ तुमच्या खात्याचे नाही.');
        }

        if ($representation->representation_status !== SuchakProfileRepresentation::STATUS_ACTIVE) {
            throw new InvalidArgumentException('फक्त सक्रिय स्थळ बाजारपेठेत वापरता येते.');
        }

        if (! $representation->hasValidConsent()) {
            throw new InvalidArgumentException('संमती वैध असल्याशिवाय स्थळ बाजारपेठेत वापरता येणार नाही.');
        }
    }

    /**
     * A withdrawn, fulfilled or expired challenge takes no new proposals.
     *
     * `isBrowsableBy()` is the gate — the SAME one browse and openListing use, so a listing a Suchak
     * cannot see is a listing he cannot answer, and the two can never disagree. It evaluates expiry
     * live rather than trusting `status`, which is why no sweep runs here: a challenge whose day has
     * passed stops accepting proposals at that instant.
     *
     * The candidate's consent is re-read too. It was valid when the challenge was published; a
     * consent that has lapsed since means the listing should never have been answerable, and this is
     * where a helper finds that out rather than after committing.
     */
    private function assertChallengeAcceptsProposals(
        SuchakMarketplaceChallenge $challenge,
        SuchakAccount $helperAccount,
    ): void {
        if (! $challenge->isBrowsableBy($helperAccount)) {
            throw new InvalidArgumentException(
                'या आव्हानाला आता स्थळ सुचवता येणार नाही — '.$this->challengeClosedReason($challenge).'.'
            );
        }

        $challenge->loadMissing('representation');

        if ($challenge->representation?->hasValidConsent() !== true) {
            throw new InvalidArgumentException('या स्थळाची संमती आता वैध नाही.');
        }
    }

    /**
     * Phrases the refusal only — the GATE is isBrowsableBy() and nothing else. Reads the row's own
     * timestamps rather than translating the status enum, the same way
     * SuchakCollaborationService::customerAgreementStateReason() does, so no second list of statuses
     * exists here to drift away from the one that decides.
     */
    private function challengeClosedReason(SuchakMarketplaceChallenge $challenge): string
    {
        if ($challenge->withdrawn_at !== null) {
            return 'प्रसिद्ध करणाऱ्याने ते मागे घेतले आहे';
        }

        if ($challenge->fulfilled_at !== null) {
            return 'या आव्हानासाठी स्थळ आधीच निश्चित झाले आहे';
        }

        if ($challenge->isPastExpiry()) {
            return 'त्याची मुदत संपली आहे';
        }

        if (! $challenge->isOpen()) {
            return 'ते आता खुले नाही';
        }

        return 'बाजारपेठ फक्त पडताळणी झालेल्या सूचकांना दिसते';
    }

    /**
     * A10: the same candidate, to the same challenge, twice.
     *
     * Status-BLIND on purpose. assertNoDuplicateOpenRequest() inside createRequest() already stops
     * a second OPEN proposal; re-proposing the identical candidate to the identical challenge after
     * a rejection is not a retry, it is putting the same question to a publisher who has already
     * answered it. The database carries the same rule as
     * `unique(marketplace_challenge_id, requesting_representation_id)`, so a future second entrance
     * cannot reintroduce it; this check exists to say so in Marathi instead of with a 500.
     */
    private function assertNotAlreadyProposed(
        SuchakMarketplaceChallenge $challenge,
        SuchakProfileRepresentation $representation,
    ): void {
        $exists = SuchakCollaborationRequest::query()
            ->where('marketplace_challenge_id', $challenge->id)
            ->where('requesting_representation_id', $representation->id)
            ->exists();

        if ($exists) {
            throw new InvalidArgumentException('हे स्थळ या आव्हानासाठी तुम्ही आधीच सुचवले आहे.');
        }
    }

    /**
     * D4: the share is the CHALLENGE's, declared in advance and not negotiable.
     *
     * Refused rather than silently ignored, for the reason normalizeDeclaredShare() gives about the
     * currency: a client that keeps sending a field it believes is honoured is worse off than one
     * told plainly who owns it. This is the input-side half of H5 — the other half refuses to move
     * the split after the fact (SuchakCollaborationService::updateCommissionTerms).
     *
     * @param  array<string, mixed>  $input
     */
    private function assertNoDeclaredTerms(array $input): void
    {
        foreach (self::DECLARED_TERMS_INPUTS as $forbidden) {
            if (trim((string) ($input[$forbidden] ?? '')) !== '') {
                throw new InvalidArgumentException(self::REFUSAL_SHARE_ALREADY_DECLARED);
            }
        }
    }

    /**
     * Section 4: "Publication attaches to whichever agreement is accepted at that moment."
     *
     * The latest ACCEPTED revision on the candidate's customer context. Not the latest revision —
     * a pending revision is a proposal the customer has not agreed to, and D3 freezes amounts on
     * acceptance, so a share declared against un-accepted terms would be a slice of a number that
     * can still move.
     */
    private function acceptedAgreementFor(
        SuchakAccount $account,
        SuchakProfileRepresentation $representation,
    ): SuchakCustomerAgreement {
        $representation->loadMissing('customerContext');
        $context = $representation->customerContext;

        if ($context === null) {
            throw new InvalidArgumentException('या स्थळासाठी ग्राहक नोंद नाही; आधी करार तयार करा.');
        }

        /** @var SuchakCustomerAgreement|null $agreement */
        $agreement = SuchakCustomerAgreement::query()
            ->where('suchak_account_id', $account->id)
            ->where('customer_context_id', $context->id)
            ->where('terms_status', SuchakCustomerAgreement::TERMS_ACCEPTED)
            ->orderByDesc('agreement_revision')
            ->orderByDesc('id')
            ->first();

        if ($agreement === null) {
            throw new InvalidArgumentException('ग्राहकाने स्वीकारलेला करार असल्याशिवाय आव्हान प्रसिद्ध करता येणार नाही.');
        }

        return $agreement;
    }

    /**
     * A percent share only means something against a fixed figure.
     *
     * Same rule, and the same reasoning, as SuchakSuccessFeeTrancheService::
     * assertPackageCarriesFixedSuccessFee(): `as_wished` and `none` have no total to take a
     * percentage of. D5 makes `none` a legitimate choice — "a Suchak who declared nothing owes
     * nothing" — so the refusal is aimed at the contradiction of promising a percentage of it, not
     * at the mode. A FIXED-amount declaration needs no base and is allowed either way: it is a
     * rupee figure the publisher owes regardless of what his customer pays him.
     *
     * @param  array<string, mixed>  $terms
     */
    private function assertShareHasABase(SuchakCustomerAgreement $agreement, array $terms): void
    {
        if ($terms['declared_share_type'] !== SuchakCommissionAgreement::SPLIT_CUSTOM_PERCENT) {
            return;
        }

        $package = $agreement->servicePackage;

        if ($package === null
            || $package->post_marriage_fee_mode !== SuchakCustomerPlan::MODE_FIXED
            || $package->post_marriage_fee_amount === null
            || (float) $package->post_marriage_fee_amount <= 0.0) {
            throw new InvalidArgumentException('ठरलेले यशस्वी विवाह शुल्क नसताना टक्केवारीत वाटा जाहीर करता येणार नाही.');
        }
    }

    /**
     * The declaration, and ONLY the declaration.
     *
     * No currency is read from `$input`, and a caller who sends one is refused rather than quietly
     * ignored — a silently dropped field is how a client keeps believing it works. The currency has
     * one owner and this method is not it (SuchakMarketplaceChallenge::declaredShareCurrency()).
     *
     * @param  array<string, mixed>  $input
     * @return array{declared_share_type: string, declared_share_percent: ?string, declared_share_amount: ?string}
     */
    private function normalizeDeclaredShare(array $input): array
    {
        $type = (string) ($input['declared_share_type'] ?? '');

        if (! in_array($type, SuchakMarketplaceChallenge::DECLARED_SHARE_TYPES, true)) {
            throw new InvalidArgumentException('जाहीर वाटा टक्केवारीत किंवा ठरलेल्या रकमेत असावा.');
        }

        // The attack CURRENCY_INPUTS exists to close; the list itself lives on the class.
        foreach (self::CURRENCY_INPUTS as $forbidden) {
            if (trim((string) ($input[$forbidden] ?? '')) !== '') {
                throw new InvalidArgumentException(self::REFUSAL_CURRENCY_IS_THE_AGREEMENTS);
            }
        }

        if ($type === SuchakCommissionAgreement::SPLIT_CUSTOM_PERCENT) {
            $percent = $input['declared_share_percent'] ?? null;
            if (! is_numeric($percent) || (float) $percent <= 0.0 || (float) $percent > 100.0) {
                throw new InvalidArgumentException('जाहीर वाटा 0 पेक्षा जास्त आणि 100 पर्यंत असावा.');
            }

            return [
                'declared_share_type' => $type,
                'declared_share_percent' => number_format((float) $percent, 2, '.', ''),
                'declared_share_amount' => null,
            ];
        }

        $amount = $input['declared_share_amount'] ?? null;
        if (! is_numeric($amount) || (float) $amount <= 0.0) {
            throw new InvalidArgumentException('जाहीर रक्कम 0 पेक्षा जास्त असावी.');
        }

        return [
            'declared_share_type' => $type,
            'declared_share_percent' => null,
            'declared_share_amount' => number_format((float) $amount, 2, '.', ''),
        ];
    }

    /**
     * The publisher's own expiry decision.
     *
     * Explicitly NOT SuchakPolicyService::collaborationSlaDays(), which is a named counterparty's
     * deadline to answer a request that already has two parties. NULL is accepted and means "open
     * until I withdraw it".
     */
    private function normalizeExpiry(mixed $value): ?\Illuminate\Support\Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $expiresAt = \Illuminate\Support\Carbon::parse((string) $value);
        } catch (\Throwable) {
            throw new InvalidArgumentException('मुदत संपण्याची तारीख वाचता आली नाही.');
        }

        if ($expiresAt->isPast()) {
            throw new InvalidArgumentException('मुदत भविष्यातील असावी.');
        }

        return $expiresAt;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordActivity(
        string $actionType,
        SuchakMarketplaceChallenge $challenge,
        ?User $actor,
        ?string $ipAddress,
        ?string $userAgent,
        array $metadata,
    ): void {
        $challenge->loadMissing('representation');

        $this->activityLogger->record([
            // Always the ORIGINATING Suchak, including on a read by someone else: D18 shows this
            // log to him, and a row filed under the viewer's account would never reach him.
            'suchak_account_id' => $challenge->suchak_account_id,
            'actor_user_id' => $actor?->id,
            'actor_type' => $actor === null ? SuchakActivityLog::ACTOR_SYSTEM : SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => $actionType,
            'target_type' => 'suchak_marketplace_challenge',
            'target_id' => $challenge->id,
            'matrimony_profile_id' => $challenge->representation?->matrimony_profile_id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 512, ''),
            'metadata_json' => array_merge($metadata, [
                'representation_id' => (int) $challenge->representation_id,
                'status' => $challenge->status,
                'declared_share_type' => $challenge->declared_share_type,
                'declared_share_percent' => $challenge->declared_share_percent,
                'declared_share_amount' => $challenge->declared_share_amount,
                'expires_at' => $challenge->expires_at?->toIso8601String(),
            ]),
        ]);
    }

    private function nullableLimitedString(mixed $value, int $limit): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : Str::limit($normalized, $limit, '');
    }
}
