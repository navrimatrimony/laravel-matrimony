<?php

namespace App\Services;

use App\DTO\RuleResult;
use App\Models\MatrimonyProfile;
use App\Models\SystemRule;
use App\Models\User;
use App\Services\Matching\MatchingService;
use App\Support\ErrorFactory;

/**
 * Single entry for configurable rules, mandatory-core completion signals used in product gates,
 * and **compatibility scoring** (always use {@see getMatchResult()} / {@see getMatchResultForProfiles()}).
 * Numeric completion reads from {@see ProfileCompletionEngine}; thresholds live in {@see SystemRule} / admin.
 */
class RuleEngineService
{
    public const KEY_PROFILE_COMPLETION_MIN = 'profile_completion_min';

    public const KEY_SHOWCASE_AUTOFILL_LOG_MIN_CORE = 'showcase_autofill_log_min_core_pct';

    public function __construct(
        private readonly ProfileCompletionEngine $profileCompletionEngine,
        private readonly MatchingService $matchingService,
        private readonly AIBehaviorService $aiBehaviorService,
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
     * Product gate before running matching between two accounts (both must own profiles; not self).
     */
    public function checkMatchingAllowed(User $userA, User $userB): bool
    {
        if ((int) $userA->id === (int) $userB->id) {
            return false;
        }

        return $userA->matrimonyProfile instanceof MatrimonyProfile
            && $userB->matrimonyProfile instanceof MatrimonyProfile;
    }

    /**
     * Single entry for numeric compatibility between two accounts (SSOT).
     *
     * @return array{
     *     score: int,
     *     grade: string,
     *     breakdown: array<string, int>,
     *     normalized_breakdown: array<string, int>,
     *     is_compatible: bool,
     *     ai_boost: int,
     *     final_score: int,
     *     debug: array{base_score: int, ai_boost: int},
     * }
     */
    public function getMatchResult(User $userA, User $userB): array
    {
        if (! $this->checkMatchingAllowed($userA, $userB)) {
            $empty = $this->emptyMatchPayload();
            $empty['ai_boost'] = 0;
            $empty['final_score'] = (int) $empty['score'];
            $empty['debug'] = [
                'base_score' => (int) $empty['score'],
                'ai_boost' => 0,
            ];

            return $empty;
        }

        /** @var MatrimonyProfile $source */
        $source = $userA->matrimonyProfile;
        /** @var MatrimonyProfile $target */
        $target = $userB->matrimonyProfile;
        $base = $this->matchResultFromProfiles($source, $target);

        $targetProfile = $userB->matrimonyProfile ?? null;
        $aiBoost = 0;
        if ($targetProfile instanceof MatrimonyProfile) {
            $aiBoost = $this->aiBehaviorService->getBoost($userA, $targetProfile);
        }

        $finalScore = min(100, (int) $base['score'] + $aiBoost);

        $base['ai_boost'] = $aiBoost;
        $base['final_score'] = $finalScore;
        $base['debug'] = [
            'base_score' => (int) $base['score'],
            'ai_boost' => $aiBoost,
        ];

        if ((int) $base['score'] === 0 && $aiBoost > 0) {
            $base['grade'] = 'AI Match';
        }

        return $base;
    }

    /**
     * Listing / card entry: applies {@see checkMatchingAllowed()} when the candidate profile has a user;
     * otherwise falls back to profile-only scoring (e.g. showcase rows without an account).
     *
     * @return array{score: int, grade: string, breakdown: array<string, int>, normalized_breakdown: array<string, int>, is_compatible: bool}
     */
    public function getMatchResultForProfiles(MatrimonyProfile $viewerProfile, MatrimonyProfile $viewedProfile): array
    {
        $viewerUser = $viewerProfile->relationLoaded('user') ? $viewerProfile->user : $viewerProfile->user()->first();
        if (! $viewerUser instanceof User) {
            return $this->emptyMatchPayload();
        }

        $viewedUser = $viewedProfile->relationLoaded('user') ? $viewedProfile->user : $viewedProfile->user()->first();
        if ($viewedUser instanceof User) {
            return $this->getMatchResult($viewerUser, $viewedUser);
        }

        return $this->matchResultFromProfiles($viewerProfile, $viewedProfile);
    }

    /**
     * @return array{score: int, grade: string, breakdown: array<string, int>, normalized_breakdown: array<string, int>, is_compatible: bool}
     */
    public function emptyMatchResult(): array
    {
        return $this->emptyMatchPayload();
    }

    /**
     * Placeholder batch hook (future optimization). Uses {@see getMatchResultForProfiles()} so gates stay centralized.
     *
     * @param  list<MatrimonyProfile>  $profiles
     * @return list<array{score: int, grade: string, breakdown: array<string, int>, normalized_breakdown: array<string, int>, is_compatible: bool}>
     */
    public function forMany(User $user, array $profiles): array
    {
        $viewer = $user->matrimonyProfile;
        if (! $viewer instanceof MatrimonyProfile) {
            return collect($profiles)->map(fn () => $this->emptyMatchPayload())->values()->all();
        }

        return collect($profiles)
            ->map(function ($profile) use ($viewer): array {
                if (! $profile instanceof MatrimonyProfile) {
                    return $this->emptyMatchPayload();
                }

                return $this->getMatchResultForProfiles($viewer, $profile);
            })
            ->values()
            ->all();
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

    /**
     * @return array{score:int, grade:string, breakdown:array<string,int>, normalized_breakdown:array<string,int>, is_compatible:bool}
     */
    private function matchResultFromProfiles(MatrimonyProfile $source, MatrimonyProfile $target): array
    {
        $breakdownPayload = $this->matchingService->computeMatchBreakdown($source, $target);
        $field = is_array($breakdownPayload['field_points'] ?? null) ? $breakdownPayload['field_points'] : [];
        $score = (int) ($breakdownPayload['final_score'] ?? 0);
        $breakdown = [
            'matching_age' => (int) ($field['age'] ?? 0),
            'matching_location' => (int) ($field['location'] ?? 0),
            'matching_education' => (int) ($field['education'] ?? 0),
            'matching_caste' => (int) ($field['community'] ?? 0),
            'matching_profile_completion' => (int) ($field['preferences'] ?? 0),
        ];
        $sum = array_sum($breakdown);
        $normalized = $sum > 0
            ? collect($breakdown)->map(fn (int $v): int => (int) round(($v / $sum) * 100))->all()
            : [];

        return [
            'score' => $score,
            'grade' => $this->gradeFromScore($score),
            'breakdown' => $breakdown,
            'normalized_breakdown' => $normalized,
            'is_compatible' => $score >= 60,
        ];
    }

    /**
     * @return array{score:int, grade:string, breakdown:array<string,int>, normalized_breakdown:array<string,int>, is_compatible:bool}
     */
    private function emptyMatchPayload(): array
    {
        return [
            'score' => 0,
            'grade' => 'Low',
            'breakdown' => [],
            'normalized_breakdown' => [],
            'is_compatible' => false,
        ];
    }

    private function gradeFromScore(int $score): string
    {
        if ($score >= 80) {
            return 'Excellent';
        }
        if ($score >= 60) {
            return 'Good';
        }
        if ($score >= 40) {
            return 'Average';
        }

        return 'Low';
    }
}
