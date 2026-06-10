<?php

namespace App\Console\Commands;

use App\Models\SuchakAccount;
use App\Modules\Suchak\Services\SuchakWorkflowAutomationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerateSuchakWorkflowReminders extends Command
{
    protected $signature = 'suchak:workflow-reminders
        {--account= : Generate reminders for one Suchak account id}
        {--at= : Evaluation datetime, for example 2026-06-10 09:00:00}';

    protected $description = 'Generate idempotent Suchak follow-up, payment, consent, and meeting reminders.';

    public function handle(SuchakWorkflowAutomationService $automationService): int
    {
        $account = null;
        $accountId = $this->option('account');

        if ($accountId !== null && $accountId !== '') {
            $account = SuchakAccount::query()->findOrFail((int) $accountId);
        }

        $atOption = $this->option('at');
        $at = $atOption ? Carbon::parse((string) $atOption) : now();
        $reminders = $automationService->generateDueReminders($account, $at);

        $this->info('Suchak workflow reminders generated: '.$reminders->count());
        $this->line('Provider delivery remains pending_credentials; this command creates WhatsApp copy only.');

        return self::SUCCESS;
    }
}
