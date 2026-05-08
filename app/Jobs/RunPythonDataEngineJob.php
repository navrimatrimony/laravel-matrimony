<?php

namespace App\Jobs;

use App\Services\PythonDataEngineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs the bundled python-data-engine asynchronously (production queue path).
 */
class RunPythonDataEngineJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    public int $backoff = 60;

    public function __construct(
        public string $mode
    ) {
        $queue = strtolower(trim($mode)) === 'fix'
            ? (string) config('data_engine.queues.repair', 'governance-repair')
            : (string) config('data_engine.queues.snapshot', 'governance-snapshot');
        $this->onQueue($queue);
        $this->timeout = (int) config('data_engine.queues.timeout_seconds', 900);
        $this->tries = (int) config('data_engine.queues.tries', 3);
        $this->backoff = (int) config('data_engine.queues.backoff_seconds', 60);
    }

    public function handle(PythonDataEngineService $service): void
    {
        $mode = strtolower(trim($this->mode));

        if ($mode === 'fix') {
            $service->runFix();

            return;
        }

        $service->runAnalyze();
    }
}
