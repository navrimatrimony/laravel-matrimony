<?php

namespace App\Services;

/**
 * Result of duplicate detection. Read-only; no profile creation, no history, no lifecycle change.
 */
final class DuplicateResult
{
    public const TYPE_SAME_USER = 'SAME_USER';
    public const TYPE_HARD_DUPLICATE = 'HARD_DUPLICATE';
    public const TYPE_HIGH_PROBABILITY = 'HIGH_PROBABILITY';
    public const TYPE_HIGH_RISK = 'HIGH_RISK';

    public function __construct(
        public readonly bool $isDuplicate,
        public readonly string $duplicateType,
        public readonly ?int $existingProfileId,
        public readonly string $reason,
    ) {}

    public static function notDuplicate(): self
    {
        return new self(false, '', null, 'No duplicate detected.');
    }
}
