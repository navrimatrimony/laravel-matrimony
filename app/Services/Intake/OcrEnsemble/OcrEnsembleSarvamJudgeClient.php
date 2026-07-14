<?php

namespace App\Services\Intake\OcrEnsemble;

use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleSarvamJudgeClientInterface;
use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeRequest;
use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Phase 4d Sarvam judge HTTP client.
 *
 * - Accepts {@see SarvamJudgeRequest}
 * - Serializes deterministically
 * - Single HTTP entry point (no DB / merge / envelope mutation)
 */
final class OcrEnsembleSarvamJudgeClient implements OcrEnsembleSarvamJudgeClientInterface
{
    public const SYSTEM_PROMPT = 'You are a biodata field judge. Given a JSON judge request with only triggered fields, return JSON with a "fields" array of objects containing field_name, value, confidence, and reason. Only include triggered fields. Do not invent unrelated fields.';

    public function __construct(
        private readonly OcrEnsembleSarvamJudgeResponseParser $responseParser = new OcrEnsembleSarvamJudgeResponseParser,
    ) {}

    public function judge(SarvamJudgeRequest $request): SarvamJudgeResponse
    {
        $httpPayload = $this->buildHttpPayload($request);
        $payloadHash = hash('sha256', $this->encodePayload($httpPayload));

        if ($request->isEmpty()) {
            return SarvamJudgeResponse::emptyRequest($payloadHash);
        }

        $endpoint = trim((string) config('ocr.ensemble.phase4.client.endpoint', ''));
        $apiKey = trim((string) config('ocr.ensemble.phase4.client.api_key', ''));
        if ($apiKey === '') {
            $apiKey = trim((string) config('services.sarvam.subscription_key', ''));
        }

        if ($endpoint === '') {
            return SarvamJudgeResponse::failure(
                outcome: SarvamJudgeResponse::OUTCOME_CONFIG_ERROR,
                errorCode: 'missing_endpoint',
                errorMessage: 'Phase 4 Sarvam judge endpoint is not configured',
                requestPayloadHash: $payloadHash,
            );
        }

        if ($apiKey === '') {
            return SarvamJudgeResponse::failure(
                outcome: SarvamJudgeResponse::OUTCOME_CONFIG_ERROR,
                errorCode: 'missing_api_key',
                errorMessage: 'Phase 4 Sarvam judge API key is not configured',
                requestPayloadHash: $payloadHash,
            );
        }

        $timeout = max(1, (int) config('ocr.ensemble.phase4.client.timeout_seconds', 30));
        $connectTimeout = max(1, (int) config('ocr.ensemble.phase4.client.connect_timeout_seconds', 10));
        $maxAttempts = max(1, (int) config('ocr.ensemble.phase4.client.max_attempts', 3));

        $attempt = 0;
        $lastFailure = null;

        while ($attempt < $maxAttempts) {
            $attempt++;

            try {
                $response = Http::withHeaders([
                    'api-subscription-key' => $apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                    ->timeout($timeout)
                    ->connectTimeout($connectTimeout)
                    ->withBody($this->encodePayload($httpPayload), 'application/json')
                    ->post($endpoint);
            } catch (ConnectionException $e) {
                $lastFailure = SarvamJudgeResponse::failure(
                    outcome: SarvamJudgeResponse::OUTCOME_TIMEOUT,
                    errorCode: 'connection_timeout',
                    errorMessage: $e->getMessage(),
                    attemptCount: $attempt,
                    requestPayloadHash: $payloadHash,
                );

                if ($attempt < $maxAttempts) {
                    $this->backoff($attempt);
                    continue;
                }

                return $lastFailure;
            } catch (\Throwable $e) {
                return SarvamJudgeResponse::failure(
                    outcome: SarvamJudgeResponse::OUTCOME_HTTP_ERROR,
                    errorCode: 'transport_exception',
                    errorMessage: $e->getMessage(),
                    attemptCount: $attempt,
                    requestPayloadHash: $payloadHash,
                );
            }

            if ($this->shouldRetryStatus($response->status()) && $attempt < $maxAttempts) {
                $lastFailure = SarvamJudgeResponse::failure(
                    outcome: SarvamJudgeResponse::OUTCOME_HTTP_ERROR,
                    errorCode: 'http_'.$response->status(),
                    errorMessage: 'Retryable HTTP status '.$response->status(),
                    attemptCount: $attempt,
                    statusCode: $response->status(),
                    requestPayloadHash: $payloadHash,
                );
                $this->backoff($attempt);
                continue;
            }

            return $this->mapHttpResponse($response, $attempt, $payloadHash);
        }

        return $lastFailure ?? SarvamJudgeResponse::failure(
            outcome: SarvamJudgeResponse::OUTCOME_HTTP_ERROR,
            errorCode: 'exhausted_retries',
            errorMessage: 'Retry attempts exhausted',
            attemptCount: $attempt,
            requestPayloadHash: $payloadHash,
        );
    }

    /**
     * Deterministic HTTP body for the Sarvam judge endpoint.
     *
     * @return array{
     *     model: string,
     *     temperature: float,
     *     messages: list<array{role: string, content: string}>
     * }
     */
    public function buildHttpPayload(SarvamJudgeRequest $request): array
    {
        $model = $this->resolveChatModel();

        return [
            'model' => $model,
            'temperature' => 0.0,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => self::SYSTEM_PROMPT,
                ],
                [
                    'role' => 'user',
                    'content' => $request->toCanonicalJson(),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function encodePayload(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function mapHttpResponse(Response $response, int $attemptCount, string $payloadHash): SarvamJudgeResponse
    {
        if (! $response->successful()) {
            return SarvamJudgeResponse::failure(
                outcome: SarvamJudgeResponse::OUTCOME_HTTP_ERROR,
                errorCode: 'http_'.$response->status(),
                errorMessage: 'HTTP status '.$response->status(),
                attemptCount: $attemptCount,
                statusCode: $response->status(),
                requestPayloadHash: $payloadHash,
            );
        }

        $body = (string) $response->body();
        if (trim($body) === '') {
            return SarvamJudgeResponse::failure(
                outcome: SarvamJudgeResponse::OUTCOME_EMPTY_RESPONSE,
                errorCode: 'empty_body',
                errorMessage: 'Successful HTTP response had an empty body',
                attemptCount: $attemptCount,
                statusCode: $response->status(),
                requestPayloadHash: $payloadHash,
            );
        }

        $parsed = $this->responseParser->parse($body);
        if (! $parsed['ok']) {
            return SarvamJudgeResponse::failure(
                outcome: (string) $parsed['outcome'],
                errorCode: (string) ($parsed['error_code'] ?? 'parse_failed'),
                errorMessage: (string) ($parsed['error_message'] ?? 'Failed to parse Sarvam response'),
                attemptCount: $attemptCount,
                statusCode: $response->status(),
                requestPayloadHash: $payloadHash,
            );
        }

        return SarvamJudgeResponse::success(
            fields: $parsed['fields'],
            attemptCount: $attemptCount,
            statusCode: $response->status(),
            requestPayloadHash: $payloadHash,
        );
    }

    /**
     * Prefer OCR_ENSEMBLE_PHASE4_SARVAM_MODEL when set; otherwise reuse shared Sarvam chat model
     * (services.sarvam.chat_model / SARVAM_CHAT_MODEL) — same SSOT as AiBoostService.
     */
    private function resolveChatModel(): string
    {
        $override = trim((string) (config('ocr.ensemble.phase4.client.model') ?? ''));
        if ($override !== '') {
            return $override;
        }

        $shared = trim((string) config('services.sarvam.chat_model', ''));
        if ($shared !== '') {
            return $shared;
        }

        // Last resort matches config/services.php factory default when env is empty.
        return 'sarvam-105b';
    }

    private function shouldRetryStatus(int $status): bool
    {
        if ($status === 408 || $status === 429) {
            return true;
        }

        return $status >= 500 && $status <= 599;
    }

    private function backoff(int $attempt): void
    {
        $baseMs = max(0, (int) config('ocr.ensemble.phase4.client.retry_base_ms', 200));
        $maxMs = max($baseMs, (int) config('ocr.ensemble.phase4.client.retry_max_ms', 2000));
        if ($baseMs === 0) {
            return;
        }

        $delayMs = min($maxMs, $baseMs * (2 ** max(0, $attempt - 1)));
        Sleep::usleep($delayMs * 1000);
    }
}
