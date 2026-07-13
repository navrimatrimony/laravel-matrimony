<?php

namespace App\Services\Intake\OcrEnsemble\Data;

final class Phase3ResolutionResult
{
    public const OUTCOME_SKIPPED = 'skipped';

    public const OUTCOME_NOT_IMPLEMENTED = 'not_implemented';

    public const OUTCOME_RESOLVED = 'resolved';

    private function __construct(
        public readonly string $outcome,
        public readonly string $reason,
        public readonly ?FieldResolutionEnvelope $envelope = null,
        public readonly ?string $assembledParseInputText = null,
    ) {}

    public static function skipped(string $reason): self
    {
        return new self(self::OUTCOME_SKIPPED, $reason);
    }

    public static function notImplemented(string $reason): self
    {
        return new self(self::OUTCOME_NOT_IMPLEMENTED, $reason);
    }

    public static function resolved(
        FieldResolutionEnvelope $envelope,
        ?string $assembledParseInputText = null,
    ): self {
        return new self(
            outcome: self::OUTCOME_RESOLVED,
            reason: 'resolved',
            envelope: $envelope,
            assembledParseInputText: $assembledParseInputText,
        );
    }

    public function wasSkipped(): bool
    {
        return $this->outcome === self::OUTCOME_SKIPPED;
    }

    public function wasNotImplemented(): bool
    {
        return $this->outcome === self::OUTCOME_NOT_IMPLEMENTED;
    }
}
