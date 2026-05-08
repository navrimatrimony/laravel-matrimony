<?php

namespace App\Services;

use App\Models\DataEngineRun;

/**
 * Thin facade over {@see DataEngineService} for admin actions (analyze / fix).
 */
class PythonDataEngineService
{
    public function __construct(
        protected DataEngineService $engine
    ) {}

    public function runAnalyze(): DataEngineRun
    {
        return $this->engine->run('analyze');
    }

    public function runFix(): DataEngineRun
    {
        return $this->engine->run('fix');
    }

    /**
     * True when a row is still marked running (stale rows are released on admin pages / before runs).
     */
    public function isRunning(): bool
    {
        return DataEngineRun::query()->where('status', 'running')->exists();
    }
}
