<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsApp\WhatsAppMessageProvider;
use App\Services\Messaging\MetaWhatsAppCloudService;
use App\Support\WhatsApp\WhatsAppSendResult;
use Throwable;

class MetaWhatsAppMessageProvider implements WhatsAppMessageProvider
{
    public function __construct(
        private readonly MetaWhatsAppCloudService $cloudService,
    ) {}

    public function sendTemplateMessage(array $payload): WhatsAppSendResult
    {
        if (! config('whatsapp.response_live_enabled', false)) {
            return WhatsAppSendResult::failure(
                'live_send_disabled',
                'WhatsApp Response live provider sending is disabled.'
            );
        }

        if (! $this->cloudService->canSendEngagementTemplate()) {
            return WhatsAppSendResult::failure(
                'meta_template_not_configured',
                'Meta WhatsApp credentials or engagement template are not configured.'
            );
        }

        $recipient = trim((string) ($payload['recipient_mobile'] ?? ''));
        if ($recipient === '') {
            return WhatsAppSendResult::failure(
                'missing_recipient',
                'Recipient mobile number is not available for WhatsApp provider send.'
            );
        }

        $firstLine = $this->messageLine($payload);
        $extraLines = $this->extraLines($payload);

        try {
            $sent = $this->cloudService->sendEngagementTemplate($recipient, $firstLine, $extraLines);
        } catch (Throwable $e) {
            return WhatsAppSendResult::failure(
                'meta_send_exception',
                $this->safeError($e->getMessage())
            );
        }

        if (! $sent) {
            return WhatsAppSendResult::failure(
                'meta_send_failed',
                'Meta WhatsApp provider returned failure.'
            );
        }

        return WhatsAppSendResult::success();
    }

    private function messageLine(array $payload): string
    {
        $sender = trim((string) data_get($payload, 'sender_summary.name', ''));
        $requestId = (string) ($payload['request_id'] ?? '');

        if ($sender !== '') {
            return "WhatsApp Response request from {$sender}. Reference #{$requestId}.";
        }

        return "WhatsApp Response request. Reference #{$requestId}.";
    }

    /**
     * @return list<string>
     */
    private function extraLines(array $payload): array
    {
        $lines = [];

        $education = trim((string) data_get($payload, 'sender_summary.education', ''));
        $occupation = trim((string) data_get($payload, 'sender_summary.occupation', ''));
        $residence = trim((string) data_get($payload, 'sender_summary.residence', ''));
        $profileLink = trim((string) ($payload['profile_link'] ?? ''));

        $summary = trim(implode(' | ', array_filter([$education, $occupation, $residence])));
        if ($summary !== '') {
            $lines[] = $summary;
        }
        if ($profileLink !== '') {
            $lines[] = $profileLink;
        }

        return $lines;
    }

    private function safeError(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return 'Meta WhatsApp provider failed.';
        }

        return mb_substr($message, 0, 240);
    }
}
