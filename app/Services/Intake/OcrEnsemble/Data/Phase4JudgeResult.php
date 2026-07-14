<?php

namespace App\Services\Intake\OcrEnsemble\Data;

final class Phase4JudgeResult
{
    public const OUTCOME_SKIPPED = 'skipped';

    public const OUTCOME_NOT_IMPLEMENTED = 'not_implemented';

    public const OUTCOME_NOOP = 'noop';

    public const OUTCOME_SOFT_FAILED = 'soft_failed';

    public const OUTCOME_RESOLVED = 'resolved';

    private function __construct(
        public readonly string $outcome,
        public readonly string $reason,
        public readonly ?SarvamJudgeTriggerReport $triggerReport = null,
        public readonly ?FieldResolutionEnvelope $envelope = null,
        public readonly ?string $assembledParseInputText = null,
    ) {}

    public static function skipped(string $reason, ?SarvamJudgeTriggerReport $triggerReport = null): self
    {
        return new self(self::OUTCOME_SKIPPED, $reason, $triggerReport);
    }

    public static function notImplemented(string $reason): self
    {
        return new self(self::OUTCOME_NOT_IMPLEMENTED, $reason);
    }

    public static function noop(string $reason, ?SarvamJudgeTriggerReport $triggerReport = null): self
    {
        return new self(self::OUTCOME_NOOP, $reason, $triggerReport);
    }

    public static function softFailed(string $reason, ?SarvamJudgeTriggerReport $triggerReport = null): self
    {
        return new self(self::OUTCOME_SOFT_FAILED, $reason, $triggerReport);
    }

    public static function resolved(
        FieldResolutionEnvelope $envelope,
        ?string $assembledParseInputText = null,
        ?SarvamJudgeTriggerReport $triggerReport = null,
    ): self {
        return new self(
            outcome: self::OUTCOME_RESOLVED,
            reason: 'resolved',
            triggerReport: $triggerReport,
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

    public function wasNoop(): bool
    {
        return $this->outcome === self::OUTCOME_NOOP;
    }

    public function wasSoftFailed(): bool
    {
        return $this->outcome === self::OUTCOME_SOFT_FAILED;
    }

    public function wasResolved(): bool
    {
        return $this->outcome === self::OUTCOME_RESOLVED;
    }
}
