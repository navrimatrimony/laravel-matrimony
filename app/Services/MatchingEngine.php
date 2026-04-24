<?php

namespace App\Services;

use App\Models\MatrimonyProfile;
use App\Models\SystemRule;
use App\Models\User;
use App\Services\Matching\Rules\AgeMatchRule;
use App\Services\Matching\Rules\CasteMatchRule;
use App\Services\Matching\Rules\EducationMatchRule;
use App\Services\Matching\Rules\LocationMatchRule;
use App\Services\Matching\Rules\NullMatchRule;
use App\Services\Matching\Rules\ProfileCompletionMatchRule;
use Illuminate\Support\Facades\Cache;

/**
 * ⚠️ INTERNAL SERVICE — do not use directly from controllers, HTTP layer, or Blade.
 * All product matching reads must go through {@see RuleEngineService::getMatchResult()} /
 * {@see RuleEngineService::getMatchResultForProfiles()} (SSOT).
 *
 * DB-driven compatibility scoring; presentation lives in {@see \App\Services\Matching\MatchingPresenter}.
 */
class MatchingEngine
{
    public const RULE_MATCHING_AGE = 'matching_age';

    public const RULE_MATCHING_LOCATION = 'matching_location';

    public const RULE_MATCHING_EDUCATION = 'matching_education';

    public const RULE_MATCHING_CASTE = 'matching_caste';

    public const RULE_MATCHING_PROFILE_COMPLETION = 'matching_profile_completion';

    public const RULE_MATCHING_MINIMUM_SCORE = 'matching_minimum_score';

    /** @var list<string> */
    public const DIMENSION_RULE_KEYS = [
        self::RULE_MATCHING_AGE,
        self::RULE_MATCHING_LOCATION,
        self::RULE_MATCHING_EDUCATION,
        self::RULE_MATCHING_CASTE,
        self::RULE_MATCHING_PROFILE_COMPLETION,
    ];

    public const RULES_CACHE_KEY = 'matching_rules';

    /** @deprecated Use {@see self::RULES_CACHE_KEY} */
    private const LEGACY_RULES_CACHE_KEY = 'matching_engine:system_rules:v1';

    private const RULES_CACHE_TTL_SECONDS = 300;

    /**
     * @return array{score: int, grade: string, breakdown: array<string, int>, normalized_breakdown: array<string, int>, is_compatible: bool}
     */
    public function for(User $userA, User $userB): array
    {
        $profileA = $userA->matrimonyProfile;
        $profileB = $userB->matrimonyProfile;
        if (! $profileA instanceof MatrimonyProfile || ! $profileB instanceof MatrimonyProfile) {
            return $this->emptyScorePayload();
        }

        return $this->scoreBetweenProfiles($profileA, $profileB);
    }

    /**
     * @internal Prefer {@see RuleEngineService::getMatchResultForProfiles()} from HTTP / product code.
     *
     * @return array{
     *     score: int,
     *     grade: string,
     *     breakdown: array<string, int>,
     *     normalized_breakdown: array<string, int>,
     *     is_compatible: bool,
     * }
     */
    public function scoreBetweenProfiles(MatrimonyProfile $profileA, MatrimonyProfile $profileB): array
    {
        $rules = $this->getDimensionRules();
        $rawScore = 0;
        $breakdown = [];
        $totalWeight = array_sum(array_column($rules, 'weight'));

        foreach ($rules as $rule) {
            $value = $this->applyRule($rule, $profileA, $profileB);
            $breakdown[$rule['key']] = $value;
            $rawScore += $value;
        }

        $normalizedScore = $totalWeight > 0
            ? (int) round(($rawScore / $totalWeight) * 100)
            : 0;

        return [
            'score' => $normalizedScore,
            'grade' => $this->grade($normalizedScore),
            'breakdown' => $breakdown,
            'normalized_breakdown' => $this->normalizeBreakdown($breakdown, $totalWeight),
            'is_compatible' => $normalizedScore >= $this->minimumScore(),
        ];
    }

    /**
     * Placeholder batch helper (does not apply {@see RuleEngineService::checkMatchingAllowed()}).
     * Product code should use {@see RuleEngineService::forMany()}.
     *
     * @param  list<MatrimonyProfile>  $profiles
     * @return list<array{score: int, grade: string, breakdown: array<string, int>, normalized_breakdown: array<string, int>, is_compatible: bool}>
     */
    public function forMany(User $user, array $profiles): array
    {
        return collect($profiles)
            ->map(function ($profile) use ($user): array {
                if (! $profile instanceof MatrimonyProfile) {
                    return $this->emptyScorePayload();
                }
                $other = $profile->relationLoaded('user') ? $profile->user : $profile->user()->first();

                return $other instanceof User ? $this->for($user, $other) : $this->emptyScorePayload();
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, int>  $breakdown
     * @return array<string, int>
     */
    private function normalizeBreakdown(array $breakdown, int $totalWeight): array
    {
        if ($totalWeight === 0) {
            return [];
        }

        return collect($breakdown)
            ->map(fn (int $value): int => (int) round(($value / $totalWeight) * 100))
            ->all();
    }

    /**
     * @return array{score: int, grade: string, breakdown: array<string, int>, normalized_breakdown: array<string, int>, is_compatible: bool}
     */
    public function emptyScorePayload(): array
    {
        return [
            'score' => 0,
            'grade' => 'Low',
            'breakdown' => [],
            'normalized_breakdown' => [],
            'is_compatible' => false,
        ];
    }

    /**
     * @return list<array{key: string, weight: int, meta: array<string, mixed>}>
     */
    private function getDimensionRules(): array
    {
        return Cache::remember(self::RULES_CACHE_KEY, self::RULES_CACHE_TTL_SECONDS, function () {
            return SystemRule::query()
                ->whereIn('key', self::DIMENSION_RULE_KEYS)
                ->orderBy('key')
                ->get()
                ->map(function (SystemRule $rule) {
                    return [
                        'key' => $rule->key,
                        'weight' => max(0, (int) $rule->value),
                        'meta' => is_array($rule->meta) ? $rule->meta : [],
                    ];
                })
                ->values()
                ->all();
        });
    }

    public static function forgetRulesCache(): void
    {
        Cache::forget(self::RULES_CACHE_KEY);
        Cache::forget(self::LEGACY_RULES_CACHE_KEY);
    }

    private function minimumScore(): int
    {
        $rule = SystemRule::query()->where('key', self::RULE_MATCHING_MINIMUM_SCORE)->first();
        if ($rule === null || $rule->value === '') {
            return 60;
        }

        return max(0, min(100, (int) $rule->value));
    }

    /**
     * @param  array{key: string, weight: int, meta: array<string, mixed>}  $rule
     */
    private function applyRule(array $rule, MatrimonyProfile $profileA, MatrimonyProfile $profileB): int
    {
        $class = $this->resolveHandlerClass($rule['key']);

        return app($class)->apply($profileA, $profileB, $rule);
    }

    /**
     * @return class-string
     */
    protected function resolveHandlerClass(string $key): string
    {
        return [
            self::RULE_MATCHING_AGE => AgeMatchRule::class,
            self::RULE_MATCHING_LOCATION => LocationMatchRule::class,
            self::RULE_MATCHING_EDUCATION => EducationMatchRule::class,
            self::RULE_MATCHING_CASTE => CasteMatchRule::class,
            self::RULE_MATCHING_PROFILE_COMPLETION => ProfileCompletionMatchRule::class,
        ][$key] ?? NullMatchRule::class;
    }

    private function grade(int $score): string
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
