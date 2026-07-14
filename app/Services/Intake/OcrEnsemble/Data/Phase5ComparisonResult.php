<?php

namespace App\Services\Intake\OcrEnsemble\Data;

final class Phase5ComparisonResult
{
    public const OUTCOME_SKIPPED = 'skipped';

    public const OUTCOME_NOT_IMPLEMENTED = 'not_implemented';

    public const OUTCOME_EMPTY = 'empty';

    public const OUTCOME_RESOLVED = 'resolved';

    private function __construct(
        public readonly string $outcome,
        public readonly string $reason,
        public readonly ?OcrComparisonTable $table = null,
    ) {}

    public static function skipped(string $reason): self
    {
        return new self(self::OUTCOME_SKIPPED, $reason);
    }

    public static function notImplemented(string $reason): self
    {
        return new self(self::OUTCOME_NOT_IMPLEMENTED, $reason);
    }

    public static function empty(string $reason, ?OcrComparisonTable $table = null): self
    {
        return new self(self::OUTCOME_EMPTY, $reason, $table);
    }

    public static function resolved(OcrComparisonTable $table): self
    {
        return new self(self::OUTCOME_RESOLVED, 'resolved', $table);
    }

    public function wasSkipped(): bool
    {
        return $this->outcome === self::OUTCOME_SKIPPED;
    }

    public function wasNotImplemented(): bool
    {
        return $this->outcome === self::OUTCOME_NOT_IMPLEMENTED;
    }

    public function wasEmpty(): bool
    {
        return $this->outcome === self::OUTCOME_EMPTY;
    }

    public function wasResolved(): bool
    {
        return $this->outcome === self::OUTCOME_RESOLVED;
    }
}
