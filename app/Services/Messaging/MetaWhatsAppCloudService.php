<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends WhatsApp template messages via Meta Cloud API (Graph).
 */
class MetaWhatsAppCloudService
{
    public function isConfiguredForOtp(): bool
    {
        return $this->isCoreConfigured()
            && trim((string) config('whatsapp.otp_template_name')) !== '';
    }

    public function canSendEngagementTemplate(): bool
    {
        return $this->isCoreConfigured()
            && trim((string) config('whatsapp.engagement_template_name')) !== '';
    }

    private function isCoreConfigured(): bool
    {
        $token = trim((string) config('whatsapp.access_token'));
        $phoneId = trim((string) config('whatsapp.phone_number_id'));

        return $token !== '' && $phoneId !== '';
    }

    /**
     * @param  string  $mobileDigits  National or full number (digits only, no +).
     * @param  string  $otp  Six-digit OTP.
     */
    public function sendOtp(string $mobileDigits, string $otp): bool
    {
        if (! $this->isConfiguredForOtp()) {
            return false;
        }

        $parameters = $this->buildBodyParametersFromJsonOrSingle(
            (string) config('whatsapp.otp_template_body_parameters_json', ''),
            $otp
        );

        return $this->postTemplate(
            $mobileDigits,
            (string) config('whatsapp.otp_template_name'),
            (string) config('whatsapp.otp_template_language', 'en_US'),
            $parameters
        );
    }

    /**
     * Optional engagement / reminder template (separate Meta template name).
     *
     * @param  list<string>  $bodyTextLines  One string per template body variable in order.
     */
    public function sendEngagementTemplate(string $mobileDigits, string $firstBodyLine, array $extraBodyLines = []): bool
    {
        if (! $this->canSendEngagementTemplate()) {
            return false;
        }

        $params = $this->buildBodyParametersFromJsonOrSingle(
            (string) config('whatsapp.engagement_template_body_parameters_json', ''),
            $firstBodyLine
        );
        foreach ($extraBodyLines as $line) {
            $params[] = ['type' => 'text', 'text' => (string) $line];
        }

        if ($params === []) {
            return false;
        }

        return $this->postTemplate(
            $mobileDigits,
            (string) config('whatsapp.engagement_template_name'),
            (string) config('whatsapp.engagement_template_language', 'en_US'),
            $params
        );
    }

    /**
     * @param  list<array{type: string, text: string}>  $parameters
     */
    private function postTemplate(string $mobileDigits, string $templateName, string $language, array $parameters): bool
    {
        $to = $this->formatRecipientE164Digits($mobileDigits);
        if ($to === '') {
            Log::warning('whatsapp_invalid_recipient', ['mobile' => $mobileDigits]);

            return false;
        }

        $version = config('whatsapp.graph_version', 'v22.0');
        $phoneNumberId = config('whatsapp.phone_number_id');
        $url = sprintf('https://graph.facebook.com/%s/%s/messages', $version, $phoneNumberId);

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => [
                    'code' => $language,
                ],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => $parameters,
                    ],
                ],
            ],
        ];

        $response = Http::withToken((string) config('whatsapp.access_token'))
            ->timeout((int) config('whatsapp.http_timeout', 15))
            ->acceptJson()
            ->asJson()
            ->post($url, $payload);

        if (! $response->successful()) {
            Log::warning('whatsapp_cloud_send_failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * @return list<array{type: string, text: string}>
     */
    private function buildBodyParametersFromJsonOrSingle(string $rawJson, string $singleFallback): array
    {
        if (trim($rawJson) !== '') {
            $decoded = json_decode($rawJson, true);
            if (is_array($decoded)) {
                $out = [];
                foreach ($decoded as $item) {
                    if (is_array($item) && ($item['type'] ?? '') === 'text' && isset($item['text'])) {
                        $out[] = ['type' => 'text', 'text' => (string) $item['text']];
                    }
                }

                if ($out !== []) {
                    return $out;
                }
            }
        }

        return [
            ['type' => 'text', 'text' => $singleFallback],
        ];
    }

    /**
     * WhatsApp "to" field: country code + national number, digits only, no +.
     */
    private function formatRecipientE164Digits(string $mobileDigits): string
    {
        $digits = preg_replace('/\D/', '', $mobileDigits) ?? '';
        if ($digits === '') {
            return '';
        }

        if (strlen($digits) > 10) {
            return $digits;
        }

        $cc = preg_replace('/\D/', '', (string) config('whatsapp.default_country_code', '91')) ?? '91';

        return $cc.$digits;
    }
}
