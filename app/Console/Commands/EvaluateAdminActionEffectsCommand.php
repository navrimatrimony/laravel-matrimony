<?php

namespace App\Console\Commands;

use App\Services\Admin\AdminDashboardMetricsService;
use Illuminate\Console\Command;

class EvaluateAdminActionEffectsCommand extends Command
{
    protected $signature = 'admin:evaluate-action-effects';

    protected $description = 'Compare metrics 24–72h after admin insight actions and log admin_action_effect rows.';

    public function handle(AdminDashboardMetricsService $metrics): int
    {
        $n = $metrics->evaluatePendingAdminActionEffects();
        $this->info("Recorded {$n} effect(s).");

        return self::SUCCESS;
    }
}
