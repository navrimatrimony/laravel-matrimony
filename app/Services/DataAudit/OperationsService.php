<?php

namespace App\Services\DataAudit;

use App\Models\DataAuditOperationEvent;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class OperationsService
{
    /**
     * @param  callable():array<string,mixed>  $callback
     * @return array{ok: bool, status: string, context: array<string,mixed>, error?: string}
     */
    public function runLockedOperation(
        string $operation,
        callable $callback,
        int $ttlSeconds = 900,
        bool $waitForLock = false
    ): array {
        $startedAt = microtime(true);
        $memoryStart = memory_get_usage(true);
        $now = now();
        $lockKey = 'data-audit:lock:'.$operation;
        $lock = Cache::lock($lockKey, max(60, $ttlSeconds));

        try {
            if ($waitForLock) {
                $lock->block(3);
            } elseif (! $lock->get()) {
                $result = [
                    'ok' => true,
                    'status' => 'skipped_locked',
                    'context' => ['reason' => 'overlapping_run_prevented'],
                ];
                $this->record($operation, $now, $startedAt, $memoryStart, $result);

                return $result;
            }
        } catch (LockTimeoutException) {
            $result = [
                'ok' => true,
                'status' => 'skipped_locked',
                'context' => ['reason' => 'lock_wait_timeout'],
            ];
            $this->record($operation, $now, $startedAt, $memoryStart, $result);

            return $result;
        }

        try {
            $context = $callback();
            $result = [
                'ok' => true,
                'status' => 'success',
                'context' => $context,
            ];
        } catch (Throwable $e) {
            $result = [
                'ok' => false,
                'status' => 'failed',
                'error' => $e->getMessage(),
                'context' => ['exception' => get_class($e)],
            ];
        } finally {
            $lock->release();
        }

        $this->record($operation, $now, $startedAt, $memoryStart, $result);

        return $result;
    }

    public function buildHeartbeatSummary(): array
    {
        $ops = ['analyze', 'snapshot', 'compare', 'cleanup', 'notify'];
        $summary = [];
        foreach ($ops as $op) {
            $lastSuccess = DataAuditOperationEvent::query()
                ->where('operation', $op)
                ->where('status', 'success')
                ->orderByDesc('id')
                ->first();
            $lastFailed = DataAuditOperationEvent::query()
                ->where('operation', $op)
                ->where('status', 'failed')
                ->orderByDesc('id')
                ->first();

            $failureStreak = 0;
            $recent = DataAuditOperationEvent::query()
                ->where('operation', $op)
                ->orderByDesc('id')
                ->limit(20)
                ->get();
            foreach ($recent as $row) {
                if ($row->status === 'success') {
                    break;
                }
                if ($row->status === 'failed') {
                    $failureStreak++;
                }
            }

            $durations = DataAuditOperationEvent::query()
                ->where('operation', $op)
                ->where('status', 'success')
                ->whereNotNull('duration_ms')
                ->orderByDesc('id')
                ->limit(10)
                ->pluck('duration_ms')
                ->all();
            $avgDuration = $durations === [] ? null : (int) round(array_sum($durations) / count($durations));

            $summary[$op] = [
                'last_success_at' => $lastSuccess?->finished_at?->toIso8601String(),
                'last_failed_at' => $lastFailed?->finished_at?->toIso8601String(),
                'failure_streak' => $failureStreak,
                'avg_duration_ms' => $avgDuration,
            ];
        }

        return $summary;
    }

    public function detectStorageHealth(): array
    {
        $snapshotBase = (string) config('data-governance.platform.storage.snapshot_base_path', storage_path('app/data-audit/snapshots'));
        $comparisonBase = (string) config('data-governance.platform.storage.comparison_base_path', base_path('python-data-engine/output/comparisons'));
        $reportsBase = base_path('python-data-engine/output/reports');

        return [
            'snapshots_bytes' => $this->directorySize($snapshotBase),
            'comparisons_bytes' => $this->directorySize($comparisonBase),
            'reports_bytes' => $this->directorySize($reportsBase),
        ];
    }

    public function applyRetention(bool $execute = false): array
    {
        $snapshotBase = (string) config('data-governance.platform.storage.snapshot_base_path', storage_path('app/data-audit/snapshots'));
        $comparisonBase = (string) config('data-governance.platform.storage.comparison_base_path', base_path('python-data-engine/output/comparisons'));
        $reportsBase = base_path('python-data-engine/output/reports');
        $logsBase = storage_path('logs');

        $snapshotKeepPerEntity = max(1, (int) config('data_engine.retention.snapshot_keep_per_entity', 20));
        $snapshotMaxAgeDays = max(1, (int) config('data_engine.retention.snapshot_max_age_days', 30));
        $comparisonKeep = max(1, (int) config('data_engine.retention.comparison_keep_files', 50));
        $comparisonMaxAgeDays = max(1, (int) config('data_engine.retention.comparison_max_age_days', 30));
        $reportsMaxAgeDays = max(1, (int) config('data_engine.retention.report_max_age_days', 30));
        $logsMaxAgeDays = max(1, (int) config('data_engine.retention.log_max_age_days', 30));

        $deleted = [];
        $candidates = [];
        $namingViolations = 0;
        $orphanDirectories = 0;

        if (is_dir($snapshotBase)) {
            foreach (File::directories($snapshotBase) as $entityDir) {
                $files = File::files($entityDir);
                if ($files === []) {
                    $orphanDirectories++;
                }
                usort($files, fn ($a, $b) => $b->getMTime() <=> $a->getMTime());
                foreach ($files as $i => $file) {
                    if (! preg_match('/^snapshot_\d{4}_\d{2}_\d{2}_\d{6}\.json$/', $file->getFilename())) {
                        $namingViolations++;
                    }
                    $tooMany = $i >= $snapshotKeepPerEntity;
                    $tooOld = $file->getMTime() < now()->subDays($snapshotMaxAgeDays)->getTimestamp();
                    if (! $tooMany && ! $tooOld) {
                        continue;
                    }
                    $candidates[] = $file->getPathname();
                    if ($execute) {
                        File::delete($file->getPathname());
                        $deleted[] = $file->getPathname();
                    }
                }
            }
        }

        $candidates = array_merge($candidates, $this->retainRollingFiles($comparisonBase, 'snapshot_comparison_*.json', $comparisonKeep, $comparisonMaxAgeDays, $execute, $deleted));
        $candidates = array_merge($candidates, $this->retainRollingFiles($reportsBase, 'engine_*.json', 999999, $reportsMaxAgeDays, $execute, $deleted));
        $candidates = array_merge($candidates, $this->retainRollingFiles($logsBase, 'data-engine*.log*', 999999, $logsMaxAgeDays, $execute, $deleted));

        return [
            'execute' => $execute,
            'candidates_count' => count(array_unique($candidates)),
            'deleted_count' => count($deleted),
            'naming_violations' => $namingViolations,
            'orphan_snapshot_directories' => $orphanDirectories,
        ];
    }

    public function quarantineFile(string $file, string $reason): ?string
    {
        if (! is_file($file)) {
            return null;
        }
        $qDir = base_path('python-data-engine/output/quarantine');
        File::ensureDirectoryExists($qDir);
        $target = $qDir.'/'.basename($file).'.'.now()->format('Ymd_His').'.quarantine';
        File::move($file, $target);

        $auditDir = base_path('python-data-engine/output/recovery-audit');
        File::ensureDirectoryExists($auditDir);
        File::append($auditDir.'/recovery.log', json_encode([
            'at' => now()->toIso8601String(),
            'source' => $file,
            'target' => $target,
            'reason' => $reason,
        ], JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $target;
    }

    private function retainRollingFiles(string $dir, string $pattern, int $keep, int $maxAgeDays, bool $execute, array &$deleted): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $files = glob($dir.DIRECTORY_SEPARATOR.$pattern) ?: [];
        usort($files, fn ($a, $b) => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
        $candidates = [];
        foreach ($files as $i => $path) {
            $mtime = filemtime($path) ?: 0;
            $tooMany = $i >= $keep;
            $tooOld = $mtime < now()->subDays($maxAgeDays)->getTimestamp();
            if (! $tooMany && ! $tooOld) {
                continue;
            }
            $candidates[] = $path;
            if ($execute && is_file($path)) {
                File::delete($path);
                $deleted[] = $path;
            }
        }

        return $candidates;
    }

    private function directorySize(string $path): int
    {
        if (! is_dir($path)) {
            return 0;
        }

        $total = 0;
        foreach (File::allFiles($path) as $file) {
            $total += (int) $file->getSize();
        }

        return $total;
    }

    /**
     * @param  array{ok: bool, status: string, context: array<string,mixed>, error?: string}  $result
     */
    private function record(
        string $operation,
        Carbon $startedAtCarbon,
        float $startedAt,
        int $memoryStart,
        array $result
    ): void {
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $memoryPeakKb = (int) round((memory_get_peak_usage(true) - $memoryStart) / 1024);
        $finishedAt = now();

        DataAuditOperationEvent::query()->create([
            'operation' => $operation,
            'status' => $result['status'],
            'duration_ms' => $durationMs,
            'memory_peak_kb' => max(0, $memoryPeakKb),
            'error_message' => $result['error'] ?? null,
            'context' => $result['context'],
            'started_at' => $startedAtCarbon,
            'finished_at' => $finishedAt,
        ]);

        Log::channel('data_engine')->info('data_audit.operation_event', [
            'operation' => $operation,
            'status' => $result['status'],
            'duration_ms' => $durationMs,
            'memory_peak_kb' => $memoryPeakKb,
            'error' => $result['error'] ?? null,
        ]);
    }
}

