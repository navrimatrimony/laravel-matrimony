<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentLogger
{
    /**
     * @param  array<string,mixed>  $context
     */
    public static function logEvent(string $eventName, array $context = []): void
    {
        $traceId = trim((string) ($context['trace_id'] ?? ''));
        if ($traceId === '') {
            $traceId = (string) Str::uuid();
        }

        $payload = [
            'event_name' => $eventName,
            'txnid' => $context['txnid'] ?? null,
            'user_id' => isset($context['user_id']) ? (int) $context['user_id'] : null,
            'plan_id' => isset($context['plan_id']) ? (int) $context['plan_id'] : null,
            'plan_term_id' => isset($context['plan_term_id']) ? (int) $context['plan_term_id'] : null,
            'gateway_status' => $context['gateway_status'] ?? null,
            'internal_status' => $context['internal_status'] ?? null,
            'amount' => isset($context['amount']) ? (float) $context['amount'] : null,
            'source' => $context['source'] ?? null,
            'trace_id' => $traceId,
            'timestamp' => now()->toIso8601String(),
        ];

        Log::info('payment.lifecycle', $payload);
        self::incrementMetricForEvent($eventName, $context);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private static function incrementMetricForEvent(string $eventName, array $context): void
    {
        $metricMap = [
            'payment_initiated' => 'payments_initiated_total',
            'payment_finalized' => 'payments_success_total',
            'payment_failed' => 'payments_failed_total',
            'webhook_received' => 'webhook_requests_total',
            'invoice_generated' => 'invoice_generated_total',
        ];
        $metric = $metricMap[$eventName] ?? null;
        if ($metric !== null) {
            self::incrementCounter($metric);
        }

        if (($context['source'] ?? null) === 'webhook' && ($context['internal_status'] ?? '') === 'failed') {
            self::incrementCounter('webhook_failures_total');
        }
        if (($context['gateway_status'] ?? '') === 'signature_failed') {
            self::incrementCounter('webhook_signature_fail_total');
        }
        if (($context['internal_status'] ?? '') === 'idempotent_hit') {
            self::incrementCounter('idempotency_hits_total');
        }
        if (($context['internal_status'] ?? '') === 'invoice_pdf_failed') {
            self::incrementCounter('invoice_pdf_failures_total');
        }
    }

    public static function incrementCounter(string $metric, int $by = 1): void
    {
        $key = self::counterKey($metric, now()->format('YmdHi'));
        Cache::add($key, 0, now()->addHours(3));
        Cache::increment($key, max(1, $by));
    }

    public static function recordTiming(string $metric, int $valueMs): void
    {
        $valueMs = max(0, $valueMs);
        self::incrementCounter($metric, $valueMs);

        $bucket = now()->format('YmdHi');
        $key = self::timingSamplesKey($metric, $bucket);
        $samples = Cache::get($key, []);
        if (! is_array($samples)) {
            $samples = [];
        }
        $samples[] = $valueMs;
        if (count($samples) > 400) {
            $samples = array_slice($samples, -400);
        }
        Cache::put($key, $samples, now()->addHours(3));
    }

    public static function getTimingP95LastMinutes(string $metric, int $minutes = 5): int
    {
        $minutes = max(1, $minutes);
        $all = [];
        for ($i = 0; $i < $minutes; $i++) {
            $bucket = now()->subMinutes($i)->format('YmdHi');
            $samples = Cache::get(self::timingSamplesKey($metric, $bucket), []);
            if (is_array($samples) && $samples !== []) {
                foreach ($samples as $v) {
                    $all[] = (int) $v;
                }
            }
        }
        if ($all === []) {
            return 0;
        }
        sort($all);
        $idx = (int) ceil(0.95 * count($all)) - 1;
        $idx = max(0, min($idx, count($all) - 1));

        return (int) $all[$idx];
    }

    public static function getCounterLastMinutes(string $metric, int $minutes = 5): int
    {
        $minutes = max(1, $minutes);
        $sum = 0;
        for ($i = 0; $i < $minutes; $i++) {
            $bucket = now()->subMinutes($i)->format('YmdHi');
            $sum += (int) Cache::get(self::counterKey($metric, $bucket), 0);
        }

        return $sum;
    }

    private static function counterKey(string $metric, string $bucket): string
    {
        return 'payment_metric:'.$metric.':'.$bucket;
    }

    private static function timingSamplesKey(string $metric, string $bucket): string
    {
        return 'payment_timing_samples:'.$metric.':'.$bucket;
    }
}

