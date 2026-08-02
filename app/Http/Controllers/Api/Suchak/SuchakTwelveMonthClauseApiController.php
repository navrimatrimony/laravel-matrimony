<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakCustomerContext;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakTwelveMonthClauseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * THE DOOR ONTO THE 12-MONTH CLAUSE (blueprint D11, D21, 9a A5/A6/A13).
 *
 * A clause nobody can query is a clause nobody can enforce. `viewed` rows have been writable since
 * the customer's portal door landed, and the binding they create was, until this controller, a fact
 * sitting in a table with no way to ask about it — which is the same defect as a column with no
 * writer, one step later in the pipeline.
 *
 * Two questions, because they are genuinely two:
 *
 *   GET .../twelve-month-clause            "what is still binding on this customer, and until when"
 *   GET .../twelve-month-clause/{candidate} "is a share owed on THIS pair, and until when"
 *
 * ── WHO MAY ASK ──────────────────────────────────────────────────────────────────────────────
 *
 * The Suchak who holds the customer, and nobody else. The clause is HIS claim — D21 makes it
 * survive the engagement, so it is owed to the customer-owning Suchak whatever became of the
 * helper — and a list of "families this Suchak still has a hold over" is not market economics
 * (blueprint 9), it is one Suchak's book of business. A context belonging to another account
 * answers 404, the shape SuchakAgreementLinkApiController established: a Suchak has no business
 * learning that another Suchak's customer exists.
 *
 * The FAMILY reads the same clause on their own portal page (CustomerStageDoorController), which is
 * the other half of this door and the more important one — the family is the party the clause
 * binds and the only one who can be surprised by it a year later.
 *
 * ── WHAT THIS IS NOT ─────────────────────────────────────────────────────────────────────────
 *
 * Read-only, and no money moves. `success_fee` is the frozen figure the clause is ABOUT, so the
 * answer to "is a share owed" can say how much; the marriage outcome, success attribution and the
 * owed-vs-paid ledger are Phase 4 (blueprint 11). Nothing here writes, invoices, holds or schedules
 * anything, and there is no timer behind it — lapse is computed on this read.
 */
class SuchakTwelveMonthClauseApiController extends Controller
{
    /**
     * GET /api/v1/suchak/customer-contexts/{customerContext}/twelve-month-clause
     *
     * 200: `{ success, data: { customer_context_id, terms: {...}, bindings: [ ... ] } }`
     *
     * Released rows are returned too, with their `release_reason`. A dispute a year later needs to
     * read "she viewed him, and it did not bind, because they already knew each other" — filtering
     * those out would leave the record looking as though the view never happened.
     */
    public function index(
        Request $request,
        SuchakCustomerContext $customerContext,
        SuchakTwelveMonthClauseService $clauseService,
    ): JsonResponse {
        $user = $this->suchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        if ((int) $customerContext->suchak_account_id !== (int) $user->suchakAccount->id) {
            return $this->error(__('suchak.api.errors.customer_not_found'), 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'customer_context_id' => (int) $customerContext->id,
                'terms' => $clauseService->terms(),
                'bindings' => $clauseService->bindingsForCustomer($customerContext),
            ],
        ]);
    }

    /**
     * GET /api/v1/suchak/customer-contexts/{customerContext}/twelve-month-clause/{candidate}
     *
     * The single question, and it always answers. A candidate the family never viewed comes back
     * `binds: false` with `release_reason: never_viewed` rather than 404 — "no" and "I have no
     * record" must not arrive as the same response to a caller that only checks the status code,
     * because this is the read a Phase 4 marriage will hang its attribution off.
     *
     * 200: `{ success, data: { ...verdict } }`
     */
    public function show(
        Request $request,
        SuchakCustomerContext $customerContext,
        int $candidate,
        SuchakTwelveMonthClauseService $clauseService,
    ): JsonResponse {
        $user = $this->suchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        if ((int) $customerContext->suchak_account_id !== (int) $user->suchakAccount->id) {
            return $this->error(__('suchak.api.errors.customer_not_found'), 404);
        }

        return response()->json([
            'success' => true,
            'data' => array_merge(
                $clauseService->verdictFor($customerContext, $candidate),
                ['terms' => $clauseService->terms()],
            ),
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
