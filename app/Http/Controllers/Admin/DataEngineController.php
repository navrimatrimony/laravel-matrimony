<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\DataEngineAlreadyRunningException;
use App\Exceptions\DataEngineDisabledException;
use App\Exceptions\DataEngineTimeoutException;
use App\Http\Controllers\Controller;
use App\Jobs\RunPythonDataEngineJob;
use App\Models\DataEngineRun;
use App\Models\MatrimonyProfile;
use App\Services\DataAudit\SnapshotStorageService;
use App\Services\DataAudit\OperationsService;
use App\Services\DataEngineGovernanceService;
use App\Services\DataEngineService;
use App\Services\MrLocalizationFillService;
use App\Services\PythonDataEngineService;
use App\Services\SystemSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DataEngineController extends Controller
{
    public function index(Request $request, DataEngineService $service, SystemSettingService $systemSettings, SnapshotStorageService $snapshotStorage, OperationsService $ops, DataEngineGovernanceService $governance): View
    {
        $monitor = $service->getMonitorStatus();

        $runs = DataEngineRun::query()
            ->orderByDesc('created_at')
            ->paginate(25);

        $engineRunning = (bool) ($monitor['running'] ?? false);
        $latestRun = DataEngineRun::query()->orderByDesc('created_at')->first();
        $latestSummary = null;
        $latestGuidance = null;
        $latestLineage = null;

        if ($latestRun?->report_path) {
            $full = base_path(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $latestRun->report_path));
            if (is_readable($full)) {
                $raw = file_get_contents($full);
                $decoded = $raw !== false ? json_decode($raw, true) : null;
                if (is_array($decoded)) {
                    $latestSummary = $service->summarizeReport($decoded, $latestRun->mode);
                    $latestGuidance = $this->buildSimpleGuidance($decoded, $latestSummary);
                    $latestLineage = is_array($decoded['data_lineage'] ?? null) ? $decoded['data_lineage'] : null;
                }
            }
        }

        $lastExecution = Cache::get('data_engine:last_execution');
        $snapshotMeta = $snapshotStorage->latestSnapshotMeta();
        $snapshotCount = $snapshotStorage->countAllSnapshots();
        $snapshotHealth = $snapshotMeta !== null ? 'ready' : 'empty';
        $comparisonSummary = null;
        $comparisonDir = (string) config('data-governance.platform.storage.comparison_base_path', base_path('python-data-engine/output/comparisons'));
        if (is_dir($comparisonDir)) {
            $files = File::files($comparisonDir);
            if ($files !== []) {
                usort($files, fn ($a, $b) => $b->getMTime() <=> $a->getMTime());
                $latest = $files[0];
                $raw = file_get_contents($latest->getPathname());
                $decoded = $raw !== false ? json_decode($raw, true) : null;
                if (is_array($decoded)) {
                    $summary = is_array($decoded['summary'] ?? null) ? $decoded['summary'] : [];
                    $comparisonSummary = [
                        'path' => $latest->getPathname(),
                        'generated_at' => date('c', $latest->getMTime()),
                        'health_score' => isset($decoded['health_score']) ? (int) $decoded['health_score'] : null,
                        'mismatch_count' => (int) ($summary['mismatch_count'] ?? 0),
                        'high_severity_count' => (int) ($summary['high_severity_count'] ?? 0),
                    ];
                }
            }
        }

        $heartbeat = $ops->buildHeartbeatSummary();
        $storageHealth = $ops->detectStorageHealth();
        $staleHours = max(1, (int) config('data_engine.ops.stale_hours', 24));
        $unhealthy = false;
        foreach ($heartbeat as $row) {
            $failureStreak = (int) ($row['failure_streak'] ?? 0);
            if ($failureStreak >= (int) config('data_engine.ops.warning_failure_streak', 2)) {
                $unhealthy = true;
                break;
            }
            $lastSuccessAt = $row['last_success_at'] ?? null;
            if ($lastSuccessAt && now()->diffInHours($lastSuccessAt) > $staleHours) {
                $unhealthy = true;
                break;
            }
        }

        $retention = [
            'snapshot_keep_per_entity' => (int) config('data_engine.retention.snapshot_keep_per_entity', 20),
            'snapshot_max_age_days' => (int) config('data_engine.retention.snapshot_max_age_days', 30),
            'comparison_keep_files' => (int) config('data_engine.retention.comparison_keep_files', 50),
            'comparison_max_age_days' => (int) config('data_engine.retention.comparison_max_age_days', 30),
            'report_max_age_days' => (int) config('data_engine.retention.report_max_age_days', 30),
            'log_max_age_days' => (int) config('data_engine.retention.log_max_age_days', 30),
        ];
        $dashboardPayload = $governance->latestDashboardPayload() ?? [];
        $healthCards = is_array($dashboardPayload['health_cards'] ?? null) ? $dashboardPayload['health_cards'] : [];
        $riskSummary = is_array($dashboardPayload['risk_summaries'] ?? null) ? $dashboardPayload['risk_summaries'] : [];
        $issueSummaries = is_array($dashboardPayload['issue_summaries'] ?? null) ? $dashboardPayload['issue_summaries'] : [];
        $workflowRows = $governance->workflows();
        $failedWorkflowCount = count(array_filter($workflowRows, fn ($w) => is_array($w) && (($w['state'] ?? '') === 'failed')));
        $warningCount = count(array_filter($issueSummaries, fn ($i) => is_array($i) && in_array(($i['severity'] ?? ''), ['medium', 'high', 'critical'], true)));
        $friendlyImpact = $this->friendlyImpactMap($issueSummaries);
        $attentionItems = count(array_filter($issueSummaries, fn ($i) => is_array($i) && in_array(($i['severity'] ?? ''), ['critical', 'high', 'medium'], true)));
        $seriousItems = count(array_filter($issueSummaries, fn ($i) => is_array($i) && in_array(($i['severity'] ?? ''), ['critical', 'high'], true)));
        $governanceSimpleHealth = [
            'score' => (int) ($riskSummary['overall_platform_health'] ?? 0),
            'items_needing_review' => $attentionItems,
            'serious_items' => $seriousItems,
            'snapshots_on_disk' => $snapshotCount,
            'last_dashboard_refresh' => is_string($dashboardPayload['generated_at'] ?? null) ? $dashboardPayload['generated_at'] : null,
        ];

        $profilePickerQuery = trim((string) $request->query('profile_search', ''));
        // SSOT: governance profile picker MUST surface `matrimony_profiles.id`,
        // not `users.id` — the governance routes/snapshots/comparisons all key
        // off matrimony profile id (e.g. `matrimony_profile_<id>` snapshot dirs,
        // `MatrimonyProfile::whereKey($profileId)`). Showing user.id here used
        // to mislead admins into opening the wrong governance profile.
        //
        // NOTE: do NOT alias the `matrimony_profiles` table here — the model
        // uses the SoftDeletes trait, whose global scope adds
        // `matrimony_profiles.deleted_at is null` using the literal table name.
        // Aliasing would break that scope at the SQL level.
        $profilePickerRows = MatrimonyProfile::query()
            ->leftJoin('users', 'users.id', '=', 'matrimony_profiles.user_id')
            ->select([
                'matrimony_profiles.id as id',
                'matrimony_profiles.user_id as user_id',
                'matrimony_profiles.full_name as full_name',
                'users.name as user_name',
                'users.mobile as mobile',
                'users.email as email',
                'matrimony_profiles.created_at as created_at',
            ])
            ->when($profilePickerQuery !== '', function ($q) use ($profilePickerQuery): void {
                $q->where(function ($inner) use ($profilePickerQuery): void {
                    $like = '%'.$profilePickerQuery.'%';
                    $inner
                        ->where('matrimony_profiles.full_name', 'like', $like)
                        ->orWhere('users.name', 'like', $like)
                        ->orWhere('users.mobile', 'like', $like)
                        ->orWhere('users.email', 'like', $like);
                    if (ctype_digit($profilePickerQuery)) {
                        $needle = (int) $profilePickerQuery;
                        $inner->orWhere('matrimony_profiles.id', $needle)
                            ->orWhere('matrimony_profiles.user_id', $needle);
                    }
                });
            })
            ->orderByDesc('matrimony_profiles.id')
            ->limit(15)
            ->get();

        return view('admin.data-engine.index', [
            'runs' => $runs,
            'monitor' => $monitor,
            'engineRunning' => $engineRunning,
            'engineDbOn' => $systemSettings->boolean('data_engine_enabled', true),
            'enginePowered' => $service->isEffectiveEnabled(),
            'envEngineAllows' => filter_var(config('data_engine.enabled'), FILTER_VALIDATE_BOOLEAN),
            'showQueueBadge' => app()->environment('production') && (bool) config('data_engine.queue_on_production', true),
            'latestRun' => $latestRun,
            'latestSummary' => $latestSummary,
            'latestGuidance' => $latestGuidance,
            'latestLineage' => $latestLineage,
            'lastExecution' => is_array($lastExecution) ? $lastExecution : null,
            'snapshotMeta' => $snapshotMeta,
            'snapshotCount' => $snapshotCount,
            'snapshotHealth' => $snapshotHealth,
            'comparisonSummary' => $comparisonSummary,
            'heartbeat' => $heartbeat,
            'storageHealth' => $storageHealth,
            'retentionPolicy' => $retention,
            'opsUnhealthy' => $unhealthy,
            'governanceDashboard' => $dashboardPayload,
            'governanceHealthCards' => $healthCards,
            'governanceRiskSummary' => $riskSummary,
            'governanceIssueSummaries' => $issueSummaries,
            'failedWorkflowCount' => $failedWorkflowCount,
            'warningIssueCount' => $warningCount,
            'friendlyImpact' => $friendlyImpact,
            'governanceSimpleHealth' => $governanceSimpleHealth,
            'profilePickerQuery' => $profilePickerQuery,
            'profilePickerRows' => $profilePickerRows,
        ]);
    }

    private function friendlyImpactMap(array $issues): array
    {
        $out = [];
        foreach ($issues as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = (string) ($row['issue'] ?? '');
            $out[$key] = match ($key) {
                'Duplicate identities' => [
                    'en' => 'Same member may appear multiple times, causing confusion in profile operations.',
                    'mr' => 'एकच सदस्य अनेकदा दिसू शकतो, त्यामुळे प्रोफाइल ऑपरेशन्समध्ये गोंधळ होतो.',
                ],
                'Validation errors' => [
                    'en' => 'Incomplete core profile data can reduce matching quality and trust.',
                    'mr' => 'अपूर्ण प्रोफाइल माहितीमुळे मॅचिंग गुणवत्ता आणि विश्वास कमी होतो.',
                ],
                'Cross-layer mismatches' => [
                    'en' => 'Profile information may show inconsistently across admin/API/public views.',
                    'mr' => 'प्रोफाइल माहिती admin/API/public views मध्ये विसंगत दिसू शकते.',
                ],
                'Schema integrity risks' => [
                    'en' => 'Sparse data structure can weaken search and reporting reliability.',
                    'mr' => 'डेटा स्ट्रक्चर sparse असल्यास search आणि reporting ची reliability कमी होते.',
                ],
                'High severity lineage/comparison' => [
                    'en' => 'Critical data inconsistencies may impact downstream governance decisions.',
                    'mr' => 'गंभीर डेटा विसंगतीमुळे governance निर्णयांवर परिणाम होऊ शकतो.',
                ],
                default => [
                    'en' => 'Data quality issue detected that may affect platform operations.',
                    'mr' => 'डेटा गुणवत्ता समस्या सापडली असून प्लॅटफॉर्म ऑपरेशन्सवर परिणाम होऊ शकतो.',
                ],
            };
        }

        return $out;
    }

    public function status(DataEngineService $service): JsonResponse
    {
        return response()->json($service->getMonitorStatus());
    }

    /**
     * Build simple "what is wrong + what to do" guidance for low-technical admins.
     *
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>|null  $summary
     * @return array<string, mixed>
     */
    protected function buildSimpleGuidance(array $report, ?array $summary): array
    {
        $summary = is_array($summary) ? $summary : [];

        $validationErrors = is_array($report['validation_errors'] ?? null) ? $report['validation_errors'] : [];
        $emptyPhone = 0;
        $missingName = 0;
        foreach ($validationErrors as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (($row['rule'] ?? null) === 'missing_name') {
                $missingName++;
            }
            if (($row['rule'] ?? null) === 'invalid_phone') {
                $msg = strtolower((string) ($row['message'] ?? ''));
                if (str_contains($msg, 'empty')) {
                    $emptyPhone++;
                }
            }
        }

        $anomalies = is_array($summary['anomalies'] ?? null) ? $summary['anomalies'] : [];
        $quality = isset($summary['quality_score']) ? (int) $summary['quality_score'] : null;
        $issues = isset($summary['total_issues']) ? (int) $summary['total_issues'] : 0;
        $schemaIssues = isset($summary['schema_issues']) ? (int) $summary['schema_issues'] : 0;
        $dups = isset($summary['duplicate_groups']) ? (int) $summary['duplicate_groups'] : 0;
        $val = isset($summary['validation_errors']) ? (int) $summary['validation_errors'] : 0;
        $mis = isset($summary['mismatch_rows']) ? (int) $summary['mismatch_rows'] : 0;

        $nextAction = [
            'title' => 'No urgent action',
            'reason' => 'Current run looks stable. Re-run analyze after new data arrives.',
            'route' => route('admin.data-engine.index'),
            'button' => 'Refresh run list',
            'severity' => 'good',
        ];

        if ($emptyPhone > 0 || $missingName > 0) {
            $nextAction = [
                'title' => 'Fix missing user core fields first',
                'reason' => "Empty phone: {$emptyPhone}, Missing name: {$missingName}. These directly reduce match readiness.",
                'route' => route('admin.duplicate-phones.index'),
                'button' => 'Open duplicate / phone cleanup',
                'severity' => 'high',
            ];
        } elseif ($dups > 0) {
            $nextAction = [
                'title' => 'Resolve duplicate identities',
                'reason' => "Duplicate groups found: {$dups}. Prevent wrong matching and duplicate outreach.",
                'route' => route('admin.duplicate-phones.index'),
                'button' => 'Resolve duplicates',
                'severity' => 'high',
            ];
        } elseif ($mis > 0) {
            $nextAction = [
                'title' => 'Review city mismatch rules',
                'reason' => "Mismatch rows found: {$mis}. City mismatch can break location filters.",
                'route' => route('admin.monitoring.index'),
                'button' => 'Open monitoring',
                'severity' => 'mid',
            ];
        } elseif ($schemaIssues > 0) {
            $nextAction = [
                'title' => 'Review sparse schema fields',
                'reason' => "Null-heavy columns: {$schemaIssues}. Improve intake defaults before running fix mode.",
                'route' => route('admin.data-engine.index'),
                'button' => 'Run analyze again after intake updates',
                'severity' => 'mid',
            ];
        } elseif ($quality !== null && $quality < 70) {
            $nextAction = [
                'title' => 'Quality low — review full report now',
                'reason' => "Quality score is {$quality}, below expected healthy level.",
                'route' => route('admin.data-engine.index'),
                'button' => 'Inspect latest report details',
                'severity' => 'high',
            ];
        }

        return [
            'quality' => $quality,
            'issues' => $issues,
            'breakdown' => [
                'duplicate_groups' => $dups,
                'validation_errors' => $val,
                'mismatch_rows' => $mis,
                'schema_issues' => $schemaIssues,
            ],
            'empty_phone_count' => $emptyPhone,
            'missing_name_count' => $missingName,
            'anomalies' => $anomalies,
            'next_action' => $nextAction,
        ];
    }

    public function runAnalyze(DataEngineService $dataEngine, PythonDataEngineService $python): RedirectResponse
    {
        if (! $dataEngine->isEffectiveEnabled()) {
            return redirect()
                ->route('admin.data-engine.index')
                ->with('error', 'Python engine is disabled.');
        }

        if ($this->shouldQueueDataEngineRun()) {
            RunPythonDataEngineJob::dispatch('analyze');

            return redirect()
                ->route('admin.data-engine.index')
                ->with('status', 'Analyze run has been queued. Ensure a queue worker is running to execute it.');
        }

        try {
            $run = $python->runAnalyze();
        } catch (DataEngineDisabledException $e) {
            return redirect()->route('admin.data-engine.index')->with('error', $e->getMessage());
        } catch (DataEngineAlreadyRunningException $e) {
            return redirect()->route('admin.data-engine.index')->with('error', $e->getMessage());
        } catch (DataEngineTimeoutException $e) {
            return redirect()
                ->route('admin.data-engine.index')
                ->with(
                    'error',
                    'Data engine timed out. Increase DATA_ENGINE_TIMEOUT or inspect storage/logs/data-engine.log.'
                );
        } catch (\Throwable $e) {
            return redirect()->route('admin.data-engine.index')->with('error', $e->getMessage());
        }

        $message = match ($run->status) {
            'completed' => 'Analyze run finished successfully.',
            'failed' => 'Analyze run finished with failures. Open the latest run for details.',
            default => 'Analyze run finished.',
        };

        return redirect()
            ->route('admin.data-engine.index')
            ->with('status', $message);
    }

    public function runFix(DataEngineService $dataEngine, PythonDataEngineService $python): RedirectResponse
    {
        if (! $dataEngine->isEffectiveEnabled()) {
            return redirect()
                ->route('admin.data-engine.index')
                ->with('error', 'Python engine is disabled.');
        }

        if (! $dataEngine->isFixModeAllowed()) {
            return redirect()
                ->route('admin.data-engine.index')
                ->with('error', 'Fix mode is safety-locked. Enable DATA_ENGINE_ALLOW_FIX_MODE=true for intentional runs.');
        }

        if ($this->shouldQueueDataEngineRun()) {
            RunPythonDataEngineJob::dispatch('fix');

            return redirect()
                ->route('admin.data-engine.index')
                ->with('status', 'Fix run has been queued. Ensure a queue worker is running to execute it.');
        }

        try {
            $run = $python->runFix();
        } catch (DataEngineDisabledException $e) {
            return redirect()->route('admin.data-engine.index')->with('error', $e->getMessage());
        } catch (DataEngineAlreadyRunningException $e) {
            return redirect()->route('admin.data-engine.index')->with('error', $e->getMessage());
        } catch (DataEngineTimeoutException $e) {
            return redirect()
                ->route('admin.data-engine.index')
                ->with(
                    'error',
                    'Data engine timed out. Increase DATA_ENGINE_TIMEOUT or inspect storage/logs/data-engine.log.'
                );
        } catch (\Throwable $e) {
            return redirect()->route('admin.data-engine.index')->with('error', $e->getMessage());
        }

        $message = match ($run->status) {
            'completed' => 'Fix run finished successfully.',
            'failed' => 'Fix run finished with failures. Review the report before running fix again.',
            default => 'Fix run finished.',
        };

        return redirect()
            ->route('admin.data-engine.index')
            ->with('status', $message);
    }

    /**
     * Production: avoid long HTTP requests by dispatching {@see RunPythonDataEngineJob}.
     */
    protected function shouldQueueDataEngineRun(): bool
    {
        return (bool) config('data_engine.queue_on_production', true)
            && app()->environment('production');
    }

    public function toggleEngine(SystemSettingService $settings): RedirectResponse
    {
        $wasOn = $settings->boolean('data_engine_enabled', true);
        $settings->set('data_engine_enabled', ! $wasOn);

        return redirect()
            ->route('admin.data-engine.index')
            ->with('engine_toggle', $wasOn ? 'db_off' : 'db_on');
    }

    public function show(DataEngineRun $run, DataEngineService $service): View
    {
        $reportData = null;
        $rawJson = null;
        $summary = null;
        $jsonPreview = null;
        $jsonTruncated = false;

        if ($run->report_path) {
            $full = base_path(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $run->report_path));
            if (is_readable($full)) {
                $raw = file_get_contents($full);
                $rawJson = $raw !== false ? $raw : null;
                if ($rawJson !== null) {
                    $limit = 20000;
                    if (strlen($rawJson) > $limit) {
                        $jsonPreview = substr($rawJson, 0, $limit)."\n\n… (truncated — use Download for full file)";
                        $jsonTruncated = true;
                    } else {
                        $jsonPreview = $rawJson;
                    }
                }
                $decoded = $rawJson !== null ? json_decode($rawJson, true) : null;
                $reportData = is_array($decoded) ? $decoded : null;
                if ($reportData !== null) {
                    $summary = $service->summarizeReport($reportData, $run->mode);
                }
            }
        }

        return view('admin.data-engine.show', [
            'run' => $run,
            'report' => $reportData,
            'rawJson' => $rawJson,
            'jsonPreview' => $jsonPreview,
            'jsonTruncated' => $jsonTruncated,
            'summary' => $summary,
        ]);
    }

    public function dataIntegrity(DataEngineService $service): View
    {
        $service->releaseStaleRunningRuns();

        $latestRun = DataEngineRun::query()->orderByDesc('created_at')->first();
        $di = [
            'summary' => [
                'health_score' => null,
                'registry_core_keys' => 0,
                'missing_columns_for_core_registry' => 0,
                'semantic_duplicate_group_warnings' => 0,
            ],
            'registry_vs_profile' => ['missing_columns' => []],
            'semantic_groups_triggered' => [],
            'implementation' => [],
            'roadmap' => [],
        ];

        if ($latestRun?->report_path) {
            $full = base_path(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $latestRun->report_path));
            if (is_readable($full)) {
                $raw = file_get_contents($full);
                $decoded = $raw !== false ? json_decode($raw, true) : null;
                if (is_array($decoded) && is_array($decoded['data_integrity'] ?? null)) {
                    $di = array_replace_recursive($di, $decoded['data_integrity']);
                }
            }
        }

        $engineRunning = DataEngineRun::query()->where('status', 'running')->exists();

        return view('admin.data-engine.data-integrity', [
            'latestRun' => $latestRun,
            'di' => $di,
            'engineRunning' => $engineRunning,
            'enginePowered' => $service->isEffectiveEnabled(),
        ]);
    }

    public function dataLineage(DataEngineService $service): View
    {
        $service->releaseStaleRunningRuns();

        $latestRun = DataEngineRun::query()->orderByDesc('created_at')->first();
        $dl = [
            'summary' => [
                'health_score' => null,
                'manifest_errors' => 0,
                'wrong_sources' => 0,
                'multi_source_conflicts' => 0,
                'wizard_public_mismatches' => 0,
                'missing_render_risks' => 0,
                'fields_audited' => 0,
            ],
            'manifest_errors' => [],
            'wrong_sources' => [],
            'multi_source_conflicts' => [],
            'wizard_public_mismatches' => [],
            'missing_render_risks' => [],
            'implementation' => [],
        ];

        if ($latestRun?->report_path) {
            $full = base_path(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $latestRun->report_path));
            if (is_readable($full)) {
                $raw = file_get_contents($full);
                $decoded = $raw !== false ? json_decode($raw, true) : null;
                if (is_array($decoded) && is_array($decoded['data_lineage'] ?? null)) {
                    $dl = array_replace_recursive($dl, $decoded['data_lineage']);
                }
            }
        }

        $engineRunning = DataEngineRun::query()->where('status', 'running')->exists();

        return view('admin.data-engine.data-lineage', [
            'latestRun' => $latestRun,
            'dl' => $dl,
            'engineRunning' => $engineRunning,
            'enginePowered' => $service->isEffectiveEnabled(),
        ]);
    }

    public function comparisons(Request $request, DataEngineService $service): View
    {
        $service->releaseStaleRunningRuns();
        $severity = (string) $request->query('severity', '');
        $showSuppressed = (string) $request->query('suppressed', '0') === '1';

        $comparisonDir = (string) config('data-governance.platform.storage.comparison_base_path', base_path('python-data-engine/output/comparisons'));
        $files = is_dir($comparisonDir) ? File::files($comparisonDir) : [];
        usort($files, fn ($a, $b) => $b->getMTime() <=> $a->getMTime());

        $reports = [];
        foreach (array_slice($files, 0, 30) as $file) {
            $raw = file_get_contents($file->getPathname());
            $decoded = $raw !== false ? json_decode($raw, true) : null;
            if (! is_array($decoded)) {
                continue;
            }
            $rows = is_array($decoded['comparisons'] ?? null) ? $decoded['comparisons'] : [];
            $rows = array_values(array_filter($rows, function ($r) use ($severity, $showSuppressed) {
                if (! is_array($r)) {
                    return false;
                }
                if (! $showSuppressed && ! empty($r['suppressed'])) {
                    return false;
                }
                if ($severity !== '' && (($r['effective_severity'] ?? $r['severity'] ?? '') !== $severity)) {
                    return false;
                }
                return true;
            }));

            $snapshotPath = is_string($decoded['snapshot_path'] ?? null) ? (string) $decoded['snapshot_path'] : '';
            $profileId = null;
            if ($snapshotPath !== '' && preg_match('/matrimony_profile_(\d+)/', $snapshotPath, $m) === 1) {
                $profileId = (int) $m[1];
            }

            $reports[] = [
                'file' => $file->getFilename(),
                'generated_at' => $decoded['generated_at'] ?? date('c', $file->getMTime()),
                'health_score' => $decoded['health_score'] ?? null,
                'summary' => $decoded['summary'] ?? [],
                'trends' => $decoded['trends'] ?? [],
                'comparisons' => $rows,
                'profile_id' => $profileId,
                'snapshot_path' => $snapshotPath,
            ];
        }

        $latest = $reports[0] ?? null;
        $persistent = is_array($latest['trends'] ?? null)
            ? array_values(array_filter($latest['trends'], fn ($t) => is_array($t) && (($t['trend'] ?? '') === 'persistent')))
            : [];

        return view('data-governance::comparisons', [
            'reports' => $reports,
            'latest' => $latest,
            'persistent' => $persistent,
            'severity' => $severity,
            'showSuppressed' => $showSuppressed,
        ]);
    }

    public function marathiColumns(DataEngineService $service, MrLocalizationFillService $mrFill): View
    {
        $service->releaseStaleRunningRuns();

        $latestRun = DataEngineRun::query()->orderByDesc('created_at')->first();
        $reportData = null;
        $summary = null;

        $mr = $mrFill->buildLiveLocalizationReport();

        if ($latestRun?->report_path) {
            $full = base_path(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $latestRun->report_path));
            if (is_readable($full)) {
                $raw = file_get_contents($full);
                $decoded = $raw !== false ? json_decode($raw, true) : null;
                if (is_array($decoded)) {
                    $reportData = $decoded;
                    $summary = $service->summarizeReport($reportData, $latestRun->mode);
                    $fixFromFile = $decoded['mr_localization']['fix'] ?? null;
                    if (is_array($fixFromFile)) {
                        $mr['fix'] = $fixFromFile;
                    }
                }
            }
        }

        $engineRunning = DataEngineRun::query()->where('status', 'running')->exists();

        return view('admin.data-engine.marathi-columns', [
            'latestRun' => $latestRun,
            'summary' => $summary,
            'mr' => $mr,
            'report' => $reportData,
            'engineRunning' => $engineRunning,
            'enginePowered' => $service->isEffectiveEnabled(),
        ]);
    }

    public function download(DataEngineRun $run): BinaryFileResponse
    {
        if (! $run->report_path) {
            abort(404);
        }

        $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $run->report_path);
        $full = base_path($relative);

        $reportsReal = realpath(base_path('python-data-engine'.DIRECTORY_SEPARATOR.'output'.DIRECTORY_SEPARATOR.'reports'));
        $pathReal = realpath($full);

        if ($reportsReal === false || $pathReal === false) {
            abort(404);
        }

        if (! str_starts_with($pathReal, $reportsReal)) {
            abort(404);
        }

        if (! is_readable($pathReal)) {
            abort(404);
        }

        return response()->download($pathReal, 'data-engine-run-'.$run->id.'.json');
    }
}
