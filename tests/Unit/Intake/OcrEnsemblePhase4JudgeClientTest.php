<?php

use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleSarvamJudgeClientInterface;
use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeRequest;
use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeRequestField;
use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeResponse;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase4Constants;
use App\Services\Intake\OcrEnsemble\OcrEnsembleSarvamJudgeClient;
use App\Services\Intake\OcrEnsemble\OcrEnsembleSarvamJudgeResponseParser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Sleep::fake();
    config()->set('ocr.ensemble.phase4.client.endpoint', 'https://example.test/v1/chat/completions');
    config()->set('ocr.ensemble.phase4.client.api_key', 'test-key');
    config()->set('ocr.ensemble.phase4.client.model', 'sarvam-m');
    config()->set('ocr.ensemble.phase4.client.timeout_seconds', 5);
    config()->set('ocr.ensemble.phase4.client.connect_timeout_seconds', 2);
    config()->set('ocr.ensemble.phase4.client.max_attempts', 3);
    config()->set('ocr.ensemble.phase4.client.retry_base_ms', 10);
    config()->set('ocr.ensemble.phase4.client.retry_max_ms', 40);
});

function phase4dSampleRequest(): SarvamJudgeRequest
{
    return new SarvamJudgeRequest(
        schemaVersion: OcrEnsemblePhase4Constants::SCHEMA_VERSION,
        pipelineVersion: OcrEnsemblePhase4Constants::PIPELINE_VERSION,
        intakeId: 901,
        triggerReasons: [
            'date_of_birth' => OcrEnsemblePhase4Constants::TRIGGER_REASON_DOB_MISSING,
        ],
        fields: [
            new SarvamJudgeRequestField(
                fieldName: 'date_of_birth',
                triggerReason: OcrEnsemblePhase4Constants::TRIGGER_REASON_DOB_MISSING,
                resolvedValue: null,
                normalizedValue: null,
                status: 'missing',
                source: 'missing',
                winningEngine: null,
                confidence: null,
                fieldReason: 'missing',
                candidates: [],
                normalized: [],
                validator: ['passed' => false, 'code' => 'missing', 'detail' => null],
                ocrSnippets: ['जन्म तारीख :'],
                engineMetadata: [
                    'winning_engine' => null,
                    'candidate_engines' => [],
                    'engines_present' => ['laravel_native_ocr'],
                ],
            ),
        ],
    );
}

