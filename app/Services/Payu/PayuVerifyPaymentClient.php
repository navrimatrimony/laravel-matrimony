<?php

namespace App\Services\Payu;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Optional PayU {@code verify_payment} webservice client.
 *
 * When disabled / misconfigured, {@see isConfigured()} is false and callers
 * keep the reverse-hash-only activation path.
 *
 * @see https://docs.payu.in/reference/verify_payment_api
 */
class PayuVerifyPaymentClient
{
    public function isConfigured(): bool
    {
        if (! (bool) config('payu.verify_payment.enabled', false)) {
            return false;
        }

        $key = trim((string) config('payu.merchant_key', ''));
        $salt = trim((string) config('payu.merchant_salt', ''));
        $url = trim((string) $this->endpointUrl());

        return $key !== '' && $salt !== '' && $url !== '';
    }

    /**
     * @return array{ok: bool, configured: bool, skipped: bool, status: string|null, amount: string|null, raw: mixed, message: string}
     */
    public function verifyTransaction(string $txnid): array
    {
        $txnid = trim($txnid);
        if ($txnid === '') {
            return $this->result(ok: false, skipped: false, status: null, amount: null, raw: null, message: 'Missing txnid for verify_payment.');
        }

        if (! $this->isConfigured()) {
            return $this->result(ok: true, skipped: true, status: null, amount: null, raw: null, message: 'PayU verify_payment is not configured; skipped.');
        }

        $key = trim((string) config('payu.merchant_key', ''));
        $salt = trim((string) config('payu.merchant_salt', ''));
        $command = 'verify_payment';
        $hash = strtolower(hash('sha512', $key.'|'.$command.'|'.$txnid.'|'.$salt));
        $url = $this->endpointUrl();

        try {
            $response = Http::asForm()
                ->timeout((int) config('payu.verify_payment.timeout_seconds', 15))
                ->post($url, [
                    'key' => $key,
                    'command' => $command,
                    'var1' => $txnid,
                    'hash' => $hash,
                ]);
        } catch (\Throwable $exception) {
            report($exception);
            Log::warning('payu_verify_payment_http_failed', [
                'txnid' => $txnid,
                'message' => $exception->getMessage(),
            ]);

            return $this->result(ok: false, skipped: false, status: null, amount: null, raw: null, message: 'PayU verify_payment request failed.');
        }

        $raw = $response->json();
        if (! is_array($raw)) {
            $raw = ['body' => $response->body(), 'http_status' => $response->status()];
        }

        if (! $response->successful()) {
            Log::warning('payu_verify_payment_http_status', [
                'txnid' => $txnid,
                'http_status' => $response->status(),
            ]);

            return $this->result(ok: false, skipped: false, status: null, amount: null, raw: $raw, message: 'PayU verify_payment HTTP error.');
        }

        $detail = $this->extractTransactionDetail($raw, $txnid);
        $status = strtolower(trim((string) ($detail['status'] ?? $detail['transaction_status'] ?? '')));
        $amount = isset($detail['amt'])
            ? (string) $detail['amt']
            : (isset($detail['amount']) ? (string) $detail['amount'] : null);

        $topStatus = $raw['status'] ?? null;
        $ok = ($topStatus === 1 || $topStatus === '1' || $topStatus === true)
            && in_array($status, ['success', 'captured'], true);

        if (! $ok) {
            Log::info('payu_verify_payment_not_success', [
                'txnid' => $txnid,
                'status' => $status,
                'top_status' => $topStatus,
            ]);

            return $this->result(
                ok: false,
                skipped: false,
                status: $status !== '' ? $status : null,
                amount: $amount,
                raw: $raw,
                message: 'PayU verify_payment did not confirm success.',
            );
        }

        return $this->result(ok: true, skipped: false, status: $status, amount: $amount, raw: $raw, message: 'PayU verify_payment confirmed success.');
    }

    private function endpointUrl(): string
    {
        $configured = trim((string) config('payu.verify_payment.url', ''));
        if ($configured !== '') {
            return $configured;
        }

        $checkout = strtolower((string) config('payu.checkout_url', ''));
        if (str_contains($checkout, 'test.payu.in') || str_contains($checkout, 'sandbox')) {
            return 'https://test.payu.in/merchant/postservice.php?form=2';
        }

        return 'https://info.payu.in/merchant/postservice.php?form=2';
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function extractTransactionDetail(array $raw, string $txnid): array
    {
        $details = $raw['transaction_details'] ?? null;
        if (! is_array($details)) {
            return [];
        }

        if (isset($details[$txnid]) && is_array($details[$txnid])) {
            return $details[$txnid];
        }

        foreach ($details as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rowTxn = trim((string) ($row['txnid'] ?? $row['txnId'] ?? ''));
            if ($rowTxn !== '' && strcasecmp($rowTxn, $txnid) === 0) {
                return $row;
            }
        }

        $first = reset($details);

        return is_array($first) ? $first : [];
    }

    /**
     * @return array{ok: bool, configured: bool, skipped: bool, status: string|null, amount: string|null, raw: mixed, message: string}
     */
    private function result(
        bool $ok,
        bool $skipped,
        ?string $status,
        ?string $amount,
        mixed $raw,
        string $message,
    ): array {
        return [
            'ok' => $ok,
            'configured' => $this->isConfigured(),
            'skipped' => $skipped,
            'status' => $status,
            'amount' => $amount,
            'raw' => $raw,
            'message' => $message,
        ];
    }
}
