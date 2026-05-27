<?php

namespace App\Support\WhatsApp;

class WhatsAppSendResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly array|string|null $rawResponse = null,
    ) {}

    public static function success(?string $providerMessageId = null, array|string|null $rawResponse = null): self
    {
        return new self(
            success: true,
            providerMessageId: $providerMessageId,
            rawResponse: $rawResponse,
        );
    }

    public static function failure(string $errorCode, string $errorMessage, array|string|null $rawResponse = null): self
    {
        return new self(
            success: false,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            rawResponse: $rawResponse,
        );
    }

    /**
     * @return array{success: bool, provider_message_id: ?string, error_code: ?string, error_message: ?string, raw_response: array|string|null}
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'provider_message_id' => $this->providerMessageId,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'raw_response' => $this->rawResponse,
        ];
    }
}
