<?php

namespace App\Services;

use App\DTO\RuleResult;
use App\Models\MatrimonyProfile;
use App\Models\SystemRule;
use App\Models\User;
use App\Support\ErrorFactory;

/**
 * Single entry for configurable rules and the mandatory-core completion signal used in product gates.
 * Numeric completion reads from {@see ProfileCompletionEngine}; thresholds live in {@see SystemRule} / admin.
 */
class RuleEngineService
{
    public const KEY_PROFILE_COMPLETION_MIN = 'profile_completion_min';

    public const KEY_SHOWCASE_AUTOFILL_LOG_MIN_CORE = 'showcase_autofill_log_min_core_pct';

    public function __construct(
        private readonly ProfileCompletionEngine $profileCompletionEngine,
    ) {}

    /**
     * Minimum mandatory-core completeness % for interest send/accept when enforcement is on.
     * Prefers {@see SystemRule} when present; otherwise {@see ProfileCompletenessService::interestMinimumPercent()} (admin_settings).
     */
    public function resolveInterestMinimumPercent(): int
    {
        $rule = $this->systemRule(self::KEY_PROFILE_COMPLETION_MIN);
        if ($rule !== null && $rule->value !== '') {
            return max(0, min(100, (int) $rule->value));
        }

        return ProfileCompletenessService::interestMinimumPercent();
    }

    /**
     * Threshold (mandatory-core %) under which showcase autofill logs a diagnostic line — DB-driven via {@see SystemRule}.
     */
    public function resolveShowcaseAutofillLogMinCorePercent(): int
    {
        $rule = $this->systemRule(self::KEY_SHOWCASE_AUTOFILL_LOG_MIN_CORE);
        if ($rule === null || $rule->value === '') {
            return 0;
        }

        return max(0, min(100, (int) $rule->value));
    }

    /**
     * Mandatory-field (core) completeness 0–100; same signal used for interest rules.
     */
    public function mandatoryCoreCompletionPercent(MatrimonyProfile $profile): int
    {
        return $this->profileCompletionEngine->forProfile($profile)['mandatory_core'];
    }

    /**
     * Wizard / profile “detailed” completeness across catalog sections.
     */
    public function detailedCompletionPercent(MatrimonyProfile $profile): int
    {
        return $this->profileCompletionEngine->forProfile($profile)['detailed'];
    }

    public function mandatoryCoreCompletionIsComplete(MatrimonyProfile $profile): bool
    {
        return $this->profileCompletionEngine->forProfile($profile)['is_mandatory_complete'];
    }

    public function passesInterestMandatoryCore(MatrimonyProfile $profile): bool
    {
        $required = $this->resolveInterestMinimumPercent();
        if ($required <= 0) {
            return true;
        }

        $completion = $this->profileCompletionEngine->forProfile($profile);

        return $completion['mandatory_core'] >= $required;
    }

    public function checkInterestMandatoryCoreForSender(MatrimonyProfile $profile): RuleResult
    {
        $rule = $this->systemRule(self::KEY_PROFILE_COMPLETION_MIN);
        $required = $this->resolveInterestMinimumPercent();
        if ($required <= 0) {
            return RuleResult::allow();
        }

        $completion = $this->profileCompletionEngine->forProfile($profile);
        if ($completion['mandatory_core'] >= $required) {
            return RuleResult::allow();
        }

        return ErrorFactory::profileIncompleteSender($required, $rule?->meta);
    }

    public function checkInterestMandatoryCoreForSendTarget(MatrimonyProfile $profile): RuleResult
    {
        $rule = $this->systemRule(self::KEY_PROFILE_COMPLETION_MIN);
        $required = $this->resolveInterestMinimumPercent();
        if ($required <= 0) {
            return RuleResult::allow();
        }

        $completion = $this->profileCompletionEngine->forProfile($profile);
        if ($completion['mandatory_core'] >= $required) {
            return RuleResult::allow();
        }

        return ErrorFactory::profileIncompleteTarget($rule?->meta);
    }

    public function checkInterestMandatoryCoreForAccept(MatrimonyProfile $receiverProfile): RuleResult
    {
        $rule = $this->systemRule(self::KEY_PROFILE_COMPLETION_MIN);
        $required = $this->resolveInterestMinimumPercent();
        if ($required <= 0) {
            return RuleResult::allow();
        }

        $completion = $this->profileCompletionEngine->forProfile($receiverProfile);
        if ($completion['mandatory_core'] >= $required) {
            return RuleResult::allow();
        }

        return ErrorFactory::profileIncompleteAccept($required, $rule?->meta);
    }

    /**
     * Interest “sender core complete” gate, or “profile exists” for {@see User} without a matrimony profile.
     */
    public function checkProfileCompletion(User|MatrimonyProfile $subject): RuleResult
    {
        $profile = $subject instanceof User ? $subject->matrimonyProfile : $subject;
        if (! $profile instanceof MatrimonyProfile) {
            return ErrorFactory::interestApiMatrimonyProfileRequired();
        }

        return $this->checkInterestMandatoryCoreForSender($profile);
    }

    private function systemRule(string $key): ?SystemRule
    {
        return SystemRule::query()->where('key', $key)->first();
    }
}