function phase4dSuccessBody(string $dob = '1992-01-04'): string
{
    return json_encode([
        'choices' => [
            [
                'message' => [
                    'content' => json_encode([
                        'fields' => [
                            [
                                'field_name' => 'date_of_birth',
                                'value' => $dob,
                                'confidence' => 0.91,
                                'reason' => 'vision_read',
                            ],
                        ],
                    ], JSON_UNESCAPED_UNICODE),
                ],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE);
}

test('judge success returns immutable field DTOs', function () {
    Http::fake([
        'https://example.test/v1/chat/completions' => Http::response(phase4dSuccessBody(), 200),
    ]);

    $response = app(OcrEnsembleSarvamJudgeClientInterface::class)->judge(phase4dSampleRequest());

    expect($response->ok)->toBeTrue()
        ->and($response->outcome)->toBe(SarvamJudgeResponse::OUTCOME_SUCCESS)
        ->and($response->attemptCount)->toBe(1)
        ->and($response->statusCode)->toBe(200)
        ->and($response->fieldNames())->toBe(['date_of_birth'])
        ->and($response->fields[0]->value)->toBe('1992-01-04')
        ->and($response->fields[0]->confidence)->toBe(0.91);

    Http::assertSentCount(1);
});

test('judge handles connection timeout gracefully and retries', function () {
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;
        throw new ConnectionException('cURL error 28: timeout');
    });

    $response = app(OcrEnsembleSarvamJudgeClientInterface::class)->judge(phase4dSampleRequest());

    expect($response->ok)->toBeFalse()
        ->and($response->outcome)->toBe(SarvamJudgeResponse::OUTCOME_TIMEOUT)
        ->and($response->errorCode)->toBe('connection_timeout')
        ->and($response->attemptCount)->toBe(3)
        ->and($calls)->toBe(3);

    Sleep::assertSleptTimes(2);
});

test('judge handles http 500 with exponential retry then failure', function () {
    Http::fake([
        'https://example.test/v1/chat/completions' => Http::sequence()
            ->push('server error', 500)
            ->push('server error', 500)
            ->push('server error', 500),
    ]);

    $response = app(OcrEnsembleSarvamJudgeClientInterface::class)->judge(phase4dSampleRequest());

    expect($response->ok)->toBeFalse()
        ->and($response->outcome)->toBe(SarvamJudgeResponse::OUTCOME_HTTP_ERROR)
        ->and($response->statusCode)->toBe(500)
        ->and($response->attemptCount)->toBe(3);

    Http::assertSentCount(3);
});

test('judge succeeds after retryable http failure', function () {
    Http::fake([
        'https://example.test/v1/chat/completions' => Http::sequence()
            ->push('temporary', 500)
            ->push(phase4dSuccessBody('1990-05-15'), 200),
    ]);

    $response = app(OcrEnsembleSarvamJudgeClientInterface::class)->judge(phase4dSampleRequest());

    expect($response->ok)->toBeTrue()
        ->and($response->attemptCount)->toBe(2)
        ->and($response->fields[0]->value)->toBe('1990-05-15');

    Http::assertSentCount(2);
});

test('non-2xx mapHttpResponse logs status model and body prefix only', function () {
    Log::spy();

    $body = '{"error":{"message":"invalid_request_error diagnostic body"}}'.str_repeat('x', 600);

    Http::fake([
        'https://example.test/v1/chat/completions' => Http::response($body, 400),
    ]);

    $response = app(OcrEnsembleSarvamJudgeClientInterface::class)->judge(phase4dSampleRequest());

    expect($response->ok)->toBeFalse()
        ->and($response->errorCode)->toBe('http_400')
        ->and($response->statusCode)->toBe(400);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($response): bool {
            return $message === 'phase4_sarvam_http_response'
                && ($context['http_status'] ?? null) === 400
                && ($context['resolved_model'] ?? null) === 'sarvam-m'
                && is_string($context['response_body_prefix'] ?? null)
                && strlen((string) $context['response_body_prefix']) === 500
                && str_contains((string) $context['response_body_prefix'], 'invalid_request_error')
                && ($context['payload_hash'] ?? null) === $response->requestPayloadHash
                && ($context['attempt_count'] ?? null) === 1
                && ! array_key_exists('api_key', $context)
                && ! array_key_exists('request_payload', $context)
                && ! array_key_exists('ocr_text', $context);
        });
});

test('judge handles malformed json gracefully', function () {
    Http::fake([
        'https://example.test/v1/chat/completions' => Http::response('{not-json', 200),
    ]);

    $response = app(OcrEnsembleSarvamJudgeClientInterface::class)->judge(phase4dSampleRequest());

    expect($response->ok)->toBeFalse()
        ->and($response->outcome)->toBe(SarvamJudgeResponse::OUTCOME_INVALID_JSON)
        ->and($response->errorCode)->toBe('malformed_json')
        ->and($response->fields)->toBe([]);
});

test('judge handles empty response body gracefully', function () {
    Http::fake([
        'https://example.test/v1/chat/completions' => Http::response('', 200),
    ]);

    $response = app(OcrEnsembleSarvamJudgeClientInterface::class)->judge(phase4dSampleRequest());

    expect($response->ok)->toBeFalse()
        ->and($response->outcome)->toBe(SarvamJudgeResponse::OUTCOME_EMPTY_RESPONSE)
        ->and($response->errorCode)->toBe('empty_body');
});

test('empty request skips http and returns empty_request outcome', function () {
    Http::fake();

    $response = app(OcrEnsembleSarvamJudgeClientInterface::class)->judge(SarvamJudgeRequest::empty(12));

    expect($response->ok)->toBeTrue()
        ->and($response->outcome)->toBe(SarvamJudgeResponse::OUTCOME_EMPTY_REQUEST)
        ->and($response->attemptCount)->toBe(0)
        ->and($response->fields)->toBe([]);

    Http::assertNothingSent();
});

test('missing api key returns config error without http', function () {
    Http::fake();
    config()->set('ocr.ensemble.phase4.client.api_key', '');
    config()->set('services.sarvam.subscription_key', '');

    $response = app(OcrEnsembleSarvamJudgeClientInterface::class)->judge(phase4dSampleRequest());

    expect($response->ok)->toBeFalse()
        ->and($response->outcome)->toBe(SarvamJudgeResponse::OUTCOME_CONFIG_ERROR)
        ->and($response->errorCode)->toBe('missing_api_key');

    Http::assertNothingSent();
});

