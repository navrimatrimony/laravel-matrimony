<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCustomerPayment;
use App\Models\SuchakSuccessFeeTranche;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakSuccessFeeTrancheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * THE DOOR onto the success-fee LEDGER — blueprint §7.4, M9 and M10.
 *
 *   GET  …/collaborations/{collaboration}/success-fee-tranches            read the ledger
 *   POST …/collaborations/{collaboration}/success-fee-tranches/release    record what the rungs earned
 *   POST …/collaborations/{collaboration}/success-fee-tranches/{tranche}/settlement
 *                                                                        record that it was PAID
 *
 * The five ledger columns on `suchak_success_fee_tranches` shipped with the table and nothing
 * originated them: the only writer was a copy-forward that moves state a previous revision already
 * held. `isReleased()`, `isSettled()` and `isCommitted()` could therefore only ever return false,
 * and every rule reading them — M9's guard on re-cutting an accepted split, M10's cascade, §7.4's
 * per-tranche attribution — was inert. These three routes are where that state now comes from.
 *
 * ── WHY THE GET IS NOT MERELY A READ OF WHAT WAS WRITTEN ─────────────────────────────────────
 *
 * Production may not run `schedule:run`, and the notifications and governance queues have had no
 * worker since 2026-06-17. So the ledger is DERIVED from the settled rungs every time it is asked
 * (SuchakSuccessFeeTrancheService::entitlement) and the POST merely writes that derivation down.
 * A row that is released by the ladder but not yet recorded reads as `is_released: true` with
 * `is_recorded: false` — the arithmetic fallback Phase 3 established, so a family's instalment is
 * never invisible because a button was not pressed.
 *
 * ── WHO MAY DO WHAT ──────────────────────────────────────────────────────────────────────────
 *
 * READ and RELEASE: either Suchak on the engagement. Neither is a claim — the release asserts
 * nothing new, it applies frozen arithmetic to rungs both sides already recorded under the ladder's
 * own actor rules, and it is idempotent. The helper has as much interest as the owner in the record
 * existing, because §7.4 attributes per tranche and his declared share hangs off it.
 *
 * SETTLE: the CUSTOMER-OWNING Suchak alone. He holds the customer relationship and the collection
 * (§2, M1 — "each customer pays only their own Suchak"), and a helper marking another family's
 * money as received is the forgery A7 exists to stop, on the largest sum in the system.
 *
 * ── WHAT THIS DOOR DOES NOT CLAIM ────────────────────────────────────────────────────────────
 *
 * Nothing here verifies a wedding, a साखरपुडा or a rupee. It reads rungs recorded elsewhere and
 * a payment row recorded elsewhere. No `*_verified` flag is written on this path and no OTP exists
 * on production (D23, §10 S4).
 */
class SuchakSuccessFeeTrancheApiController extends Controller
{
    /**
     * GET /api/v1/suchak/collaborations/{collaboration}/success-fee-tranches
     *
     * 200 with the ledger. An engagement with no split returns an empty `tranches` array and a
     * `success_fee_amount` — an undivided success fee is a real answer and the commonest one.
     */
    public function index(
        Request $request,
        SuchakCollaborationRequest $collaboration,
        SuchakSuccessFeeTrancheService $trancheService,
    ): JsonResponse {
        $user = $this->participatingSuchakUser($request, $collaboration);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $payload = $trancheService->ledgerPayload($collaboration);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return response()->json([
            'success' => true,
            'data' => $payload,
        ]);
    }

    /**
     * POST /api/v1/suchak/collaborations/{collaboration}/success-fee-tranches/release
     *
     * Records every tranche this engagement's settled rungs have earned, including M10's cascade
     * onto earlier unpaid instalments. Idempotent: calling it again after the first time writes
     * nothing and returns the same ledger.
     */
    public function release(
        Request $request,
        SuchakCollaborationRequest $collaboration,
        SuchakSuccessFeeTrancheService $trancheService,
    ): JsonResponse {
        $user = $this->participatingSuchakUser($request, $collaboration);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $payload = $trancheService->release($collaboration);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('suchak.api.success_fee.tranches_released'),
            'data' => $payload,
        ]);
    }

    /**
     * POST /api/v1/suchak/collaborations/{collaboration}/success-fee-tranches/{tranche}/settlement
     *
     * Body: `customer_payment_id` (required). Binds an already-recorded customer payment to an
     * already-released tranche. Every rule about whether it may be bound — released first, same
     * agreement, payment actually paid, one payment per tranche — is the service's, answered in a
     * sentence the Suchak can read.
     */
    public function settle(
        Request $request,
        SuchakCollaborationRequest $collaboration,
        SuchakSuccessFeeTranche $tranche,
        SuchakSuccessFeeTrancheService $trancheService,
    ): JsonResponse {
        $user = $this->participatingSuchakUser($request, $collaboration);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        if ((int) $collaboration->customerOwnerSuchakAccountId() !== (int) $user->suchakAccount->id) {
            return $this->error(__('suchak.api.errors.customer_payment_owner_only'), 403);
        }

        $validated = $request->validate([
            'customer_payment_id' => ['required', 'integer'],
        ]);

        try {
            $ledgerAgreement = $trancheService->ledgerAgreementFor($collaboration);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        // The tranche must belong to THIS engagement's live ledger. Without it a Suchak could
        // settle another customer's instalment through an engagement he happens to be on.
        if ((int) $tranche->customer_agreement_id !== (int) $ledgerAgreement->id) {
            return $this->error(__('suchak.api.errors.tranche_not_found'), 404);
        }

        /** @var SuchakCustomerPayment|null $payment */
        $payment = SuchakCustomerPayment::query()
            ->whereKey((int) $validated['customer_payment_id'])
            ->where('suchak_account_id', $user->suchakAccount->id)
            ->first();

        if (! $payment instanceof SuchakCustomerPayment) {
            return $this->error(__('suchak.api.errors.payment_not_found'), 404);
        }

        try {
            $trancheService->settle($tranche, $payment);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('suchak.api.success_fee.payment_recorded'),
            'data' => $trancheService->ledgerPayload($collaboration),
        ]);
    }

    /**
     * A Suchak account is required (403), and it must be one of the two on this engagement (404).
     *
     * 404 and not 403 for the second, for the reason SuchakMarriageOutcomeApiController already
     * gives: the existence of an engagement is itself information about two other Suchaks and two
     * families, and telling a stranger "forbidden" confirms it.
     */
    private function participatingSuchakUser(
        Request $request,
        SuchakCollaborationRequest $collaboration,
    ): User|JsonResponse {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return $this->error(__('suchak.api.errors.suchak_account_required'), 403);
        }

        if ($collaboration->sideForAccount((int) $user->suchakAccount->id) === null) {
            return $this->error(__('suchak.api.errors.engagement_not_found'), 404);
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
