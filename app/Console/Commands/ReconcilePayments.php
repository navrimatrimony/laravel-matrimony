<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\Subscription;
use App\Services\MonitoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Daily payment reconciliation (payments vs subscriptions vs invoices)';

    public function handle(MonitoringService $monitoring): int
    {
        $paidNotActivated = (int) Payment::query()
            ->where('payment_status', 'success')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('subscriptions')
                    ->whereColumn('subscriptions.user_id', 'payments.user_id')
                    ->whereColumn('subscriptions.plan_id', 'payments.plan_id');
            })
            ->count();

        $activatedWithoutPayment = (int) Subscription::query()
            ->where('status', Subscription::STATUS_ACTIVE)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('payments')
                    ->whereColumn('payments.user_id', 'subscriptions.user_id')
                    ->whereColumn('payments.plan_id', 'subscriptions.plan_id')
                    ->where('payments.payment_status', 'success');
            })
            ->count();

        $amountMismatch = (int) DB::table('payments')
            ->join('subscriptions', function ($join) {
                $join->on('subscriptions.user_id', '=', 'payments.user_id')
                    ->on('subscriptions.plan_id', '=', 'payments.plan_id');
            })
            ->where('payments.payment_status', 'success')
            ->whereRaw('ABS(COALESCE(JSON_EXTRACT(payments.meta, "$.final_amount_after_coupon"), payments.amount_paid, payments.amount) - COALESCE(JSON_EXTRACT(subscriptions.meta, "$.checkout_snapshot.final_amount"), 0)) > 0.02')
            ->count();

        $missingInvoice = 0;
        if (Schema::hasTable('payment_invoices')) {
            $missingInvoice = (int) Payment::query()
                ->where('payment_status', 'success')
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('payment_invoices')
                        ->whereColumn('payment_invoices.payment_id', 'payments.id');
                })
                ->count();
        }

        $summary = [
            'paid_not_activated' => $paidNotActivated,
            'activated_without_payment' => $activatedWithoutPayment,
            'amount_mismatch' => $amountMismatch,
            'missing_invoice' => $missingInvoice,
            'integrity_snapshot' => $monitoring->getDataIntegrityStats(),
            'generated_at' => now()->toIso8601String(),
        ];

        Log::info('payments.reconcile.summary', $summary);
        $this->sendReconcileEmailStub($summary);
        $this->line(json_encode($summary, JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function sendReconcileEmailStub(array $summary): void
    {
        Log::warning('payments.reconcile.email.stub', $summary);
    }
}

