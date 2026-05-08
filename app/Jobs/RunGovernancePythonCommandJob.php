<?php

namespace App\Jobs;

use App\Services\DataEngineGovernanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunGovernancePythonCommandJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout;

    public int $tries;

    public int $backoff;

    /**
     * @param  array<int,string>  $args
     */
    public function __construct(
        public array $args,
        public string $governanceQueue
    ) {
        $this->onQueue($governanceQueue);
        $this->timeout = (int) config('data_engine.queues.timeout_seconds', 900);
        $this->tries = (int) config('data_engine.queues.tries', 3);
        $this->backoff = (int) config('data_engine.queues.backoff_seconds', 60);
    }

    public function handle(DataEngineGovernanceService $governance): void
    {
        $governance->executePythonOpsCommand($this->args);
    }
}

