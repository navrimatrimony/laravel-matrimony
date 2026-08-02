<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakCustomerContext;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCustomerHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * THE DOOR ONTO ONE CUSTOMER'S HISTORY (blueprint D20, §11 phase 5).
 *
 *   GET /api/v1/suchak/customer-contexts/{customerContext}/history
 *
 * ── WHO MAY ASK ──────────────────────────────────────────────────────────────────────────────
 *
 * The Suchak whose account OWNS this customer context, and nobody else. A context belonging to
 * another account answers 404 — not 403 — because "forbidden" confirms the customer exists, and a
 * Suchak has no business learning that another Suchak's family is on this platform. That is the
 * shape `SuchakTwelveMonthClauseApiController` and `SuchakAgreementLinkApiController` already use
 * on the same key, for the same reason.
 *
 * ── WHAT THIS DOOR DELIBERATELY DOES NOT YET DO ──────────────────────────────────────────────
 *
 * §9's visibility matrix also admits customer history to a HELPING Suchak *before accepting* and to
 * other verified Suchaks, and A11 is the attack that motivates it (*"a customer takes many meetings
 * and rejects everything"*). That wider disclosure is NOT built here: this route is owner-scoped
 * only. The service's payload is already identity-free counts, so the wider surface would be the
 * same figures behind a different gate — but a family's trail is not something to open to the whole
 * marketplace as a side effect of building the owner's read, and which counts a stranger may see
 * (the marriage date certainly not) is a product decision, not an implementation detail. Recorded
 * here rather than left implied.
 *
 * ── NO MONEY, ON PURPOSE ─────────────────────────────────────────────────────────────────────
 *
 * D17 puts cumulative spend on the payments screen and nowhere else. This payload carries no rupee
 * figure at all, so no screen built from it can become the regret ledger §15 records the product
 * owner rejecting twice.
 */
class SuchakCustomerHistoryApiController extends Controller
{
    /**
     * GET /api/v1/suchak/customer-contexts/{customerContext}/history
     *
     * No query parameters. 200: `{ success, data: { …history } }`.
     *
     * A customer with no trail yet answers 200 with `is_new: true` and zeroes, never 404: "nothing
     * has happened yet" is a real and useful answer to the Suchak who is about to make something
     * happen.
     */
    public function show(
        Request $request,
        SuchakCustomerContext $customerContext,
        SuchakCustomerHistoryService $historyService,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return $this->error('सूचक खाते आवश्यक आहे.', 403);
        }

        if ((int) $customerContext->suchak_account_id !== (int) $user->suchakAccount->id) {
            return $this->error('हा ग्राहक तुमच्या खात्यात सापडला नाही.', 404);
        }

        return response()->json([
            'success' => true,
            'data' => $historyService->forCustomer($customerContext),
        ]);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
