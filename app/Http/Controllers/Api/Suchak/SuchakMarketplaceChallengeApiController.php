<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakMarketplaceChallenge;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakMarketplaceChallengeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * The door for the challenge object (blueprint D4 / D18, phase 2).
 *
 * Every capability the service exposes has a route here, and nothing is left reachable only from a
 * test: publish, withdraw, the publisher's own list (without which he could never learn the id he
 * withdraws), the browse read, and the single-listing open that D18 requires to be logged.
 *
 * Authorisation copies SuchakCollaborationStagesApiController exactly, because this codebase has no
 * policy layer: the service's own InvalidArgumentException surfaced as 422, a caller-must-hold-a-
 * Suchak-account check in front, and a 404 for rows outside the caller's account — a Suchak has no
 * business learning that another Suchak's representation exists.
 *
 * The one deviation is `show`. A marketplace listing is, by design, another Suchak's row, so it is
 * NOT scoped to the caller's account; the gate is the challenge's own audience (D18: verified
 * Suchaks only) and its live status, both enforced in the service.
 */
class SuchakMarketplaceChallengeApiController extends Controller
{
    /**
     * POST /api/v1/suchak/marketplace/challenges
     *
     * Publish one of the caller's candidates with a declared share (D4). The customer agreement is
     * NOT a parameter: section 4 attaches publication to whichever revision is accepted at that
     * moment, so the service resolves it and freezes it onto the row.
     *
     * Body: `representation_id` (required int, caller's own), `declared_share_type`
     * (`custom_percent` | `fixed_amount`), `declared_share_percent` (required with
     * `custom_percent`, 0.01–100), `declared_share_amount` (required with `fixed_amount`),
     * `expires_at` (optional ISO date, future; omit for "open until I withdraw it"),
     * `publisher_note` (optional, max 2000).
     *
     * There is NO currency parameter, and sending one is a 422 rather than a silent drop. A share is
     * a slice of the success fee on the package the customer agreement froze, so it is denominated
     * in that agreement's currency and the publisher does not get to name it — with the parameter
     * present, `share_currency=USD` against an INR agreement rendered the publisher's own ₹1,00,000
     * success fee to every browsing Suchak as "USD 1,00,000".
     *
     * 201: `{ success, message, data: <listing payload> }` — the same shape browse returns, so the
     * publisher sees exactly what he has just put in front of the market.
     */
    public function store(
        Request $request,
        SuchakMarketplaceChallengeService $challengeService,
    ): JsonResponse {
        $user = $this->suchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $validated = $request->validate([
            'representation_id' => ['required', 'integer'],
            'declared_share_type' => ['required', 'string', Rule::in(SuchakMarketplaceChallenge::DECLARED_SHARE_TYPES)],
            'declared_share_percent' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'declared_share_amount' => ['nullable', 'numeric', 'gt:0'],
            'expires_at' => ['nullable', 'date'],
            'publisher_note' => ['nullable', 'string', 'max:2000'],
            // Named so they can be REFUSED. Leaving them out of the rules would let validate() drop
            // them silently, and a client that keeps sending a currency it believes is honoured is
            // worse off than one told plainly that the agreement owns it. The LIST is the service's
            // (CURRENCY_INPUTS) so the route and the service can never disagree about what is
            // refused, and the sentence is the service's too.
        ] + $this->prohibited(SuchakMarketplaceChallengeService::CURRENCY_INPUTS),
            $this->prohibitedMessages(
                SuchakMarketplaceChallengeService::CURRENCY_INPUTS,
                SuchakMarketplaceChallengeService::REFUSAL_CURRENCY_IS_THE_AGREEMENTS,
            ));

        /** @var SuchakProfileRepresentation|null $representation */
        $representation = SuchakProfileRepresentation::query()
            ->whereKey((int) $validated['representation_id'])
            ->where('suchak_account_id', $user->suchakAccount->id)
            ->first();

        if ($representation === null) {
            return $this->error(__('suchak.api.errors.profile_not_found'), 404);
        }

        try {
            $challenge = $challengeService->publish(
                $user->suchakAccount,
                $user,
                $representation,
                $validated,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('suchak.api.challenge.published'),
            'data' => $challengeService->listingPayload($challenge),
        ], 201);
    }

