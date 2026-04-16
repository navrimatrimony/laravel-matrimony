<?php

namespace App\Console\Commands;

use App\Services\Showcase\ShowcaseIncomingInterestResponderService;
use Illuminate\Console\Command;

class ShowcaseRespondIncomingInterestsCommand extends Command
{
    protected $signature = 'showcase:respond-incoming-interests {--limit=150 : Max pending rows to consider}';

    protected $description = 'Auto accept/reject pending interests sent to showcase profiles (requires incoming_auto_respond_enabled).';

    public function handle(ShowcaseIncomingInterestResponderService $responder): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $r = $responder->processPending($limit);
        $this->info(sprintf('accepted=%d rejected=%d skipped=%d', $r['accepted'], $r['rejected'], $r['skipped']));

        return self::SUCCESS;
    }
}
