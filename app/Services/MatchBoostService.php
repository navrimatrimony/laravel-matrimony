<?php

namespace App\Services;

use App\Models\MatchBoostSetting;
use App\Models\MatchingBoostRule;
use App\Models\MatrimonyProfile;
use App\Models\Plan;
use App\Models\ProfileKycSubmission;
use App\Models\User;
use App\Services\Matching\MatchBoostSettingDefaults;
use App\Services\Matching\MatchingConfigService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;

/**
 * Applies the admin-configured ranking adjustment layer on top of the base 0-100 compatibility score.
 *
 * The base score keeps its meaning (weighted field compatibility); this class only adds a bounded,
 * explainable delta describing how *usable* the candidate is to whoever is looking at the list.
 *
 * Signal families, in the order they survive the aggregate cap:
 *   1. trust / quality — KYC, photo, completeness, mobile verification, recency (candidate-intrinsic)
 *   2. pair signals    — occupation/location similarity, optional AI
 *   3. commercial tier — paid plan, gold/silver extra (trimmed first)
 *
 * Every weight lives in a `matching_boost_rules` row (admin screen: Matching engine → Boost rules).
 * Nothing here is a hardcoded constant; the legacy `match_boost_settings` singleton is used only as a
 * fallback when the rules table is absent/empty, and still owns the AI provider on/off switch.
 */
class MatchBoostService
{
    private const PAIR_CACHE_TTL_SECONDS = 86400;

    /** Quality signals change when the candidate edits their profile, so they expire far sooner. */
    private const CANDIDATE_CACHE_TTL_SECONDS = 600;

    private const CONFIG_CACHE_KEY = 'match_boost_config_v2';

    private const CONFIG_CACHE_TTL_SECONDS = 60;

    public const RULE_VERIFIED_KYC = 'verified_kyc';

    public const RULE_PHOTO = 'photo';

    public const RULE_COMPLETENESS = 'completeness';

    public const RULE_VERIFIED_MOBILE = 'verified_mobile';

    public const RULE_ACTIVE = 'active';

    public const RULE_SIMILARITY = 'similarity';

    public const RULE_AI = 'ai';

    public const RULE_PREMIUM = 'premium';

    public const RULE_GOLD_EXTRA = 'gold_extra';

    public const RULE_SILVER_EXTRA = 'silver_extra';

    public const RULE_AGGREGATE_CAP = 'aggregate_cap';

    /**
     * Priority order used both for reporting and for trimming against the aggregate cap.
     * Quality first, commercial tier last — a paid plan must never push a trust signal out of the cap.
     *
     * @var list<string>
     */
    private const SIGNAL_PRIORITY = [
        self::RULE_VERIFIED_KYC,
        self::RULE_PHOTO,
        self::RULE_COMPLETENESS,
        self::RULE_VERIFIED_MOBILE,
        self::RULE_ACTIVE,
        self::RULE_SIMILARITY,
        self::RULE_AI,
        self::RULE_PREMIUM,
        self::RULE_GOLD_EXTRA,
        self::RULE_SILVER_EXTRA,
    ];

    /** Per-process schema memo — these lookups hit information_schema and run per candidate otherwise. */
    private static ?bool $hasKycTable = null;

    private static ?bool $hasMobileVerifiedColumn = null;

    /**
     * Per-instance memo in front of the shared cache.
     *
     * `Cache::remember()` is not free: the production store is `database`, so every candidate cost a
     * `cache` table SELECT, and the key itself needs {@see self::configVersion()} — another cache read.
     * A single match request asks for the same candidate's signals repeatedly (ladder tiers, then the
     * Suchak fit pass), so the store was being asked hundreds of times for values that cannot change
     * mid-request. Same values, same cache semantics — just not re-fetched within one request.
     *
     * @var array<string, array<string, int>>
     */
    private array $qualityPointsMemo = [];

    /** @var array<string, array<string, int>> */
    private array $pairPointsMemo = [];