    /**
     * POST /api/v1/suchak/marketplace/challenges/{challenge}/withdraw
     *
     * Body: `withdrawn_reason` (optional, max 2000 — A8 makes the stated reason the only
     * contemporaneous evidence that will exist months later).
     * 200: `{ success, message, data: { challenge_id, status, withdrawn_at, withdrawn_reason } }`
     */
    public function withdraw(
        Request $request,
        SuchakMarketplaceChallenge $challenge,
        SuchakMarketplaceChallengeService $challengeService,
    ): JsonResponse {
        $user = $this->suchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $validated = $request->validate([
            'withdrawn_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        // 404 rather than 403: a Suchak has no business learning that another Suchak's challenge
        // exists by the shape of the refusal. Browse is where other people's challenges are seen.
        if ((int) $challenge->suchak_account_id !== (int) $user->suchakAccount->id) {
            return $this->error(__('suchak.api.errors.challenge_not_found'), 404);
        }

        try {
            $withdrawn = $challengeService->withdraw(
                $challenge,
                $user->suchakAccount,
                $user,
                $validated['withdrawn_reason'] ?? null,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('suchak.api.challenge.withdrawn'),
            'data' => [
                'challenge_id' => (int) $withdrawn->id,
                'status' => $withdrawn->status,
                'withdrawn_at' => $withdrawn->withdrawn_at?->toIso8601String(),
                'withdrawn_reason' => $withdrawn->withdrawn_reason,
            ],
        ]);
    }

    /**
     * GET /api/v1/suchak/marketplace/challenges
     *
     * The marketplace as a VERIFIED Suchak sees it (D18): other Suchaks' live challenges, each
     * carrying the masked candidate summary (D19a — name, village, detailed address and mobile
     * hidden unless the originating Suchak revealed them; photograph always shown), the declared
     * share and the expiry.
     *
     * Query: `per_page` (optional, 1–50, default 12).
     * 200: `{ success, data: [ <listing> ], meta: { current_page, last_page, per_page, total } }`
     *
     * Not logged per card. D18 logs a listing OPEN — see `show`.
     */
    public function index(
        Request $request,
        SuchakMarketplaceChallengeService $challengeService,
    ): JsonResponse {
        $user = $this->suchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $listings = $challengeService->browse(
                $user->suchakAccount,
                $this->perPage($request, 12, 50),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->paginated($listings);
    }

    /**
     * GET /api/v1/suchak/marketplace/challenges/mine
     *
     * The caller's own challenges in every state, so he can find the id he withdraws and see what
     * he has already promised. Carries the withdrawal fields the browse listing has no reason to.
     */
    public function mine(
        Request $request,
        SuchakMarketplaceChallengeService $challengeService,
    ): JsonResponse {
        $user = $this->suchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return $this->paginated($challengeService->published(
            $user->suchakAccount,
            $this->perPage($request, 20, 50),
        ));
    }

    /**
     * GET /api/v1/suchak/marketplace/challenges/{challenge}
     *
     * Open ONE listing. This read is LOGGED and shown to the originating Suchak (D18) — it is the
     * first read `suchak_activity_logs` has ever recorded, under its own action
     * `marketplace_listing_opened` rather than a write action borrowed to stand in for it.
     *
     * 200: `{ success, data: <listing> }`. 422 when the challenge is not live, its candidate's
     * consent has lapsed, the caller is not verified, or the caller is the publisher.
     */
    public function show(
        Request $request,
        SuchakMarketplaceChallenge $challenge,
        SuchakMarketplaceChallengeService $challengeService,
    ): JsonResponse {
        $user = $this->suchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $listing = $challengeService->openListing(
                $challenge,
                $user->suchakAccount,
                $user,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return response()->json([
            'success' => true,
            'data' => $listing,
        ]);
    }

    /**
     * POST /api/v1/suchak/marketplace/challenges/{challenge}/proposals
     *
     * ACCEPT BY PROPOSING (D7). The helping Suchak answers a challenge by naming one of his own
     * candidates; there is no bare-accept endpoint and there must never be one.
     *
     * Body: `representation_id` (required int, the CALLER's own candidate), `message` (optional,
     * max 2000 — his note to the publisher).
     *
     * There is no share parameter and no currency parameter, and sending one is a 422 rather than a
     * silent drop (D4: the share is declared in the challenge, upfront, and is not negotiable). The
     * challenge's own candidate is not a parameter either — a helper answers the candidate the
     * challenge is for, or nothing.
     *
     * 201: `{ success, message, data: { collaboration_id, challenge_id, status, marketplace_stage,
     *         stage_event: {...}, declared_share: {...} } }`. The declared share is echoed back
     *      READ FROM THE CHALLENGE, so the helper sees the terms he has just accepted.
     *
     * 422 covers: not verified (D18/A10), own challenge (A2), the same candidate proposed twice
     * (A10), a withdrawn / fulfilled / expired challenge, a lapsed consent on either candidate, the
     * open-request quota, and — added 2026-08-03 — §7.2's STOP-LOSS: the ORIGINATING Suchak has 2
     * or more meeting claims, or ₹5,000 or more, sitting unanswered past their window. That last
     * one is the only 422 here that is not about the caller at all, and its sentence says so by
     * naming the other Suchak's numbers. 404 covers a representation that is not the caller's.
     */
    public function propose(
        Request $request,
        SuchakMarketplaceChallenge $challenge,
        SuchakMarketplaceChallengeService $challengeService,
    ): JsonResponse {
        $user = $this->suchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $validated = $request->validate([
            'representation_id' => ['required', 'integer'],
            'message' => ['nullable', 'string', 'max:2000'],
            // Named so they can be REFUSED. D4 puts the share in the challenge; a helper who keeps
            // sending one must be told who owns it, not quietly overruled. The LIST lives on the
            // service (DECLARED_TERMS_INPUTS) and is read here rather than restated: written out
            // twice, a tenth share field added to one copy left the other permissive.
        ] + $this->prohibited(SuchakMarketplaceChallengeService::DECLARED_TERMS_INPUTS),
            $this->prohibitedMessages(
                SuchakMarketplaceChallengeService::DECLARED_TERMS_INPUTS,
                SuchakMarketplaceChallengeService::REFUSAL_SHARE_ALREADY_DECLARED,
            ));

        /** @var SuchakProfileRepresentation|null $representation */
        $representation = SuchakProfileRepresentation::query()
            ->whereKey((int) $validated['representation_id'])
            ->where('suchak_account_id', $user->suchakAccount->id)
            ->first();

        if ($representation === null) {
            return $this->error(__('suchak.api.errors.profile_not_found'), 404);
        }

        try {
            $proposed = $challengeService->proposeCandidate(
                $challenge,
                $user->suchakAccount,
                $user,
                $representation,
                $validated,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        $collaboration = $proposed['request'];
        $stageEvent = $proposed['stage_event'];

        return response()->json([
            'success' => true,
            'message' => __('suchak.api.challenge.proposal_sent'),
            'data' => [
                'collaboration_id' => (int) $collaboration->id,
                'challenge_id' => (int) $challenge->id,
                'status' => $collaboration->status,
                'marketplace_stage' => $collaboration->fresh()?->marketplace_stage,
                'stage_event' => [
                    'stage_event_id' => (int) $stageEvent->id,
                    'stage_key' => $stageEvent->stage_key,
                    'claimed_at' => $stageEvent->claimed_at?->toIso8601String(),
                ],
                // Read from the challenge, never from the request body: this is what he accepted.
                'declared_share' => $challengeService->listingPayload($challenge->fresh() ?? $challenge)['declared_share'],
            ],
        ], 201);
    }

    /**
     * GET /api/v1/suchak/marketplace/challenges/{challenge}/my-candidates
     *
     * WHICH OF MY CANDIDATES SHOULD ANSWER THIS CHALLENGE (D7a). The other half of accept-by-
     * proposing: `propose` takes a `representation_id`, and without this read the only way to obtain
     * one was to scroll an unfiltered list of every candidate the Suchak holds.
     *
     * Query: `q` (NAME, partial — see below), `age_min`, `age_max`, `district_id`, `taluka_id`,
     * `income_min`, `income_max`, `education` (partial), `page`, `per_page` (1–50, default 20).
     *
     * `q` here is the NAME. On GET /suchak/search the same key is education-or-occupation free text,
     * and that is the fixed client contract for both — so it is mapped to the filter owner's explicit
     * `name` key HERE rather than by giving `q` two meanings inside the owner. A helper reading only
     * the owner would otherwise find no `q`-means-name rule anywhere.
     *
     * 200: `{ success, data: { candidates: [...], meta: { total, page, per_page } } }`, ranked by
     * `match_score` DESC against the challenge's candidate. `already_proposed` is true for a
     * candidate that already answers THIS challenge — the pair guard refuses those, so the app greys
     * them out instead of letting the Suchak find out by failing.
     *
     * Own candidates are NOT masked; masking is for other Suchaks' candidates (D19a).
     *
     * 422 when the caller lacks the marketplace badge (D18), owns the challenge (A2 — a Suchak
     * cannot answer his own), or the challenge no longer accepts proposals. Same gates as `propose`,
     * so a candidate this list offers is one `propose` will accept.
     */
    public function myCandidates(
        Request $request,
        SuchakMarketplaceChallenge $challenge,
        SuchakMarketplaceChallengeService $challengeService,
    ): JsonResponse {
        $user = $this->suchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'education' => ['nullable', 'string', 'max:80'],
            'age_min' => ['nullable', 'integer', 'min:18', 'max:100'],
            'age_max' => ['nullable', 'integer', 'min:18', 'max:100'],
            'district_id' => ['nullable', 'integer', 'min:1'],
            'taluka_id' => ['nullable', 'integer', 'min:1'],
            'income_min' => ['nullable', 'numeric', 'min:0'],
            'income_max' => ['nullable', 'numeric', 'min:0'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        // `q` is TRANSLATED to the owner's `name`, never forwarded as `q`. Forwarding it as well ran
        // the owner's education/occupation free-text filter over a person's name and returned an
        // empty list for every search — the first thing the test caught.
        $filters = $validated;
        unset($filters['q']);
        $filters['name'] = $validated['q'] ?? null;

        try {
            $candidates = $challengeService->ownCandidatesFor(
                $challenge,
                $user->suchakAccount,
                $filters,
                $this->perPage($request, 20, 50),
                max(1, (int) ($validated['page'] ?? 1)),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'candidates' => $candidates->items(),
                'meta' => [
                    'total' => $candidates->total(),
                    'page' => $candidates->currentPage(),
                    'per_page' => $candidates->perPage(),
                ],
            ],
        ]);
    }

    /**
     * GET /api/v1/suchak/marketplace/challenges/{challenge}/proposals
     *
     * The publisher's inbox for ONE challenge — the door he accepts or rejects from, using the
     * existing POST /suchak/collaborations/{collaboration}/accept|reject, which already gate on the
     * target actor (he is the target in this reversed direction).
     *
     * Without this the accept door is blind: the collaborations list gives an id and a Suchak name,
     * and a commitment made on partial information is a bad one (D19). Each proposed candidate is
     * masked by the same cross-Suchak presenter that masks his own (D19a).
     *
     * Query: `per_page` (optional, 1–50, default 20). 404 when the challenge is not the caller's.
     */
    public function proposals(
        Request $request,
        SuchakMarketplaceChallenge $challenge,
        SuchakMarketplaceChallengeService $challengeService,
    ): JsonResponse {
        $user = $this->suchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        if ((int) $challenge->suchak_account_id !== (int) $user->suchakAccount->id) {
            return $this->error(__('suchak.api.errors.challenge_not_found'), 404);
        }

        try {
            $proposals = $challengeService->proposalsFor(
                $challenge,
                $user->suchakAccount,
                $this->perPage($request, 20, 50),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->paginated($proposals);
    }

    /**
     * `prohibited` rules for a field list the SERVICE owns.
     *
     * The list is never written out here. A field the service refuses and the route does not is a
     * field the route accepts and then throws a 422 about from one layer down; a field the route
     * refuses and the service does not is a field any non-route caller may still send. One list,
     * two entrances, and this is the adapter between them.
     *
     * @param  list<string>  $fields
     * @return array<string, list<string>>
     */
    private function prohibited(array $fields): array
    {
        return array_fill_keys($fields, ['prohibited']);
    }

    /**
     * The same list again, as `field.prohibited` message keys carrying the service's own sentence.
     *
     * @param  list<string>  $fields
     * @return array<string, string>
     */
    private function prohibitedMessages(array $fields, string $message): array
    {
        return array_fill_keys(
            array_map(static fn (string $field): string => $field.'.prohibited', $fields),
            $message,
        );
    }

    private function paginated(mixed $paginator): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    private function perPage(Request $request, int $default, int $max): int
    {
        $requested = (int) $request->integer('per_page', $default);

        return max(1, min($max, $requested === 0 ? $default : $requested));
    }

    private function suchakUser(Request $request): User|JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return $this->error(__('suchak.api.errors.suchak_account_required'), 403);
        }

        return $user;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
