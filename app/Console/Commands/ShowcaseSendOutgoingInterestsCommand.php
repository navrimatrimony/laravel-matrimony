<?php

namespace App\Console\Commands;

use App\Services\Showcase\ShowcaseOutgoingInterestSenderService;
use Illuminate\Console\Command;

class ShowcaseSendOutgoingInterestsCommand extends Command
{
    protected $signature = 'showcase:send-outgoing-interests {--batch=50 : Max showcase profiles processed per run}';

    protected $description = 'Auto-send interests from showcase profiles to real members using showcase policy controls.';

    public function handle(ShowcaseOutgoingInterestSenderService $sender): int
    {
        $batch = max(1, min(2000, (int) $this->option('batch')));
        $r = $sender->run($batch);
        $this->info(sprintf('created=%d skipped=%d', $r['created'], $r['skipped']));

        return self::SUCCESS;
    }
}
