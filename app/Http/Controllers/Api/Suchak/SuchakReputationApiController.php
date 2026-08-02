<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakReputationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * THE DOOR ONTO A SUCHAK'S BEHAVIOURAL RECORD (blueprint §11 phase 5, D12, D13, §9).
 *
 * Two routes, because they are two different acts:
 *
 *   GET /api/v1/suchak/reputation                    my own record — what other Suchaks see of me
 *   GET /api/v1/suchak/reputation/{suchakAccount}    another Suchak's, before I commit to him
 *
 * ── WHY ANOTHER SUCHAK MAY READ IT ───────────────────────────────────────────────────────────
 *
 * §9's visibility matrix admits Suchak reputation to every column, and D12 makes it two-sided. The
 * record only does its job if the Suchak deciding whether to open his own customer to a competitor
 * — or to answer his challenge — can see it BEFORE he commits, which is D19's reasoning applied to
 * the counterparty instead of the candidate: a commitment made on partial information is a bad one.
 *
 * The gate is the VERIFICATION BADGE, the same one `SuchakCrossSuchakObligationApiController::
 * declarerRatio()` applies and for the same reason: D18 makes the marketplace visible to verified
 * Suchaks only and A10 ties participation to the badge, because one person running two accounts is
 * otherwise free to farm a reputation for the other. An unverified caller gets 403 rather than a
 * number he has not earned the right to see.
 *
 * ── WHY THE OWN-RECORD ROUTE IS NOT GATED ────────────────────────────────────────────────────
 *
 * A Suchak may always read his own card, verified or not, and that is the same choice
 * `SuchakMarketplaceChallengeService::published()` already made about the unanswered-claim counter:
 * *"§7.2 clause 3 will stop his next helper, and a Suchak who cannot see why cannot fix it."* A
 * record you are judged by and cannot read is not a record, it is a rumour.
 *
 * ── WHAT THIS DOOR DOES NOT DO ───────────────────────────────────────────────────────────────
 *
 * Nothing here writes, scores, ranks or restricts. It publishes counts and, where there are enough
 * events to mean anything, proportions. It is NOT `SuchakQualityControlService` — that is an admin
 * restriction risk score and §12 forbids confusing the two.
 */
class SuchakReputationApiController extends Controller
{
    /**
     * GET /api/v1/suchak/reputation
     *
     * No query parameters. 200: `{ success, data: { …record } }`.
     */
    public function own(Request $request, SuchakReputationService $reputationService): JsonResponse
    {
        $user = $this->suchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return response()->json([
            'success' => true,
            'data' => $reputationService->record($user->suchakAccount),
        ]);
    }

    /**
     * GET /api/v1/suchak/reputation/{suchakAccount}
     *
     * No query parameters. 200 with the same shape as `own()`; 403 for an unverified caller.
     *
     * A Suchak account that has done nothing comes back with `is_new: true` and every proportion
     * `null` — never 404 and never `0%`. D13: a Suchak with no history is NEW, not bad, and "I have
     * no record of him" and "his record is bad" must never arrive as the same response.
     */
    public function show(
        Request $request,
        SuchakAccount $suchakAccount,
        SuchakReputationService $reputationService,
    ): JsonResponse {
        $user = $this->suchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        if (! $user->suchakAccount->isVerified()) {
            return $this->error(__('suchak.api.errors.verified_suchaks_only'), 403);
        }

        return response()->json([
            'success' => true,
            'data' => $reputationService->record($suchakAccount),
        ]);
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