    /** @var array{version: string, ai_enabled: bool, rules: array<string, array{value: int, max_cap: int, is_active: bool, meta: array<string, mixed>}>}|null */
    private ?array $configMemo = null;

    public function __construct(
        protected AiBoostService $aiBoost,
        protected SubscriptionService $subscriptions,
        protected MatchingConfigService $matchingConfig,
        protected ProfileCompletionEngine $completion,
    ) {}

    public function forgetCache(): void
    {
        Cache::forget(self::CONFIG_CACHE_KEY);
        $this->configMemo = null;
        $this->qualityPointsMemo = [];
        $this->pairPointsMemo = [];
    }

    /**
     * @param  User  $userA  Seeker (viewer context)
     * @param  User  $userB  Candidate profile owner
     */
    public function applyBoost(User $userA, User $userB, int $baseScore): int
    {
        $baseScore = max(0, min(100, $baseScore));

        return max(0, min(100, $baseScore + $this->boostDelta($userA, $userB)));
    }

    /**
     * Total bounded adjustment for the pair (already capped).
     */
    public function boostDelta(User $userA, User $userB): int
    {
        $total = 0;
        foreach ($this->explainBoost($userA, $userB) as $signal) {
            $total += (int) $signal['points'];
        }

        return $total;
    }

    /**
     * Truthful, Suchak-facing "why is this candidate ranked here".
     *
     * The returned points always sum to exactly the delta {@see applyBoost()} applied — signals that
     * were trimmed by the aggregate cap are trimmed here too, so no reason is ever shown for points
     * that were not actually awarded.
     *
     * @return list<array{key: string, points: int, reason: string}>
     */
    public function explainBoost(User $userA, User $userB): array
    {
        $profileA = $userA->matrimonyProfile;
        $profileB = $userB->matrimonyProfile;
        if (! $profileA instanceof MatrimonyProfile || ! $profileB instanceof MatrimonyProfile) {
            return [];
        }

        $points = $this->candidateQualityPoints($profileB, $userB)
            + $this->pairPoints($profileA, $profileB, $userB);

        return $this->cappedSignals($points);
    }

    /**
     * Candidate-intrinsic quality adjustment only — no seeker/actor context involved.
     *
     * Exposed separately so surfaces that deliberately skip the actor-keyed layer (Suchak-initiated
     * pairings, where the represented candidate's dormant account has no behaviour of its own) can
     * still rank reachable/verified/complete candidates above empty ones.
     *
     * @return list<array{key: string, points: int, reason: string}>
     */
    public function explainCandidateQuality(MatrimonyProfile $profileB, ?User $userB = null): array
    {
        $userB ??= $profileB->user;

        return $this->cappedSignals($this->candidateQualityPoints($profileB, $userB));
    }

    public function candidateQualityDelta(MatrimonyProfile $profileB, ?User $userB = null): int
    {
        $total = 0;
        foreach ($this->explainCandidateQuality($profileB, $userB) as $signal) {
            $total += (int) $signal['points'];
        }

        return $total;
    }

    // ---------------------------------------------------------------------
    // Signal computation
    // ---------------------------------------------------------------------

    /**
     * Cached per candidate (not per pair) — these signals depend only on the candidate.
     *
     * @return array<string, int>
     */
    private function candidateQualityPoints(MatrimonyProfile $profileB, ?User $userB): array
    {
        $key = 'match_boost_quality:'.$profileB->getKey()
            .':'.$this->configVersion()
            .':'.(int) ($profileB->updated_at?->timestamp ?? 0);

        if (isset($this->qualityPointsMemo[$key])) {
            return $this->qualityPointsMemo[$key];
        }

        /** @var array<string, int> $cached */
        $cached = Cache::remember(
            $key,
            self::CANDIDATE_CACHE_TTL_SECONDS,
            fn (): array => $this->computeCandidateQualityPoints($profileB, $userB),
        );

        return $this->qualityPointsMemo[$key] = $cached;
    }

