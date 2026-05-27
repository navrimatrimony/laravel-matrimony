<?php

namespace App\Console\Commands;

use App\Services\MediationRequestService;
use Illuminate\Console\Command;

class UpdateMediationPipelineStates extends Command
{
    protected $signature = 'mediation:pipeline-update {--limit=200}';

    protected $description = 'Update due WhatsApp Response delivery pipeline states without sending WhatsApp messages';

    public function handle(MediationRequestService $mediation): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $result = $mediation->updateDuePipelineStates($limit);

        $this->info('Expired requests: '.$result['expired']);
        $this->info('Reminder due requests: '.$result['reminder_due']);

        return self::SUCCESS;
    }
}
