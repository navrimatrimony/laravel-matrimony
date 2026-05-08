<?php

namespace App\Console\Commands;

use App\Services\DataEngineGovernanceService;
use Illuminate\Console\Command;

class GovernanceVerifyRepeaterDiffsCommand extends Command
{
    protected $signature = 'governance:verify-repeater-diffs {--profile=}';

    protected $description = 'Verify repeater governance runtime artifacts (Phase-6J).';

    public function handle(DataEngineGovernanceService $governance): int
    {
        $args = ['verify-repeater-diffs'];
        if ($this->option('profile') !== null && $this->option('profile') !== '') {
            $args[] = '--profile';
            $args[] = (string) $this->option('profile');
        }
        $out = $governance->runPythonJsonCommand($args);
        unset($out['_exit_code']);
        $this->line(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return ($out['status'] ?? '') === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
