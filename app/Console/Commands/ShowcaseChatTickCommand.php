<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ShowcaseChat\ShowcaseOrchestrationService;
use App\Services\ShowcaseChat\ShowcasePresenceService;

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
        if (! app(\App\Services\FeatureFlagService::class)->isEnabled(\App\Support\FeatureFlagKey::SHOWCASE_PROFILES)) {
            $this->info('Showcase Profiles feature is disabled; skipping.');

            return self::SUCCESS;
        }

        $count = app(ShowcaseOrchestrationService::class)->processDueEvents();

        app(ShowcasePresenceService::class)->syncLastSeenFromActiveSessions();

        $this->info("Processed showcase events: {$count}");

        return self::SUCCESS;
    }
}