<?php

namespace App\Console\Commands;

use App\Services\WhatsAppResponseDeliveryService;
use Illuminate\Console\Command;

class WhatsAppResponseProviderCheck extends Command
{
    protected $signature = 'whatsapp-response:provider-check';

    protected $description = 'Show WhatsApp Response provider configuration status without sending messages';

    public function handle(WhatsAppResponseDeliveryService $deliveryService): int
    {
        $status = $deliveryService->providerStatus();

        $this->info('WhatsApp Response provider status');
        $this->line('Configured provider: '.$status['configured_provider']);
        $this->line('Active provider: '.$status['active_provider']);
        $this->line('Provider configured: '.($status['provider_configured'] ? 'yes' : 'no'));
        $this->line('Live send enabled: '.($status['live_send_enabled'] ? 'yes' : 'no'));
        $this->line('Meta core config present: '.($status['meta_core_configured'] ? 'yes' : 'no'));
        $this->line('Engagement template configured: '.($status['engagement_template_configured'] ? 'yes' : 'no'));
        $this->warn('No WhatsApp message was sent.');

        return self::SUCCESS;
    }
}
