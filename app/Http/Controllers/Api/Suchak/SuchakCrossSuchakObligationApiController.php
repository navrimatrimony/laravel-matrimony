<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCrossSuchakObligation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCrossSuchakObligationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * THE DOOR onto "Suchak A owes Suchak B" — blueprint §7 M2/M3 and §9a A7.
 *
 * Six routes, one per capability, every one added in the same commit as the capability it exposes:
 *
 *   POST …/collaborations/{collaboration}/cross-suchak-obligations   raise (idempotent, EITHER side)
 *   GET  …/collaborations/{collaboration}/cross-suchak-obligations   owed-vs-paid for one engagement
 *   GET  …/cross-suchak-obligations                                  my ledger, both directions
 *   GET  …/cross-suchak-obligations/ratio                            MY realized-vs-declared (A7)
 *   GET  …/cross-suchak-obligations/ratio/{suchakAccount}            ANOTHER declarer's ratio (A7)
 *   POST …/cross-suchak-obligations/{obligation}/settle              the HELPER marks it received
 *
 * ── WHY RAISING IS A ROUTE AND NOT A SIDE EFFECT ─────────────────────────────────────────────
 *
 * It is idempotent and open to BOTH Suchaks, and that is M3 enforced at the door: *"suppressing the
 * record must accelerate the obligation, never kill it."* If only the payer could raise his own
 * debt, doing nothing would erase it. The payee can already record the wedding himself
 * (`STAGE_MARRIAGE` is `CLAIMANT_EITHER_SUCHAK`); he can now raise the share that follows from it.
 *
 * ── WHY THE RATIO IS READABLE BY ANOTHER SUCHAK ──────────────────────────────────────────────
 *
 * A7 calls it *"a public realized-vs-declared ratio on every declarer's card"*, and the ratio only
 * stops inflated declarations if the helper deciding whether to answer a challenge can see it
 * BEFORE he commits. §9's visibility matrix already admits another Suchak's fees to verified
 * Suchaks as market economics, and D18/A10 gate marketplace participation on the verification
 * badge — so that is the gate here too, and an unverified account gets 403 rather than a number it
 * could not have earned the right to see.
 *
 * The payload carries NO candidate, NO customer and NO agreement — only counts, sums and a
 * percentage about one Suchak's own promises. A declarer's ratio is about the declarer.
 */
