<?php

namespace App\Services\Governance\Explainability;

class GovernanceExplainabilityService
{
    /**
     * @param  array<string,mixed>  $issue
     * @return array<string,mixed>
     */
    public function explain(array $issue): array
    {
        $type = (string) ($issue['comparison_type'] ?? $issue['type'] ?? 'mismatch');
        $message = match ($type) {
            'api_drift' => 'Field exists in DB but API output is inconsistent for this field path.',
            'missing_render' => 'Field exists in wizard/db flow but missing on public profile render.',
            'semantic_equivalent' => 'Values differ in representation but normalization resolves equivalence.',
            default => 'Cross-layer mismatch detected in governance comparison.',
        };

        return [
            'message' => $message,
            'severity' => (string) ($issue['severity'] ?? 'medium'),
            'affected_systems' => ['wizard', 'database', 'api', 'public_profile'],
            'recommended_action' => 'Run profile-level validation, inspect lineage, then execute safe repair workflow.',
        ];
    }
}

