<?php

namespace App\Support;

/**
 * Result of admin profile edit orchestration (post-validation).
 */
final class AdminProfileEditResult
{
    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly bool $mutated = false,
        public readonly bool $escalated_to_conflict = false,
        /** @var list<string> */
        public readonly array $edited_fields = [],
    ) {}
}
