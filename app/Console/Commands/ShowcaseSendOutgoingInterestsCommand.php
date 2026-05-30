<?php

namespace App\Console\Commands;

use App\Models\AdminSetting;
use App\Services\Showcase\ShowcaseInterestPolicyService;
use App\Services\Showcase\ShowcaseOutgoingInterestSenderService;
use Illuminate\Console\Command;

class ShowcaseSendOutgoingInterestsCommand extends Command
{
    protected $signature = 'showcase:send-outgoing-interests {--batch= : Max showcase profiles per run (default: admin outgoing_auto_batch_per_run)}';

    protected $description = 'Auto-send interests from showcase profiles to real members using showcase policy controls.';

    public function handle(ShowcaseOutgoingInterestSenderService $sender): int
    {
        $batch = $this->resolveBatchSize();
        $r = $sender->run($batch);
        $this->info(sprintf('created=%d skipped=%d', $r['created'], $r['skipped']));

        return self::SUCCESS;
    }

    private function resolveBatchSize(): int
    {
        $batchOption = $this->option('batch');
        if ($batchOption !== null && $batchOption !== '') {
            return max(1, min(2000, (int) $batchOption));
        }

        $configured = (int) AdminSetting::getValue(
            ShowcaseInterestPolicyService::KEY_PREFIX.'outgoing_auto_batch_per_run',
            '50'
        );

        return max(1, min(2000, $configured));
    }
}
