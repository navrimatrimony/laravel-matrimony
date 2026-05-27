<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsApp\WhatsAppMessageProvider;
use App\Support\WhatsApp\WhatsAppSendResult;

class NullWhatsAppMessageProvider implements WhatsAppMessageProvider
{
    public function sendTemplateMessage(array $payload): WhatsAppSendResult
    {
        return WhatsAppSendResult::failure(
            'provider_not_configured',
            'No WhatsApp provider is configured for WhatsApp Response.'
        );
    }
}