    /**
     * @return array<string, int>
     */
    private function computeCandidateQualityPoints(MatrimonyProfile $profileB, ?User $userB): array
    {
        return [
            self::RULE_VERIFIED_KYC => $this->kycPoints($profileB),
            self::RULE_PHOTO => $this->photoPoints($profileB),
            self::RULE_COMPLETENESS => $this->completenessPoints($profileB),
            self::RULE_VERIFIED_MOBILE => $this->mobileVerifiedPoints($userB),
            self::RULE_ACTIVE => $this->recencyPoints($profileB, $userB),
        ];
    }

    /**
     * Cached per pair — similarity and the optional AI call both depend on both profiles.
     *
     * @return array<string, int>
     */
    private function pairPoints(MatrimonyProfile $profileA, MatrimonyProfile $profileB, User $userB): array
    {
        $id1 = min((int) $profileA->getKey(), (int) $profileB->getKey());
        $id2 = max((int) $profileA->getKey(), (int) $profileB->getKey());
        $key = 'match_boost_pair:'.$id1.':'.$id2.':'.$this->configVersion();

        /** @var array<string, int> $cached */
        $cached = $this->pairPointsMemo[$key] ??= Cache::remember(
            $key,
            self::PAIR_CACHE_TTL_SECONDS,
            fn (): array => [
                self::RULE_SIMILARITY => $this->profilesSimilarityHit($profileA, $profileB)
                    ? $this->ruleValue(self::RULE_SIMILARITY)
                    : 0,
                self::RULE_AI => $this->aiPoints($profileA, $profileB),
            ],
        );

        // Tier depends on the candidate's live subscription state, which must not be pair-cached for a day.
        return $cached + $this->tierPoints($userB);
    }

    private function photoPoints(MatrimonyProfile $profileB): int
    {
        return $profileB->hasApprovedPublicPhoto() ? $this->ruleValue(self::RULE_PHOTO) : 0;
    }

