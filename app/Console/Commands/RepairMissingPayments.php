<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\PaymentService;
use App\Support\PaymentLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairMissingPayments extends Command
{
    protected $signature = 'payments:repair-missing {--limit=100 : Max payments to process per run}';

    protected $description = 'Repair successful payments missing subscriptions via PaymentService::finalize()';

    public function handle(PaymentService $payments): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $rows = Payment::query()
            ->where('payment_status', 'success')
            ->whereNotNull('plan_id')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('subscriptions')
                    ->whereColumn('subscriptions.user_id', 'payments.user_id')
                    ->whereColumn('subscriptions.plan_id', 'payments.plan_id')
                    ->where('subscriptions.status', 'active');
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $ok = 0;
        $failed = 0;
        foreach ($rows as $payment) {
            try {
                $sub = $payments->finalize((string) $payment->txnid, 'retry_worker');
                if ($sub) {
                    $ok++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                PaymentLogger::logEvent('payment_failed', [
                    'txnid' => $payment->txnid,
                    'user_id' => $payment->user_id,
                    'plan_id' => $payment->plan_id,
                    'plan_term_id' => $payment->plan_term_id,
                    'source' => 'retry_worker',
                    'internal_status' => 'repair_exception',
                    'gateway_status' => (string) $payment->payment_status,
                    'amount' => (float) ($payment->amount_paid ?? $payment->amount ?? 0),
                ]);
                $this->error('Failed txnid '.$payment->txnid.': '.$e->getMessage());
            }
        }

        $this->info("payments:repair-missing done. total={$rows->count()} ok={$ok} failed={$failed}");

        return self::SUCCESS;
    }
}

