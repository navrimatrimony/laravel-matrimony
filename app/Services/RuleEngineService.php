<?php

namespace App\Services;

use App\DTO\RuleResult;
use App\Models\MatrimonyProfile;
use App\Models\SystemRule;
use App\Support\ErrorFactory;

/**
 * Evaluates configurable system rules (see system_rules). Interest mandatory-core % uses the same
 * completion signal as {@see ProfileCompletenessService::percentage()}.
 */
class RuleEngineService
{
    public const KEY_PROFILE_COMPLETION_MIN = 'profile_completion_min';

    /**
     * Minimum mandatory-core completeness % for interest send/accept when enforcement is on.
     * Prefers {@see SystemRule} when present; otherwise {@see ProfileCompletenessService::interestMinimumPercent()} (admin_settings).
     */
    public function resolveInterestMinimumPercent(): int
    {
        $rule = SystemRule::query()->where('key', self::KEY_PROFILE_COMPLETION_MIN)->first();
        if ($rule !== null && $rule->value !== '') {
            return max(0, min(100, (int) $rule->value));
        }

        return ProfileCompletenessService::interestMinimumPercent();
    }

    public function passesInterestMandatoryCore(MatrimonyProfile $profile): bool
    {
        $required = $this->resolveInterestMinimumPercent();
        if ($required <= 0) {
            return true;
        }

        return ProfileCompletenessService::percentage($profile) >= $required;
    }

    public function checkInterestMandatoryCoreForSender(MatrimonyProfile $profile): RuleResult
    {
        $rule = SystemRule::query()->where('key', self::KEY_PROFILE_COMPLETION_MIN)->first();
        $required = $this->resolveInterestMinimumPercent();
        if ($required <= 0) {
            return RuleResult::allow();
        }

        if (ProfileCompletenessService::percentage($profile) >= $required) {
            return RuleResult::allow();
        }

        return ErrorFactory::profileIncompleteSender($required, $rule?->meta);
    }

    public function checkInterestMandatoryCoreForSendTarget(MatrimonyProfile $profile): RuleResult
    {
        $rule = SystemRule::query()->where('key', self::KEY_PROFILE_COMPLETION_MIN)->first();
        $required = $this->resolveInterestMinimumPercent();
        if ($required <= 0) {
            return RuleResult::allow();
        }

        if (ProfileCompletenessService::percentage($profile) >= $required) {
            return RuleResult::allow();
        }

        return ErrorFactory::profileIncompleteTarget($rule?->meta);
    }

    public function checkInterestMandatoryCoreForAccept(MatrimonyProfile $receiverProfile): RuleResult
    {
        $rule = SystemRule::query()->where('key', self::KEY_PROFILE_COMPLETION_MIN)->first();
        $required = $this->resolveInterestMinimumPercent();
        if ($required <= 0) {
            return RuleResult::allow();
        }

        if (ProfileCompletenessService::percentage($receiverProfile) >= $required) {
            return RuleResult::allow();
        }

        return ErrorFactory::profileIncompleteAccept($required, $rule?->meta);
    }

    /**
     * Alias for naming parity with product language ("profile completion" gate).
     */
    public function checkProfileCompletion(MatrimonyProfile $profile): RuleResult
    {
        return $this->checkInterestMandatoryCoreForSender($profile);
    }
}
