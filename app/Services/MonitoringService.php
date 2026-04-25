<?php

namespace App\Services;

use App\Models\AdminSetting;
use App\Models\Payment;
use App\Models\PaymentInvoice;
use App\Models\Subscription;
use App\Support\PaymentLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class MonitoringService
{
    /**
     * @return array<string,mixed>
     */
    public function getLivePaymentsStats(): array
    {
        $since = now()->subMinutes(5);
        $initiated = PaymentLogger::getCounterLastMinutes('payments_initiated_total', 5);
        $success = (int) Payment::query()->where('payment_status', 'success')->where('created_at', '>=', $since)->count();
        $failed = (int) Payment::query()->whereIn('payment_status', ['failed', 'failure'])->where('created_at', '>=', $since)->count();
        $pendingOver10m = (int) Payment::query()
            ->whereIn('payment_status', ['pending', 'initiated'])
            ->where('created_at', '<=', now()->subMinutes(10))
            ->count();
        $initiated = max($initiated, $success + $failed);
        $successRate = $initiated > 0 ? round(($success / $initiated) * 100, 2) : 100.0;

        return [
            'window' => '5m',
            'payments_initiated_total' => $initiated,
            'payments_success_total' => $success,
            'payments_failed_total' => $failed,
            'payments_pending_over_10m_total' => $pendingOver10m,
            'success_rate_pct' => $successRate,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getWebhookHealth(): array
    {
        $requests = PaymentLogger::getCounterLastMinutes('webhook_requests_total', 5);
        $failures = PaymentLogger::getCounterLastMinutes('webhook_failures_total', 5);
        $signatureFails = PaymentLogger::getCounterLastMinutes('webhook_signature_fail_total', 5);
        $idempotencyHits = PaymentLogger::getCounterLastMinutes('idempotency_hits_total', 5);

        return [
            'window' => '5m',
            'webhook_requests_total' => $requests,
            'webhook_failures_total' => $failures,
            'webhook_signature_fail_total' => $signatureFails,
            'idempotency_hits_total' => $idempotencyHits,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getDataIntegrityStats(): array
    {
        $missingSubscription = $this->missingSubscriptionCount();
        $missingInvoice = $this->missingInvoiceCount();
        $amountMismatch = $this->paymentSubscriptionMismatchCount();

        return [
            'missing_subscription_count' => $missingSubscription,
            'missing_invoice_count' => $missingInvoice,
            'payment_subscription_mismatch_count' => $amountMismatch,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getFinanceStats(): array
    {
        $today = now()->startOfDay();

        return [
            'today_success_payments' => (int) Payment::query()->where('payment_status', 'success')->where('created_at', '>=', $today)->count(),
            'today_refunds' => (int) Payment::query()->where('payment_status', 'refunded')->where('created_at', '>=', $today)->count(),
            'today_success_amount' => (float) Payment::query()->where('payment_status', 'success')->where('created_at', '>=', $today)->sum('amount_paid'),
            'today_refund_amount' => (float) Payment::query()->where('payment_status', 'refunded')->where('created_at', '>=', $today)->sum('amount_paid'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getReliabilityMetrics(): array
    {
        $queueLag = $this->queueLagSeconds();
        $failedJobs = Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0;

        return [
            'queue_lag_seconds' => $queueLag,
            'failed_jobs_total' => $failedJobs,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function evaluateAlerts(): array
    {
        $live = $this->getLivePaymentsStats();
        $webhook = $this->getWebhookHealth();
        $integrity = $this->getDataIntegrityStats();
        $reliability = $this->getReliabilityMetrics();
        $invoicePdfFailures = PaymentLogger::getCounterLastMinutes('invoice_pdf_failures_total', 15);
        $invoiceGenerated = PaymentLogger::getCounterLastMinutes('invoice_generated_total', 15);
        $invoiceFailureRate = $invoiceGenerated > 0 ? ($invoicePdfFailures / $invoiceGenerated) * 100 : 0.0;

        $alerts = [];
        $successThreshold = $this->threshold('success_rate_threshold', 85);
        if ((float) $live['success_rate_pct'] < $successThreshold) {
            $alerts[] = $this->fireAlert('SEV-1', 'success_rate_below_threshold', [
                'actual' => $live['success_rate_pct'],
                'threshold' => $successThreshold,
            ]);
        }

        $webhookFailureThreshold = (int) $this->threshold('webhook_failure_threshold', 5);
        if ((int) $webhook['webhook_failures_total'] > $webhookFailureThreshold) {
            $alerts[] = $this->fireAlert('SEV-1', 'webhook_failures_spike', [
                'actual' => $webhook['webhook_failures_total'],
                'threshold' => $webhookFailureThreshold,
            ]);
        }

        if ((int) $integrity['missing_subscription_count'] > 0) {
            $alerts[] = $this->fireAlert('SEV-1', 'missing_subscription_detected', [
                'actual' => $integrity['missing_subscription_count'],
                'threshold' => 0,
            ]);
        }

        $queueLagThreshold = (int) $this->threshold('queue_lag_threshold', 120);
        if ((int) $reliability['queue_lag_seconds'] > $queueLagThreshold) {
            $alerts[] = $this->fireAlert('SEV-1', 'queue_lag_high', [
                'actual' => $reliability['queue_lag_seconds'],
                'threshold' => $queueLagThreshold,
            ]);
        }

        $invoiceFailureThreshold = $this->threshold('invoice_failure_threshold', 2);
        if ($invoiceFailureRate > $invoiceFailureThreshold) {
            $alerts[] = $this->fireAlert('SEV-2', 'invoice_failure_rate_high', [
                'actual' => round($invoiceFailureRate, 2),
                'threshold' => $invoiceFailureThreshold,
            ]);
        }

        if ((int) $live['payments_pending_over_10m_total'] > 20) {
            $alerts[] = $this->fireAlert('SEV-2', 'pending_payments_over_threshold', [
                'actual' => $live['payments_pending_over_10m_total'],
                'threshold' => 20,
            ]);
        }

        $latencyP95 = $this->p95InvoiceRenderLatencyMs();
        if ($latencyP95 > 2000) {
            $alerts[] = $this->fireAlert('SEV-3', 'p95_latency_high', [
                'actual' => $latencyP95,
                'threshold' => 2000,
            ]);
        }

        return [
            'live' => $live,
            'webhook' => $webhook,
            'integrity' => $integrity,
            'reliability' => $reliability,
            'alerts' => $alerts,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fireAlert(string $severity, string $code, array $details): array
    {
        $payload = [
            'severity' => $severity,
            'code' => $code,
            'details' => $details,
            'at' => now()->toIso8601String(),
        ];
        Log::critical('payments.alert', $payload);
        $this->sendAlert($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $alert
     */
    private function sendAlert(array $alert): void
    {
        Log::warning('payments.alert.dispatch.stub', $alert);
    }

    private function threshold(string $key, float $fallback): float
    {
        $raw = AdminSetting::getValue($key, null);
        if ($raw === null || trim((string) $raw) === '') {
            Log::warning('payments.monitoring.threshold_fallback', ['key' => $key, 'fallback' => $fallback]);

            return $fallback;
        }

        return (float) $raw;
    }

    private function queueLagSeconds(): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }
        $oldest = DB::table('jobs')->min('available_at');
        if (! $oldest) {
            return 0;
        }

        return max(0, now()->timestamp - (int) $oldest);
    }

    private function missingSubscriptionCount(): int
    {
        return (int) Payment::query()
            ->where('payment_status', 'success')
            ->whereNotNull('plan_id')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('subscriptions')
                    ->whereColumn('subscriptions.user_id', 'payments.user_id')
                    ->whereColumn('subscriptions.plan_id', 'payments.plan_id');
            })
            ->count();
    }

    private function missingInvoiceCount(): int
    {
        if (! Schema::hasTable('payment_invoices')) {
            return (int) Payment::query()->where('payment_status', 'success')->count();
        }

        return (int) Payment::query()
            ->where('payment_status', 'success')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('payment_invoices')
                    ->whereColumn('payment_invoices.payment_id', 'payments.id');
            })
            ->count();
    }

    private function paymentSubscriptionMismatchCount(): int
    {
        return (int) Payment::query()
            ->where('payment_status', 'success')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('subscriptions')
                    ->whereColumn('subscriptions.user_id', 'payments.user_id')
                    ->whereColumn('subscriptions.plan_id', 'payments.plan_id')
                    ->where('subscriptions.status', Subscription::STATUS_ACTIVE);
            })
            ->count();
    }

    private function p95InvoiceRenderLatencyMs(): int
    {
        return PaymentLogger::getTimingP95LastMinutes('invoice_render_time_ms', 5);
    }
}

