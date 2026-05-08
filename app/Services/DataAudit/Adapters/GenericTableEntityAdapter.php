<?php

namespace App\Services\DataAudit\Adapters;

use App\Services\DataAudit\Contracts\EntityAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GenericTableEntityAdapter implements EntityAdapter
{
    public function __construct(
        private readonly string $entityKey,
        private readonly array $entityConfig
    ) {}

    public function key(): string
    {
        return $this->entityKey;
    }

    public function resolveTargets(?int $id, int $limit): Collection
    {
        $table = (string) ($this->entityConfig['table'] ?? '');
        $idCol = (string) ($this->entityConfig['id_column'] ?? 'id');
        if ($table === '' || ! Schema::hasTable($table)) {
            return collect();
        }

        $q = DB::table($table)->orderByDesc($idCol);
        if (($id ?? 0) > 0) {
            $row = DB::table($table)->where($idCol, (int) $id)->first();

            return $row ? collect([$row]) : collect();
        }

        return $q->limit(max(1, $limit))->get();
    }

    public function entityId(mixed $target): int|string|null
    {
        $idCol = (string) ($this->entityConfig['id_column'] ?? 'id');
        if (is_object($target) && isset($target->{$idCol})) {
            return $target->{$idCol};
        }

        return null;
    }

    public function captureSnapshot(mixed $target, array $sources): array
    {
        $fields = is_array($this->entityConfig['canonical_fields'] ?? null) ? $this->entityConfig['canonical_fields'] : [];
        $dbValues = [];
        foreach ($fields as $field) {
            $dbValues[(string) $field] = is_object($target) && isset($target->{$field}) ? $target->{$field} : null;
        }
        $id = $this->entityId($target);

        return [
            'snapshot_version' => '1',
            'profile_id' => $id,
            'entity_type' => $this->entityKey,
            'entity_id' => $id,
            'captured_at' => now()->toIso8601String(),
            'sources' => ['db' => true, 'api' => false, 'rendered' => false],
            'db' => $dbValues,
            'api' => ['info' => 'generic_table_adapter_db_only'],
            'rendered' => ['pages' => [], 'fields' => []],
            'metrics' => [
                'capture_duration_ms' => 0,
                'memory_peak_kb' => 0,
                'rendered_pages_count' => 0,
            ],
        ];
    }
}

