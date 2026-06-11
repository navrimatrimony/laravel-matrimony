<?php

namespace App\Console\Commands;

use App\Models\SuchakAccount;
use App\Modules\Suchak\Services\SuchakExportRetentionService;
use Illuminate\Console\Command;

class RunSuchakRetentionArchiveRules extends Command
{
    protected $signature = 'suchak:retention-archive {--account-id=} {--limit=50}';

    protected $description = 'Evaluate Suchak export retention archive rules without deleting source records.';

    public function handle(SuchakExportRetentionService $exportRetentionService): int
    {
        $account = null;
        $accountId = $this->option('account-id');

        if ($accountId !== null && $accountId !== '') {
            $account = SuchakAccount::query()->findOrFail((int) $accountId);
        }

        $runs = $exportRetentionService->runRetentionArchiveJob(
            null,
            $account,
            (int) $this->option('limit'),
        );

        $this->info('Suchak retention archive rules evaluated: '.$runs->count());

        return self::SUCCESS;
    }
}
