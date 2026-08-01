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
            // Named so it can be REFUSED. Leaving it out of the rules would let validate() drop it
            // silently, and a client that keeps sending a currency it believes is honoured is worse
            // off than one told plainly that the agreement owns it.
            'share_currency' => ['prohibited'],
            'currency' => ['prohibited'],
            'expires_at' => ['nullable', 'date'],
            'publisher_note' => ['nullable', 'string', 'max:2000'],
        ], [
            'share_currency.prohibited' => 'वाट्याचे चलन ग्राहकाच्या करारातून येते; ते वेगळे देता येत नाही.',
            'currency.prohibited' => 'वाट्याचे चलन ग्राहकाच्या करारातून येते; ते वेगळे देता येत नाही.',
        ]);

        /** @var SuchakProfileRepresentation|null $representation */
        $representation = SuchakProfileRepresentation::query()
            ->whereKey((int) $validated['representation_id'])
            ->where('suchak_account_id', $user->suchakAccount->id)
            ->first();

        if ($representation === null) {
            return $this->error('हे स्थळ तुमच्या खात्यात सापडले नाही.', 404);
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
            'message' => 'आव्हान बाजारपेठेत प्रसिद्ध झाले.',
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
            return $this->error('हे आव्हान तुमच्या खात्यात सापडले नाही.', 404);
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
            'message' => 'आव्हान मागे घेतले.',
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
            return $this->error('सूचक खाते आवश्यक आहे.', 403);
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
