<?php

namespace App\Services\Intake;

use App\Contracts\Intake\BulkIntakeWhatsAppConsentSender;
use App\Models\IntakeWhatsAppMessage;
use App\Models\IntakeWhatsAppSession;
use App\Support\WhatsApp\WhatsAppSendResult;
use Illuminate\Support\Str;

/**
 * Default bulk-intake consent sender — records session + outbound message locally.
 * Use until Meta Cloud API templates are approved and live sending is enabled.
 */
class LogBulkIntakeWhatsAppConsentSender implements BulkIntakeWhatsAppConsentSender
{
    public function sendPermissionMessage(
        string $normalizedMobile,
        string $body,
        array $buttons,
        array $context = []
    ): WhatsAppSendResult {
        $waContactId = $this->waContactId($normalizedMobile);
        $phoneNumberId = trim((string) config('whatsapp.phone_number_id', ''));
        if ($phoneNumberId === '') {
            $phoneNumberId = 'bulk-intake-log-sender';
        }

        $session = IntakeWhatsAppSession::query()
            ->where('wa_phone_number_id', $phoneNumberId)
            ->where('wa_contact_wa_id', $waContactId)
            ->first();

        $sessionMeta = array_filter([
            'bulk_intake_permission_flow' => true,
            'bulk_intake_batch_item_id' => isset($context['bulk_intake_batch_item_id'])
                ? (int) $context['bulk_intake_batch_item_id']
                : null,
            'bulk_intake_batch_id' => isset($context['bulk_intake_batch_id'])
                ? (int) $context['bulk_intake_batch_id']
                : null,
        ], static fn (mixed $value): bool => $value !== null);

        if ($session === null) {
            $session = IntakeWhatsAppSession::query()->create([
                'wa_phone_number_id' => $phoneNumberId,
                'wa_contact_wa_id' => $waContactId,
                'normalized_mobile' => $normalizedMobile,
                'actor_type' => 'unknown',
                'source_surface' => IntakeWhatsAppSession::SOURCE_SURFACE_WHATSAPP,
                'session_status' => IntakeWhatsAppSession::STATUS_WAITING_FOR_CONSENT,
                'consent_status' => IntakeWhatsAppSession::CONSENT_PENDING,
                'last_message_at' => now(),
                'session_meta_json' => $sessionMeta,
            ]);
        } else {
            $existingMeta = is_array($session->session_meta_json) ? $session->session_meta_json : [];
            $session->forceFill([
                'normalized_mobile' => $session->normalized_mobile ?? $normalizedMobile,
                'session_status' => IntakeWhatsAppSession::STATUS_WAITING_FOR_CONSENT,
                'consent_status' => IntakeWhatsAppSession::CONSENT_PENDING,
                'last_message_at' => now(),
                'session_meta_json' => array_merge($existingMeta, $sessionMeta),
            ])->save();
        }

        $providerMessageId = 'bulk-consent-'.Str::uuid()->toString();
        $message = IntakeWhatsAppMessage::query()->create([
            'intake_whatsapp_session_id' => $session->id,
            'biodata_intake_id' => isset($context['biodata_intake_id']) ? (int) $context['biodata_intake_id'] : null,
            'direction' => IntakeWhatsAppMessage::DIRECTION_OUTBOUND,
            'wa_message_id' => $providerMessageId,
            'message_type' => IntakeWhatsAppMessage::TYPE_INTERACTIVE,
            'text_body' => $body,
            'processing_status' => IntakeWhatsAppMessage::STATUS_PROCESSED,
            'webhook_payload_json' => [
                'driver' => 'log',
                'buttons' => $buttons,
                'context' => $context,
            ],
            'sent_at' => now(),
            'processed_at' => now(),
        ]);

        return WhatsAppSendResult::success($providerMessageId, [
            'driver' => 'log',
            'intake_whatsapp_session_id' => (int) $session->id,
            'intake_whatsapp_message_id' => (int) $message->id,
        ]);
    }

    private function waContactId(string $normalizedMobile): string
    {
        $countryCode = trim((string) config('whatsapp.default_country_code', '91'));
        if ($countryCode === '') {
            $countryCode = '91';
        }

        return $countryCode.$normalizedMobile;
    }
}
