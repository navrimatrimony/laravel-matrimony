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
        if (! $this->cloudService->canSendEngagementTemplate() && $templateName === '') {
            return WhatsAppSendResult::failure(
                'bulk_consent_not_configured',
                'Meta WhatsApp bulk consent template or credentials are not configured.'
            );
        }

        // Template + interactive button wiring will be added when Meta API is available.
        return WhatsAppSendResult::failure(
            'bulk_consent_meta_not_implemented',
            'Meta bulk consent sender is not wired yet. Use log sender for testing.'
        );
    }
}
