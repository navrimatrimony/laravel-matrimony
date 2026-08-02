<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakCustomerPayment;
use App\Models\SuchakSuccessFeeTranche;
use App\Models\User;
use App\Support\MoneyFormat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * THE RECEIPTS A SUCHAK MAY BIND TO AN INSTALMENT — the missing read half of
 * `POST …/success-fee-tranches/{tranche}/settlement`.
 *
 * That route takes a `suchak_customer_payments.id`, and NOTHING in the Suchak API ever listed
 * that id space: `/suchak/payments` returns `suchak_ledger_entries`, `/suchak/payment-requests`
 * returns `suchak_payment_requests` ids (a different space entirely), and a `customer_payment_id`
 * reached the app only in the one-shot body of `mark-paid`, which nothing stores. So the settle
 * half of the §7.4 ledger was unreachable: the app could see what a family OWED and never record
 * that they had paid it.
 *
 * ── AUTHORIZATION IS THE NEIGHBOURS', NOT A NEW ONE ──────────────────────────────────────────
 *
 * `suchak_account_id = the caller's account` — the identical predicate
 * `SuchakSuccessFeeTrancheApiController::settle()` applies to the very row this list hands back
 * ("हा भरणा तुमच्या खात्यात सापडला नाही."), and the one
 * `SuchakCollaborationStagesApiController::linkCustomerAgreement()` applies to an agreement. A
 * customer payment names one Suchak account and one only (M1 — each customer pays their own
 * Suchak), so scoping on it is the whole answer; there is no second path and none is invented
 * here. What this door lists is exactly what the settle door will accept as belonging to the
 * caller, so the picker can never offer a receipt the write would refuse as somebody else's.
 *
 * ── WHY `is_bound_to_tranche` IS ON EVERY ROW ────────────────────────────────────────────────
 *
 * Binding an already-bound receipt is how a family gets credited twice, so a human choosing one
 * must be able to SEE that it is spent — with the instalments it is spent on named, because M10's
 * headline case is legitimate: a wedding cascades three instalments, the family pays the whole
 * sum in ONE receipt, and that one receipt settles all three. So "already bound" is a warning to
 * read, never a refusal to make here: whether one more instalment still fits inside a receipt is
 * arithmetic `SuchakSuccessFeeTrancheService::settle()` owns (a tranche settles against a receipt
 * only if its own amount fits in what that receipt has left), and it is not restated on this
 * read. This door reports state; the write door rules.
 *
 * ── AND WHY NOTHING IS FILTERED OUT ──────────────────────────────────────────────────────────
 *
 * Unpaid and partially-paid rows are listed too, carrying their status. The settle service
 * refuses them in its own Marathi sentence ("भरणा पूर्ण झाल्याची नोंद नसताना…"), and a Suchak who
 * cannot see the ₹5,000 he recorded as pending has no way to understand why the instalment will
 * not close. Hiding the row would move that rule into the client, where it would drift.
 */
class SuchakCustomerPaymentsApiController extends Controller
{
    /**
     * GET /api/v1/suchak/customer-payments
     *
     * Query: `customer_agreement_id` (optional int — the settle door requires the receipt and the
     * tranche to sit on the SAME agreement, so this is the filter that makes the picker correct),
     * `limit` (optional, 1..100, default 50).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return response()->json([
                'success' => false,
                'message' => __('suchak.api.errors.suchak_account_required'),
            ], 403);
        }

        /** @var SuchakAccount $account */
        $account = $user->suchakAccount;