test('request serialization is deterministic across builds', function () {
    $client = app(OcrEnsembleSarvamJudgeClient::class);
    $request = phase4dSampleRequest();

    $first = $client->encodePayload($client->buildHttpPayload($request));
    $second = $client->encodePayload($client->buildHttpPayload($request));

    expect($first)->toBe($second)
        ->and($first)->toContain('"temperature":0');

    $decoded = json_decode($first, true, 512, JSON_THROW_ON_ERROR);
    expect($decoded['messages'][1]['content'])->toBe($request->toCanonicalJson());
});

test('http request body uses deterministic serialization', function () {
    Http::fake([
        'https://example.test/v1/chat/completions' => Http::response(phase4dSuccessBody(), 200),
    ]);

    $client = app(OcrEnsembleSarvamJudgeClient::class);
    $request = phase4dSampleRequest();
    $expectedBody = $client->encodePayload($client->buildHttpPayload($request));

    $client->judge($request);

    Http::assertSent(function ($httpRequest) use ($expectedBody) {
        return $httpRequest->url() === 'https://example.test/v1/chat/completions'
            && $httpRequest->body() === $expectedBody
            && $httpRequest->hasHeader('api-subscription-key', 'test-key');
    });
});

test('judge model prefers phase4 override then shared SARVAM_CHAT_MODEL', function () {
    $client = app(OcrEnsembleSarvamJudgeClient::class);
    $request = phase4dSampleRequest();

    config()->set('ocr.ensemble.phase4.client.model', 'phase4-override-model');
    config()->set('services.sarvam.chat_model', 'sarvam-105b');
    $overridePayload = $client->buildHttpPayload($request);
    expect($overridePayload['model'])->toBe('phase4-override-model');

    config()->set('ocr.ensemble.phase4.client.model', null);
    config()->set('services.sarvam.chat_model', 'sarvam-105b');
    $sharedPayload = $client->buildHttpPayload($request);
    expect($sharedPayload['model'])->toBe('sarvam-105b');

    config()->set('ocr.ensemble.phase4.client.model', '');
    config()->set('services.sarvam.chat_model', '');
    $fallbackPayload = $client->buildHttpPayload($request);
    expect($fallbackPayload['model'])->toBe('sarvam-105b');
});

test('response parser accepts direct fields payload and ignores unknown fields', function () {
    $parsed = app(OcrEnsembleSarvamJudgeResponseParser::class)->parse(json_encode([
        'fields' => [
            ['field_name' => 'gender', 'value' => 'male'],
            ['field_name' => 'religion', 'value' => 'Hindu', 'confidence' => 0.8],
            ['field_name' => 'religion', 'value' => 'duplicate'],
        ],
    ], JSON_THROW_ON_ERROR));

    expect($parsed['ok'])->toBeTrue()
        ->and(count($parsed['fields']))->toBe(1)
        ->and($parsed['fields'][0]->fieldName)->toBe('religion')
        ->and($parsed['fields'][0]->value)->toBe('Hindu');
});

test('response DTO round trips through array', function () {
    $original = SarvamJudgeResponse::success(
        fields: [
            \App\Services\Intake\OcrEnsemble\Data\SarvamJudgeResponseField::fromArray([
                'field_name' => 'religion',
                'value' => 'Hindu',
                'confidence' => 0.7,
                'reason' => 'dictionary',
            ]),
        ],
        attemptCount: 1,
        statusCode: 200,
        requestPayloadHash: 'abc',
    );

    expect(SarvamJudgeResponse::fromArray($original->toArray())->toArray())->toBe($original->toArray());
});

test('client production files do not merge persist or call benchmark', function () {
    $paths = [
        app_path('Services/Intake/OcrEnsemble/OcrEnsembleSarvamJudgeClient.php'),
        app_path('Services/Intake/OcrEnsemble/OcrEnsembleSarvamJudgeResponseParser.php'),
        app_path('Services/Intake/OcrEnsemble/Data/SarvamJudgeResponse.php'),
        app_path('Services/Intake/OcrEnsemble/Data/SarvamJudgeResponseField.php'),
    ];

    foreach ($paths as $file) {
        $contents = (string) file_get_contents($file);
        expect($contents)->not->toContain('OcrEnsembleBenchmark')
            ->and($contents)->not->toContain('field_resolution_json')
            ->and($contents)->not->toContain('->save(')
            ->and($contents)->not->toContain('OcrEnsembleSarvamJudgeMerger');
    }
});