class SuchakCrossSuchakObligationApiController extends Controller
{
    /**
     * POST /api/v1/suchak/collaborations/{collaboration}/cross-suchak-obligations
     *
     * 200 with the engagement's owed-vs-paid card. Idempotent — a second call adds nothing and is
     * not an error, because "already raised" is the state the caller asked for.
     */
    public function raise(
        Request $request,
        SuchakCollaborationRequest $collaboration,
        SuchakCrossSuchakObligationService $obligationService,
    ): JsonResponse {
        $user = $this->participatingSuchakUser($request, $collaboration);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $obligationService->raise(
                $collaboration,
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
            'message' => 'जाहीर केलेल्या वाट्याची देय नोंद झाली.',
            'data' => $obligationService->forEngagement($collaboration->fresh() ?? $collaboration),
        ]);
    }

    /**
     * GET /api/v1/suchak/collaborations/{collaboration}/cross-suchak-obligations
     *
     * An engagement with no obligations answers 200 with an empty list, not 404: "nothing is owed"
     * is a real answer here, unlike a marriage that was never recorded.
     */
    public function forEngagement(
        Request $request,
        SuchakCollaborationRequest $collaboration,
        SuchakCrossSuchakObligationService $obligationService,
    ): JsonResponse {
        $user = $this->participatingSuchakUser($request, $collaboration);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return response()->json([
            'success' => true,
            'data' => $obligationService->forEngagement($collaboration),
        ]);
    }

    /**
     * GET /api/v1/suchak/cross-suchak-obligations — what I owe and what I am owed.
     */
    public function index(Request $request, SuchakCrossSuchakObligationService $obligationService): JsonResponse
    {
        $user = $this->suchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return response()->json([
            'success' => true,
            'data' => $obligationService->ledgerFor($user->suchakAccount),
        ]);
    }

    /**
     * GET /api/v1/suchak/cross-suchak-obligations/ratio — A7, for the calling Suchak.
     */
    public function ownRatio(Request $request, SuchakCrossSuchakObligationService $obligationService): JsonResponse
    {
        $user = $this->suchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return response()->json([
            'success' => true,
            'data' => $obligationService->declarerRatio((int) $user->suchakAccount->id)
                + ['platform_exposure' => $obligationService->overdueExposureFor((int) $user->suchakAccount->id)],
        ]);
    }

    /**
     * GET /api/v1/suchak/cross-suchak-obligations/ratio/{suchakAccount} — A7, for a declarer a
     * helper is deciding whether to work for.
     *
     * Verified callers only (D18 / A10), and the payload is the declarer's promises alone.
     */
    public function declarerRatio(
        Request $request,
        SuchakAccount $suchakAccount,
        SuchakCrossSuchakObligationService $obligationService,
    ): JsonResponse {
        $user = $this->suchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        if (! $user->suchakAccount->isVerified()) {
            return $this->error('पडताळणी झालेल्या सूचकांनाच ही माहिती पाहता येते.', 403);
        }

        return response()->json([
            'success' => true,
            'data' => $obligationService->declarerRatio((int) $suchakAccount->id),
        ]);
    }

    /**
     * POST /api/v1/suchak/cross-suchak-obligations/{obligation}/settle
     *
     * Body: `settlement_reference` (nullable, max 160 — a UPI or bank reference), `settlement_note`
     * (nullable, max 2000). The payee-only rule is the SERVICE's and is surfaced verbatim; a second
     * copy of it in this validator would be a second place for A7's one enforcement to drift.
     */
    public function settle(
        Request $request,
        SuchakCrossSuchakObligation $obligation,
        SuchakCrossSuchakObligationService $obligationService,
    ): JsonResponse {
        $user = $this->suchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $accountId = (int) $user->suchakAccount->id;
        if ((int) $obligation->payer_suchak_account_id !== $accountId
            && (int) $obligation->payee_suchak_account_id !== $accountId) {
            // 404, not 403: whether two OTHER Suchaks owe each other money is not this caller's
            // business to learn, and "forbidden" confirms the row exists.
            return $this->error('ही नोंद तुमच्या खात्यात सापडली नाही.', 404);
        }

        $validated = $request->validate([
            'settlement_reference' => ['nullable', 'string', 'max:160'],
            'settlement_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $settled = $obligationService->settle(
                $obligation,
                $user->suchakAccount,
                $user,
                $validated['settlement_reference'] ?? null,
                $validated['settlement_note'] ?? null,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        $collaboration = $settled->collaborationRequest;

        return response()->json([
            'success' => true,
            'message' => 'वाटा मिळाल्याची नोंद झाली.',
            'data' => $collaboration === null
                ? null
                : $obligationService->forEngagement($collaboration),
        ]);
    }

    private function suchakUser(Request $request): User|JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return $this->error('सूचक खाते आवश्यक आहे.', 403);
        }

        return $user;
    }

    /**
     * A Suchak account is required (403), and it must be one of the two on this engagement (404) —
     * the same shape `SuchakMarriageOutcomeApiController` uses, for the same privacy reason.
     */
    private function participatingSuchakUser(
        Request $request,
        SuchakCollaborationRequest $collaboration,
    ): User|JsonResponse {
        $user = $this->suchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        if ($collaboration->sideForAccount((int) $user->suchakAccount->id) === null) {
            return $this->error('हे सहकार्य तुमच्या खात्यात सापडले नाही.', 404);
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
