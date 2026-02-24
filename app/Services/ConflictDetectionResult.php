<?php

namespace App\Services;

use App\Models\ConflictRecord;

/**
 * Result of conflict detection. Contains created conflict records and escalation flag.
 */
final class ConflictDetectionResult
{
    public function __construct(
        /** @var ConflictRecord[] */
        public readonly array $conflictRecords,
        public readonly bool $requiresAdminResolution,
    ) {}

    public static function empty(): self
    {
        return new self([], false);
    }
}
