<?php

namespace App\Jobs;

use App\Models\DataEngineAdminAction;
use App\Services\DataEngineGovernanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExecuteDataEngineGovernanceActionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 360;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public int $actionId)
    {
        $this->onQueue((string) config('data_engine.queues.repair', 'governance-repair'));
        $this->timeout = (int) config('data_engine.queues.timeout_seconds', 900);
        $this->tries = (int) config('data_engine.queues.tries', 3);
        $this->backoff = (int) config('data_engine.queues.backoff_seconds', 60);
    }

    public function handle(DataEngineGovernanceService $governance): void
    {
        $action = DataEngineAdminAction::query()->find($this->actionId);
        if (! $action) {
            return;
        }
        $governance->executeQueuedAction($action);
    }
}

