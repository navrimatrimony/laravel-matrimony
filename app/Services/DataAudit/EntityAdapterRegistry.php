<?php

namespace App\Services\DataAudit;

use App\Services\DataAudit\Adapters\GenericTableEntityAdapter;
use App\Services\DataAudit\Adapters\MatrimonyProfileEntityAdapter;
use App\Services\DataAudit\Contracts\EntityAdapter;

class EntityAdapterRegistry
{
    public function __construct(
        private readonly MatrimonyProfileEntityAdapter $matrimonyAdapter
    ) {}

    public function resolve(string $entityKey): EntityAdapter
    {
        $entities = config('data_audit_platform.entities', []);
        $cfg = is_array($entities[$entityKey] ?? null) ? $entities[$entityKey] : null;
        if ($cfg === null) {
            throw new \InvalidArgumentException('Unknown entity key: '.$entityKey);
        }

        $adapter = (string) ($cfg['adapter'] ?? '');
        if ($adapter === 'matrimony_profile') {
            return $this->matrimonyAdapter;
        }

        return new GenericTableEntityAdapter($entityKey, $cfg);
    }
}

