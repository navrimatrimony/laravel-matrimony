<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ShowcaseChat\ShowcaseOrchestrationService;

class ShowcaseChatTickCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'showcase-chat:tick';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process pending showcase chat orchestration events (read, typing, reply, presence).';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = app(ShowcaseOrchestrationService::class)->processDueEvents();

        $this->info("Processed showcase events: {$count}");

        return self::SUCCESS;
    }
}