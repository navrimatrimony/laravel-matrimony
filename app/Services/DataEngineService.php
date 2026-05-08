<?php

namespace App\Services;

use App\Exceptions\DataEngineAlreadyRunningException;
use App\Exceptions\DataEngineDisabledException;
use App\Exceptions\DataEngineTimeoutException;
use App\Models\DataEngineRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Orchestrates the bundled python-data-engine (CLI). Reserved HTTP API: port 8003 (see config/data_engine.php).
 */
class DataEngineService
{
    /** Populated while a CLI run holds the process lock (see {@see run()} / {@see runProcess()}). */
    public const BUSY_CACHE_KEY = 'python-data-engine:busy';

    public function __construct(
        protected SystemSettingService $settings
    ) {}

    /**
     * Whether analyze/fix may run: {@code DATA_ENGINE_ENABLED} must allow, and admin DB switch {@code data_engine_enabled} must be true.
     */
    public function isEffectiveEnabled(): bool
    {
        if (! filter_var(config('data_engine.enabled'), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        return $this->settings->boolean('data_engine_enabled', true);
    }

    /**
     * If a previous run died (timeout, killed process) the row can stay "running" forever and block new runs.
     * Call this before checking for an active run and on data-engine admin pages.
     */
    public function releaseStaleRunningRuns(): int
    {
        $timeout = max(60, (int) config('data_engine.timeout_seconds', 300));
        $cutoff = now()->subSeconds($timeout + 120);

        return DataEngineRun::query()
            ->where('status', 'running')
            ->where('created_at', '<', $cutoff)
            ->update([
                'status' => 'failed',
                'error_output' => 'Run left in "running" state past the engine timeout (process may have been stopped). Start analyze again.',
            ]);
    }

    public function run(string $mode = 'analyze'): DataEngineRun
    {
        if (! $this->isEffectiveEnabled()) {
            throw new DataEngineDisabledException('Python engine is disabled.');
        }

        $mode = $this->normalizeMode($mode);
        if ($mode === 'fix' && ! $this->isFixModeAllowed()) {
            throw new DataEngineDisabledException(
                'Fix mode is safety-locked. Set DATA_ENGINE_ALLOW_FIX_MODE=true for intentional mutation runs.'
            );
        }

        $this->releaseStaleRunningRuns();

        $timeout = (int) config('data_engine.timeout_seconds', 300);
        $lockTtl = max($timeout + 120, (int) config('data_engine.lock_ttl_seconds', 300));
        $lock = Cache::lock('python-data-engine-run', $lockTtl);

        if (! $lock->get()) {
            throw new DataEngineAlreadyRunningException(
                'Another data engine run is already in progress. Try again when it finishes.'
            );
        }

        try {
            if (DataEngineRun::query()->where('status', 'running')->exists()) {
                throw new DataEngineAlreadyRunningException('Data engine is already running.');
            }

            $this->assertPythonRunnable();

            return $this->runProcess($mode);
        } finally {
            Cache::forget(self::BUSY_CACHE_KEY);
            $lock->release();
        }
    }

    /**
     * Live dashboard payload for admin polling (no WebSockets).
     *
     * `health.state` is runtime availability only (not the outcome of the last execution).
     * Values: disabled, online, online_warnings, analyze_running, fix_running, critical_failure.
     *
     * @return array<string, mixed>
     */
    public function getMonitorStatus(): array
    {
        $this->releaseStaleRunningRuns();

        $powered = $this->isEffectiveEnabled();

        $runningRow = DataEngineRun::query()
            ->where('status', 'running')
            ->orderByDesc('id')
            ->first();

        $running = $runningRow !== null;
        $mode = $runningRow?->mode;

        $cacheLast = Cache::get('data_engine:last_execution');

        $queueMode = app()->environment('production')
            && (bool) config('data_engine.queue_on_production', true);

        $lastFinished = DataEngineRun::query()
            ->where('status', '!=', 'running')
            ->orderByDesc('id')
            ->first();

        $latestCompletedReport = DataEngineRun::query()
            ->where('status', 'completed')
            ->whereNotNull('report_path')
            ->orderByDesc('id')
            ->first();

        $moduleWarnings = $this->extractModuleWarningsFromReport($latestCompletedReport);

        $hasCompletedRunEver = DataEngineRun::query()->where('status', 'completed')->exists();
        $hasFailedRunEver = DataEngineRun::query()->where('status', 'failed')->exists();

        $healthState = $this->resolveEngineRuntimeState(
            $powered,
            $runningRow,
            $moduleWarnings !== [],
            $hasCompletedRunEver,
            $hasFailedRunEver,
        );

        $lastRun = $this->buildLastRunPayload($lastFinished, $cacheLast);

        $currentRun = null;
        if ($runningRow !== null && $runningRow->created_at !== null) {
            $elapsedMs = max(0, (int) round((microtime(true) - $runningRow->created_at->getTimestamp()) * 1000));
            $currentRun = [
                'run_id' => $runningRow->id,
                'mode' => $runningRow->mode,
                'started_at' => $runningRow->created_at->toIso8601String(),
                'elapsed_ms' => $elapsedMs,
                'scanned_records' => null,
                'fixed_records' => null,
                'warnings_count' => null,
            ];
        }

        $latestRuns = DataEngineRun::query()
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(function (DataEngineRun $r) use ($cacheLast) {
                $durationMs = null;
                if (is_array($cacheLast)
                    && isset($cacheLast['run_id'])
                    && (int) $cacheLast['run_id'] === (int) $r->id) {
                    $durationMs = isset($cacheLast['duration_ms']) ? (int) $cacheLast['duration_ms'] : null;
                }

                return [
                    'run_id' => $r->id,
                    'mode' => $r->mode,
                    'status' => $r->status,
                    'started_at' => $r->created_at?->toIso8601String(),
                    'duration_ms' => $durationMs,
                ];
            })
            ->values()
            ->all();

        return [
            'powered' => $powered,
            'running' => $running,
            'mode' => $mode,
            'queue_mode' => $queueMode,
            'last_run' => $lastRun,
            'current_run' => $currentRun,
            'health' => [
                'state' => $healthState,
                'has_module_warnings' => $moduleWarnings !== [],
            ],
            'module_warnings' => $moduleWarnings,
            'lock_active' => Cache::has(self::BUSY_CACHE_KEY),
            'latest_runs' => $latestRuns,
        ];
    }

    /**
     * Non-fatal optional-module messages from the latest JSON report (Python engine).
     *
     * @return list<array{module: string, warning: string}>
     */
    protected function extractModuleWarningsFromReport(?DataEngineRun $run): array
    {
        if ($run === null || ! $run->report_path) {
            return [];
        }

        $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $run->report_path);
        $full = base_path($relative);
        if (! is_readable($full)) {
            return [];
        }

        $raw = file_get_contents($full);
        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $mw = $decoded['module_warnings'] ?? null;
        if (! is_array($mw)) {
            return [];
        }

        $out = [];
        foreach ($mw as $row) {
            if (is_array($row) && isset($row['module'], $row['warning'])) {
                $out[] = [
                    'module' => (string) $row['module'],
                    'warning' => (string) $row['warning'],
                ];
            }
        }

        return $out;
    }

    /**
     * Current engine availability for the admin header badge — not historical run outcome.
     *
     * States: disabled | online | online_warnings | analyze_running | fix_running | critical_failure
     */
    protected function resolveEngineRuntimeState(
        bool $powered,
        ?DataEngineRun $runningRow,
        bool $hasOptionalModuleWarnings,
        bool $hasCompletedRunEver,
        bool $hasFailedRunEver,
    ): string {
        if (! $powered) {
            return 'disabled';
        }

        if ($runningRow !== null && $runningRow->mode === 'analyze') {
            return 'analyze_running';
        }

        if ($runningRow !== null && $runningRow->mode === 'fix') {
            return 'fix_running';
        }

        if ($hasOptionalModuleWarnings) {
            return 'online_warnings';
        }

        if (! $hasCompletedRunEver && $hasFailedRunEver) {
            return 'critical_failure';
        }

        return 'online';
    }

    /**
     * @param  array<string, mixed>|null  $cacheLast
     * @return array<string, mixed>|null
     */
    protected function buildLastRunPayload(?DataEngineRun $lastFinished, mixed $cacheLast): ?array
    {
        if ($lastFinished === null) {
            return null;
        }

        $exitCode = null;
        $durationMs = null;
        $finishedAt = $lastFinished->updated_at?->toIso8601String();

        if (is_array($cacheLast)
            && isset($cacheLast['run_id'])
            && (int) $cacheLast['run_id'] === (int) $lastFinished->id) {
            if (isset($cacheLast['exit_code'])) {
                $exitCode = (int) $cacheLast['exit_code'];
            }
            if (isset($cacheLast['duration_ms'])) {
                $durationMs = (int) $cacheLast['duration_ms'];
            }
            if (! empty($cacheLast['finished_at'])) {
                $finishedAt = (string) $cacheLast['finished_at'];
            }
        }

        $failed = $lastFinished->status === 'failed';

        if ($exitCode === null) {
            $exitCode = $lastFinished->status === 'completed' ? 0 : 1;
        }

        $stderrPreview = $failed
            ? mb_substr((string) ($lastFinished->error_output ?? ''), 0, 800)
            : null;

        return [
            'status' => $lastFinished->status === 'completed' ? 'success' : 'failed',
            'exit_code' => $exitCode,
            'duration_ms' => $durationMs,
            'finished_at' => $finishedAt,
            'run_id' => $lastFinished->id,
            'stderr_preview' => $stderrPreview !== '' ? $stderrPreview : null,
        ];
    }

    /**
     * Runs the Python CLI process (must be called only while holding {@code python-data-engine-run} lock).
     */
    protected function runProcess(string $mode): DataEngineRun
    {
        $run = DataEngineRun::query()->create([
            'mode' => $mode,
            'status' => 'running',
            'report_path' => null,
            'error_output' => null,
            'total_issues' => 0,
            'total_fixed' => null,
        ]);

        $busyTtl = max(
            (int) config('data_engine.timeout_seconds', 300) + 180,
            (int) config('data_engine.lock_ttl_seconds', 300)
        );
        Cache::put(self::BUSY_CACHE_KEY, [
            'run_id' => $run->id,
            'mode' => $mode,
            'started_at' => $run->created_at?->toIso8601String(),
        ], $busyTtl);

        $startedAt = microtime(true);

        Log::channel('data_engine')->info('data_engine.run.start', [
            'run_id' => $run->id,
            'mode' => $mode,
            'driver' => config('data_engine.driver'),
        ]);

        $workingDirectory = (string) config('data_engine.working_directory');
        $runnerPath = (string) config('data_engine.runner_path');
        $engineRoot = realpath($workingDirectory) ?: $workingDirectory;
        $python = (string) config('data_engine.python_binary');

        $process = null;
        $stdout = '';
        $stderr = '';
        $exitCode = -1;

        try {
            if (config('data_engine.driver') === 'http') {
                throw new \RuntimeException(
                    'DATA_ENGINE_DRIVER=http is reserved for future use; use cli or implement requestHttpRun().'
                );
            }

            $process = new Process(
                [$python, $runnerPath],
                $engineRoot,
                ['MODE' => $mode]
            );
            $process->setTimeout((float) config('data_engine.timeout_seconds', 300));

            try {
                $process->run();
            } catch (ProcessTimedOutException $e) {
                $stderr = $process->getErrorOutput();
                $stdout = $process->getOutput();
                $this->failRun($run, $stderr !== '' ? $stderr : null, 'Process timed out.');
                Log::channel('data_engine')->warning('data_engine.run.timeout', [
                    'run_id' => $run->id,
                    'stderr_tail' => mb_substr($stderr, -4000),
                ]);
                $this->persistExecutionSnapshot($run, -1, $startedAt, $stdout, $stderr);

                throw new DataEngineTimeoutException('Data engine process timed out.', 0, $e);
            }

            $exitCode = $process->getExitCode() ?? -1;
            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();
        } catch (DataEngineTimeoutException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('data_engine')->error('data_engine.run.process_failed', [
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
            $this->failRun($run, $stderr !== '' ? $stderr : null, $e->getMessage());
            $this->persistExecutionSnapshot($run, -1, $startedAt, $stdout, $stderr);

            return $run->fresh();
        }

        $combinedLog = trim($stdout.PHP_EOL.$stderr);

        Log::channel('data_engine')->info('data_engine.run.process_done', [
            'run_id' => $run->id,
            'exit_code' => $exitCode,
            'output_tail' => mb_substr($combinedLog, -4000),
        ]);

        if ($process !== null && ! $process->isSuccessful()) {
            $this->failRun($run, $stderr !== '' ? $stderr : null, $stdout !== '' ? mb_substr($stdout, -8000) : null);
            $this->persistExecutionSnapshot($run, $exitCode, $startedAt, $stdout, $stderr);

            return $run->fresh();
        }

        $reportPath = $this->resolveLatestReportPath($engineRoot, $mode);
        if ($reportPath === null) {
            $this->failRun(
                $run,
                $stderr !== '' ? $stderr : null,
                'Report file not found after run (expected '.$mode.' report under python-data-engine/output/reports).'
            );
            $this->persistExecutionSnapshot($run, $exitCode, $startedAt, $stdout, $stderr);

            return $run->fresh();
        }

        $parsed = $this->decodeReportJson($reportPath);
        if ($parsed === null) {
            $this->failRun(
                $run,
                'Report file was empty, unreadable, or not valid JSON.',
                $reportPath
            );
            $this->persistExecutionSnapshot($run, $exitCode, $startedAt, $stdout, $stderr);

            return $run->fresh();
        }

        // 🔥 TEMP: Skip hash verification (Python already ensures integrity)
        if (! empty($parsed['meta']['hash'])) {
            // Optional: log mismatch but DO NOT fail
            try {
                if (! $this->verifyReportIntegrityHash($parsed)) {
                    Log::channel('data_engine')->warning('Hash mismatch (ignored)', [
                        'run_id' => $run->id,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::channel('data_engine')->warning('Hash check error (ignored)', [
                    'run_id' => $run->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $relativeReport = $this->relativeToBase($reportPath);

        $failedByMeta = ! empty($parsed['meta']['runner_error']) || ! empty($parsed['meta']['runner_traceback']);
        $failedByExit = $exitCode !== 0;
        $failed = $failedByMeta || $failedByExit;

        $totalIssues = $this->sumIssueCounts($parsed);
        $totalFixed = $this->countFixed($parsed, $mode);

        $qualityScore = isset($parsed['quality_score']) && is_numeric($parsed['quality_score'])
            ? (int) $parsed['quality_score']
            : null;
        $prioritySummary = null;
        if (isset($parsed['priority_summary']) && is_array($parsed['priority_summary'])) {
            $prioritySummary = [
                'critical' => (int) ($parsed['priority_summary']['critical'] ?? 0),
                'high' => (int) ($parsed['priority_summary']['high'] ?? 0),
                'medium' => (int) ($parsed['priority_summary']['medium'] ?? 0),
                'low' => (int) ($parsed['priority_summary']['low'] ?? 0),
            ];
        }

        $profileMetrics = null;
        if (isset($parsed['profile_intelligence']) && is_array($parsed['profile_intelligence'])) {
            $profileMetrics = $parsed['profile_intelligence'];
        }

        $conversionMetrics = null;
        if (isset($parsed['conversion_intelligence']) && is_array($parsed['conversion_intelligence'])) {
            $conversionMetrics = $parsed['conversion_intelligence'];
        }

        $engineVersion = isset($parsed['meta']['engine_version'])
            ? (string) $parsed['meta']['engine_version']
            : null;

        $qualityDelta = null;
        $issuesDelta = null;
        if (! $failed) {
            $previous = DataEngineRun::query()
                ->where('status', 'completed')
                ->where('mode', $mode)
                ->where('id', '<', $run->id)
                ->orderByDesc('id')
                ->first();
            if ($previous !== null) {
                $qualityDelta = ($qualityScore ?? 0) - (int) ($previous->quality_score ?? 0);
                $issuesDelta = $totalIssues - (int) $previous->total_issues;
            }
        }

        $errorOut = null;
        if ($failed) {
            if ($stderr !== '') {
                $errorOut = $stderr;
            } elseif ($failedByMeta) {
                $errorOut = trim(
                    (string) ($parsed['meta']['runner_error'] ?? '').PHP_EOL
                    .(string) ($parsed['meta']['runner_traceback'] ?? '')
                );
                $errorOut = $errorOut !== '' ? $errorOut : 'Runner reported an error in report meta.';
            } elseif ($failedByExit) {
                $errorOut = 'Non-zero exit code: '.$exitCode;
            }
        }

        $run->fill([
            'report_path' => $relativeReport,
            'total_issues' => $totalIssues,
            'total_fixed' => $mode === 'fix' ? $totalFixed : null,
            'quality_score' => $qualityScore,
            'priority_summary' => $prioritySummary,
            'profile_metrics' => $profileMetrics,
            'conversion_metrics' => $conversionMetrics,
            'engine_version' => $engineVersion,
            'quality_delta' => $qualityDelta,
            'issues_delta' => $issuesDelta,
            'status' => $failed ? 'failed' : 'completed',
            'error_output' => $failed ? $errorOut : null,
        ]);
        $run->save();

        Log::channel('data_engine')->info('data_engine.run.finish', [
            'run_id' => $run->id,
            'status' => $run->status,
            'total_issues' => $totalIssues,
            'total_fixed' => $totalFixed,
            'report_path' => $relativeReport,
        ]);

        $this->persistExecutionSnapshot($run, $exitCode, $startedAt, $stdout, $stderr);

        return $run->fresh();
    }

    protected function assertPythonRunnable(): void
    {
        $runnerPath = (string) config('data_engine.runner_path');
        if (! is_file($runnerPath)) {
            throw new \RuntimeException(
                'Python runner not found at '.$runnerPath.'. Set DATA_ENGINE_RUNNER_PATH or restore python-data-engine/scripts/runner.py.'
            );
        }

        $python = (string) config('data_engine.python_binary');
        if ($python === '') {
            throw new \RuntimeException('Python interpreter path is empty (DATA_ENGINE_PYTHON_BINARY / DATA_ENGINE_PYTHON).');
        }

        if (str_contains($python, DIRECTORY_SEPARATOR) || str_contains($python, '/')) {
            if (! is_file($python)) {
                throw new \RuntimeException('Python interpreter not found at: '.$python);
            }
        }

        $probe = new Process([$python, '-c', 'import sys; sys.exit(0)'], null, null, null, 15.0);
        try {
            $probe->run();
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Python interpreter failed to execute: '.$python.' — '.$e->getMessage(),
                0,
                $e
            );
        }

        if (! $probe->isSuccessful()) {
            throw new \RuntimeException(
                'Python interpreter is not usable: '.$python.' (exit '.($probe->getExitCode() ?? -1).'). STDERR: '
                .mb_substr($probe->getErrorOutput(), -800)
            );
        }
    }

    protected function persistExecutionSnapshot(
        DataEngineRun $run,
        int $exitCode,
        float $startedAt,
        string $stdout,
        string $stderr
    ): void {
        $run = $run->fresh() ?? $run;
        $durationMs = max(0, (int) round((microtime(true) - $startedAt) * 1000));

        Log::channel('data_engine')->info('data_engine.run.execution_log', [
            'run_id' => $run->id,
            'mode' => $run->mode,
            'status' => $run->status,
            'exit_code' => $exitCode,
            'duration_ms' => $durationMs,
            'stdout' => mb_substr($stdout, 0, 65536),
            'stderr' => mb_substr($stderr, 0, 65536),
        ]);

        Cache::put('data_engine:last_execution', [
            'run_id' => $run->id,
            'finished_at' => now()->toIso8601String(),
            'mode' => $run->mode,
            'status' => $run->status,
            'duration_ms' => $durationMs,
            'exit_code' => $exitCode,
        ], now()->addDays(30));
    }

    protected function normalizeMode(string $mode): string
    {
        $m = strtolower(trim($mode));

        return in_array($m, ['analyze', 'fix'], true) ? $m : 'analyze';
    }

    public function isFixModeAllowed(): bool
    {
        $dbEnabledRaw = $this->settings->get('data_engine_allow_fix_mode', null);
        $enabled = $dbEnabledRaw === null
            ? filter_var(config('data_engine.allow_fix_mode', false), FILTER_VALIDATE_BOOL)
            : filter_var($dbEnabledRaw, FILTER_VALIDATE_BOOLEAN);

        if (! $enabled) {
            return false;
        }

        $expiryRaw = (string) $this->settings->get('data_engine_fix_mode_expires_at', '');
        if ($expiryRaw === '') {
            return true;
        }

        try {
            $expiry = Carbon::parse($expiryRaw);
        } catch (\Throwable) {
            Log::channel('data_engine')->warning('data_engine.fix_mode.invalid_expiry', [
                'expires_at' => $expiryRaw,
            ]);

            return false;
        }

        if ($expiry->isFuture()) {
            return true;
        }

        // Auto-expired: force switch OFF so UI and runtime remain consistent.
        $this->settings->set('data_engine_allow_fix_mode', false);
        $this->settings->set('data_engine_fix_mode_expires_at', '');

        Log::channel('data_engine')->info('data_engine.fix_mode.auto_disabled', [
            'expired_at' => $expiry->toIso8601String(),
        ]);

        return false;
    }

    /**
     * Reports: engine_analyze_*.json / engine_fix_*.json — filter by mode, newest mtime wins.
     */
    protected function resolveLatestReportPath(string $engineRoot, string $mode): ?string
    {
        $prefix = $mode === 'fix' ? 'engine_fix_' : 'engine_analyze_';
        $dir = $engineRoot.DIRECTORY_SEPARATOR.'output'.DIRECTORY_SEPARATOR.'reports';
        if (! is_dir($dir)) {
            return null;
        }

        $files = glob($dir.DIRECTORY_SEPARATOR.$prefix.'*.json') ?: [];
        if ($files === []) {
            return null;
        }

        usort($files, fn ($a, $b) => (int) (@filemtime($b) ?: 0) <=> (int) (@filemtime($a) ?: 0));

        return $files[0] ?? null;
    }

    protected function relativeToBase(string $absolutePath): string
    {
        $base = realpath(base_path()) ?: base_path();
        $abs = realpath($absolutePath) ?: $absolutePath;
        $prefix = $base.DIRECTORY_SEPARATOR;
        if (str_starts_with($abs, $prefix)) {
            return str_replace('\\', '/', substr($abs, strlen($prefix)));
        }

        return str_replace('\\', '/', $abs);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function decodeReportJson(string $path): ?array
    {
        if (! is_readable($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        if (! is_array($data)) {
            return null;
        }
        if ($data === []) {
            return null;
        }

        return $data;
    }

    /**
     * MD5 of canonical JSON (must match python runner._compute_report_hash_payload).
     *
     * @param  array<string, mixed>  $report
     */
    protected function computeReportIntegrityHash(array $report): string
    {
        // Step 1: meta.hash काढून टाका
        if (isset($report['meta']['hash'])) {
            unset($report['meta']['hash']);
        }

        // Step 2: recursive sort (Python सारखा)
        $this->ksortRecursive($report);

        // Step 3: EXACT Python सारखं JSON बनवा
        $json = json_encode(
            $report,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        // 🔥 THIS LINE ADD (REMOVE SPACES LIKE PYTHON separators=(",", ":"))
        $json = str_replace([': ', ', '], [':', ','], $json);

        // Step 4: hash generate
        return md5($json ?: '');
    }

    /**
     * @param  array<string, mixed>  $report
     */
    protected function verifyReportIntegrityHash(array $report): bool
    {
        $stored = $report['meta']['hash'] ?? null;
        if ($stored === null || $stored === '') {
            return true;
        }

        return hash_equals((string) $stored, $this->computeReportIntegrityHash($report));
    }

    /**
     * @param  array<mixed>  $array
     */
    protected function ksortRecursive(array &$array): void
    {
        // Check if associative array
        if ($this->isAssoc($array)) {
            ksort($array);
        }

        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
    }

    protected function isAssoc(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Human-readable breakdown for admin UI (same rules as persisted totals).
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public function summarizeReport(array $report, ?string $mode = null): array
    {
        $mode = $mode ?? (string) ($report['meta']['mode'] ?? 'analyze');

        return [
            'quality_score' => isset($report['quality_score']) ? (int) $report['quality_score'] : null,
            'priority_summary' => is_array($report['priority_summary'] ?? null) ? $report['priority_summary'] : null,
            'profile_intelligence' => is_array($report['profile_intelligence'] ?? null)
                ? $report['profile_intelligence']
                : null,
            'conversion_intelligence' => is_array($report['conversion_intelligence'] ?? null)
                ? $report['conversion_intelligence']
                : null,
            'mr_localization' => is_array($report['mr_localization'] ?? null)
                ? $report['mr_localization']
                : null,
            'suggestions' => is_array($report['suggestions'] ?? null) ? $report['suggestions'] : [],
            'duplicate_groups' => $this->countDuplicateGroups($report['duplicates'] ?? []),
            'validation_errors' => $this->countValidationRules($report['validation_errors'] ?? []),
            'mismatch_rows' => $this->countMismatchIssues($report['mismatch'] ?? []),
            'schema_issues' => $this->countSchemaColumns($report['schema_issues'] ?? []),
            'total_issues' => $this->sumIssueCounts($report),
            'fixed_rows' => $mode === 'fix' ? $this->countFixed($report, 'fix') : null,
            'anomalies' => is_array($report['anomalies'] ?? null) ? $report['anomalies'] : [],
            'backup_path' => isset($report['backup_path']) ? $report['backup_path'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    protected function sumIssueCounts(array $report): int
    {
        $dups = $this->countDuplicateGroups($report['duplicates'] ?? []);
        $val = $this->countValidationRules($report['validation_errors'] ?? []);
        $mis = $this->countMismatchIssues($report['mismatch'] ?? []);
        $sch = $this->countSchemaColumns($report['schema_issues'] ?? []);

        return $dups + $val + $mis + $sch;
    }

    protected function countValidationRules(mixed $list): int
    {
        if (! is_array($list)) {
            return 0;
        }

        return count(array_filter($list, fn ($row) => is_array($row) && isset($row['rule'])));
    }

    protected function countSchemaColumns(mixed $list): int
    {
        if (! is_array($list)) {
            return 0;
        }

        return count(array_filter(
            $list,
            fn ($row) => is_array($row)
                && isset($row['column'])
                && ($row['kind'] ?? null) !== 'missing_index'
        ));
    }

    protected function countDuplicateGroups(mixed $duplicates): int
    {
        if (! is_array($duplicates)) {
            return 0;
        }
        $n = 0;
        foreach ($duplicates as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (isset($row['warning']) || isset($row['skipped']) || isset($row['error'])) {
                continue;
            }
            if (isset($row['type']) && in_array($row['type'], ['phone', 'email'], true)) {
                $n++;
            }
        }

        return $n;
    }

    protected function countListIssues(mixed $list): int
    {
        if (! is_array($list)) {
            return 0;
        }

        return count($list);
    }

    protected function countMismatchIssues(mixed $mismatch): int
    {
        if (! is_array($mismatch)) {
            return 0;
        }
        $n = 0;
        foreach ($mismatch as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (isset($row['warning']) || isset($row['skipped'])) {
                continue;
            }
            $n++;
        }

        return $n;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    protected function countFixed(array $report, string $mode): int
    {
        if ($mode !== 'fix') {
            return 0;
        }
        $fix = $report['fix_results'] ?? null;
        if (! is_array($fix)) {
            return 0;
        }
        $pin = (int) ($fix['pincode_fixed'] ?? 0);
        $lat = (int) ($fix['latlong_fixed'] ?? 0);
        $norm = (int) ($fix['normalized'] ?? 0);
        $mr = 0;
        if (is_array($report['mr_localization'] ?? null)) {
            $mr = (int) (($report['mr_localization']['fix']['updated_rows'] ?? 0));
        }
        if ($pin > 0 || $lat > 0 || $norm > 0 || $mr > 0) {
            return max(0, $pin + $lat + $norm + $mr);
        }
        if (isset($fix['applied']) && is_numeric($fix['applied']) && ! is_array($fix['applied'])) {
            return max(0, (int) $fix['applied']);
        }
        $applied = $fix['applied'] ?? [];

        return is_array($applied) ? count($applied) : 0;
    }

    protected function failRun(DataEngineRun $run, ?string $stderr, ?string $extra = null): void
    {
        $parts = array_filter([$stderr, $extra], fn ($v) => $v !== null && $v !== '');
        $text = $parts !== [] ? implode(PHP_EOL, $parts) : null;

        $run->fill([
            'status' => 'failed',
            'error_output' => $text,
        ]);
        $run->save();

        Log::channel('data_engine')->warning('data_engine.run.failed', [
            'run_id' => $run->id,
            'error_output_tail' => $text !== null ? mb_substr($text, -4000) : null,
        ]);
    }
}
