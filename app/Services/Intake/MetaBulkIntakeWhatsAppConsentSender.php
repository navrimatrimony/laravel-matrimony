<?php

namespace App\Services\Intake;

use App\Contracts\Intake\BulkIntakeWhatsAppConsentSender;
use App\Services\Messaging\MetaWhatsAppCloudService;
use App\Support\WhatsApp\WhatsAppSendResult;

/**
 * Future Meta Cloud API sender for bulk-intake permission messages.
 * Disabled until template + live flag are configured.
 */
class MetaBulkIntakeWhatsAppConsentSender implements BulkIntakeWhatsAppConsentSender
{
    public function __construct(
        private readonly MetaWhatsAppCloudService $cloudService,
    ) {}

    public function sendPermissionMessage(
        string $normalizedMobile,
        string $body,
        array $buttons,
        array $context = []
    ): WhatsAppSendResult {
        if (! (bool) config('whatsapp.bulk_consent_live_enabled', false)) {
            return WhatsAppSendResult::failure(
                'bulk_consent_live_disabled',
                'Bulk intake WhatsApp consent live sending is disabled.'
            );
        }

        $templateName = trim((string) config('whatsapp.bulk_consent_template_name', ''));
        if ($templateName !== '' && $this->cloudService->sendBulkConsentInteractive($normalizedMobile, $body, array_slice($buttons, 0, 3))) {
            return WhatsAppSendResult::success('meta-interactive-'.md5($normalizedMobile.'|'.$body));
        }

        if (! $this->cloudService->canSendEngagementTemplate() && $templateName === '') {
            return WhatsAppSendResult::failure(
                'bulk_consent_not_configured',
                'Meta WhatsApp bulk consent template or credentials are not configured.'
            );
        }

        if ($templateName !== '' && $this->cloudService->sendEngagementTemplate($normalizedMobile, $body)) {
            return WhatsAppSendResult::success('meta-template-'.md5($normalizedMobile.'|'.$templateName));
        }

        return WhatsAppSendResult::failure(
            'bulk_consent_meta_send_failed',
            'Meta bulk consent message could not be sent. Check template approval and credentials.'
        );
    }
}
