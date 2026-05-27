<?php

namespace App\Contracts\WhatsApp;

use App\Support\WhatsApp\WhatsAppSendResult;

interface WhatsAppMessageProvider
{
    public function sendTemplateMessage(array $payload): WhatsAppSendResult;
}
