<?php

namespace App\Http\Controllers;

use App\Services\Payu\MemberPayuActivationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * CheckoutPro android_surl / android_furl handlers.
 *
 * Must reverse-hash verify and emit {@code PayU.onSuccess}/{@code PayU.onFailure}
 * so the native SDK can return control to the Flutter app.
 *
 * Activation is delegated to {@see MemberPayuActivationService} so PayU
 * verify_payment can be inserted before {@code finalizePayuSubscription()}.
 *
 * @see https://docs.payu.in/docs/handling-redirect-urls-surlfurl-with-android-sdk
 */
class MobilePayuSdkReturnController extends Controller
{
    public function success(Request $request, MemberPayuActivationService $activation): Response
    {
        $data = $request->all();
        $txnid = trim((string) ($data['txnid'] ?? ''));

        Log::info('payu_sdk_surl_received', [
            'txnid' => $txnid,
            'status' => $data['status'] ?? null,
        ]);

        try {
            $activation->activateSuccessfulPayment($data, null);
        } catch (HttpException $exception) {
            Log::warning('payu_sdk_surl_activation_rejected', [
                'txnid' => $txnid,
                'status' => $exception->getStatusCode(),
                'message' => $exception->getMessage(),
            ]);

            return $this->sdkJsResponse('failure', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            Log::error('payu_sdk_surl_activation_failed', [
                'txnid' => $txnid,
                'message' => $exception->getMessage(),
            ]);

            return $this->sdkJsResponse('failure', 'Payment could not be finalized.');
        }

        return $this->sdkJsResponse('success', $this->encodePayload($data));
    }

    public function failure(Request $request): Response
    {
        $data = $request->all();
        $txnid = trim((string) ($data['txnid'] ?? ''));

        Log::info('payu_sdk_furl_received', [
            'txnid' => $txnid,
            'status' => $data['status'] ?? null,
        ]);

        return $this->sdkJsResponse('failure', $this->encodePayload($data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function encodePayload(array $data): string
    {
        $safe = [];
        foreach ($data as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                continue;
            }
            $safe[(string) $key] = (string) $value;
        }

        return json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function sdkJsResponse(string $type, string $payload): Response
    {
        $fn = $type === 'success' ? 'PayU.onSuccess' : 'PayU.onFailure';
        $escaped = str_replace(
            ['\\', "'", "\n", "\r", '</'],
            ['\\\\', "\\'", '\\n', '', '<\\/'],
            $payload,
        );

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>PayU</title></head><body>'
            .'<script type="text/javascript">'
            .$fn."('".$escaped."');"
            .'</script>'
            .'<p>Please wait…</p>'
            .'</body></html>';

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
