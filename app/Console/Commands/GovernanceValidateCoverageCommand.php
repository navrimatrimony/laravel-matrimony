<?php

namespace App\Console\Commands;

use App\Services\DataEngineGovernanceService;
use Illuminate\Console\Command;

class GovernanceValidateCoverageCommand extends Command
{
    protected $signature = 'governance:validate-coverage';

    protected $description = 'Validate governance coverage map and dashboard consumption.';

    public function handle(DataEngineGovernanceService $governance): int
    {
        $governance->executePythonOpsCommand(['generate-canonical-registry']);
        $governance->executePythonOpsCommand(['generate-field-inventory']);
        $governance->executePythonOpsCommand(['ops-dashboard']);

        $dashboard = $governance->latestDashboardPayload();
        $coverage = is_array($dashboard['coverage'] ?? null) ? $dashboard['coverage'] : [];
        $totals = is_array($coverage['totals'] ?? null) ? $coverage['totals'] : [];
        $canonical = is_array($dashboard['coverage']['canonical_registry'] ?? null) ? $dashboard['coverage']['canonical_registry'] : [];
        $canMeta = is_array($canonical['meta'] ?? null) ? $canonical['meta'] : [];
        $this->info('Coverage integrity validation');
        $this->line('Total wizard fields: '.(int) ((is_array($dashboard['coverage']['auto_field_governance'] ?? null) ? ($dashboard['coverage']['totals']['total_detected_fields'] ?? 0) : ($totals['total_detected_fields'] ?? 0))));
        $this->line('Total governed fields: '.(int) ($totals['audited_fields'] ?? 0));
        $this->line('Unsupported fields: '.(int) ($totals['partial_support'] ?? 0));
        $this->line('Repeater fields: '.(int) ($totals['unsupported_repeaters'] ?? 0));
        $this->line('Compared count: '.(int) (($dashboard['risk_summaries']['critical_issue_count'] ?? 0)));
        $this->line('Canonical governed logical field count: '.(int) ($canMeta['governed_logical_field_count'] ?? ($totals['canonical_governed_logical_field_count'] ?? 0)));
        $this->line('Canonical comparison_supported_count: '.(int) ($canMeta['comparison_supported_count'] ?? ($totals['canonical_comparison_supported_count'] ?? 0)));

        return self::SUCCESS;
    }
}