    /**
     * Graded on the existing completion SSOT ({@see ProfileCompletionEngine}); nothing is recomputed here.
     */
    private function completenessPoints(MatrimonyProfile $profileB): int
    {
        $value = $this->ruleValue(self::RULE_COMPLETENESS);
        if ($value <= 0) {
            return 0;
        }

        $meta = $this->ruleMeta(self::RULE_COMPLETENESS);
        $floor = max(0, min(99, (int) ($meta['min_percent'] ?? 50)));

        try {
            $score = (int) ($this->completion->forProfile($profileB)['score'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }

        if ($score <= $floor) {
            return 0;
        }

        $fraction = ($score - $floor) / (100 - $floor);

        return max(0, min($value, (int) round($value * $fraction)));
    }

    private function mobileVerifiedPoints(?User $userB): int
    {
        if (! $userB instanceof User) {
            return 0;
        }

        self::$hasMobileVerifiedColumn ??= Schema::hasColumn('users', 'mobile_verified_at');
        if (self::$hasMobileVerifiedColumn !== true) {
            return 0;
        }

        return $userB->mobile_verified_at !== null ? $this->ruleValue(self::RULE_VERIFIED_MOBILE) : 0;
    }

    private function kycPoints(MatrimonyProfile $profileB): int
    {
        $value = $this->ruleValue(self::RULE_VERIFIED_KYC);
        self::$hasKycTable ??= Schema::hasTable('profile_kyc_submissions');
        if ($value <= 0 || self::$hasKycTable !== true) {
            return 0;
        }

        $approved = ProfileKycSubmission::query()
            ->where('matrimony_profile_id', $profileB->getKey())
            ->where('status', ProfileKycSubmission::STATUS_APPROVED)
            ->exists();

        return $approved ? $value : 0;
    }

    /**
     * Full value inside `active_within_days`, then a linear decay to zero at `stale_after_days`.
     * An abandoned profile therefore earns nothing rather than being penalised below its base score.
     */
    private function recencyPoints(MatrimonyProfile $profileB, ?User $userB): int
    {
        $value = $this->ruleValue(self::RULE_ACTIVE);
        if ($value <= 0) {
            return 0;
        }

        $at = $this->lastActivityAt($profileB, $userB);
        if (! $at instanceof CarbonInterface) {
            return 0;
        }

        $meta = $this->ruleMeta(self::RULE_ACTIVE);
        $fresh = max(1, (int) ($meta['active_within_days'] ?? 7));
        $stale = max($fresh + 1, (int) ($meta['stale_after_days'] ?? 180));

        $days = (int) floor(abs(now()->diffInDays($at)));
        if ($days <= $fresh) {
            return $value;
        }
        if ($days >= $stale) {
            return 0;
        }

        $remaining = 1.0 - (($days - $fresh) / ($stale - $fresh));

        return max(0, min($value, (int) round($value * $remaining)));
    }

    /**
     * `users.last_seen_at` is the real activity signal (refreshed by {@see \App\Http\Middleware\UpdateUserLastSeen}),
     * but Suchak-created accounts are dormant and never produce one — for those the profile's own
     * `updated_at` is the only truthful "someone is still working this candidate" timestamp, and it is
     * already what the engine's FRESH tab ranks on. The later of the two wins.
     */
    private function lastActivityAt(MatrimonyProfile $profileB, ?User $userB): ?CarbonInterface
    {
        $seen = $userB instanceof User ? $userB->last_seen_at : null;
        $touched = $profileB->updated_at;

        if ($seen === null) {
            return $touched;
        }
        if ($touched === null) {
            return $seen;
        }

        return $seen->greaterThan($touched) ? $seen : $touched;
    }

    /**
     * @return array<string, int>
     */
    private function tierPoints(User $userB): array
    {
        $empty = [self::RULE_PREMIUM => 0, self::RULE_GOLD_EXTRA => 0, self::RULE_SILVER_EXTRA => 0];

        if ($userB->isAnyAdmin()) {
            return $empty;
        }

        $plan = $this->subscriptions->getEffectivePlan($userB);
        $slug = strtolower(trim((string) ($plan->slug ?? '')));
        if ($slug === '' || Plan::isFreeCatalogSlug($slug)) {
            return $empty;
        }

        $out = $empty;
        $out[self::RULE_PREMIUM] = $this->ruleValue(self::RULE_PREMIUM);
        if (str_contains($slug, 'gold')) {
            $out[self::RULE_GOLD_EXTRA] = $this->ruleValue(self::RULE_GOLD_EXTRA);
        } elseif (str_contains($slug, 'silver')) {
            $out[self::RULE_SILVER_EXTRA] = $this->ruleValue(self::RULE_SILVER_EXTRA);
        }

        return $out;
    }

    private function aiPoints(MatrimonyProfile $profileA, MatrimonyProfile $profileB): int
    {
        // Gate is unchanged from the legacy behaviour: admin → Match boost (use_ai + Sarvam provider).
        if (! ($this->config()['ai_enabled'] ?? false)) {
            return 0;
        }

        $score = $this->aiBoost->getBoostScore($profileA, $profileB);
        $cap = $this->ruleCap(self::RULE_AI, 20);

        return max(0, min($cap, $score));
    }

    // ---------------------------------------------------------------------
    // Capping + explanation
    // ---------------------------------------------------------------------

    /**
     * Trims the raw signal points to the aggregate cap in {@see SIGNAL_PRIORITY} order, so the
     * surviving reasons always add up to the delta that was actually applied.
     *
     * @param  array<string, int>  $points
     * @return list<array{key: string, points: int, reason: string}>
     */
    private function cappedSignals(array $points): array
    {
        $cap = max(0, $this->aggregateCap());
        $out = [];
        $used = 0;

        foreach (self::SIGNAL_PRIORITY as $key) {
            if ($used >= $cap) {
                break;
            }
            $awarded = max(0, (int) ($points[$key] ?? 0));
            if ($awarded === 0) {
                continue;
            }
            $awarded = min($awarded, $cap - $used);
            $used += $awarded;
            $out[] = [
                'key' => $key,
                'points' => $awarded,
                'reason' => $this->reasonFor($key),
            ];
        }

        return $out;
    }

    /**
     * Localised when a `matching_engine.boost_reason_*` string exists; otherwise a plain, truthful
     * English fallback so a missing translation never produces a raw lang key on a Suchak screen.
     */
    private function reasonFor(string $key): string
    {
        $langKey = 'matching_engine.boost_reason_'.$key;
        if (Lang::has($langKey)) {
            return (string) __($langKey);
        }

        return match ($key) {
            self::RULE_VERIFIED_KYC => 'ID document verified',
            self::RULE_PHOTO => 'Has an approved photo',
            self::RULE_COMPLETENESS => 'Profile is well filled in',
            self::RULE_VERIFIED_MOBILE => 'Mobile number verified',
            self::RULE_ACTIVE => 'Recently active',
            self::RULE_SIMILARITY => 'Shares work or location',
            self::RULE_AI => 'AI compatibility signal',
            self::RULE_PREMIUM, self::RULE_GOLD_EXTRA, self::RULE_SILVER_EXTRA => 'Paid plan member',
            default => $key,
        };
    }

    // ---------------------------------------------------------------------
    // Config resolution (matching_boost_rules first, legacy singleton as fallback)
    // ---------------------------------------------------------------------

    /**
     * @return array{version: string, ai_enabled: bool, rules: array<string, array{value: int, max_cap: int, is_active: bool, meta: array<string, mixed>}>}
     */
    private function config(): array
    {
        if ($this->configMemo !== null) {
            return $this->configMemo;
        }

        /** @var array{version: string, ai_enabled: bool, rules: array<string, array{value: int, max_cap: int, is_active: bool, meta: array<string, mixed>}>} $cfg */
        $cfg = Cache::remember(self::CONFIG_CACHE_KEY, self::CONFIG_CACHE_TTL_SECONDS, function (): array {
            $rules = [];
            try {
                $rules = $this->matchingConfig->getBoostRules();
            } catch (\Throwable) {
                $rules = [];
            }

            $legacy = $this->legacySettings();

            if ($rules === []) {
                $rules = $this->legacyRules($legacy);
            }

            $normalised = [];
            foreach ($rules as $type => $row) {
                $normalised[(string) $type] = [
                    'value' => (int) ($row['value'] ?? 0),
                    'max_cap' => (int) ($row['max_cap'] ?? 100),
                    'is_active' => (bool) ($row['is_active'] ?? true),
                    'meta' => is_array($row['meta'] ?? null) ? $row['meta'] : [],
                ];
            }

            return [
                'version' => $this->computeVersion($legacy),
                // The legacy singleton stays the documented AI on/off switch (admin → Match boost).
                'ai_enabled' => $legacy !== null
                    && (bool) $legacy->use_ai
                    && strtolower((string) ($legacy->ai_provider ?? '')) === 'sarvam',
                'rules' => $normalised,
            ];
        });

        return $this->configMemo = $cfg;
    }

    private function legacySettings(): ?MatchBoostSetting
    {
        if (! Schema::hasTable('match_boost_settings')) {
            return null;
        }

        return MatchBoostSetting::query()->orderBy('id')->first();
    }

    /**
     * Shape-compatible fallback used only when `matching_boost_rules` is absent or empty.
     * Quality weights fall back to the shipped defaults; legacy-owned weights use the singleton.
     *
     * @return array<string, array{value: int, max_cap: int, is_active: bool, meta: array<string, mixed>}>
     */
    private function legacyRules(?MatchBoostSetting $legacy): array
    {
        $out = [];
        foreach (MatchBoostSettingDefaults::snapshot() as $row) {
            $out[(string) $row['boost_type']] = [
                'value' => (int) $row['value'],
                'max_cap' => (int) $row['max_cap'],
                'is_active' => (bool) $row['is_active'],
                'meta' => is_array($row['meta']) ? $row['meta'] : [],
            ];
        }

        if ($legacy === null) {
            return $out;
        }

        $out[self::RULE_ACTIVE]['value'] = (int) $legacy->boost_active_weight;
        $out[self::RULE_ACTIVE]['meta']['active_within_days'] = max(1, (int) $legacy->active_within_days);
        $out[self::RULE_PREMIUM]['value'] = (int) $legacy->boost_premium_weight;
        $out[self::RULE_GOLD_EXTRA]['value'] = (int) $legacy->boost_gold_extra;
        $out[self::RULE_SILVER_EXTRA]['value'] = (int) $legacy->boost_silver_extra;
        $out[self::RULE_SIMILARITY]['value'] = (int) $legacy->boost_similarity_weight;
        $out[self::RULE_AGGREGATE_CAP]['max_cap'] = max(0, (int) $legacy->max_boost_limit);

        return $out;
    }

    private function computeVersion(?MatchBoostSetting $legacy): string
    {
        $parts = [(string) ($legacy?->updated_at?->timestamp ?? '0')];

        if (Schema::hasTable('matching_boost_rules')) {
            $parts[] = (string) (MatchingBoostRule::query()->max('updated_at') ?? '0');
            $parts[] = (string) MatchingBoostRule::query()->count();
        }

        return substr(md5(implode('|', $parts)), 0, 12);
    }

    private function configVersion(): string
    {
        return (string) ($this->config()['version'] ?? '0');
    }

    /**
     * @return array{value: int, max_cap: int, is_active: bool, meta: array<string, mixed>}|null
     */
    private function rule(string $type): ?array
    {
        return $this->config()['rules'][$type] ?? null;
    }

    /**
     * Effective weight for a rule: 0 when inactive/missing, otherwise the value clamped by its own cap.
     */
    private function ruleValue(string $type): int
    {
        $row = $this->rule($type);
        if ($row === null || ! $row['is_active']) {
            return 0;
        }

        return max(0, min(max(0, $row['max_cap']), $row['value']));
    }

    private function ruleCap(string $type, int $fallback): int
    {
        $row = $this->rule($type);

        return $row === null ? $fallback : max(0, $row['max_cap']);
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleMeta(string $type): array
    {
        return $this->rule($type)['meta'] ?? [];
    }

    private function aggregateCap(): int
    {
        $row = $this->rule(self::RULE_AGGREGATE_CAP);
        if ($row === null) {
            return 25;
        }
        if (! $row['is_active']) {
            return 0;
        }

        return max(0, $row['max_cap']);
    }

    // ---------------------------------------------------------------------

    private function profilesSimilarityHit(MatrimonyProfile $a, MatrimonyProfile $b): bool
    {
        $tbl = $a->getTable();
        $hasProfFk = Schema::hasColumn($tbl, 'profession_id');
        $a->loadMissing(['occupationMaster', 'occupationCustom', 'occupationMaster.category.workingWithType']);
        $b->loadMissing(['occupationMaster', 'occupationCustom', 'occupationMaster.category.workingWithType']);

        $midA = (int) ($a->occupation_master_id ?? 0);
        $midB = (int) ($b->occupation_master_id ?? 0);
        if ($midA > 0 && $midA === $midB) {
            return true;
        }
        $cidA = (int) ($a->occupation_custom_id ?? 0);
        $cidB = (int) ($b->occupation_custom_id ?? 0);
        if ($cidA > 0 && $cidA === $cidB) {
            return true;
        }

        $pA = $hasProfFk ? (int) ($a->getAttribute('profession_id') ?: 0) : (int) ($a->resolvedProfession()?->id ?? 0);
        $pB = $hasProfFk ? (int) ($b->getAttribute('profession_id') ?: 0) : (int) ($b->resolvedProfession()?->id ?? 0);
        if ($pA > 0 && $pA === $pB) {
            return true;
        }

        $cA = (int) ($a->location_id ?? 0);
        $cB = (int) ($b->location_id ?? 0);
        if ($cA > 0 && $cA === $cB) {
            return true;
        }

        $sA = (int) ($a->state_id ?? 0);
        $sB = (int) ($b->state_id ?? 0);

        return $sA > 0 && $sA === $sB;
    }
}