        $validated = $request->validate([
            'customer_agreement_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $agreementId = isset($validated['customer_agreement_id'])
            ? (int) $validated['customer_agreement_id']
            : null;

        /** @var \Illuminate\Database\Eloquent\Collection<int, SuchakCustomerPayment> $payments */
        $payments = SuchakCustomerPayment::query()
            // THE ownership predicate — the settle door's own, not a second one.
            ->where('suchak_account_id', $account->id)
            ->when($agreementId !== null, fn ($query) => $query->where('customer_agreement_id', $agreementId))
            ->with([
                'paymentRequest:id,request_title,request_title_mr',
                'customerAgreement:id,agreement_revision,package_name,customer_context_id',
            ])
            // The money's own instant first, the row's id as the tie-break — a receipt recorded
            // late for an old payment belongs where the money arrived, not where it was typed.
            ->orderByDesc('payment_received_at')
            ->orderByDesc('id')
            ->limit($validated['limit'] ?? 50)
            ->get();

        $boundByPayment = $this->boundTranchesFor($payments->pluck('id')->all());

        return response()->json([
            'success' => true,
            'message' => 'Suchak customer payments loaded.',
            'data' => [
                'account_id' => $account->id,
                'customer_agreement_id' => $agreementId,
                'customer_payments' => $payments
                    ->map(fn (SuchakCustomerPayment $payment): array => $this->payload(
                        $payment,
                        $boundByPayment[(int) $payment->id] ?? [],
                    ))
                    ->values()
                    ->all(),
            ],
        ]);
    }

    /**
     * Which success-fee instalments each of these receipts is already bound to.
     *
     * One query for the page, keyed by payment. No amount is priced here on purpose: a tranche's
     * rupee figure exists in exactly one place (`SuchakSuccessFeeTrancheService::amounts()`, T1/T2)
     * and is never stored, so quoting one from this read would be a second arithmetic owner.
     *
     * @param  list<int>  $paymentIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function boundTranchesFor(array $paymentIds): array
    {
        if ($paymentIds === []) {
            return [];
        }

        $bound = [];

        SuchakSuccessFeeTranche::query()
            ->whereIn('customer_payment_id', $paymentIds)
            ->orderBy('customer_agreement_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'customer_agreement_id', 'customer_payment_id', 'trigger_stage_key', 'settled_at'])
            ->each(function (SuchakSuccessFeeTranche $tranche) use (&$bound): void {
                $stageKey = (string) $tranche->trigger_stage_key;

                $bound[(int) $tranche->customer_payment_id][] = [
                    'tranche_id' => (int) $tranche->id,
                    'customer_agreement_id' => (int) $tranche->customer_agreement_id,
                    'trigger_stage_key' => $stageKey,
                    'trigger_stage_label' => SuchakCollaborationStageEvent::stageLabel($stageKey),
                    'settled_at' => $tranche->settled_at?->toIso8601String(),
                ];
            });

        return $bound;
    }

    /**
     * Enough for a human to pick the RIGHT receipt without guessing: what it was worth, when the
     * money arrived, what it was for, its reference — and whether it is already spent.
     *
     * Amounts go out twice: the raw numeric string for arithmetic and comparison, and a
     * `*_display` string through `App\Support\MoneyFormat` — the ONE money formatter, Latin digits
     * with Indian comma grouping. No client may re-group a lakh.
     *
     * @param  list<array<string, mixed>>  $boundTranches
     * @return array<string, mixed>
     */
    private function payload(SuchakCustomerPayment $payment, array $boundTranches): array
    {
        $currency = (string) ($payment->currency ?: 'INR');

        return [
            // THE id `POST …/success-fee-tranches/{tranche}/settlement` takes.
            'id' => (int) $payment->id,
            'customer_agreement_id' => $payment->customer_agreement_id === null
                ? null
                : (int) $payment->customer_agreement_id,
            'agreement_revision' => $payment->customerAgreement?->agreement_revision === null
                ? null
                : (int) $payment->customerAgreement->agreement_revision,
            'payment_request_id' => $payment->payment_request_id === null
                ? null
                : (int) $payment->payment_request_id,

            // WHAT IT WAS FOR. The request's own title is what the family was actually asked for;
            // the package name is the plan it was asked under. Both are the Suchak's own words —
            // this is his own customer, so nothing here is masked or maskable.
            'request_title' => $payment->paymentRequest?->request_title_mr
                ?: $payment->paymentRequest?->request_title,
            'package_name' => $payment->customerAgreement?->package_name,
            'collection_note' => $payment->collection_note,

            // HOW MUCH. `amount_received` is the figure the settle door budgets against — a
            // tranche binds to a receipt only while its own amount still fits in what is left.
            'currency' => $currency,
            'amount_received' => $payment->amount_received === null
                ? null
                : (string) $payment->amount_received,
            'amount_received_display' => MoneyFormat::amount($payment->amount_received, $currency),
            'amount_due' => $payment->amount_due === null ? null : (string) $payment->amount_due,
            'amount_due_display' => MoneyFormat::amount($payment->amount_due, $currency),

            // WHEN. `payment_received_at` is when the MONEY arrived (and is what `settled_at`
            // copies); `recorded_at` is when somebody typed it. They are different facts and are
            // never collapsed.
            'payment_received_at' => $payment->payment_received_at?->toIso8601String(),
            'recorded_at' => $payment->created_at?->toIso8601String(),

            // ITS REFERENCE, and how it came in.
            'payment_reference' => $payment->payment_reference,
            'payment_mode' => $payment->payment_mode,
            'payment_status' => $payment->payment_status,
            // The settle door refuses anything but a completed payment; this is that same state
            // read back, so a picker can show WHY rather than silently omitting the row.
            'is_paid' => $payment->payment_status === SuchakCustomerPayment::STATUS_PAID,

            // ── ALREADY SPENT? ──
            //
            // True means at least one success-fee instalment already carries this receipt.
            // Binding it again is legitimate ONLY for M10's cascade (one receipt paying several
            // instalments at once) and is otherwise how a family gets credited twice.
            'is_bound_to_tranche' => $boundTranches !== [],
            'bound_tranche_count' => count($boundTranches),
            'bound_tranches' => $boundTranches,
        ];
    }
}
