<?php

namespace App\Console\Commands;

use App\Services\DataAudit\EntityAdapterRegistry;
use App\Services\DataAudit\OperationsService;
use App\Services\DataAudit\SnapshotStorageService;
use Illuminate\Console\Command;

class DataAuditSnapshotCommand extends Command
{
    protected $signature = 'data-audit:snapshot
        {--entity=matrimony_profile : Entity key from data_audit_platform config}
        {--id= : Capture single entity id}
        {--profile= : Capture single profile id}
        {--limit=10 : Number of latest profiles when --profile is omitted}
        {--wizard : Include wizard rendered snapshot}
        {--public-profile : Include public profile rendered snapshot}
        {--api : Include API snapshot payload}';

    protected $description = 'Capture deterministic data-audit snapshots (DB/API/rendered)';

    public function handle(
        EntityAdapterRegistry $adapterRegistry,
        SnapshotStorageService $storage,
        OperationsService $ops
    ): int {
        $result = $ops->runLockedOperation('snapshot', function () use ($adapterRegistry, $storage) {
            $sources = $this->resolveSources();
            $entityKey = (string) ($this->option('entity') ?: 'matrimony_profile');
            $adapter = $adapterRegistry->resolve($entityKey);

            $id = (int) ($this->option('id') ?: $this->option('profile') ?: 0);
            $targets = $adapter->resolveTargets($id > 0 ? $id : null, max(1, (int) ($this->option('limit') ?? 10)));
            if ($targets->isEmpty()) {
                return ['entity' => $entityKey, 'captured_profiles' => 0, 'rows' => []];
            }

            $rows = [];
            foreach ($targets as $target) {
                $snapshot = $adapter->captureSnapshot($target, $sources);
                $entityId = (string) ($adapter->entityId($target) ?? 'unknown');
                $path = $storage->storeEntitySnapshot($entityKey, $entityId, $snapshot);
                $rows[] = [
                    'entity' => $entityKey,
                    'entity_id' => $entityId,
                    'path' => $path,
                    'duration_ms' => (string) ($snapshot['metrics']['capture_duration_ms'] ?? 0),
                    'rendered_pages' => (string) ($snapshot['metrics']['rendered_pages_count'] ?? 0),
                ];
            }

            return [
                'entity' => $entityKey,
                'sources' => $sources,
                'captured_profiles' => count($rows),
                'rows' => $rows,
                'snapshot_count_total' => $storage->countAllSnapshots(),
                'latest_snapshot' => $storage->latestSnapshotMeta(),
            ];
        }, 900);

        if ($result['status'] === 'skipped_locked') {
            $this->warn('Snapshot skipped: overlapping run prevented.');
            return self::SUCCESS;
        }
        if (! $result['ok']) {
            $this->error((string) ($result['error'] ?? 'Snapshot failed.'));
            return self::FAILURE;
        }

        $context = $result['context'];
        $rows = is_array($context['rows'] ?? null) ? $context['rows'] : [];
        if ($rows === []) {
            $this->warn('No entities found for snapshot capture.');
        } else {
            $this->table(['entity', 'entity_id', 'path', 'duration_ms', 'rendered_pages'], $rows);
        }
        $this->info(json_encode($context, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * @return array{api: bool, public_profile: bool, wizard: bool}
     */
    private function resolveSources(): array
    {
        $wizard = (bool) $this->option('wizard');
        $public = (bool) $this->option('public-profile');
        $api = (bool) $this->option('api');

        if (! $wizard && ! $public && ! $api) {
            return ['api' => true, 'public_profile' => true, 'wizard' => true];
        }

        return ['api' => $api, 'public_profile' => $public, 'wizard' => $wizard];
    }

}

