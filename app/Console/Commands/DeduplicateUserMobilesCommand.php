<?php

namespace App\Console\Commands;

use App\Services\DuplicateMobileResolutionService;
use Illuminate\Console\Command;

class DeduplicateUserMobilesCommand extends Command
{
    protected $signature = 'users:dedupe-mobiles';

    protected $description = 'Rewrite duplicate users.mobile values with _dup_{id} suffixes (no deletes). Run before enforcing unique index.';

    public function handle(DuplicateMobileResolutionService $service): int
    {
        $n = $service->dedupeAll();
        $this->info("Updated {$n} user row(s).");

        return self::SUCCESS;
    }
}
