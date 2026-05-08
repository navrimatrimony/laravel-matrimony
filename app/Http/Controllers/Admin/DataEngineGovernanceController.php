<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ExecuteDataEngineGovernanceActionJob;
use App\Models\DataEngineAdminAction;
use App\Models\MatrimonyProfile;
use App\Services\DataEngineGovernanceService;
use App\Services\DataEnginePermissionService;
use App\Services\Governance\FieldLineageService;
use App\Services\Governance\GovernanceProfilePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DataEngineGovernanceController extends Controller
{
    public function issues(Request $request, DataEngineGovernanceService $governance, DataEnginePermissionService $permissions): View
    {
        abort_unless($permissions->canView($request->user()), 403);
        $severity = (string) $request->query('severity', '');
        $q = (string) $request->query('q', '');

        $issues = $governance->issues($severity !== '' ? $severity : null, $q !== '' ? $q : null);
        $grouped = [
            'by_severity' => collect($issues)->groupBy(fn ($i) => (string) ($i['severity'] ?? 'low'))->toArray(),
            'by_module' => collect($issues)->mapWithKeys(fn ($i) => [($i['issue'] ?? 'unknown') => ($i['affected_modules'] ?? [])])->toArray(),
        ];

        return view('admin.data-engine.issues', [
            'issues' => $issues,
            'severity' => $severity,
            'q' => $q,
            'grouped' => $grouped,
            'trends' => $governance->trendAnalytics(),
            'canApprove' => $permissions->canApprove($request->user()),
        ]);
    }

    public function workflows(Request $request, DataEngineGovernanceService $governance, DataEnginePermissionService $permissions): View
    {
        abort_unless($permissions->canView($request->user()), 403);

        return view('admin.data-engine.workflows', [
            'workflows' => $governance->workflows(),
            'trends' => $governance->trendAnalytics(),
        ]);
    }

    public function audit(Request $request, DataEnginePermissionService $permissions): View
    {
        abort_unless($permissions->canReview($request->user()), 403);
        $rows = DataEngineAdminAction::query()->with(['user', 'approver'])->orderByDesc('id')->paginate(30);

        return view('admin.data-engine.audit', [
            'rows' => $rows,
            'canApprove' => $permissions->canApprove($request->user()),
        ]);
    }

    public function systemHealth(Request $request, DataEngineGovernanceService $governance, DataEnginePermissionService $permissions): View
    {
        abort_unless($permissions->canView($request->user()), 403);

        return view('admin.data-engine.system-health', ['health' => $governance->latestSystemHealth()]);
    }

    public function rollback(Request $request, DataEngineGovernanceService $governance, DataEnginePermissionService $permissions): View
    {
        abort_unless($permissions->canReview($request->user()), 403);

        $history = DataEngineAdminAction::query()
            ->where(function ($q) {
                $q->where('workflow_state', 'rolled_back')
                    ->orWhere('action', 'rollback_manifest');
            })
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        return view('admin.data-engine.rollback', [
            'manifests' => $governance->rollbackManifests(),
            'history' => $history,
        ]);
    }

    public function executeAction(Request $request, DataEngineGovernanceService $governance, DataEnginePermissionService $permissions): RedirectResponse
    {
        $user = $request->user();
        abort_unless($permissions->canOperate($user), 403);

        $action = (string) $request->input('action');
        $recipe = $request->input('recipe');
        $params = [
            'preview_limit' => max(1, (int) $request->input('preview_limit', 25)),
            'manifest_path' => (string) $request->input('manifest_path', ''),
            'min_confidence' => (float) $request->input('min_confidence', 0.7),
            'max_risk' => (string) $request->input('max_risk', 'medium'),
        ];
        $isDestructive = in_array($action, ['execute_fix', 'rollback_manifest'], true);
        if ($isDestructive) {
            abort_unless($permissions->canDestructive($user), 403);
        }

        $record = $governance->queueAction($user, $action, is_string($recipe) ? $recipe : null, $params);
        if ($record->status !== 'pending_approval') {
            ExecuteDataEngineGovernanceActionJob::dispatch($record->id);
            return back()->with('status', 'Governance action queued.');
        }

        return back()->with('status', 'Action submitted for reviewer approval.');
    }

    public function approveAction(int $actionId, Request $request, DataEngineGovernanceService $governance, DataEnginePermissionService $permissions): RedirectResponse
    {
        abort_unless($permissions->canApprove($request->user()), 403);
        $action = DataEngineAdminAction::query()->findOrFail($actionId);
        $action = $governance->approveAction($action, $request->user());
        ExecuteDataEngineGovernanceActionJob::dispatch($action->id);

        return back()->with('status', 'Action approved and queued.');
    }

    public function refreshDashboard(Request $request, DataEngineGovernanceService $governance, DataEnginePermissionService $permissions): RedirectResponse
    {
        abort_unless($permissions->canOperate($request->user()), 403);
        $governance->refreshDashboardArtifacts();

        return back()->with('status', 'Dashboard artifacts refreshed.');
    }

    public function liveStatus(Request $request, DataEnginePermissionService $permissions): JsonResponse
    {
        abort_unless($permissions->canView($request->user()), 403);
        $latest = DataEngineAdminAction::query()->orderByDesc('id')->limit(10)->get(['id', 'action', 'recipe', 'status', 'workflow_state', 'progress_percent', 'eta_at', 'created_at', 'finished_at', 'result_payload']);
        $counts = [
            'running' => DataEngineAdminAction::query()->where('status', 'running')->count(),
            'failed' => DataEngineAdminAction::query()->where('status', 'failed')->count(),
            'queued' => DataEngineAdminAction::query()->where('status', 'queued')->count(),
            'pending_approval' => DataEngineAdminAction::query()->where('status', 'pending_approval')->count(),
        ];

        return response()->json(['counts' => $counts, 'latest' => $latest]);
    }

    public function profile(int $profileId, Request $request, DataEngineGovernanceService $governance, DataEnginePermissionService $permissions, FieldLineageService $lineage): View
    {
        $user = $request->user();
        abort_unless($permissions->canView($user), 403);
        [$comparison, $snapshot] = $this->loadProfileGovernanceArtifacts($profileId);
        $issues = [];
        foreach (($comparison['comparisons'] ?? []) as $row) {
            if (! is_array($row) || ($row['status'] ?? '') !== 'fail') {
                continue;
            }
            $issues[] = [
                'field_path' => (string) ($row['field'] ?? ''),
                'source_value' => $row['db'] ?? null,
                'target_value' => $row['rendered'] ?? ($row['api'] ?? null),
                'normalized_value' => $row['api'] ?? null,
                'diff_reason' => (string) ($row['comparison_type'] ?? ''),
                'confidence' => (float) (($comparison['reliability']['reliability_score'] ?? 0) / 100),
                'lineage' => 'wizard -> database -> api -> public_profile',
            ];
        }
        $history = DataEngineAdminAction::query()->whereJsonContains('request_payload->profile_id', $profileId)->orderByDesc('id')->limit(50)->get();

        $comparisonTruth = is_array($comparison['comparison_truth'] ?? null) ? $comparison['comparison_truth'] : [];
        $lineageRows = $lineage->lineageForFields($comparisonTruth['compared_fields'] ?? []);
        $repeaterFieldDiffs = is_array($comparison['repeater_field_diffs'] ?? null) ? $comparison['repeater_field_diffs'] : [];
        $repeaterGovernance = is_array($snapshot['repeater_governance'] ?? null) ? $snapshot['repeater_governance'] : [];
        $parityPath = base_path('python-data-engine/output/health/parity_validation_report.json');
        $parityReport = is_file($parityPath) ? json_decode((string) file_get_contents($parityPath), true) : null;
        $parityReport = is_array($parityReport) ? $parityReport : null;

        $presenter = app(GovernanceProfilePresenter::class);
        $liveProfile = $this->loadLiveProfileForGovernance($profileId);
        $governanceView = $presenter->buildViewModel(
            $profileId,
            $comparison,
            $snapshot,
            $comparisonTruth,
            $repeaterFieldDiffs,
            $repeaterGovernance,
            $liveProfile,
        );
        $storageCounters = $this->buildProfileStorageCounters($profileId);
        $governanceView['overview_counters'] = array_merge(
            $governanceView['overview_counters'] ?? [],
            $storageCounters
        );
        $initialBadges = $presenter->buildLiveBadges($snapshot, $comparison, $comparisonTruth, $repeaterFieldDiffs);

        $publicProfileExists = MatrimonyProfile::query()->whereKey($profileId)->exists();

        return view('admin.governance.profile', [
            'profileId' => $profileId,
            'comparison' => $comparison,
            'snapshot' => $snapshot,
            'issues' => $issues,
            'history' => $history,
            'trends' => $governance->trendAnalytics(),
            'comparisonTruth' => $comparisonTruth,
            'lineageRows' => $lineageRows,
            'repeaterFieldDiffs' => $repeaterFieldDiffs,
            'repeaterGovernance' => $repeaterGovernance,
            'adminWorkflows' => $governance->workflows(),
            'rollbackManifests' => $governance->rollbackManifests(),
            'parityReport' => $parityReport,
            'governanceView' => $governanceView,
            'initialBadges' => $initialBadges,
            'canOperateGovernance' => $permissions->canOperate($user),
            'statusUrl' => route('admin.governance.profiles.status', $profileId),
            'actionUrl' => route('admin.governance.profiles.actions', $profileId),
            'diagnosticsUrl' => route('admin.governance.profiles.diagnostics', $profileId),
            'wizardUrl' => route('admin.profiles.show', ['id' => $profileId]),
            'publicProfileUrl' => $publicProfileExists ? route('matrimony.profile.show', ['matrimony_profile_id' => $profileId]) : null,
            'publicProfileExists' => $publicProfileExists,
            'issueCenterUrl' => route('admin.data-engine.issues', ['q' => $profileId]),
            'workflowsUrl' => route('admin.data-engine.workflows'),
            'auditUrl' => route('admin.data-engine.audit'),
            'rollbackUrl' => route('admin.data-engine.rollback'),
        ]);
    }

    /**
     * Load the *live* matrimony profile core row + all repeater rows so that
     * presenter components (lineage tab, repeater panels, etc.) can show real
     * DB truth even when the snapshot/comparison artifact is stale or partial.
     *
     * Returned shape:
     *   [
     *     'core' => ['col1' => val, ...],   // matrimony_profiles row (single)
     *     'repeaters' => [
     *       'education_history' => [['col' => val], ...],
     *       'career_history'    => [...],
     *       'siblings'          => [...],
     *       'children'          => [...],
     *       'relatives'         => [...],
     *       'property_assets'   => [...],
     *       'contacts'          => [...],
     *     ],
     *   ]
     *
     * @return array{core: array<string,mixed>, repeaters: array<string,list<array<string,mixed>>>}
     */
    private function loadLiveProfileForGovernance(int $profileId): array
    {
        // Build a *schema-driven* core so the lineage universe is identical
        // for every profile. Earlier we only included columns whose row
        // existed (e.g. `profile_extended_attributes` row missing → all
        // `extended.*` cards disappeared from the "How data flows" tab),
        // which made one profile show 127 cards and another 84. Now we
        // enumerate every column of `matrimony_profiles` and the 1:1 aux
        // tables, defaulting to NULL when there's no row, so the cards
        // appear consistently and a missing aux row becomes a "MISSING"
        // card instead of silently disappearing.
        $core = [];
        $internalCoreSkip = ['id', 'user_id', 'created_at', 'updated_at', 'deleted_at'];
        if (Schema::hasTable('matrimony_profiles')) {
            $columns = Schema::getColumnListing('matrimony_profiles');
            foreach ($columns as $col) {
                if (in_array($col, $internalCoreSkip, true)) {
                    continue;
                }
                $core[$col] = null;
            }
            $row = DB::table('matrimony_profiles')->where('id', $profileId)->first();
            if ($row !== null) {
                foreach ((array) $row as $col => $val) {
                    if (in_array($col, $internalCoreSkip, true)) {
                        continue;
                    }
                    $core[$col] = $val;
                }
            }
        }

        // 1:1 auxiliary tables that hold scalar profile attributes outside
        // the main `matrimony_profiles` row. Each row is uniquely keyed by
        // `profile_id`. Without folding these into core, lineage cards for
        // About Me narrative, horoscope details, partner preference criteria
        // etc. would silently drop off the "How data flows" tab even though
        // the schema reserves those columns. We prefix the column with a
        // namespace (`extended.`, `horoscope.`, `partner_pref.`) so we never
        // clash with a real `matrimony_profiles` column of the same name.
        // Internal columns (id / *_id of the link / timestamps) are skipped.
        $auxTables = [
            'extended' => 'profile_extended_attributes',
            'horoscope' => 'profile_horoscope_data',
            'partner_pref' => 'profile_preference_criteria',
        ];
        $auxSkip = ['id', 'profile_id', 'created_at', 'updated_at', 'deleted_at'];
        foreach ($auxTables as $prefix => $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $columns = Schema::getColumnListing($table);
            foreach ($columns as $col) {
                if (in_array($col, $auxSkip, true)) {
                    continue;
                }
                $core[$prefix.'.'.$col] = null;
            }
            $row = DB::table($table)->where('profile_id', $profileId)->first();
            if ($row === null) {
                continue;
            }
            foreach ((array) $row as $col => $val) {
                if (in_array($col, $auxSkip, true)) {
                    continue;
                }
                $core[$prefix.'.'.$col] = $val;
            }
        }

        // Geo hierarchy (country / state / district / taluka) is not stored
        // as separate columns on `matrimony_profiles`; it is derived from
        // `location_id`, `birth_city_id`, etc. Always reserve the derived
        // keys so the cards stay in the universe (empty → "MISSING" card)
        // and only fill them when the profile resolves to a real ancestor.
        $this->reserveDerivedGeoKeys($core);
        $profileModel = MatrimonyProfile::query()->find($profileId);
        if ($profileModel !== null) {
            $this->mergeDerivedGeoHierarchyIntoCore($core, $profileModel);
        }

        // Map presenter repeater keys → physical tables. Adding a new repeater
        // key here automatically lights up its panel + lineage rows.
        $repeaterTables = [
            'education_history' => 'profile_education',
            'career_history' => 'profile_career',
            'siblings' => 'profile_siblings',
            'children' => 'profile_children',
            'relatives' => 'profile_relatives',
            'property_assets' => 'profile_property_assets',
            'contacts' => 'profile_contacts',
        ];

        $repeaters = [];
        foreach ($repeaterTables as $repKey => $table) {
            $repeaters[$repKey] = [];
            if (! Schema::hasTable($table)) {
                continue;
            }
            $rows = DB::table($table)
                ->where('profile_id', $profileId)
                ->get()
                ->map(fn ($r) => (array) $r)
                ->all();
            $repeaters[$repKey] = $rows;
        }

        return [
            'core' => $core,
            'repeaters' => $repeaters,
        ];
    }

    /**
     * Reserve the `derived.{residence|birth|native|work}.{country|state|district|taluka}_id`
     * keys so the lineage universe always includes them — even if the profile
     * has no `location_id` / `birth_city_id` / `native_city_id` / `work_city_id`.
     * The leaf `location_id` itself is intentionally skipped here because it
     * already appears under its canonical card (`city` / native / work / birth).
     *
     * @param  array<string,mixed>  $core
     */
    private function reserveDerivedGeoKeys(array &$core): void
    {
        $scopes = ['residence', 'birth', 'native', 'work'];
        $levels = ['country_id', 'state_id', 'district_id', 'taluka_id'];
        foreach ($scopes as $scope) {
            foreach ($levels as $lvl) {
                $key = 'derived.'.$scope.'.'.$lvl;
                if (! array_key_exists($key, $core)) {
                    $core[$key] = null;
                }
            }
        }
    }

    /**
     * @param  array<string,mixed>  $core
     */
    private function mergeDerivedGeoHierarchyIntoCore(array &$core, MatrimonyProfile $profile): void
    {
        $bundles = [
            'residence' => $profile->residenceLocationHierarchyHints(),
            'birth' => $profile->birthCityHierarchyHints(),
            'native' => $profile->nativePlaceHierarchyHints(),
            'work' => $profile->workCityHierarchyHints(),
        ];
        foreach ($bundles as $prefix => $hints) {
            foreach ($hints as $k => $v) {
                if ($k === 'location_id') {
                    continue;
                }
                $trim = trim((string) $v);
                if ($trim === '') {
                    continue;
                }
                $fieldKey = 'derived.'.$prefix.'.'.$k;
                $core[$fieldKey] = ctype_digit($trim) ? (int) $trim : $trim;
            }
        }
    }

    /**
     * Build real DB-backed storage counters for a profile.
     *
     * @return array<string,int>
     */
    private function buildProfileStorageCounters(int $profileId): array
    {
        $storageTotal = 0;
        $storageFilled = 0;

        if (Schema::hasTable('matrimony_profiles')) {
            $profile = (array) (DB::table('matrimony_profiles')->where('id', $profileId)->first() ?? []);
            [$total, $filled] = $this->countFilledFromRows(
                $profile === [] ? [] : [$profile],
                ['id', 'user_id', 'created_at', 'updated_at', 'deleted_at']
            );
            $storageTotal += $total;
            $storageFilled += $filled;
        }

        $relatedTables = [
            'profile_partner_preferences',
            'profile_education',
            'profile_career',
            'profile_siblings',
            'profile_children',
            'profile_relatives',
            'profile_property_assets',
            'profile_properties',
            'profile_contacts',
        ];

        foreach ($relatedTables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $rows = DB::table($table)->where('profile_id', $profileId)->get()->map(fn ($r) => (array) $r)->all();
            [$total, $filled] = $this->countFilledFromRows(
                $rows,
                ['id', 'profile_id', 'created_at', 'updated_at', 'deleted_at']
            );
            $storageTotal += $total;
            $storageFilled += $filled;
        }

        $storageEmpty = max(0, $storageTotal - $storageFilled);
        $fillPct = $storageTotal > 0 ? (int) round(($storageFilled / $storageTotal) * 100) : 0;

        return [
            'total_saved_data_points' => $storageTotal,
            'filled_data_points' => $storageFilled,
            'empty_data_points' => $storageEmpty,
            'saved_data_fill_percent' => $fillPct,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  list<string>  $skipKeys
     * @return array{0:int,1:int}
     */
    private function countFilledFromRows(array $rows, array $skipKeys): array
    {
        $total = 0;
        $filled = 0;
        $skip = array_fill_keys($skipKeys, true);

        foreach ($rows as $row) {
            foreach ($row as $key => $value) {
                if (isset($skip[$key])) {
                    continue;
                }
                $total++;
                if ($this->hasMeaningfulValue($value)) {
                    $filled++;
                }
            }
        }

        return [$total, $filled];
    }

    private function hasMeaningfulValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    public function profileStatus(int $profileId, DataEnginePermissionService $permissions): JsonResponse
    {
        abort_unless($permissions->canView(request()->user()), 403);
        [$comparison, $snapshot] = $this->loadProfileGovernanceArtifacts($profileId);
        $comparisonTruth = is_array($comparison['comparison_truth'] ?? null) ? $comparison['comparison_truth'] : [];
        $repeaterFieldDiffs = is_array($comparison['repeater_field_diffs'] ?? null) ? $comparison['repeater_field_diffs'] : [];
        $repeaterGovernance = is_array($snapshot['repeater_governance'] ?? null) ? $snapshot['repeater_governance'] : [];
        $presenter = app(GovernanceProfilePresenter::class);
        $badges = $presenter->buildLiveBadges($snapshot, $comparison, $comparisonTruth, $repeaterFieldDiffs);
        $liveProfile = $this->loadLiveProfileForGovernance($profileId);
        $vm = $presenter->buildViewModel($profileId, $comparison, $snapshot, $comparisonTruth, $repeaterFieldDiffs, $repeaterGovernance, $liveProfile);

        $apiTab = $vm['api_tab'] ?? ['ok' => true];

        return response()->json([
            'badges' => array_values($badges),
            'generated_at' => $comparison['generated_at'] ?? null,
            'checked_at_iso' => $comparison['generated_at'] ?? Carbon::now()->toIso8601String(),
            'issue_count' => count($vm['issue_cards']),
            'api_ok' => (bool) ($apiTab['ok'] ?? true),
        ]);
    }

    public function profileDiagnostics(int $profileId, DataEnginePermissionService $permissions): JsonResponse
    {
        abort_unless($permissions->canView(request()->user()), 403);
        [$comparison, $snapshot] = $this->loadProfileGovernanceArtifacts($profileId);

        return response()->json([
            'comparison' => $comparison,
            'snapshot' => $snapshot,
        ]);
    }

    public function profileAction(int $profileId, Request $request, DataEngineGovernanceService $governance, DataEnginePermissionService $permissions): JsonResponse
    {
        abort_unless($permissions->canOperate($request->user()), 403);
        $action = (string) $request->input('action');
        $allowed = [
            'rebuild_snapshot',
            'rerun_comparison',
            'validate_api_parity',
            'refresh_coverage',
            'rerun_repeater_diff',
        ];
        if (! in_array($action, $allowed, true)) {
            return response()->json(['ok' => false, 'message' => 'Unknown action.'], 422);
        }
        try {
            $message = '';
            $headline = '';
            $parityPayload = [];
            if ($action === 'rebuild_snapshot') {
                Artisan::call('data-audit:snapshot', [
                    '--entity' => 'matrimony_profile',
                    '--profile' => (string) $profileId,
                    '--wizard' => true,
                    '--public-profile' => true,
                    '--api' => true,
                ]);
                $message = 'Snapshot was rebuilt for this profile.';
                $headline = 'Snapshot rebuilt successfully';
            }
            if ($action === 'rerun_comparison' || $action === 'rerun_repeater_diff') {
                Artisan::call('data-audit:compare', [
                    '--profile' => (string) $profileId,
                    '--latest' => true,
                ]);
                $message = $action === 'rerun_repeater_diff'
                    ? 'Comparison re-run (includes repeater checks).'
                    : 'Comparison re-run for this profile.';
                $headline = $action === 'rerun_repeater_diff'
                    ? 'Repeater and layer check finished'
                    : 'Comparison finished';
            }
            if ($action === 'validate_api_parity') {
                $parityPayload = $governance->runPythonJsonCommand(['verify-api-parity', '--profile', (string) $profileId]);
                unset($parityPayload['_exit_code']);
                $okParity = ($parityPayload['status'] ?? '') === 'ok';
                $message = $okParity
                    ? 'API parity check passed for the latest snapshot.'
                    : 'API parity check reported missing fields — see details.';
                $headline = $okParity ? 'API check passed' : 'API check found gaps';
            }
            if ($action === 'refresh_coverage') {
                $governance->refreshDashboardArtifacts();
                $message = 'Coverage and dashboard files were refreshed.';
                $headline = 'Coverage summary refreshed';
            }

            [$comparison, $snapshot] = $this->loadProfileGovernanceArtifacts($profileId);
            $artifactSummary = $this->summarizeArtifacts($profileId, $comparison, $snapshot);
            $artifactVerification = $this->verifyActionArtifacts($action, $comparison, $snapshot);

            $result = [
                'success' => (bool) ($artifactVerification['ok'] ?? true),
                'profile_id' => $profileId,
                'artifact_summary' => $artifactSummary,
                'artifact_verification' => $artifactVerification,
            ];

            if (! $result['success']) {
                $headline = 'Action completed but verification failed';
                $message = (string) ($artifactVerification['message'] ?? 'The action ran, but fresh artifacts were not found.');
            }

            if ($action === 'validate_api_parity') {
                $missingKeys = $parityPayload['missing_api_keys'] ?? [];
                $missingLabels = [];
                if (is_array($missingKeys)) {
                    foreach ($missingKeys as $m) {
                        if (is_array($m) && isset($m['field'])) {
                            $missingLabels[] = GovernanceProfilePresenter::fieldLabelPair((string) $m['field'])['en'];
                        }
                    }
                }
                $result['api_check'] = [
                    'passed' => ($parityPayload['status'] ?? '') === 'ok',
                    'missing_labels_en' => $missingLabels,
                    'checked_at' => $parityPayload['generated_at'] ?? null,
                ];
                if (! ($result['api_check']['passed'])) {
                    $result['success'] = false;
                }
            }

            return response()->json([
                'ok' => $result['success'],
                'message' => $message,
                'headline' => $headline,
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
                'headline' => 'Action failed',
                'result' => [
                    'success' => false,
                    'profile_id' => $profileId,
                    'error' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * @param  array<string, mixed>  $comparison
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function summarizeArtifacts(int $profileId, array $comparison, array $snapshot): array
    {
        $renderedBlock = $snapshot['rendered'] ?? null;
        $renderedFieldCount = null;
        if (is_array($renderedBlock)) {
            $fields = $renderedBlock['fields'] ?? null;
            if (is_array($fields) && $fields !== []) {
                $renderedFieldCount = count($fields);
            } else {
                $fbc = $renderedBlock['fields_by_source'] ?? [];
                if (is_array($fbc)) {
                    $distinct = [];
                    foreach ($fbc as $group) {
                        if (! is_array($group)) {
                            continue;
                        }
                        foreach (array_keys($group) as $fname) {
                            $distinct[$fname] = true;
                        }
                    }
                    $renderedFieldCount = count($distinct) > 0 ? count($distinct) : null;
                }
            }
        }
        $compSummary = is_array($comparison['summary'] ?? null) ? $comparison['summary'] : [];
        $comparisonFieldCount = (int) ($compSummary['compared_fields'] ?? count($comparison['comparisons'] ?? []));
        $repeaterDiffRows = is_array($comparison['repeater_field_diffs'] ?? null)
            ? count($comparison['repeater_field_diffs'])
            : 0;
        $rg = $snapshot['repeater_governance'] ?? [];
        $profileSectionsCaptured = 0;
        if (is_array($rg)) {
            $byRepeater = $rg['by_repeater'] ?? null;
            if (is_array($byRepeater)) {
                $profileSectionsCaptured = count($byRepeater);
            } else {
                foreach ($rg as $k => $_) {
                    if ($k === 'runtime_proof') {
                        continue;
                    }
                    $profileSectionsCaptured++;
                }
            }
        }

        return [
            'profile_id' => $profileId,
            'has_snapshot' => $snapshot !== [],
            'has_comparison' => $comparison !== [],
            'snapshot_generated_at' => $snapshot['generated_at'] ?? null,
            'comparison_generated_at' => $comparison['generated_at'] ?? null,
            'rendered_field_count' => $renderedFieldCount,
            'comparison_field_count' => $comparisonFieldCount,
            'repeater_cells_checked' => $repeaterDiffRows,
            'profile_sections_captured' => $profileSectionsCaptured,
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function loadProfileGovernanceArtifacts(int $profileId): array
    {
        $cmpDir = base_path('python-data-engine/output/comparisons');
        $files = glob($cmpDir.DIRECTORY_SEPARATOR.'snapshot_comparison_*.json') ?: [];
        rsort($files);
        $comparison = [];
        foreach ($files as $file) {
            $decoded = json_decode((string) File::get($file), true);
            if (! is_array($decoded)) {
                continue;
            }
            $snapPath = (string) ($decoded['snapshot_path'] ?? '');
            if ($snapPath !== '' && str_contains($snapPath, '_'.$profileId.DIRECTORY_SEPARATOR)) {
                $comparison = $decoded;
                break;
            }
        }
        $snapshot = [];
        if ($comparison !== [] && is_file((string) ($comparison['snapshot_path'] ?? ''))) {
            $snap = json_decode((string) file_get_contents((string) $comparison['snapshot_path']), true);
            $snapshot = is_array($snap) ? $snap : [];
        }
        if ($snapshot === []) {
            $latestSnapshotPath = $this->latestSnapshotPathForProfile($profileId);
            if ($latestSnapshotPath !== null && is_file($latestSnapshotPath)) {
                $snap = json_decode((string) file_get_contents($latestSnapshotPath), true);
                $snapshot = is_array($snap) ? $snap : [];
            }
        }

        return [$comparison, $snapshot];
    }

    /**
     * @param  array<string, mixed>  $comparison
     * @param  array<string, mixed>  $snapshot
     * @return array{ok: bool, message: string}
     */
    private function verifyActionArtifacts(string $action, array $comparison, array $snapshot): array
    {
        if ($action === 'refresh_coverage') {
            return ['ok' => true, 'message' => 'Coverage refresh does not produce profile-level artifacts.'];
        }

        if ($action === 'rebuild_snapshot') {
            if ($snapshot === []) {
                return ['ok' => false, 'message' => 'Snapshot rebuild finished, but no snapshot data was found for this profile.'];
            }

            return ['ok' => true, 'message' => 'Snapshot artifact verified.'];
        }

        if ($action === 'rerun_comparison' || $action === 'rerun_repeater_diff' || $action === 'validate_api_parity') {
            if ($comparison === []) {
                return ['ok' => false, 'message' => 'Action ran, but comparison artifact was not found for this profile.'];
            }

            return ['ok' => true, 'message' => 'Comparison artifact verified.'];
        }

        return ['ok' => true, 'message' => 'No artifact verification required.'];
    }

    private function latestSnapshotPathForProfile(int $profileId): ?string
    {
        $patterns = [
            base_path('python-data-engine/output/quarantine/snapshots/matrimony_profile_'.$profileId.'/snapshot_*.json'),
            base_path('python-data-engine/output/snapshots/matrimony_profile_'.$profileId.'/snapshot_*.json'),
        ];
        $candidates = [];
        foreach ($patterns as $pattern) {
            $matches = glob($pattern) ?: [];
            foreach ($matches as $m) {
                $candidates[] = $m;
            }
        }
        if ($candidates === []) {
            return null;
        }
        usort($candidates, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $candidates[0] ?? null;
    }
}

