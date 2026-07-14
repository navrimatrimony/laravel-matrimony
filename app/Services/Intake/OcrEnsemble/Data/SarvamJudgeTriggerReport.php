<?php

namespace App\Services\Intake\OcrEnsemble\Data;

/**
 * Read-only trigger evaluation for Phase 4 Sarvam judge (no API call).
 *
 * @phpstan-type TriggerMap array<string, string>
 */
final class SarvamJudgeTriggerReport
{
    /**
     * @param  TriggerMap  $triggeredFields  field_key => trigger_reason
     * @param  list<string>  $evaluatedTriggerFields
     * @param  list<string>  $reasons
     */
    public function __construct(
        public readonly bool $shouldInvokeSarvam,
        public readonly array $triggeredFields,
        public readonly array $evaluatedTriggerFields,
        public readonly ?string $skipReason = null,
        public readonly int $unresolvedCount = 0,
        public readonly int $conflictingCount = 0,
        public readonly array $reasons = [],
    ) {}

    public function shouldJudge(): bool
    {
        return $this->shouldInvokeSarvam;
    }

    /**
     * @return list<string>
     */
    public function triggerFields(): array
    {
        return array_keys($this->triggeredFields);
    }

    public static function empty(string $skipReason = 'no_triggers'): self
    {
        return new self(
            shouldInvokeSarvam: false,
            triggeredFields: [],
            evaluatedTriggerFields: [],
            skipReason: $skipReason,
            unresolvedCount: 0,
            conflictingCount: 0,
            reasons: [],
        );
    }

    /**
     * @param  array{
     *     should_invoke_sarvam?: bool,
     *     triggered_fields?: array<string, string>,
     *     evaluated_trigger_fields?: list<string>,
     *     skip_reason?: string|null,
     *     unresolved_count?: int,
     *     conflicting_count?: int,
     *     reasons?: list<string>
     * }  $data
     */
    public static function fromArray(array $data): self
    {
        $triggered = is_array($data['triggered_fields'] ?? null) ? $data['triggered_fields'] : [];
        $reasons = is_array($data['reasons'] ?? null)
            ? array_values($data['reasons'])
            : array_values($triggered);

        return new self(
            shouldInvokeSarvam: (bool) ($data['should_invoke_sarvam'] ?? false),
            triggeredFields: $triggered,
            evaluatedTriggerFields: is_array($data['evaluated_trigger_fields'] ?? null)
                ? array_values($data['evaluated_trigger_fields'])
                : [],
            skipReason: isset($data['skip_reason']) ? (string) $data['skip_reason'] : null,
            unresolvedCount: (int) ($data['unresolved_count'] ?? 0),
            conflictingCount: (int) ($data['conflicting_count'] ?? 0),
            reasons: $reasons,
        );
    }

    /**
     * @return array{
     *     should_invoke_sarvam: bool,
     *     triggered_fields: array<string, string>,
     *     evaluated_trigger_fields: list<string>,
     *     skip_reason: string|null,
     *     unresolved_count: int,
     *     conflicting_count: int,
     *     reasons: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'should_invoke_sarvam' => $this->shouldInvokeSarvam,
            'triggered_fields' => $this->triggeredFields,
            'evaluated_trigger_fields' => $this->evaluatedTriggerFields,
            'skip_reason' => $this->skipReason,
            'unresolved_count' => $this->unresolvedCount,
            'conflicting_count' => $this->conflictingCount,
            'reasons' => $this->reasons,
        ];
    }
}
