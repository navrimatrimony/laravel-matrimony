<?php

namespace App\Console\Commands;

use App\Services\Showcase\ShowcaseRandomViewService;
use Illuminate\Console\Command;

class ShowcaseRandomViewsCommand extends Command
{
    protected $signature = 'showcase:random-views';

    protected $description = 'Create weighted random showcase→real profile views (admin settings / scheduled).';

    public function handle(ShowcaseRandomViewService $service): int
    {
        if (! app(\App\Services\FeatureFlagService::class)->isEnabled(\App\Support\FeatureFlagKey::SHOWCASE_PROFILES)) {
            $this->info('Showcase Profiles feature is disabled; skipping.');

            return self::SUCCESS;
        }

        $n = $service->run();
        $this->info("Showcase random views created: {$n}");

        return self::SUCCESS;
    }
}
