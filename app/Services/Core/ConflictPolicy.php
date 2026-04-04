<?php

namespace App\Services\Core;

use App\Models\ConflictRecord;

class ConflictPolicy
{
    /**
     * Single entry point for creating conflict rows. Normalizes string values at creation time.
     * Accepts field_name or legacy alias field_key. Optional meta is ignored (no DB column).
     *
     * @param  array<string, mixed>  $data
     */
    public static function create(array $data): ConflictRecord
    {
        $fieldName = $data['field_name'] ?? $data['field_key'] ?? null;

        $row = [
            'profile_id' => $data['profile_id'] ?? null,
            'field_name' => $fieldName,
            'field_type' => $data['field_type'] ?? 'CORE',
            'old_value' => self::normalize($data['old_value'] ?? null),
            'new_value' => self::normalize($data['new_value'] ?? null),
            'source' => $data['source'] ?? 'SYSTEM',
            'detected_at' => $data['detected_at'] ?? now(),
            'resolution_status' => $data['resolution_status'] ?? 'PENDING',
        ];

        foreach (['resolved_by', 'resolved_at', 'resolution_reason'] as $optional) {
            if (array_key_exists($optional, $data)) {
                $row[$optional] = $data[$optional];
            }
        }

        return ConflictRecord::create($row);
    }

    private static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '' || $value === '—') {
            return null;
        }

        return $value;
    }
}
