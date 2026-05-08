<?php

namespace App\Console\Commands;

use App\Services\DataEngineGovernanceService;
use Illuminate\Console\Command;

class GovernanceGenerateFieldInventoryCommand extends Command
{
    protected $signature = 'governance:generate-field-inventory';

    protected $description = 'Generate dynamic full governance field inventory.';

    public function handle(DataEngineGovernanceService $governance): int
    {
        $result = $governance->executePythonOpsCommand(['generate-field-inventory']);
        $runtime = $result['runtime_verification'] ?? [];
        $this->info('Field inventory generated.');
        $this->line('Wizard fields: '.(int) ($runtime['total_wizard_fields'] ?? 0));
        $this->line('Governed fields: '.(int) ($runtime['total_governed_fields'] ?? 0));
        $this->line('Unsupported: '.(int) ($runtime['unsupported_field_count'] ?? 0));
        $this->line('Comparison supported: '.(int) ($runtime['comparison_supported_count'] ?? 0));

        return self::SUCCESS;
    }
}

