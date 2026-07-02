<?php

namespace App\Services\Intake;

use App\Models\AdminSetting;

class IntakeSmartRoutingPolicy
{
    public const KEY_ENABLED = 'intake_smart_routing_enabled';

    public const KEY_SKIP_PAID_VISION_ENABLED = 'intake_smart_routing_skip_paid_vision_enabled';

    public const KEY_REUSE_PREVIOUS_ENABLED = 'intake_smart_routing_reuse_previous_enabled';

    public const KEY_MIN_CONFIDENCE = 'intake_smart_routing_min_confidence';

    public const KEY_REQUIRE_HUMAN_REVIEWED_REFERENCE = 'intake_smart_routing_require_human_reviewed_reference';

    public const KEY_ALLOW_SARVAM_SKIP_ACTIONS = 'intake_smart_routing_allow_sarvam_skip_actions';

    public const KEY_DRY_RUN_ONLY = 'intake_smart_routing_dry_run_only';

    private const LIVE_ACTIONS = [
        'reuse_previous',
        'cheap_ocr_only',
        'call_sarvam',
    ];

    /**
     * @param  array<string, mixed>  $recommendation
     * @return array<string, mixed>
     */
    public function evaluate(array $recommendation): array
    {
        $guardrails = $this->guardrails($recommendation);

        if (! $guardrails['enabled']) {
            return $this->blocked($guardrails, 'routing_disabled');
        }

        if ($guardrails['dry_run_only']) {
            return $this->blocked($guardrails, 'dry_run_only');
        }

        if (! $guardrails['skip_paid_vision_enabled']) {
            return $this->blocked($guardrails, 'skip_paid_vision_disabled');
        }

        $action = $guardrails['recommended_action'];
        if (! in_array($action, self::LIVE_ACTIONS, true)) {
            return $this->blocked($guardrails, 'unsupported_action');
        }

        if (! in_array($action, $guardrails['allow_sarvam_skip_actions'], true)) {
            return $this->blocked($guardrails, 'action_not_allowlisted');
        }

        if ($action === 'reuse_previous' && ! $guardrails['reuse_previous_enabled']) {
            return $this->blocked($guardrails, 'reuse_previous_disabled');
        }

        if ($guardrails['confidence'] < $guardrails['min_confidence']) {
            return $this->blocked($guardrails, 'confidence_below_min');
        }

        if ($action === 'reuse_previous' && ! $guardrails['duplicate_reuse_eligible']) {
            return $this->blocked($guardrails, 'duplicate_reuse_not_eligible');
        }

        if (
            $action === 'reuse_previous'
            && $guardrails['require_human_reviewed_reference']
            && ! $guardrails['duplicate_reference_has_reviewed_snapshot']
        ) {
            return $this->blocked($guardrails, 'human_reviewed_reference_required');
        }

        return [
            'enabled' => $guardrails['enabled'],
            'dry_run_only' => $guardrails['dry_run_only'],
            'allowed_live_action' => $action,
            'blocked_reason' => null,
            'guardrails' => $guardrails,
        ];
    }

    /**
     * @param  array<string, mixed>  $recommendation
     * @return array<string, mixed>
     */
    private function guardrails(array $recommendation): array
    {
        $signals = is_array($recommendation['signals'] ?? null) ? $recommendation['signals'] : [];

        return [
            'enabled' => $this->settingBool(self::KEY_ENABLED, (bool) config('intake.smart_routing.enabled', false)),
            'dry_run_only' => $this->settingBool(self::KEY_DRY_RUN_ONLY, (bool) config('intake.smart_routing.dry_run_only', true)),
            'skip_paid_vision_enabled' => $this->settingBool(
                self::KEY_SKIP_PAID_VISION_ENABLED,
                (bool) config('intake.smart_routing.skip_paid_vision_enabled', false)
            ),
            'reuse_previous_enabled' => $this->settingBool(
                self::KEY_REUSE_PREVIOUS_ENABLED,
                (bool) config('intake.smart_routing.reuse_previous_enabled', false)
            ),
            'min_confidence' => $this->settingFloat(
                self::KEY_MIN_CONFIDENCE,
                (float) config('intake.smart_routing.min_confidence', 0.90)
            ),
            'require_human_reviewed_reference' => $this->settingBool(
                self::KEY_REQUIRE_HUMAN_REVIEWED_REFERENCE,
                (bool) config('intake.smart_routing.require_human_reviewed_reference', true)
            ),
            'allow_sarvam_skip_actions' => $this->allowlistedActions(),
            'recommended_action' => $this->scalarString($recommendation['recommended_action'] ?? null, 'unknown'),
            'confidence' => $this->floatValue($recommendation['confidence'] ?? null),
            'would_skip_paid_vision' => $this->boolValue($recommendation['would_skip_paid_vision'] ?? false),
            'duplicate_reuse_eligible' => $this->boolValue($signals['duplicate_reuse_eligible'] ?? false),
            'duplicate_reuse_trust' => $this->scalarString($signals['duplicate_reuse_trust'] ?? null, 'unknown'),
            'duplicate_reference_intake_id' => $this->nullableInt($signals['duplicate_reference_intake_id'] ?? null),
            'duplicate_reference_has_reviewed_snapshot' => $this->boolValue(
                $signals['duplicate_reference_has_reviewed_snapshot'] ?? false
            ),
            'duplicate_reference_reason' => $this->scalarString($signals['duplicate_reference_reason'] ?? null, ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $guardrails
     * @return array<string, mixed>
     */
    private function blocked(array $guardrails, string $reason): array
    {
        return [
            'enabled' => $guardrails['enabled'],
            'dry_run_only' => $guardrails['dry_run_only'],
            'allowed_live_action' => null,
            'blocked_reason' => $reason,
            'guardrails' => $guardrails,
        ];
    }

    private function settingBool(string $key, bool $default): bool
    {
        return filter_var(AdminSetting::getValue($key, $default ? '1' : '0'), FILTER_VALIDATE_BOOLEAN);
    }

    private function settingFloat(string $key, float $default): float
    {
        $raw = AdminSetting::getValue($key, (string) $default);

        return is_numeric($raw) ? (float) $raw : $default;
    }

    /**
     * @return list<string>
     */
    private function allowlistedActions(): array
    {
        $default = config('intake.smart_routing.allow_sarvam_skip_actions', ['reuse_previous']);
        $raw = AdminSetting::getValue(self::KEY_ALLOW_SARVAM_SKIP_ACTIONS, json_encode($default));

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $this->normalizeActions($decoded);
            }

            return $this->normalizeActions(explode(',', $raw));
        }

        if (is_array($raw)) {
            return $this->normalizeActions($raw);
        }

        return $this->normalizeActions(is_array($default) ? $default : ['reuse_previous']);
    }

    /**
     * @param  array<int|string, mixed>  $actions
     * @return list<string>
     */
    private function normalizeActions(array $actions): array
    {
        $normalized = [];
        foreach ($actions as $action) {
            if (! is_scalar($action)) {
                continue;
            }

            $action = trim((string) $action);
            if ($action === '' || ! in_array($action, self::LIVE_ACTIONS, true)) {
                continue;
            }

            $normalized[] = $action;
        }

        return array_values(array_unique($normalized));
    }

    private function scalarString(mixed $value, string $default): string
    {
        if (! is_scalar($value)) {
            return $default;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : $default;
    }

    private function floatValue(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
        }

        return false;
    }

    private function nullableInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
