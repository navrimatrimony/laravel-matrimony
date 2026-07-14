<?php

namespace App\Services\Intake\OcrEnsemble\Data;

use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase4Constants;

/**
 * Immutable Sarvam judge HTTP outcome (no DB writes, no merge).
 *
 * @phpstan-type SarvamJudgeResponseArray array{
 *     ok: bool,
 *     outcome: string,
 *     status_code: int|null,
 *     attempt_count: int,
 *     error_code: string|null,
 *     error_message: string|null,
 *     fields: list<array<string, mixed>>,
 *     request_payload_hash: string|null,
 *     http_status: int|null,
 *     resolved_model: string|null,
 *     response_body_prefix: string|null
 * }
 */
final class SarvamJudgeResponse
{
    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_EMPTY_REQUEST = 'empty_request';

    public const OUTCOME_CONFIG_ERROR = 'config_error';

    public const OUTCOME_TIMEOUT = 'timeout';

    public const OUTCOME_HTTP_ERROR = 'http_error';

    public const OUTCOME_INVALID_JSON = 'invalid_json';

    public const OUTCOME_EMPTY_RESPONSE = 'empty_response';

    /**
     * @param  list<SarvamJudgeResponseField>  $fields
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $outcome,
        public readonly ?int $statusCode,
        public readonly int $attemptCount,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly array $fields,
        public readonly ?string $requestPayloadHash = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $resolvedModel = null,
        public readonly ?string $responseBodyPrefix = null,
    ) {}

    /**
     * @param  list<SarvamJudgeResponseField>  $fields
     */
    public static function success(
        array $fields,
        int $attemptCount,
        ?int $statusCode = 200,
        ?string $requestPayloadHash = null,
    ): self {
        return new self(
            ok: true,
            outcome: self::OUTCOME_SUCCESS,
            statusCode: $statusCode,
            attemptCount: $attemptCount,
            errorCode: null,
            errorMessage: null,
            fields: $fields,
            requestPayloadHash: $requestPayloadHash,
        );
    }

    public static function emptyRequest(?string $requestPayloadHash = null): self
    {
        return new self(
            ok: true,
            outcome: self::OUTCOME_EMPTY_REQUEST,
            statusCode: null,
            attemptCount: 0,
            errorCode: null,
            errorMessage: null,
            fields: [],
            requestPayloadHash: $requestPayloadHash,
        );
    }

    public static function failure(
        string $outcome,
        string $errorCode,
        ?string $errorMessage = null,
        int $attemptCount = 0,
        ?int $statusCode = null,
        ?string $requestPayloadHash = null,
        ?int $httpStatus = null,
        ?string $resolvedModel = null,
        ?string $responseBodyPrefix = null,
    ): self {
        return new self(
            ok: false,
            outcome: $outcome,
            statusCode: $statusCode,
            attemptCount: $attemptCount,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            fields: [],
            requestPayloadHash: $requestPayloadHash,
            httpStatus: $httpStatus,
            resolvedModel: $resolvedModel,
            responseBodyPrefix: $responseBodyPrefix,
        );
    }

    /**
     * @param  SarvamJudgeResponseArray  $data
     */
    public static function fromArray(array $data): self
    {
        $fieldsData = is_array($data['fields'] ?? null) ? $data['fields'] : [];
        $fields = [];
        foreach ($fieldsData as $fieldData) {
            if (! is_array($fieldData)) {
                continue;
            }
            $fields[] = SarvamJudgeResponseField::fromArray($fieldData);
        }

        return new self(
            ok: (bool) ($data['ok'] ?? false),
            outcome: (string) ($data['outcome'] ?? self::OUTCOME_HTTP_ERROR),
            statusCode: is_numeric($data['status_code'] ?? null) ? (int) $data['status_code'] : null,
            attemptCount: (int) ($data['attempt_count'] ?? 0),
            errorCode: isset($data['error_code']) ? (string) $data['error_code'] : null,
            errorMessage: isset($data['error_message']) ? (string) $data['error_message'] : null,
            fields: $fields,
            requestPayloadHash: isset($data['request_payload_hash']) ? (string) $data['request_payload_hash'] : null,
            httpStatus: is_numeric($data['http_status'] ?? null) ? (int) $data['http_status'] : null,
            resolvedModel: isset($data['resolved_model']) ? (string) $data['resolved_model'] : null,
            responseBodyPrefix: isset($data['response_body_prefix']) ? (string) $data['response_body_prefix'] : null,
        );
    }

    /**
     * @return SarvamJudgeResponseArray
     */
    public function toArray(): array
    {
        $fields = [];
        foreach ($this->fields as $field) {
            $fields[] = $field->toArray();
        }

        return [
            'ok' => $this->ok,
            'outcome' => $this->outcome,
            'status_code' => $this->statusCode,
            'attempt_count' => $this->attemptCount,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'fields' => $fields,
            'request_payload_hash' => $this->requestPayloadHash,
            'http_status' => $this->httpStatus,
            'resolved_model' => $this->resolvedModel,
            'response_body_prefix' => $this->responseBodyPrefix,
        ];
    }

    /**
     * @return list<string>
     */
    public function fieldNames(): array
    {
        $names = [];
        foreach ($this->fields as $field) {
            $names[] = $field->fieldName;
        }

        return $names;
    }

    public function schemaVersion(): string
    {
        return OcrEnsemblePhase4Constants::SCHEMA_VERSION;
    }
}
