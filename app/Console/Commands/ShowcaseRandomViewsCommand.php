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
        $n = $service->run();
        $this->info("Showcase random views created: {$n}");

        return self::SUCCESS;
    }
}
