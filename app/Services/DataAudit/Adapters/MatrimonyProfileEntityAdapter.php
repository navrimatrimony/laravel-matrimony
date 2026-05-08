<?php

namespace App\Services\DataAudit\Adapters;

use App\Models\MatrimonyProfile;
use App\Services\DataAudit\Contracts\EntityAdapter;
use App\Services\DataAudit\SnapshotGeneratorService;
use Illuminate\Support\Collection;

class MatrimonyProfileEntityAdapter implements EntityAdapter
{
    public function __construct(
        private readonly SnapshotGeneratorService $generator
    ) {}

    public function key(): string
    {
        return 'matrimony_profile';
    }

    public function resolveTargets(?int $id, int $limit): Collection
    {
        if (($id ?? 0) > 0) {
            $single = MatrimonyProfile::query()->with('user')->find((int) $id);

            return $single ? collect([$single]) : collect();
        }

        return MatrimonyProfile::query()
            ->with('user')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();
    }

    public function entityId(mixed $target): int|string|null
    {
        return $target instanceof MatrimonyProfile ? $target->id : null;
    }

    public function captureSnapshot(mixed $target, array $sources): array
    {
        if (! $target instanceof MatrimonyProfile) {
            return ['error' => 'invalid_target_for_matrimony_profile'];
        }

        return $this->generator->captureProfileSnapshot($target, $sources);
    }
}

