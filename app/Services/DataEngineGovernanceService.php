<?php

namespace App\Services;

use App\Models\DataEngineAdminAction;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class DataEngineGovernanceService
{
    /**
     * @param  array<int,string>  $args
     * @return array<string,mixed>
     */
    public function executePythonOpsCommand(array $args): array
    {
        $allowed = [
            'ops-dashboard',
            'self-heal-check',
            'snapshot-quarantine',
            'parity-validate',
            'relation-integrity',
            'api-drift-root-cause',
            'governance-regression',
            'governance-timeline',
            'bulk-governance',
            'compare',
            'analyze-explainability',
            'snapshot-diff-explorer',
            'security-audit',
            'multi-entity-validate',
            'deterministic-repair',
            'generate-field-inventory',
            'governance-runtime-truth',
            'generate-canonical-registry',
            'verify-repeater-diffs',
            'verify-api-parity',
        ];
        $cmd = $args[0] ?? '';
        if (! in_array($cmd, $allowed, true)) {
            throw new \InvalidArgumentException('Python governance command not allowlisted.');
        }

        return $this->runPython($args);
    }

    public function latestDashboardPayload(): ?array
    {
        return $this->latestJson(base_path('python-data-engine/output/dashboard'), 'dashboard_payload_*.json');
    }

    public function latestAdminReport(): ?array
    {
        return $this->latestJson(base_path('python-data-engine/output/admin_reports'), 'admin_report_*.json');
    }

    public function latestSystemHealth(): ?array
    {
        $path = base_path('python-data-engine/output/health/scheduler_recovery.json');
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function workflows(): array
    {
        $path = base_path('python-data-engine/output/workflows/workflow_state.json');
        $rows = [];
        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            $rows = is_array($decoded['actions'] ?? null) ? $decoded['actions'] : [];
        }
        $dbRows = DataEngineAdminAction::query()->orderByDesc('id')->limit(80)->get();
        foreach ($dbRows as $r) {
            $rows[] = [
                'action_id' => 'db:'.$r->id.':'.$r->action,
                'state' => $r->workflow_state ?: $r->status,
                'timestamp' => $r->created_at?->toIso8601String(),
                'progress_percent' => $r->progress_percent,
                'eta_at' => $r->eta_at?->toIso8601String(),
            ];
        }

        return array_values(array_filter($rows, fn ($r) => is_array($r)));
    }

    public function issues(?string $severity = null, ?string $q = null): array
    {
        $payload = $this->latestDashboardPayload();
        $issues = is_array($payload['issue_summaries'] ?? null) ? $payload['issue_summaries'] : [];
        $issues = array_values(array_filter($issues, function ($row) use ($severity, $q) {
            if (! is_array($row)) {
                return false;
            }
            if ($severity !== null && $severity !== '' && (($row['severity'] ?? '') !== $severity)) {
                return false;
            }
            if ($q !== null && $q !== '') {
                $hay = strtolower(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
                if (! str_contains($hay, strtolower($q))) {
                    return false;
                }
            }

            return true;
        }));

        $rawIssues = array_map(function ($row) {
            $issue = (string) ($row['issue'] ?? '');
            $row['recommended_recipe'] = match ($issue) {
                'Duplicate identities' => 'duplicate_profiles',
                'Cross-layer mismatches' => 'stale_indexes',
                'Schema integrity risks' => 'stale_indexes',
                default => 'stale_indexes',
            };
            $row['business_impact'] = $this->businessImpactForIssue($issue, (int) ($row['affected_count'] ?? 0));
            $row['affected_modules'] = $this->affectedModulesForIssue($issue);
            $row['cross_module_impact'] = $this->crossImpactForIssue($issue);
            $row['recommended_next_action'] = $this->recommendedNextAction($issue, (string) ($row['severity'] ?? 'low'));
            $row['estimated_business_impact'] = $this->estimatedBusinessImpact($row);
            $row['critical_badge'] = (($row['severity'] ?? '') === 'critical');
            $row['knowledge_assistant'] = $this->knowledgeAssistant($issue);
            $row['simulation'] = $this->simulationDefaults($row);
            $row['recurring_frequency'] = $this->recurringFrequency($issue);

            return $row;
        }, $issues);

        usort($rawIssues, function ($a, $b) {
            $rank = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
            $sa = $rank[(string) ($a['severity'] ?? 'low')] ?? 1;
            $sb = $rank[(string) ($b['severity'] ?? 'low')] ?? 1;
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }

            return ((int) ($b['affected_count'] ?? 0)) <=> ((int) ($a['affected_count'] ?? 0));
        });

        return $rawIssues;
    }

    public function rollbackManifests(): array
    {
        $base = base_path('python-data-engine/output/rollback');
        if (! is_dir($base)) {
            return [];
        }
        $files = glob($base.DIRECTORY_SEPARATOR.'rollback_manifest_*.json') ?: [];
        rsort($files);

        return array_map(fn ($f) => ['path' => $f, 'name' => basename($f)], $files);
    }

    public function queueAction(User $user, string $action, ?string $recipe, array $params = []): DataEngineAdminAction
    {
        $validated = $this->validateAction($action, $recipe, $params);
        $requiresApproval = $validated['is_destructive'] || ($validated['action'] === 'auto_self_heal');
        $pendingApproval = $requiresApproval && ! ($validated['auto_approved'] ?? false);

        return DataEngineAdminAction::query()->create([
            'user_id' => $user->id,
            'action' => $validated['action'],
            'recipe' => $validated['recipe'],
            'status' => $pendingApproval ? 'pending_approval' : 'queued',
            'workflow_state' => $pendingApproval ? 'reviewed' : 'approved',
            'progress_percent' => 0,
            'dry_run' => $validated['dry_run'],
            'is_destructive' => $validated['is_destructive'],
            'rollback_available' => $validated['rollback_available'],
            'request_payload' => $validated['params'],
            'eta_at' => now()->addMinutes(5),
        ]);
    }

    public function executeQueuedAction(DataEngineAdminAction $action): DataEngineAdminAction
    {
        if ($action->status === 'pending_approval') {
            return $action;
        }
        $this->markProgress($action, 'running', 5, 'Execution started');
        try {
            $before = $this->latestDashboardPayload();
            $action->update(['before_payload' => $before]);
            $this->markProgress($action, 'running', 25, 'Running repair pipeline');
            $result = $this->runCommandForAction($action);
            $this->markProgress($action, 'running', 60, 'Running auto validation');
            $validation = $this->autoValidateAfterFix($action);
            $after = $this->latestDashboardPayload();
            $diff = $this->buildBeforeAfterDiff($before, $after);
            $workflowState = (($validation['validation_passed'] ?? false) || $action->action !== 'execute_fix') ? 'validated' : 'failed';
            $currentPayload = is_array($action->fresh()->result_payload) ? $action->fresh()->result_payload : [];
            $action->update([
                'status' => $workflowState === 'validated' ? 'completed' : 'failed',
                'workflow_state' => $workflowState,
                'progress_percent' => 100,
                'after_payload' => $after,
                'validation_payload' => $validation + ['before_after_diff' => $diff],
                'result_payload' => array_merge($currentPayload, ['execution' => $result]),
                'finished_at' => now(),
            ]);
            if ($workflowState === 'failed' && $action->rollback_available) {
                $action->update(['workflow_state' => 'rolled_back']);
            }
        } catch (\Throwable $e) {
            $action->update([
                'status' => 'failed',
                'workflow_state' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);
        }

        return $action->fresh();
    }

    public function refreshDashboardArtifacts(): array
    {
        $this->runPython(['ops-dashboard']);
        $this->runPython(['self-heal-check']);

        return [
            'dashboard' => $this->latestDashboardPayload(),
            'system_health' => $this->latestSystemHealth(),
        ];
    }

    private function runCommandForAction(DataEngineAdminAction $action): array
    {
        $params = is_array($action->request_payload) ? $action->request_payload : [];
        return match ($action->action) {
            'preview_fix' => $this->executePythonOpsCommand(['deterministic-repair', '--recipe', (string) $action->recipe, '--preview-limit', (string) ((int) ($params['preview_limit'] ?? 25))]),
            'run_dry_run' => $this->executePythonOpsCommand(['deterministic-repair', '--recipe', (string) $action->recipe, '--preview-limit', (string) ((int) ($params['preview_limit'] ?? 25))]),
            'execute_fix' => $this->executePythonOpsCommand(['deterministic-repair', '--recipe', (string) $action->recipe, '--execute', '--preview-limit', (string) ((int) ($params['preview_limit'] ?? 25))]),
            'validate_fix' => $this->executePythonOpsCommand(['deterministic-repair', '--recipe', (string) $action->recipe, '--preview-limit', (string) ((int) ($params['preview_limit'] ?? 25))]),
            'rollback_manifest' => $this->runPython(['rollback-execute', '--manifest', (string) ($params['manifest_path'] ?? '')]),
            'approve_execute_fix' => $this->executePythonOpsCommand(['deterministic-repair', '--recipe', (string) $action->recipe, '--execute', '--preview-limit', (string) ((int) ($params['preview_limit'] ?? 25))]),
            'auto_self_heal' => $this->runSelfHealingPipeline($action),
            default => throw new \RuntimeException('Unsupported action'),
        };
    }

    private function validateAction(string $action, ?string $recipe, array $params): array
    {
        $allowed = ['preview_fix', 'run_dry_run', 'execute_fix', 'validate_fix', 'rollback_manifest', 'auto_self_heal', 'approve_execute_fix'];
        if (! in_array($action, $allowed, true)) {
            throw new \InvalidArgumentException('Action not allowlisted.');
        }

        $isDestructive = in_array($action, ['execute_fix', 'rollback_manifest', 'approve_execute_fix', 'auto_self_heal'], true);
        $dryRun = in_array($action, ['preview_fix', 'run_dry_run', 'validate_fix'], true);
        $rollbackAvailable = true;

        if ($action !== 'rollback_manifest') {
            $this->assertRecipeExists((string) $recipe);
        } elseif (empty($params['manifest_path'])) {
            throw new \InvalidArgumentException('Manifest path required for rollback.');
        }

        return [
            'action' => $action,
            'recipe' => $recipe,
            'params' => $params,
            'is_destructive' => $isDestructive,
            'dry_run' => $dryRun,
            'rollback_available' => $rollbackAvailable,
            'auto_approved' => in_array($action, ['preview_fix', 'run_dry_run', 'validate_fix'], true),
        ];
    }

    public function approveAction(DataEngineAdminAction $action, User $approver): DataEngineAdminAction
    {
        $action->update([
            'status' => 'queued',
            'workflow_state' => 'approved',
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);

        return $action->fresh();
    }

    public function trendAnalytics(): array
    {
        $rows = DataEngineAdminAction::query()->orderByDesc('id')->limit(200)->get();
        $success = $rows->where('status', 'completed')->count();
        $failed = $rows->where('status', 'failed')->count();
        $rolledBack = $rows->where('workflow_state', 'rolled_back')->count();
        $byRecipe = [];
        foreach ($rows as $r) {
            $key = (string) ($r->recipe ?: 'unknown');
            $byRecipe[$key] = ($byRecipe[$key] ?? 0) + 1;
        }
        arsort($byRecipe);

        return [
            'issue_trends' => array_slice($byRecipe, 0, 10, true),
            'recurring_failures' => $failed,
            'worsening_modules' => array_keys(array_slice($byRecipe, 0, 3, true)),
            'fix_success_rate' => ($success + $failed) > 0 ? round(($success / ($success + $failed)) * 100, 2) : 0,
            'rollback_frequency' => $rolledBack,
        ];
    }

    private function assertRecipeExists(string $recipe): void
    {
        if (! preg_match('/^[a-z0-9_]+$/', $recipe)) {
            throw new \InvalidArgumentException('Invalid recipe format.');
        }
        $path = base_path('python-data-engine/config/recipes/'.$recipe.'.yaml');
        if (! is_file($path)) {
            throw new \InvalidArgumentException('Recipe not found.');
        }
    }

    /**
     * Run runner.py and decode JSON from stdout even when the process exits non-zero (verification commands).
     *
     * @param  array<int,string>  $args
     * @return array<string,mixed>
     */
    public function runPythonJsonCommand(array $args): array
    {
        $allowed = ['verify-repeater-diffs', 'verify-api-parity'];
        $cmd = $args[0] ?? '';
        if (! in_array($cmd, $allowed, true)) {
            throw new \InvalidArgumentException('Python JSON command not allowlisted.');
        }
        $python = (string) config('data_engine.python_binary', 'python');
        $runner = base_path('python-data-engine/scripts/runner.py');
        $process = new Process(array_merge([$python, $runner], $args), base_path('python-data-engine'));
        $process->setTimeout((float) config('data_engine.timeout_seconds', 300));
        $process->run();
        $stdout = trim($process->getOutput());
        $decoded = json_decode($stdout, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException(trim($process->getErrorOutput()) !== '' ? trim($process->getErrorOutput()) : $stdout);
        }
        $decoded['_exit_code'] = $process->getExitCode();

        return $decoded;
    }

    private function runPython(array $args): array
    {
        $python = (string) config('data_engine.python_binary', 'python');
        $runner = base_path('python-data-engine/scripts/runner.py');
        $process = new Process(array_merge([$python, $runner], $args), base_path('python-data-engine'));
        $process->setTimeout((float) config('data_engine.timeout_seconds', 300));
        $process->run();
        $stdout = trim($process->getOutput());
        if (! $process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput()) !== '' ? trim($process->getErrorOutput()) : $stdout);
        }

        $decoded = json_decode($stdout, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return ['raw_output' => $stdout];
    }

    private function latestJson(string $dir, string $pattern): ?array
    {
        if (! is_dir($dir)) {
            return null;
        }
        $files = glob($dir.DIRECTORY_SEPARATOR.$pattern) ?: [];
        if ($files === []) {
            return null;
        }
        usort($files, fn ($a, $b) => (int) filemtime($b) <=> (int) filemtime($a));
        $raw = file_get_contents($files[0]);
        $decoded = $raw !== false ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    private function businessImpactForIssue(string $issue, int $affectedCount): string
    {
        $base = match ($issue) {
            'Duplicate identities' => 'Possible duplicate outreach or profile confusion',
            'Validation errors' => 'Incomplete profiles may reduce conversion',
            'Cross-layer mismatches' => 'Inconsistent profile display may hurt trust',
            default => 'Data quality may affect operational decisions',
        };

        return $base.' (approx '.$affectedCount.' records)';
    }

    private function affectedModulesForIssue(string $issue): array
    {
        return match ($issue) {
            'Duplicate identities' => ['matching', 'recommendations', 'search'],
            'Validation errors' => ['onboarding', 'profile_quality', 'recommendations'],
            'Cross-layer mismatches' => ['api', 'public_profile', 'admin_views'],
            default => ['governance', 'monitoring'],
        };
    }

    private function crossImpactForIssue(string $issue): array
    {
        return match ($issue) {
            'Validation errors' => ['nearby matching', 'recommendations', 'search ranking'],
            'Cross-layer mismatches' => ['recommendations', 'search ranking'],
            default => ['nearby matching'],
        };
    }

    private function recommendedNextAction(string $issue, string $severity): string
    {
        if (in_array($severity, ['high', 'critical'], true)) {
            return 'Preview fix -> reviewer approval -> execute with rollback readiness';
        }

        return match ($issue) {
            'Duplicate identities' => 'Run dry-run dedupe and inspect impact preview',
            default => 'Run simulation and validate expected improvement',
        };
    }

    private function estimatedBusinessImpact(array $row): string
    {
        $count = (int) ($row['affected_count'] ?? 0);
        return $count >= 50 ? 'High' : ($count >= 10 ? 'Medium' : 'Low');
    }

    private function knowledgeAssistant(string $issue): array
    {
        $glossary = [
            'api_drift' => [
                'en' => 'API output is not matching source data consistently.',
                'mr' => 'API मधील data आणि source data एकसारखे दिसत नाहीत.',
            ],
            'semantic_equivalent' => [
                'en' => 'Values look different but mean the same after normalization.',
                'mr' => 'Value वेगळे दिसले तरी normalization नंतर अर्थ तोच असतो.',
            ],
            'mismatch_severity' => [
                'en' => 'Severity indicates business risk priority for fix execution.',
                'mr' => 'Severity म्हणजे business risk नुसार fix ची प्राधान्यक्रम पातळी.',
            ],
            'rollback_risk' => [
                'en' => 'Rollback risk indicates how safely we can restore pre-fix state.',
                'mr' => 'Rollback risk म्हणजे fix नंतर जुनी स्थिती सुरक्षितपणे परत आणता येईल का.',
            ],
        ];

        return [
            'issue' => $issue,
            'glossary' => $glossary,
        ];
    }

    private function simulationDefaults(array $row): array
    {
        $affected = (int) ($row['affected_count'] ?? 0);
        $severity = (string) ($row['severity'] ?? 'low');
        $confidence = $severity === 'critical' ? 0.55 : ($severity === 'high' ? 0.7 : 0.85);
        $healthGain = min(20, max(1, (int) ceil($affected / 5)));
        return [
            'affected_rows' => $affected,
            'estimated_changes' => $affected,
            'confidence_score' => $confidence,
            'destructive_risk' => in_array($severity, ['high', 'critical'], true) ? 'high' : 'low',
            'rollback_availability' => (bool) ($row['rollback_available'] ?? true),
            'expected_health_improvement' => $healthGain,
        ];
    }

    private function recurringFrequency(string $issue): int
    {
        return DataEngineAdminAction::query()->where('recipe', Str::snake($issue))->count();
    }

    private function autoValidateAfterFix(DataEngineAdminAction $action): array
    {
        if (! in_array($action->action, ['execute_fix', 'approve_execute_fix', 'auto_self_heal'], true)) {
            return ['validation_passed' => true, 'reason' => 'non_destructive_or_preview'];
        }
        $this->runPython(['compare', '--latest']);
        $this->runPython(['ops-dashboard']);
        $after = $this->latestDashboardPayload();
        $critical = (int) ($after['risk_summaries']['critical_issue_count'] ?? 0);

        return [
            'validation_passed' => $critical === 0,
            'critical_issue_count_after' => $critical,
            'health_after' => (int) ($after['risk_summaries']['overall_platform_health'] ?? 0),
        ];
    }

    private function buildBeforeAfterDiff(?array $before, ?array $after): array
    {
        $bHealth = (int) (($before['risk_summaries']['overall_platform_health'] ?? 0));
        $aHealth = (int) (($after['risk_summaries']['overall_platform_health'] ?? 0));
        $bCrit = (int) (($before['risk_summaries']['critical_issue_count'] ?? 0));
        $aCrit = (int) (($after['risk_summaries']['critical_issue_count'] ?? 0));
        return [
            'health_before' => $bHealth,
            'health_after' => $aHealth,
            'health_improvement' => $aHealth - $bHealth,
            'critical_before' => $bCrit,
            'critical_after' => $aCrit,
            'critical_reduction' => $bCrit - $aCrit,
        ];
    }

    private function markProgress(DataEngineAdminAction $action, string $state, int $progress, string $message): void
    {
        $fresh = $action->fresh() ?? $action;
        $payload = is_array($fresh->result_payload) ? $fresh->result_payload : [];
        $logs = is_array($payload['live_logs'] ?? null) ? $payload['live_logs'] : [];
        $logs[] = ['at' => now()->toIso8601String(), 'message' => $message, 'state' => $state, 'progress' => $progress];
        $eta = now()->addSeconds(max(20, (100 - $progress) * 2));
        $fresh->update([
            'status' => $state === 'running' ? 'running' : $action->status,
            'workflow_state' => $state,
            'progress_percent' => $progress,
            'eta_at' => $eta,
            'result_payload' => array_merge($payload, ['live_logs' => $logs]),
            'started_at' => $fresh->started_at ?: now(),
        ]);
    }

    private function runSelfHealingPipeline(DataEngineAdminAction $action): array
    {
        $params = is_array($action->request_payload) ? $action->request_payload : [];
        $minConfidence = (float) ($params['min_confidence'] ?? 0.7);
        $maxRisk = (string) ($params['max_risk'] ?? 'medium');
        $this->markProgress($action, 'running', 20, 'Detecting issues for self-heal');
        $preview = $this->runPython(['auto-fix', '--recipe', (string) $action->recipe, '--preview-limit', (string) ((int) ($params['preview_limit'] ?? 25))]);
        $confidence = (float) ($preview['preview']['confidence'] ?? 0.0);
        $risk = (string) ($preview['preview']['risk'] ?? 'high');
        $riskRank = ['low' => 1, 'medium' => 2, 'high' => 3];
        if ($confidence < $minConfidence || (($riskRank[$risk] ?? 3) > ($riskRank[$maxRisk] ?? 2))) {
            return [
                'status' => 'aborted',
                'reason' => 'safety_threshold_gate',
                'preview' => $preview,
                'min_confidence' => $minConfidence,
                'max_risk' => $maxRisk,
            ];
        }
        $this->markProgress($action, 'running', 45, 'Backup and fix execution started');
        $result = $this->runPython(['auto-fix', '--recipe', (string) $action->recipe, '--execute', '--preview-limit', (string) ((int) ($params['preview_limit'] ?? 25))]);
        $this->markProgress($action, 'running', 75, 'Validation after self-heal in progress');
        $validation = $this->autoValidateAfterFix($action);

        return [
            'status' => 'completed',
            'preview' => $preview,
            'execute' => $result,
            'validation' => $validation,
            'notification' => 'self_healing_pipeline_completed',
        ];
    }
}

