<?php

namespace App\Contracts\Intake;

use App\Support\WhatsApp\WhatsAppSendResult;

interface BulkIntakeWhatsAppConsentSender
{
    /**
     * @param  list<array{id: string, title: string}>  $buttons
     */
    public function sendPermissionMessage(
        string $normalizedMobile,
        string $body,
        array $buttons,
        array $context = []
    ): WhatsAppSendResult;
}
