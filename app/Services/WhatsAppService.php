<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Outbound notifications via the WhatsApp Engine HTTP API.
 * All matrimony → engine notification traffic should go through this service.
 */
class WhatsAppService
{
    public function send(?string $phone, string $message, string $type = 'system'): void
    {
        if ($phone === null || $phone === '') {
            return;
        }

        $url = (string) config('services.whatsapp_engine.notify_url');
        if ($url === '') {
            return;
        }

        try {
            $response = Http::timeout(15)->post($url, [
                'phone' => $phone,
                'message' => $message,
                'type' => $type,
            ]);

            Log::info('WhatsApp Notification Sent', [
                'phone' => $phone,
                'response' => $response->json(),
            ]);
        } catch (\Throwable $e) {
            Log::error('WhatsApp Notification Failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function interestReceived(string $phone): void
    {
        $this->send($phone, '❤️ Someone showed interest in your profile!', 'interest');
    }

    public function matchFound(string $phone): void
    {
        $this->send($phone, '👤 We found a match for you! Check now.', 'match');
    }
}
