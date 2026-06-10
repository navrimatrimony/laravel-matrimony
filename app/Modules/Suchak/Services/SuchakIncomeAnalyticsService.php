<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakAccount;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPayment;
use App\Models\SuchakCustomerPaymentCorrection;
use App\Models\SuchakGrowthReward;
use App\Models\SuchakGrowthRewardRule;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPaymentRequest;
use App\Models\SuchakPlanPayment;
use App\Models\SuchakPlatformPayout;
use App\Models\SuchakServicePackage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class SuchakIncomeAnalyticsService
{
    private const ACTIVE_REQUEST_STATUSES = [
        SuchakPaymentRequest::STATUS_SENT,
        SuchakPaymentRequest::STATUS_OPENED,
        SuchakPaymentRequest::STATUS_PENDING,
        SuchakPaymentRequest::STATUS_PARTIALLY_PAID,
        SuchakPaymentRequest::STATUS_OVERDUE,
    ];

    private const RECEIVED_PAYMENT_STATUSES = [
        SuchakCustomerPayment::STATUS_PARTIALLY_PAID,
        SuchakCustomerPayment::STATUS_PAID,
    ];

    /**
     * @return array<string, mixed>
     */
    public function summary(SuchakAccount $account, ?Carbon $asOf = null): array
    {
        $asOf ??= now();

        $platformRevenue = $this->platformRevenue($account);
        $customerLedger = $this->customerLedger($account, $asOf);
        $payoutLiability = $this->payoutLiability($account);
        $referralRewards = $this->referralRewards($account);
        $packagePerformance = $this->packagePerformance($account);
        $sourcePerformance = $this->sourcePerformance($account);

        $customerNetIncome = max(
            0,
            $this->amount($customerLedger['received_income_amount'])
                - $this->amount($customerLedger['paid_refund_amount'])
                - $this->amount($customerLedger['posted_credit_note_amount'])
                - $this->amount($customerLedger['posted_reversal_amount'])
        );
        $netBenefit = $customerNetIncome
            + $this->amount($payoutLiability['due_amount'])
            + $this->amount($referralRewards['credit_value'])
            - $this->amount($platformRevenue['plan_payment_received_amount']);

        return [
            'as_of' => $asOf,
            'currency' => 'INR',
            'platform_revenue' => $platformRevenue,
            'customer_ledger' => $customerLedger,
            'payout_liability' => $payoutLiability,
            'referral_rewards' => $referralRewards,
            'plan_cost_amount' => $platformRevenue['plan_payment_received_amount'],
            'customer_net_income_amount' => $this->money($customerNetIncome),
            'net_benefit_amount' => $this->money($netBenefit),
            'package_performance' => $packagePerformance,
            'source_performance' => $sourcePerformance,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function platformRevenue(SuchakAccount $account): array
    {
        $base = SuchakPlanPayment::query()
            ->where('suchak_account_id', $account->id);
        $successful = (clone $base)->where('payment_status', SuchakPlanPayment::STATUS_SUCCESS);
        $pending = (clone $base)->where('payment_status', SuchakPlanPayment::STATUS_PENDING);

        return [
            'plan_payment_received_amount' => $this->sumColumn($successful, 'amount'),
            'plan_payment_pending_amount' => $this->sumColumn($pending, 'amount'),
            'plan_payment_count' => (clone $successful)->count(),
            'failed_plan_payment_count' => (clone $base)->where('payment_status', SuchakPlanPayment::STATUS_FAILED)->count(),
        ];
    }

    /**
     * @return array<string, string|int>
     */
    private function customerLedger(SuchakAccount $account, Carbon $asOf): array
    {
        $ledger = $this->directLedgerQuery($account);
        $requests = $this->directPaymentRequestQuery($account);
        $payments = $this->directCustomerPaymentQuery($account);
        $corrections = SuchakCustomerPaymentCorrection::query()
            ->where('suchak_account_id', $account->id);

        $expectedLedger = (clone $ledger)
            ->whereIn('status', [SuchakLedgerEntry::STATUS_EXPECTED, SuchakLedgerEntry::STATUS_DUE]);
        $paidStandaloneLedger = (clone $ledger)
            ->where('status', SuchakLedgerEntry::STATUS_PAID)
            ->whereDoesntHave('customerPayments');
        $activeRequests = (clone $requests)
            ->whereIn('payment_status', self::ACTIVE_REQUEST_STATUSES);
        $requestsWithoutPayments = (clone $activeRequests)
            ->whereDoesntHave('customerPayments');
        $openPayments = (clone $payments)
            ->whereIn('payment_status', [
                SuchakCustomerPayment::STATUS_PENDING,
                SuchakCustomerPayment::STATUS_PARTIALLY_PAID,
            ]);
        $overdueRequests = (clone $requests)
            ->where(function (Builder $query) use ($asOf): void {
                $query
                    ->where('payment_status', SuchakPaymentRequest::STATUS_OVERDUE)
                    ->orWhere(function (Builder $expiryQuery) use ($asOf): void {
                        $expiryQuery
                            ->whereIn('payment_status', self::ACTIVE_REQUEST_STATUSES)
                            ->whereNotNull('expires_at')
                            ->where('expires_at', '<', $asOf);
                    });
            });
        $overdueLedger = (clone $ledger)
            ->where('status', SuchakLedgerEntry::STATUS_DUE)
            ->whereNotNull('due_date')
            ->where('due_date', '<', $asOf->toDateString());

        $receivedPaymentsAmount = $this->sumColumn(
            (clone $payments)->whereIn('payment_status', self::RECEIVED_PAYMENT_STATUSES),
            'amount_received',
        );
        $standalonePaidLedgerAmount = $this->sumColumn($paidStandaloneLedger, 'amount');

        return [
            'expected_income_amount' => $this->money(
                $this->amount($this->sumColumn($expectedLedger, 'amount'))
                    + $this->amount($this->sumColumn($activeRequests, 'amount_due'))
            ),
            'received_income_amount' => $this->money(
                $this->amount($receivedPaymentsAmount)
                    + $this->amount($standalonePaidLedgerAmount)
            ),
            'pending_amount' => $this->money(
                $this->amount($this->sumColumn($requestsWithoutPayments, 'amount_due'))
                    + $this->amount($this->sumColumn($openPayments, 'balance_amount'))
                    + $this->amount($this->sumColumn((clone $ledger)->where('status', SuchakLedgerEntry::STATUS_DUE), 'amount'))
            ),
            'overdue_amount' => $this->money(
                $this->amount($this->sumColumn($overdueRequests, 'amount_due'))
                    + $this->amount($this->sumColumn($overdueLedger, 'amount'))
            ),
            'active_payment_request_count' => (clone $activeRequests)->count(),
            'overdue_payment_request_count' => (clone $overdueRequests)->count(),
            'paid_refund_amount' => $this->sumCorrection(
                $corrections,
                SuchakCustomerPaymentCorrection::TYPE_REFUND,
                [SuchakCustomerPaymentCorrection::STATUS_PAID],
            ),
            'posted_waiver_amount' => $this->sumCorrection(
                $corrections,
                SuchakCustomerPaymentCorrection::TYPE_WAIVER,
                [SuchakCustomerPaymentCorrection::STATUS_POSTED],
            ),
            'posted_credit_note_amount' => $this->sumCorrection(
                $corrections,
                SuchakCustomerPaymentCorrection::TYPE_CREDIT_NOTE,
                [SuchakCustomerPaymentCorrection::STATUS_POSTED],
            ),
            'posted_reversal_amount' => $this->sumCorrection(
                $corrections,
                SuchakCustomerPaymentCorrection::TYPE_REVERSAL,
                [SuchakCustomerPaymentCorrection::STATUS_POSTED],
            ),
        ];
    }

    /**
     * @return array<string, string|int>
     */
    private function payoutLiability(SuchakAccount $account): array
    {
        $base = SuchakPlatformPayout::query()
            ->where('suchak_account_id', $account->id);
        $due = (clone $base)->whereIn('payout_status', [
            SuchakPlatformPayout::STATUS_QUALIFIED,
            SuchakPlatformPayout::STATUS_APPROVED,
        ]);
        $held = (clone $base)->where('payout_status', SuchakPlatformPayout::STATUS_ON_HOLD);
        $paid = (clone $base)->where('payout_status', SuchakPlatformPayout::STATUS_PAID);

        return [
            'due_amount' => $this->sumNetPayout($due),
            'held_amount' => $this->sumNetPayout($held),
            'paid_amount' => $this->sumNetPayout($paid),
            'due_count' => (clone $due)->count(),
            'held_count' => (clone $held)->count(),
            'paid_count' => (clone $paid)->count(),
        ];
    }

    /**
     * @return array<string, string|int>
     */
    private function referralRewards(SuchakAccount $account): array
    {
        $base = SuchakGrowthReward::query()
            ->where('suchak_account_id', $account->id)
            ->whereNotIn('reward_status', [
                SuchakGrowthReward::STATUS_REVERSED,
                SuchakGrowthReward::STATUS_REJECTED,
            ]);
        $cash = (clone $base)->where('reward_type', SuchakGrowthRewardRule::TYPE_CASH);
        $credit = (clone $base)->where('reward_type', SuchakGrowthRewardRule::TYPE_CREDIT);
        $adminAction = (clone $base)->where('reward_type', SuchakGrowthRewardRule::TYPE_ADMIN_ACTION);

        return [
            'cash_amount' => $this->sumColumn($cash, 'reward_amount'),
            'credit_value' => $this->sumColumn($credit, 'credit_value'),
            'cash_count' => (clone $cash)->count(),
            'credit_count' => (clone $credit)->count(),
            'admin_action_count' => (clone $adminAction)->count(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function packagePerformance(SuchakAccount $account): array
    {
        $requestTotals = SuchakPaymentRequest::query()
            ->where('suchak_account_id', $account->id)
            ->selectRaw('service_package_id, COUNT(*) as request_count, SUM(COALESCE(amount_due, 0)) as requested_amount')
            ->groupBy('service_package_id')
            ->get()
            ->keyBy('service_package_id');
        $paymentTotals = SuchakCustomerPayment::query()
            ->where('suchak_account_id', $account->id)
            ->selectRaw('service_package_id, COUNT(*) as payment_count, SUM(COALESCE(amount_received, 0)) as received_amount, SUM(COALESCE(balance_amount, 0)) as balance_amount')
            ->groupBy('service_package_id')
            ->get()
            ->keyBy('service_package_id');

        return SuchakServicePackage::query()
            ->where('suchak_account_id', $account->id)
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(function (SuchakServicePackage $package) use ($requestTotals, $paymentTotals): array {
                $request = $requestTotals->get($package->id);
                $payment = $paymentTotals->get($package->id);

                return [
                    'package_id' => $package->id,
                    'package_name' => $package->package_name,
                    'package_status' => $package->package_status,
                    'price_amount' => $this->money($package->price_amount),
                    'request_count' => (int) ($request->request_count ?? 0),
                    'requested_amount' => $this->money($request->requested_amount ?? 0),
                    'payment_count' => (int) ($payment->payment_count ?? 0),
                    'received_amount' => $this->money($payment->received_amount ?? 0),
                    'balance_amount' => $this->money($payment->balance_amount ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sourcePerformance(SuchakAccount $account): array
    {
        $contexts = SuchakCustomerContext::query()
            ->where('suchak_account_id', $account->id)
            ->selectRaw('source_owner, source_type, COUNT(*) as customer_count')
            ->groupBy('source_owner', 'source_type')
            ->get();
        $requestTotals = SuchakPaymentRequest::query()
            ->join('suchak_customer_contexts', 'suchak_customer_contexts.id', '=', 'suchak_payment_requests.customer_context_id')
            ->where('suchak_payment_requests.suchak_account_id', $account->id)
            ->selectRaw('suchak_customer_contexts.source_owner, suchak_customer_contexts.source_type, SUM(COALESCE(suchak_payment_requests.amount_due, 0)) as requested_amount')
            ->groupBy('suchak_customer_contexts.source_owner', 'suchak_customer_contexts.source_type')
            ->get()
            ->keyBy(fn ($row): string => $row->source_owner.'|'.$row->source_type);
        $paymentTotals = SuchakCustomerPayment::query()
            ->join('suchak_customer_contexts', 'suchak_customer_contexts.id', '=', 'suchak_customer_payments.customer_context_id')
            ->where('suchak_customer_payments.suchak_account_id', $account->id)
            ->selectRaw('suchak_customer_contexts.source_owner, suchak_customer_contexts.source_type, SUM(COALESCE(suchak_customer_payments.amount_received, 0)) as received_amount')
            ->groupBy('suchak_customer_contexts.source_owner', 'suchak_customer_contexts.source_type')
            ->get()
            ->keyBy(fn ($row): string => $row->source_owner.'|'.$row->source_type);

        return $contexts
            ->map(function ($row) use ($requestTotals, $paymentTotals): array {
                $key = $row->source_owner.'|'.$row->source_type;
                $request = $requestTotals->get($key);
                $payment = $paymentTotals->get($key);

                return [
                    'source_owner' => $row->source_owner,
                    'source_type' => $row->source_type,
                    'customer_count' => (int) $row->customer_count,
                    'requested_amount' => $this->money($request->requested_amount ?? 0),
                    'received_amount' => $this->money($payment->received_amount ?? 0),
                ];
            })
            ->sortByDesc('customer_count')
            ->values()
            ->all();
    }

    private function directLedgerQuery(SuchakAccount $account): Builder
    {
        return SuchakLedgerEntry::query()
            ->where('suchak_account_id', $account->id)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('payment_context_id')
                    ->orWhereHas('paymentContext', function (Builder $context): void {
                        $context->where('payment_collector', SuchakPaymentContext::COLLECTOR_SUCHAK);
                    });
            });
    }

    private function directPaymentRequestQuery(SuchakAccount $account): Builder
    {
        return SuchakPaymentRequest::query()
            ->where('suchak_account_id', $account->id)
            ->whereHas('paymentContext', function (Builder $context): void {
                $context->where('payment_collector', SuchakPaymentContext::COLLECTOR_SUCHAK);
            });
    }

    private function directCustomerPaymentQuery(SuchakAccount $account): Builder
    {
        return SuchakCustomerPayment::query()
            ->where('suchak_account_id', $account->id)
            ->whereHas('paymentContext', function (Builder $context): void {
                $context->where('payment_collector', SuchakPaymentContext::COLLECTOR_SUCHAK);
            });
    }

    /**
     * @param  array<int, string>  $statuses
     */
    private function sumCorrection(Builder $query, string $type, array $statuses): string
    {
        return $this->sumColumn(
            (clone $query)
                ->where('correction_type', $type)
                ->whereIn('correction_status', $statuses),
            'amount',
        );
    }

    private function sumColumn(Builder $query, string $column): string
    {
        return $this->money((clone $query)->sum($column));
    }

    private function sumNetPayout(Builder $query): string
    {
        return $this->money((clone $query)->selectRaw('SUM(COALESCE(net_amount, amount, 0)) as aggregate')->value('aggregate'));
    }

    private function amount(mixed $amount): float
    {
        return (float) ($amount ?? 0);
    }

    private function money(mixed $amount): string
    {
        return number_format($this->amount($amount), 2, '.', '');
    }
}
