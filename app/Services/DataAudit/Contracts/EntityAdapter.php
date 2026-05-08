<?php

namespace App\Services\DataAudit\Contracts;

use Illuminate\Support\Collection;

interface EntityAdapter
{
    public function key(): string;

    public function resolveTargets(?int $id, int $limit): Collection;

    public function entityId(mixed $target): int|string|null;

    /**
     * @param  array{api: bool, public_profile: bool, wizard: bool}  $sources
     * @return array<string, mixed>
     */
    public function captureSnapshot(mixed $target, array $sources): array;
}

