<?php

namespace App\Console\Commands;

use App\Services\DataAudit\OperationsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class DataAuditNotifyCommand extends Command
{
    protected $signature = 'data-audit:notify';

    protected $description = 'Send deterministic threshold-based comparison alerts';

    public function handle(OperationsService $ops): int
    {
        $result = $ops->runLockedOperation('notify', function () use ($ops) {
            $hooks = config('data_audit_platform.notification_hooks', []);
            if (! ($hooks['enabled'] ?? false)) {
                return ['sent' => false, 'reason' => 'hooks_disabled'];
            }

            $latest = $this->latestComparison();
            if (! $latest) {
                return ['sent' => false, 'reason' => 'no_comparison'];
            }

            $health = (int) ($latest['health_score'] ?? 0);
            $summary = is_array($latest['summary'] ?? null) ? $latest['summary'] : [];
            $high = (int) ($summary['high_severity_count'] ?? 0);
            $mismatch = (int) ($summary['mismatch_count'] ?? 0);

            $thresholdHealth = (int) ($hooks['comparison_health_threshold'] ?? 70);
            $thresholdHigh = (int) ($hooks['high_severity_threshold'] ?? 3);
            $mismatchSpikeThreshold = (int) env('DATA_AUDIT_MISMATCH_SPIKE_THRESHOLD', 100);
            $storage = $ops->detectStorageHealth();
            $storageWarn = (int) config('data_engine.ops.storage_warning_bytes', 1073741824);
            $heartbeat = $ops->buildHeartbeatSummary();

            $reasons = [];
            if ($health < $thresholdHealth) {
                $reasons[] = 'health_score_low';
            }
            if ($high >= $thresholdHigh) {
                $reasons[] = 'high_severity_threshold';
            }
            if ($mismatch >= $mismatchSpikeThreshold) {
                $reasons[] = 'mismatch_spike';
            }
            $maxStorage = max($storage['snapshots_bytes'], $storage['comparisons_bytes'], $storage['reports_bytes']);
            if ($maxStorage >= $storageWarn) {
                $reasons[] = 'storage_exhaustion_risk';
            }
            if (($heartbeat['snapshot']['failure_streak'] ?? 0) >= (int) config('data_engine.ops.warning_failure_streak', 2)) {
                $reasons[] = 'snapshot_failures';
            }

            if ($reasons === []) {
                return ['sent' => false, 'reason' => 'no_threshold_breach'];
            }

            $suppressed = array_filter(array_map('trim', explode(',', (string) env('DATA_AUDIT_ALERT_SUPPRESS', ''))));
            $effectiveReasons = array_values(array_diff($reasons, $suppressed));
            if ($effectiveReasons === []) {
                return ['sent' => false, 'reason' => 'all_suppressed'];
            }

            $cooldownMinutes = (int) config('data_engine.ops.alert_cooldown_minutes', 30);
            $cooldownKey = 'data_audit:notify:cooldown:'.md5(implode('|', $effectiveReasons));
            if (Cache::has($cooldownKey)) {
                return ['sent' => false, 'reason' => 'cooldown_active', 'reasons' => $effectiveReasons];
            }

            $payload = [
                'event' => 'data_audit.threshold_breach',
                'reasons' => $effectiveReasons,
                'health_score' => $health,
                'high_severity_count' => $high,
                'mismatch_count' => $mismatch,
                'generated_at' => $latest['generated_at'] ?? null,
                'storage' => $storage,
                'heartbeat' => $heartbeat,
            ];

            $url = (string) ($hooks['webhook_url'] ?? '');
            if ($url !== '') {
                Http::timeout(10)->post($url, $payload);
            }

            $email = (string) ($hooks['email_to'] ?? '');
            if ($email !== '') {
                Mail::raw(
                    json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    fn ($m) => $m->to($email)->subject('Data Audit Alert')
                );
            }

            Cache::put($cooldownKey, true, now()->addMinutes(max(1, $cooldownMinutes)));

            return ['sent' => true, 'reasons' => $effectiveReasons];
        }, 300);

        if ($result['status'] === 'skipped_locked') {
            $this->line('Notification skipped due to lock.');
            return self::SUCCESS;
        }
        if (! $result['ok']) {
            $this->error((string) ($result['error'] ?? 'Notify failed.'));
            return self::FAILURE;
        }

        $this->line(json_encode($result['context'], JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }

    private function latestComparison(): ?array
    {
        $dir = (string) config('data-governance.platform.storage.comparison_base_path', base_path('python-data-engine/output/comparisons'));
        if (! is_dir($dir)) {
            return null;
        }
        $files = File::files($dir);
        if ($files === []) {
            return null;
        }
        usort($files, fn ($a, $b) => $b->getMTime() <=> $a->getMTime());
        $raw = file_get_contents($files[0]->getPathname());

        return $raw !== false ? json_decode($raw, true) : null;
    }
}

