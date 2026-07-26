<?php

namespace App\Services\Matching;

use App\Models\EducationDegree;
use App\Models\Interest;
use App\Models\MatchingHardFilter;
use App\Models\MatrimonyProfile;
use App\Models\ProfileMatch;
use App\Models\ProfileView;
use App\Services\EducationService;
use App\Services\MatchBoostService;
use App\Services\ProfilePreferenceMatchService;
use App\Support\MarriageAgePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scores compatible profiles for a seeker. Uses partner-preference rules from ProfilePreferenceMatchService
 * (mutual "no hard not_matched" gate) plus explainable attribute-based scoring.
 */
class MatchingService
{
    public function __construct(
        protected MatchBoostService $matchBoost,
        protected MatchingConfigService $matchingConfig,
        protected MatchingBehaviorScoringService $behaviorScoring,
    ) {}

    public const WEIGHT_AGE = 20;

    public const WEIGHT_LOCATION = 15;

    public const WEIGHT_EDUCATION = 15;

    public const WEIGHT_OCCUPATION = 10;

    public const WEIGHT_COMMUNITY = 20;

    public const WEIGHT_PREFERENCES = 20;

    public const TAB_PERFECT = 'perfect';

    public const TAB_DAILY = 'daily';

    public const TAB_NEAR = 'near';

    public const TAB_FRESH = 'fresh';

    public const TAB_VIEWED = 'viewed';

    public const TAB_INTERESTED = 'interested';

    public const TAB_SECOND_CHANCE = 'second_chance';

    public const TAB_CURATED = 'curated';

    /**
     * @return list<string>
     */
    public static function validTabs(): array
    {
        return [
            self::TAB_PERFECT,
            self::TAB_DAILY,
            self::TAB_NEAR,
            self::TAB_FRESH,
            self::TAB_SECOND_CHANCE,
            self::TAB_CURATED,
        ];
    }

    public static function normalizeTab(?string $tab): string
    {
        $t = strtolower(trim((string) $tab));

        return in_array($t, self::validTabs(), true) ? $t : self::TAB_PERFECT;
    }

    /** @var array<string, array<string, mixed>> */
    private array $prefMap = [];

    /** @var array<string, array<string, mixed>> */
    private array $directionalBuildCache = [];

    /** @var array<string, array<string, mixed>> */
    private array $componentsCache = [];

    /** @var array<int, list<int>> */
    private array $seekerViewedIdsCache = [];

    /** @var array<int, array<string, mixed>> */
    private array $communityLockCache = [];

    /**
     * Tier currently being evaluated by the relaxation ladder. Read by
     * {@see self::applyBaseCandidateFilters()} and {@see self::mutuallyPreferenceCompatible()} so the
     * five tab-specific candidate builders keep their signatures.
     */
    private int $activeTier = MatchRelaxationLadder::TIER_STRICT;

    /**
     * Outcome of the last {@see self::findMatchesForTab()} run — see {@see self::lastRelaxationSummary()}.
     *
     * @var array<string, mixed>
     */
    private array $lastRelaxation = [
        'tier' => MatchRelaxationLadder::TIER_STRICT,
        'relaxed_fields' => [],
        'floor' => 0,
        'matched' => 0,
        'floor_reached' => true,
    ];

    /**
     * What the last run had to loosen before it found enough candidates.
     *
     * `tier` is the highest tier reached, `relaxed_fields` the preference fields that stopped
     * excluding, `floor` the configured minimum and `floor_reached` whether the ladder actually got
     * there (false means even the top tier came up short). Every returned match row also carries the
     * `tier` it was admitted at, so a strict match is still distinguishable inside a relaxed run.
     *
     * @return array{tier: int, relaxed_fields: list<string>, floor: int, matched: int, floor_reached: bool}
     */
    public function lastRelaxationSummary(): array
    {
        /** @var array{tier: int, relaxed_fields: list<string>, floor: int, matched: int, floor_reached: bool} $summary */
        $summary = $this->lastRelaxation;

        return $summary;
    }

    /**
     * Active candidate universe. Null means the historical member-only pool; it is only ever set for
     * the duration of a {@see self::findMatchesForPool()} call, so member surfaces are unaffected.
     */
    private ?CandidatePoolStrategy $candidatePool = null;

    private function pool(): CandidatePoolStrategy
    {
        return $this->candidatePool ?? CandidatePoolStrategy::members();
    }

    /**
     * Runs the exact same engine against a different candidate universe (see {@see CandidatePoolStrategy}).
     * Members never reach this method — {@see findMatches()} / {@see findMatchesForTab()} keep the default pool.
     *
     * @return Collection<int, array{profile: MatrimonyProfile, score: int, base_score: int, reasons: list<string>, explain?: list<array{reason: string, impact: int}>}>
     */
    public function findMatchesForPool(
        MatrimonyProfile $profile,
        CandidatePoolStrategy $pool,
        string $tab = self::TAB_PERFECT,
        int $limit = 24,
        bool $withExplain = false,
    ): Collection {
        $previous = $this->candidatePool;
        $this->candidatePool = $pool;

        try {
            return $this->findMatchesForTab($profile, $tab, $limit, $withExplain);
        } finally {
            $this->candidatePool = $previous;
        }
    }

    /**
     * Public twin of the in-pipeline gate: opposite gender plus the mutual partner-preference rule
     * (no hard `not_matched` in either direction). Lets non-feed callers — the Suchak surfaces — reuse
     * the engine's eligibility decision instead of re-deriving one.
     */
    public function isEligiblePair(MatrimonyProfile $seeker, MatrimonyProfile $candidate): bool
    {
        if ((int) $seeker->getKey() === (int) $candidate->getKey()) {
            return false;
        }

        $seeker->loadMissing('gender');
        $candidate->loadMissing('gender');

        $opposite = $this->oppositeGenderKey($seeker);
        if ($opposite === null || $candidate->gender?->key !== $opposite) {
            return false;
        }

        $savedPref = $this->prefMap;
        $savedDir = $this->directionalBuildCache;
        $savedTier = $this->activeTier;

        try {
            $this->directionalBuildCache = [];
            // A standalone eligibility question is always asked at the strict tier.
            $this->activeTier = MatchRelaxationLadder::TIER_STRICT;
            ProfilePreferenceMatchService::flushRuntimeCaches();
            $this->prefMap = $this->bulkLoadTargetPreferences([(int) $seeker->getKey(), (int) $candidate->getKey()]);

            return $this->mutuallyPreferenceCompatible($seeker, $candidate);
        } finally {
            $this->prefMap = $savedPref;
            $this->directionalBuildCache = $savedDir;
            $this->activeTier = $savedTier;
        }
    }

    /**
     * @param  bool  $withExplain  When true, each row includes an `explain` array (admin preview / JSON API).
     * @return Collection<int, array{profile: MatrimonyProfile, score: int, base_score: int, reasons: list<string>, explain?: list<array{reason: string, impact: int}>}>
     */
    public function findMatches(MatrimonyProfile $profile, int $limit = 20, bool $withExplain = false): Collection
    {
        return $this->findMatchesForTab($profile, self::TAB_PERFECT, $limit, $withExplain);
    }

    /**
     * Tab-specific pools and ordering: Perfect (new-to-you), Daily (date shuffle), Near (local pool),
     * Fresh (recently updated), Second chance (viewed, no interest), Curated (boost lift). Viewed profiles
     * sink to the bottom on every tab except Second chance.
     *
     * @param  bool  $withExplain  When true, each row includes `explain` from {@see MatchingExplainService}.
     * @return Collection<int, array{profile: MatrimonyProfile, score: int, base_score: int, reasons: list<string>, explain?: list<array{reason: string, impact: int}>}>
     */
    public function findMatchesForTab(MatrimonyProfile $profile, string $tab, int $limit = 24, bool $withExplain = false): Collection
    {
        $tab = self::normalizeTab($tab);
        $this->prefMap = [];
        $this->directionalBuildCache = [];
        $this->componentsCache = [];
        $this->communityLockCache = [];
        $this->activeTier = MatchRelaxationLadder::TIER_STRICT;
        ProfilePreferenceMatchService::flushRuntimeCaches();

        $profile->loadMissing([
            'gender', 'preferenceCriteria',
            'religion', 'caste', 'subCaste',
            'occupationMaster.category.workingWithType', 'occupationCustom',
            'country', 'state', 'district', 'city',
            'user',
        ]);

        $floor = MatchRelaxationLadder::floor();
        $this->lastRelaxation = [
            'tier' => MatchRelaxationLadder::TIER_STRICT,
            'relaxed_fields' => [],
            'floor' => $floor,
            'matched' => 0,
            'floor_reached' => false,
        ];

        $oppositeKey = $this->oppositeGenderKey($profile);
        if ($oppositeKey === null) {
            return collect();
        }

        $poolLimit = max(1, (int) config('matching.candidate_pool_limit', 200));

        // Tiered relaxation ladder (PO-approved 2026-07-26). Run the tiers in order; stop at the FIRST
        // tier whose surviving count reaches the floor. Rows keep the tier they were admitted at, so a
        // strict match stays identifiable inside a relaxed run.
        $out = collect();
        $seen = [];
        $highestTier = MatchRelaxationLadder::TIER_STRICT;

        foreach (MatchRelaxationLadder::tiers() as $tier) {
            $highestTier = $tier;
            $this->activeTier = $tier;

            foreach ($this->collectTierRows($profile, $tab, $oppositeKey, $poolLimit, $withExplain) as $row) {
                $pid = (int) $row['profile']->getKey();
                if (isset($seen[$pid])) {
                    continue;
                }
                $seen[$pid] = true;
                $row['tier'] = $tier;
                $out->push($row);
            }

            if ($out->count() >= $floor) {
                break;
            }
        }

        $this->activeTier = MatchRelaxationLadder::TIER_STRICT;
        $this->lastRelaxation = [
            'tier' => $highestTier,
            'relaxed_fields' => MatchRelaxationLadder::relaxedFieldsUpTo($highestTier),
            'floor' => $floor,
            'matched' => $out->count(),
            'floor_reached' => $out->count() >= $floor,
        ];

        if ($tab === self::TAB_CURATED && $out->isNotEmpty()) {
            $boosted = $out->filter(static fn (array $r): bool => (int) ($r['score'] ?? 0) > (int) ($r['base_score'] ?? 0));
            if ($boosted->count() >= min(8, max(1, (int) ceil($limit / 2)))) {
                $out = $boosted->values();
            }
        }

        $sorted = $this->applyTabOrdering($out, $tab, $profile)->values();
        $sorted = $this->dedupeMatchRowsByPerson($sorted)->take($limit)->values();
        $this->lastRelaxation['matched'] = $sorted->count();

        // Only the member pool owns the persisted cache; a Suchak-scoped run must never overwrite it.
        if ($tab === self::TAB_PERFECT && $this->pool()->isMembers() && config('matching.persist_cache', false) && Schema::hasTable('profile_matches')) {
            $this->replacePersistedMatches($profile, $sorted);
        }

        return $sorted;
    }

    /**
     * One pass of the ladder at {@see self::$activeTier}. Identical to the historical single-pass body;
     * only the tolerated-mismatch set differs per tier.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function collectTierRows(
        MatrimonyProfile $profile,
        string $tab,
        string $oppositeKey,
        int $poolLimit,
        bool $withExplain,
    ): Collection {
        $this->directionalBuildCache = [];
        $this->componentsCache = [];

        $candidates = match ($tab) {
            self::TAB_VIEWED => $this->candidatesWhoViewedMe($profile, $oppositeKey),
            self::TAB_INTERESTED => $this->candidatesInterestedInMe($profile, $oppositeKey),
            self::TAB_SECOND_CHANCE => $this->candidatesSecondChance($profile, $oppositeKey),
            self::TAB_PERFECT => $this->candidatesPerfectForYou($profile, $oppositeKey, $poolLimit),
            self::TAB_NEAR => $this->candidatesNearMe($profile, $oppositeKey, $poolLimit),
            default => $this->baseCandidateQuery($profile, $oppositeKey)
                ->limit($poolLimit)
                ->get(),
        };

        if ($tab === self::TAB_FRESH) {
            $since = now()->subDays(14);
            $candidates = $candidates->filter(function (MatrimonyProfile $c) use ($since) {
                return $c->updated_at !== null && $c->updated_at->greaterThanOrEqualTo($since);
            })->values();
        }

        $candidates = $candidates
            ->unique(fn (MatrimonyProfile $c) => (int) $c->getKey())
            ->values();

        $this->eagerLoadMatchingRelations($candidates);

        // NEVER RELAXED at any tier: an explicitly rejected pair stays rejected.
        $skipExcluded = $this->candidateIdsExcludedBySkips((int) $profile->id);

        $ids = $candidates->pluck('id')->push($profile->id)->unique()->values()->all();
        $this->prefMap = $this->bulkLoadTargetPreferences($ids);

        $out = collect();
        foreach ($candidates as $candidate) {
            if (in_array((int) $candidate->id, $skipExcluded, true)) {
                continue;
            }
            if (! $this->mutuallyPreferenceCompatible($profile, $candidate)) {
                continue;
            }

            $baseScore = $this->calculateScore($profile, $candidate);
            $seekerUser = $profile->user;
            $candidateUser = $candidate->user;
            $withActorAdjustments = $this->pool()->appliesActorAdjustments();
            if ($withActorAdjustments) {
                $score = ($seekerUser && $candidateUser)
                    ? $this->matchBoost->applyBoost($seekerUser, $candidateUser, $baseScore)
                    : $baseScore;
                if ($seekerUser && $candidateUser) {
                    $score = max(0, min(100, $score + $this->behaviorScoring->scoreAdjustment($seekerUser, $candidate)));
                }
            } else {
                // The ACTOR layer (pair boost + behaviour) stays off — see
                // {@see CandidatePoolStrategy::appliesActorAdjustments()}. Candidate QUALITY is a
                // different thing entirely: verification, photo, completeness and recency belong to the
                // candidate alone and say nothing about who is looking. Dropping them made a Suchak
                // rank an empty, unverified card level with a complete, KYC-verified one.
                $score = max(0, min(100, $baseScore + $this->matchBoost->candidateQualityDelta($candidate, $candidateUser)));
            }
            $reasons = $this->explainScore($profile, $candidate);

            $row = [
                'profile' => $candidate,
                'score' => $score,
                'base_score' => $baseScore,
                'reasons' => $reasons,
                // Tolerated near-misses: shown to the seeker instead of silently deleting the candidate.
                'warnings' => $this->toleratedMismatchWarnings($profile, $candidate),
                'assumed_fields' => $this->assumedPreferenceFields($profile, $candidate),
            ];
            if ($withExplain) {
                $row['explain'] = app(MatchingExplainService::class)->explainPair($profile, $candidate);
            }
            $out->push($row);
        }

        return $out;
    }

    /**
     * One row per profile id, per member account (user_id), and per “surface clone” (same display identity).
     * Intake/showcase data often creates many active rows with the same Marathi full_name and DOB under different
     * logins; they are indistinguishable in the card UI and must not flood the feed. Ordering is preserved so
     * the first row wins (best tab rank). Tabs still recompute independently — the same person may appear in
     * more than one lens when pools differ; this only collapses duplicates within one tab response.
     *
     * @param  Collection<int, array{profile: MatrimonyProfile, score: int, base_score: int, reasons: list<string>, explain?: list<array{reason: string, impact: int}>}>  $rows
     * @return Collection<int, array{profile: MatrimonyProfile, score: int, base_score: int, reasons: list<string>, explain?: list<array{reason: string, impact: int}>}>
     */
    private function dedupeMatchRowsByPerson(Collection $rows): Collection
    {
        $seenProfileIds = [];
        $seenUserIds = [];
        $seenSurfaceFingerprints = [];
        $out = collect();

        foreach ($rows as $row) {
            $p = $row['profile'];
            $pid = (int) $p->getKey();
            if (isset($seenProfileIds[$pid])) {
                continue;
            }
            $seenProfileIds[$pid] = true;

            $uid = (int) ($p->user_id ?? 0);
            if ($uid > 0) {
                if (isset($seenUserIds[$uid])) {
                    continue;
                }
                $seenUserIds[$uid] = true;
            }

            $fp = $this->matchSurfaceFingerprint($p);
            if ($fp !== null) {
                if (isset($seenSurfaceFingerprints[$fp])) {
                    continue;
                }
                $seenSurfaceFingerprints[$fp] = true;
            }

            $out->push($row);
        }

        return $out;
    }

    /**
     * Groups rows that would look like the same person in the match card (name + gender + DOB + coarse location).
     */
    private function matchSurfaceFingerprint(MatrimonyProfile $p): ?string
    {
        $name = trim((string) ($p->full_name ?? ''));
        if ($name === '') {
            return null;
        }

        $norm = mb_strtolower(preg_replace('/\h+/u', ' ', $name), 'UTF-8');
        $dobRaw = $p->date_of_birth;
        if ($dobRaw instanceof \DateTimeInterface) {
            $dob = $dobRaw->format('Y-m-d');
        } elseif (is_string($dobRaw) && $dobRaw !== '') {
            $dob = substr($dobRaw, 0, 10);
        } else {
            $dob = '';
        }

        return implode('|', [
            $norm,
            (string) (int) ($p->gender_id ?? 0),
            $dob,
            (string) (int) ($p->city_id ?? 0),
            (string) (int) ($p->state_id ?? 0),
        ]);
    }

    /**
     * @return Collection<int, MatrimonyProfile>
     */
    private function candidatesWhoViewedMe(MatrimonyProfile $profile, string $oppositeGenderKey): Collection
    {
        if (! Schema::hasTable('profile_views')) {
            return collect();
        }

        $orderedViewerIds = [];
        $seen = [];
        $rows = ProfileView::query()
            ->where('viewed_profile_id', $profile->id)
            ->orderByDesc('id')
            ->limit(400)
            ->get(['viewer_profile_id']);
        foreach ($rows as $row) {
            $vid = (int) $row->viewer_profile_id;
            if ($vid <= 0 || isset($seen[$vid])) {
                continue;
            }
            $seen[$vid] = true;
            $orderedViewerIds[] = $vid;
        }

        if ($orderedViewerIds === []) {
            return collect();
        }

        $q = MatrimonyProfile::query()->whereIn('id', $orderedViewerIds);
        $this->applyBaseCandidateFilters($q, $profile, $oppositeGenderKey);

        return $q->get()->sortBy(function (MatrimonyProfile $p) use ($orderedViewerIds) {
            $i = array_search((int) $p->id, $orderedViewerIds, true);

            return $i === false ? 999999 : $i;
        })->values();
    }

    /**
     * @return Collection<int, MatrimonyProfile>
     */
    private function candidatesInterestedInMe(MatrimonyProfile $profile, string $oppositeGenderKey): Collection
    {
        if (! Schema::hasTable('interests')) {
            return collect();
        }

        $senderIds = Interest::query()
            ->where('receiver_profile_id', $profile->id)
            ->where('status', 'pending')
            ->orderByDesc('priority_score')
            ->orderByDesc('created_at')
            ->pluck('sender_profile_id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($senderIds === []) {
            return collect();
        }

        $q = MatrimonyProfile::query()->whereIn('id', $senderIds);
        $this->applyBaseCandidateFilters($q, $profile, $oppositeGenderKey);

        return $q->get()->sortBy(function (MatrimonyProfile $p) use ($senderIds) {
            $i = array_search((int) $p->id, $senderIds, true);

            return $i === false ? 999999 : $i;
        })->values();
    }

    /**
     * Profiles you opened but never sent interest to (re-surface after you passed them once).
     *
     * @return Collection<int, MatrimonyProfile>
     */
    /**
     * Viewed by seeker but interest not sent — belongs on Second chance, not Perfect.
     *
     * @return list<int>
     */
    private function secondChanceCandidateIds(MatrimonyProfile $profile): array
    {
        if (! Schema::hasTable('profile_views')) {
            return [];
        }

        $viewedIds = ProfileView::query()
            ->where('viewer_profile_id', $profile->id)
            ->orderByDesc('id')
            ->limit(500)
            ->pluck('viewed_profile_id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($viewedIds === []) {
            return [];
        }

        if (! Schema::hasTable('interests')) {
            return $viewedIds;
        }

        $messaged = Interest::query()
            ->where('sender_profile_id', $profile->id)
            ->whereIn('receiver_profile_id', $viewedIds)
            ->pluck('receiver_profile_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        return array_values(array_diff($viewedIds, $messaged));
    }

    /**
     * @return Collection<int, MatrimonyProfile>
     */
    private function candidatesSecondChance(MatrimonyProfile $profile, string $oppositeGenderKey): Collection
    {
        $candidateIds = $this->secondChanceCandidateIds($profile);
        if ($candidateIds === []) {
            return collect();
        }

        $q = MatrimonyProfile::query()->whereIn('id', $candidateIds);
        $this->applyBaseCandidateFilters($q, $profile, $oppositeGenderKey);

        return $q->get()->sortByDesc('updated_at')->values();
    }

    /**
     * Best new matches: full pool minus profiles already opened without interest.
     *
     * @return Collection<int, MatrimonyProfile>
     */
    private function candidatesPerfectForYou(MatrimonyProfile $profile, string $oppositeGenderKey, int $poolLimit): Collection
    {
        $all = $this->baseCandidateQuery($profile, $oppositeGenderKey)
            ->limit($poolLimit)
            ->get();

        $secondChance = array_flip($this->secondChanceCandidateIds($profile));
        if ($secondChance === []) {
            return $all;
        }

        return $all->filter(fn (MatrimonyProfile $c): bool => ! isset($secondChance[(int) $c->id]))->values();
    }

    /**
     * Same-state / same-city candidates first; widen pool only when too few local matches.
     *
     * @return Collection<int, MatrimonyProfile>
     */
    private function candidatesNearMe(MatrimonyProfile $profile, string $oppositeGenderKey, int $poolLimit): Collection
    {
        $all = $this->baseCandidateQuery($profile, $oppositeGenderKey)
            ->limit($poolLimit)
            ->get();

        $near = $all->filter(fn (MatrimonyProfile $c): bool => $this->locationProximityTier($profile, $c) >= 1)->values();
        if ($near->count() >= min(12, max(1, (int) ceil($poolLimit / 4)))) {
            return $near;
        }

        $nearIds = $near->pluck('id')->map(fn ($id) => (int) $id)->all();
        $rest = $all->filter(fn (MatrimonyProfile $c): bool => ! in_array((int) $c->id, $nearIds, true))->values();

        return $near->concat($rest)->values();
    }

    /**
     * @return list<int>
     */
    private function seekerViewedCandidateIds(int $seekerProfileId): array
    {
        if (isset($this->seekerViewedIdsCache[$seekerProfileId])) {
            return $this->seekerViewedIdsCache[$seekerProfileId];
        }

        if (! Schema::hasTable('profile_views')) {
            return $this->seekerViewedIdsCache[$seekerProfileId] = [];
        }

        $ids = ProfileView::query()
            ->where('viewer_profile_id', $seekerProfileId)
            ->orderByDesc('id')
            ->limit(800)
            ->pluck('viewed_profile_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $this->seekerViewedIdsCache[$seekerProfileId] = $ids;
    }

    /**
     * @param  Collection<int, array{profile: MatrimonyProfile, score: int, base_score: int, reasons: list<string>}>  $rows
     * @return Collection<int, array{profile: MatrimonyProfile, score: int, base_score: int, reasons: list<string>}>
     */
    private function moveViewedProfilesToBottom(Collection $rows, MatrimonyProfile $seeker): Collection
    {
        $viewedSet = array_flip($this->seekerViewedCandidateIds((int) $seeker->id));
        if ($viewedSet === []) {
            return $rows;
        }

        $unseen = collect();
        $seen = collect();
        foreach ($rows as $row) {
            if (isset($viewedSet[(int) $row['profile']->id])) {
                $seen->push($row);
            } else {
                $unseen->push($row);
            }
        }

        return $unseen->concat($seen)->values();
    }

    /**
     * @param  Collection<int, array{profile: MatrimonyProfile, score: int, base_score: int, reasons: list<string>}>  $rows
     * @return Collection<int, array{profile: MatrimonyProfile, score: int, base_score: int, reasons: list<string>}>
     */
    private function applyTabOrdering(Collection $rows, string $tab, MatrimonyProfile $profile): Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }

        if ($tab === self::TAB_NEAR) {
            $rows = $rows->sort(function (array $a, array $b) use ($profile) {
                /** @var MatrimonyProfile $pa */
                $pa = $a['profile'];
                /** @var MatrimonyProfile $pb */
                $pb = $b['profile'];
                $ta = $this->locationProximityTier($profile, $pa);
                $tb = $this->locationProximityTier($profile, $pb);
                if ($ta !== $tb) {
                    return $tb <=> $ta;
                }

                $photo = $this->comparePhotoRank($pa, $pb);
                if ($photo !== 0) {
                    return $photo;
                }

                return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            })->values();
        } elseif ($tab === self::TAB_FRESH) {
            $rows = $rows->sort(function (array $a, array $b) {
                $ua = $a['profile']->updated_at?->timestamp ?? 0;
                $ub = $b['profile']->updated_at?->timestamp ?? 0;
                if ($ua !== $ub) {
                    return $ub <=> $ua;
                }

                $photo = $this->comparePhotoRank($a['profile'], $b['profile']);
                if ($photo !== 0) {
                    return $photo;
                }

                return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            })->values();
        } elseif ($tab === self::TAB_DAILY) {
            $dateKey = now()->toDateString();

            $rows = $rows->sort(function (array $a, array $b) use ($profile, $dateKey) {
                $photo = $this->comparePhotoRank($a['profile'], $b['profile']);
                if ($photo !== 0) {
                    return $photo;
                }

                $ha = crc32($profile->id.'|'.$dateKey.'|daily|'.$a['profile']->id);
                $hb = crc32($profile->id.'|'.$dateKey.'|daily|'.$b['profile']->id);
                if ($ha !== $hb) {
                    return $ha <=> $hb;
                }

                return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            })->values();
        } elseif ($tab === self::TAB_CURATED) {
            $rows = $rows->sort(function (array $a, array $b) {
                $photo = $this->comparePhotoRank($a['profile'], $b['profile']);
                if ($photo !== 0) {
                    return $photo;
                }

                $liftA = (int) ($a['score'] ?? 0) - (int) ($a['base_score'] ?? 0);
                $liftB = (int) ($b['score'] ?? 0) - (int) ($b['base_score'] ?? 0);
                if ($liftA !== $liftB) {
                    return $liftB <=> $liftA;
                }

                return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            })->values();
        } elseif ($tab === self::TAB_SECOND_CHANCE) {
            $rows = $rows->sort(function (array $a, array $b) {
                $photo = $this->comparePhotoRank($a['profile'], $b['profile']);
                if ($photo !== 0) {
                    return $photo;
                }

                $ua = $a['profile']->updated_at?->timestamp ?? 0;
                $ub = $b['profile']->updated_at?->timestamp ?? 0;
                if ($ua !== $ub) {
                    return $ub <=> $ua;
                }

                return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            })->values();
        } else {
            $rows = $rows->sort(function (array $a, array $b) {
                $photo = $this->comparePhotoRank($a['profile'], $b['profile']);
                if ($photo !== 0) {
                    return $photo;
                }

                return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            })->values();
        }

        if ($tab !== self::TAB_SECOND_CHANCE) {
            $rows = $this->moveViewedProfilesToBottom($rows, $profile);
        }

        return $rows;
    }

    private function comparePhotoRank(MatrimonyProfile $a, MatrimonyProfile $b): int
    {
        return $this->approvedPhotoRank($b) <=> $this->approvedPhotoRank($a);
    }

    private function approvedPhotoRank(MatrimonyProfile $profile): int
    {
        return $profile->hasApprovedPublicPhoto() ? 1 : 0;
    }

    private function locationProximityTier(MatrimonyProfile $seeker, MatrimonyProfile $candidate): int
    {
        $lidS = (int) ($seeker->location_id ?? 0);
        $lidC = (int) ($candidate->location_id ?? 0);
        if ($lidS > 0 && $lidS === $lidC) {
            return 3;
        }
        $geoS = $seeker->residenceGeoAddressIds();
        $geoC = $candidate->residenceGeoAddressIds();
        $sidS = (int) ($geoS['state_id'] ?? 0);
        $sidC = (int) ($geoC['state_id'] ?? 0);
        if ($sidS > 0 && $sidS === $sidC) {
            return 2;
        }
        $coidS = (int) ($geoS['country_id'] ?? 0);
        $coidC = (int) ($geoC['country_id'] ?? 0);
        if ($coidS > 0 && $coidS === $coidC) {
            return 1;
        }

        return 0;
    }

    /**
     * @return list<int>
     */
    private function candidateIdsExcludedBySkips(int $observerProfileId): array
    {
        if (! Schema::hasTable('profile_match_tab_skips')) {
            return [];
        }

        return DB::table('profile_match_tab_skips')
            ->select('candidate_profile_id')
            ->selectRaw('COUNT(*) as skip_count')
            ->where('observer_profile_id', $observerProfileId)
            ->groupBy('candidate_profile_id')
            ->having('skip_count', '>=', 3)
            ->pluck('candidate_profile_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return Builder<MatrimonyProfile>
     */
    private function baseCandidateQuery(MatrimonyProfile $profile, string $oppositeGenderKey): Builder
    {
        $q = MatrimonyProfile::query();
        $this->applyBaseCandidateFilters($q, $profile, $oppositeGenderKey);

        return $q->orderByDesc('updated_at');
    }

    private function applyBaseCandidateFilters(Builder $q, MatrimonyProfile $profile, string $oppositeGenderKey): void
    {
        $q->whereMemberAccountsOnly()
            ->whereKeyNot($profile->id)
            ->whereNonShowcase()
            ->whereHas('gender', static fn ($g) => $g->where('key', $oppositeGenderKey));

        $pool = $this->pool();
        if ($pool->isMembers()) {
            $q->where('lifecycle_state', 'active')
                ->where('is_suspended', false);
        } else {
            $this->applySuchakUniverseAvailability($q, $pool);
        }

        // NEVER RELAXED, and never conditional on the seeker having a preference row: a candidate
        // below the legal marriage age for their own gender is not a candidate. Profiles written
        // before MarriageAgePolicy existed (or imported / admin-created) can still hold an under-age
        // DOB, and matching was the only surface that never checked. A null DOB is unknown, not
        // proven under-age, so it survives here and is graded `unknown` by the preference rows.
        $candidateFloorAge = MarriageAgePolicy::minimumAgeForGenderKey($oppositeGenderKey);
        $q->where(function (Builder $legal) use ($candidateFloorAge): void {
            $legal->whereNull('date_of_birth')
                ->orWhere('date_of_birth', '<=', now()->subYears($candidateFloorAge)->toDateString());
        });

        // Hoisted above the preference-row guard. These used to sit INSIDE `if ($pc !== null)`, so for
        // every seeker without a `profile_preference_criteria` row `$hard` was undefined and
        // `($hard[...] ?? 'off')` silently evaluated to "off" — hard filtering was dead for exactly the
        // sparse profiles that need it most. The `?? 'off'` fallbacks are gone so a missing variable
        // would now be loud instead of silently disabling the filter.
        $this->matchingConfig->ensureDefaults();
        $hard = $this->matchingConfig->getHardFilters();

        $pc = $profile->preferenceCriteria;
        if ($pc !== null) {
            $this->applyPreferredAgeBounds($q, $pc);
        }

        if ($this->hardFilterMode($hard, 'marital_status') === MatchingHardFilter::MODE_STRICT) {
            $maritalIds = [];
            if (Schema::hasTable('profile_preferred_marital_statuses')) {
                $maritalIds = DB::table('profile_preferred_marital_statuses')
                    ->where('profile_id', $profile->id)
                    ->pluck('marital_status_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }
            // The pivot is the real source; the singular column is only a legacy fallback, so it is
            // the ONLY part of this block that depends on a preference-criteria row existing.
            if ($maritalIds === [] && $pc !== null && $pc->preferred_marital_status_id) {
                $maritalIds = [(int) $pc->preferred_marital_status_id];
            }
            if ($maritalIds !== []) {
                $q->whereIn('marital_status_id', $maritalIds);
            }
        }

        if ($this->hardFilterMode($hard, 'religion') === MatchingHardFilter::MODE_STRICT) {
            $relIds = DB::table('profile_preferred_religions')
                ->where('profile_id', $profile->id)
                ->pluck('religion_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            if ($relIds !== []) {
                $q->whereIn('religion_id', $relIds);
            }
        }
        // Global admin caste row: unchanged. It stays a ceiling that can only LOOSEN — the per-seeker
        // lock below never makes this row stricter, and this row never locks a seeker who did not ask.
        if ($this->hardFilterMode($hard, 'caste') === MatchingHardFilter::MODE_STRICT) {
            $casteIds = [];
            if (Schema::hasTable('profile_preferred_castes')) {
                $casteIds = DB::table('profile_preferred_castes')
                    ->where('profile_id', $profile->id)
                    ->pluck('caste_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }
            if ($casteIds !== []) {
                $q->whereIn('caste_id', $casteIds);
            }
        }

        $this->applyCommunityLock($q, $profile);
    }

    /**
     * Mode of one admin hard-filter row.
     *
     * The array parameter is deliberately typed and required: the old code read `$hard[...] ?? 'off'`
     * with `$hard` assigned INSIDE a conditional, so a seeker with no preference row silently got
     * "off" for every filter. Passing an undefined `$hard` here is now a TypeError instead. A missing
     * KEY still falls back to off — an admin may legitimately delete a filter row, and the legacy
     * fallback map does not carry every key.
     *
     * @param  array<string, array{mode: string, preferred_penalty_points: int}>  $hardFilters
     */
    private function hardFilterMode(array $hardFilters, string $filterKey): string
    {
        return (string) ($hardFilters[$filterKey]['mode'] ?? MatchingHardFilter::MODE_OFF);
    }

    /**
     * Preferred age bounds, applied INDEPENDENTLY so a one-sided preference still filters.
     *
     * Three defects lived here:
     *  - the block required BOTH bounds, so "at least 25" was ignored entirely;
     *  - `date_of_birth >= now()->subYears($maxAge)` excluded the whole max-age cohort — asking for
     *    25–30 never showed a 30-year-old, and it contradicted the inclusive
     *    {@see \App\Services\ProfilePreferenceMatchService} age row;
     *  - `whereNotNull('date_of_birth')` deleted every DOB-less profile in SQL before any degradation
     *    logic could grade it.
     *
     * @param  Builder<MatrimonyProfile>  $q
     */
    private function applyPreferredAgeBounds(Builder $q, object $pc): void
    {
        if (($pc->preferred_age_min ?? null) !== null) {
            $minAge = (int) $pc->preferred_age_min;
            // age >= minAge  ⟺  dob <= today − minAge years (inclusive).
            $cutoff = now()->subYears($minAge)->toDateString();
            $q->where(function (Builder $w) use ($cutoff): void {
                $w->whereNull('date_of_birth')->orWhere('date_of_birth', '<=', $cutoff);
            });
        }

        if (($pc->preferred_age_max ?? null) !== null) {
            $maxAge = (int) $pc->preferred_age_max;
            // age <= maxAge  ⟺  dob >= today − (maxAge + 1) years + 1 day. Keeps the whole max-age
            // cohort in, matching the inclusive comparison the preference row already used.
            $cutoff = now()->subYears($maxAge + 1)->addDay()->toDateString();
            $q->where(function (Builder $w) use ($cutoff): void {
                $w->whereNull('date_of_birth')->orWhere('date_of_birth', '>=', $cutoff);
            });
        }
    }

    /**
     * Per-seeker community lock (PO ruling 2026-07-26). Applies only on an explicit signal — see
     * {@see CommunityLockResolver} for why an absent/false-by-default flag row must never lock.
     * Caste relaxes at {@see MatchRelaxationLadder::TIER_RELAXED_CASTE}; religion never does.
     *
     * @param  Builder<MatrimonyProfile>  $q
     */
    private function applyCommunityLock(Builder $q, MatrimonyProfile $profile): void
    {
        $lock = $this->seekerCommunityLock($profile);

        if (($lock['religion_locked'] ?? false) === true && ($lock['allowed_religion_ids'] ?? []) !== []) {
            $q->whereIn('religion_id', $lock['allowed_religion_ids']);
        }

        $casteRelaxed = in_array('caste', MatchRelaxationLadder::relaxedFieldsUpTo($this->activeTier), true);
        if (! $casteRelaxed && ($lock['caste_locked'] ?? false) === true && ($lock['allowed_caste_ids'] ?? []) !== []) {
            $q->whereIn('caste_id', $lock['allowed_caste_ids']);
        }
    }

    /**
     * Resolved once per seeker per run — never per candidate.
     *
     * @return array{caste_locked: bool, religion_locked: bool, allowed_caste_ids: list<int>, allowed_religion_ids: list<int>, signals: list<string>}
     */
    private function seekerCommunityLock(MatrimonyProfile $profile): array
    {
        $pid = (int) $profile->getKey();
        if ($pid <= 0) {
            return CommunityLockResolver::open();
        }
        if (isset($this->communityLockCache[$pid])) {
            return $this->communityLockCache[$pid];
        }

        $casteIds = Schema::hasTable('profile_preferred_castes')
            ? DB::table('profile_preferred_castes')->where('profile_id', $pid)->pluck('caste_id')->map(fn ($id) => (int) $id)->all()
            : [];
        $religionIds = Schema::hasTable('profile_preferred_religions')
            ? DB::table('profile_preferred_religions')->where('profile_id', $pid)->pluck('religion_id')->map(fn ($id) => (int) $id)->all()
            : [];

        return $this->communityLockCache[$pid] = CommunityLockResolver::resolveOne($pid, $casteIds, $religionIds);
    }

    /**
     * Lifecycle states that leave the Suchak universe regardless of representation: the candidate is
     * gone, married, or administratively stopped. Everything else (including `draft` /
     * `awaiting_user_approval`, which is where a freshly Suchak-created candidate sits) stays eligible.
     */
    private const SUCHAK_POOL_BLOCKED_LIFECYCLE_STATES = [
        'suspended',
        'archived',
        'archived_due_to_marriage',
    ];

    /**
     * Availability predicate for {@see CandidatePoolStrategy::MODE_SUCHAK_UNIVERSE}:
     * an activated platform member, OR a candidate carrying a representation that is publicly routable,
     * OR a candidate represented by the acting Suchak itself (its own book, minus pending consent claims).
     *
     * Consent remains authoritative — the representation scopes are the single source for that, this
     * only decides who is a candidate at all. Masking is applied on top by the Suchak service layer.
     *
     * @param  Builder<MatrimonyProfile>  $q
     */
    private function applySuchakUniverseAvailability(Builder $q, CandidatePoolStrategy $pool): void
    {
        $ownAccountId = $pool->suchakAccountId;

        $q->whereNotIn('lifecycle_state', self::SUCHAK_POOL_BLOCKED_LIFECYCLE_STATES)
            ->where(function (Builder $available) use ($ownAccountId): void {
                $available
                    ->where(function (Builder $member): void {
                        $member->where('lifecycle_state', 'active')->where('is_suspended', false);
                    })
                    ->orWhereHas('suchakProfileRepresentations', function (Builder $rep) use ($ownAccountId): void {
                        $rep->where(function (Builder $routable): void {
                            $routable->publiclyRoutable();
                        });

                        if ($ownAccountId !== null) {
                            $rep->orWhere(function (Builder $own) use ($ownAccountId): void {
                                $own->where('suchak_account_id', $ownAccountId)
                                    ->whereNull('revoked_at')
                                    ->whereNull('candidate_deactivated_at')
                                    ->excludingPendingConsentClaims();
                            });
                        }
                    });
            });
    }

    private function oppositeGenderKey(MatrimonyProfile $profile): ?string
    {
        $key = $profile->gender?->key;
        if ($key === 'male') {
            return 'female';
        }
        if ($key === 'female') {
            return 'male';
        }

        return null;
    }

    /**
     * @param  EloquentCollection<int, MatrimonyProfile>|Collection<int, MatrimonyProfile>  $candidates
     */
    private function eagerLoadMatchingRelations(EloquentCollection|Collection $candidates): void
    {
        $candidates->loadMissing([
            'gender', 'maritalStatus', 'religion', 'caste', 'subCaste', 'diet',
            'occupationMaster.category.workingWithType', 'occupationCustom',
            'country', 'state', 'district', 'taluka', 'city',
            'preferenceCriteria',
            'photos',
            'user',
        ]);
    }

    /**
     * @param  list<int>  $profileIds
     * @return array<int, array<string, mixed>>
     */
    private function bulkLoadTargetPreferences(array $profileIds): array
    {
        $profileIds = array_values(array_unique(array_filter($profileIds)));
        $map = [];
        foreach ($profileIds as $id) {
            $map[(int) $id] = $this->emptyPrefPayload();
        }
        if ($profileIds === []) {
            return $map;
        }

        foreach (DB::table('profile_preference_criteria')->whereIn('profile_id', $profileIds)->get() as $row) {
            $pid = (int) $row->profile_id;
            if (isset($map[$pid])) {
                $map[$pid]['criteria'] = $row;
            }
        }

        $this->mergePivotIds($map, 'profile_preferred_religions', $profileIds, 'religion_id', 'religion_ids');
        $this->mergePivotIds($map, 'profile_preferred_castes', $profileIds, 'caste_id', 'caste_ids');
        $this->mergePivotIds($map, 'profile_preferred_districts', $profileIds, 'district_id', 'district_ids');

        if (Schema::hasTable('profile_preferred_countries')) {
            $this->mergePivotIds($map, 'profile_preferred_countries', $profileIds, 'country_id', 'country_ids');
        }
        if (Schema::hasTable('profile_preferred_states')) {
            $this->mergePivotIds($map, 'profile_preferred_states', $profileIds, 'state_id', 'state_ids');
        }
        if (Schema::hasTable('profile_preferred_talukas')) {
            $this->mergePivotIds($map, 'profile_preferred_talukas', $profileIds, 'taluka_id', 'taluka_ids');
        }
        if (Schema::hasTable('profile_preferred_education_degrees')) {
            $this->mergePivotIds($map, 'profile_preferred_education_degrees', $profileIds, 'education_degree_id', 'education_degree_ids');
        }
        if (Schema::hasTable('profile_preferred_occupation_master')) {
            $this->mergePivotIds($map, 'profile_preferred_occupation_master', $profileIds, 'occupation_master_id', 'occupation_master_ids');
        }
        if (Schema::hasTable('profile_preferred_diets')) {
            $this->mergePivotIds($map, 'profile_preferred_diets', $profileIds, 'diet_id', 'diet_ids');
        }
        if (Schema::hasTable('profile_preferred_marital_statuses')) {
            $this->mergePivotIds($map, 'profile_preferred_marital_statuses', $profileIds, 'marital_status_id', 'marital_status_ids');
        }

        // Community intent + declared strictness ride along in the SAME bulk load — resolving them
        // per candidate would put two extra queries on every pair in the pool.
        $casteIdsByProfile = [];
        $religionIdsByProfile = [];
        foreach ($map as $pid => $payload) {
            $casteIdsByProfile[$pid] = $payload['caste_ids'] ?? [];
            $religionIdsByProfile[$pid] = $payload['religion_ids'] ?? [];
        }

        $locks = CommunityLockResolver::resolveMany($profileIds, $casteIdsByProfile, $religionIdsByProfile);
        $strictness = CommunityLockResolver::strictnessMapFor($profileIds);
        foreach ($map as $pid => $payload) {
            $map[$pid]['community_lock'] = $locks[$pid] ?? CommunityLockResolver::open();
            $map[$pid]['strictness'] = $strictness[$pid] ?? [];
        }

        return $map;
    }

    /**
     * @param  array<int, array<string, mixed>>  $map
     * @param  list<int>  $profileIds
     */
    private function mergePivotIds(array &$map, string $table, array $profileIds, string $column, string $mapKey): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        $rows = DB::table($table)->whereIn('profile_id', $profileIds)->get();
        foreach ($rows as $row) {
            $pid = (int) $row->profile_id;
            if (! isset($map[$pid])) {
                continue;
            }
            $map[$pid][$mapKey][] = (int) $row->{$column};
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPrefPayload(): array
    {
        return [
            'criteria' => null,
            'religion_ids' => [],
            'caste_ids' => [],
            'district_ids' => [],
            'country_ids' => [],
            'state_ids' => [],
            'taluka_ids' => [],
            'education_degree_ids' => [],
            'occupation_master_ids' => [],
            'diet_ids' => [],
            'marital_status_ids' => [],
            'community_lock' => CommunityLockResolver::open(),
            'strictness' => [],
        ];
    }

    /**
     * Mutual eligibility gate. Historically ANY `not_matched` in either direction was fatal, which
     * turned income and height — fields that emit `not_matched` for a ₹1 shortfall or a 4 cm miss —
     * into silent hard filters and was a major cause of empty feeds on sparse data.
     *
     * A mismatch is now fatal only when it is not tolerated at the active tier:
     *  - income / height are SOFT (scored + warned) unless the seeker explicitly declared must-match,
     *    and even then they become soft at {@see MatchRelaxationLadder::TIER_SOFT_INCOME_HEIGHT};
     *  - location relaxes at {@see MatchRelaxationLadder::TIER_WIDER_GEOGRAPHY};
     *  - caste relaxes at {@see MatchRelaxationLadder::TIER_RELAXED_CASTE}; religion never relaxes.
     */
    private function mutuallyPreferenceCompatible(MatrimonyProfile $a, MatrimonyProfile $b): bool
    {
        $ab = $this->directionalPreferenceBuild($a, $b);
        $ba = $this->directionalPreferenceBuild($b, $a);

        return ! $this->hasFatalMismatch($ab) && ! $this->hasFatalMismatch($ba);
    }

    /**
     * @param  array<string, mixed>  $build
     */
    private function hasFatalMismatch(array $build): bool
    {
        return $this->evaluatePreferenceBuild($build, $this->activeTier)['fatal'];
    }

    /**
     * Public, single-direction twin of the in-pipeline tolerance test.
     *
     * Any surface that filters on a {@see ProfilePreferenceMatchService::build()} payload must ask this
     * instead of counting `counts['not_matched']` itself — a raw count treats a ₹1 income shortfall or a
     * 4 cm height miss exactly like a declared religion requirement, which is the silent-hard-filter
     * defect this method exists to prevent. Tolerated rows come back as `warnings` so the surface can
     * SHOW the near-miss rather than delete the candidate.
     *
     * @param  array<string, mixed>  $build
     * @param  int|null  $tier  Defaults to the strict tier: a standalone question is never asked at a
     *                          relaxed tier, only a feed run climbing the ladder is.
     * @return array{fatal: bool, warnings: list<string>}
     */
    public function evaluatePreferenceBuild(array $build, ?int $tier = null): array
    {
        $relaxed = MatchRelaxationLadder::relaxedFieldsUpTo($tier ?? MatchRelaxationLadder::TIER_STRICT);
        $fatal = false;
        $warnings = [];

        foreach ($build['rows'] ?? [] as $row) {
            if (($row['status'] ?? '') !== ProfilePreferenceMatchService::STATUS_NOT_MATCHED) {
                continue;
            }
            if (! $this->mismatchIsTolerated($row, $relaxed)) {
                $fatal = true;

                continue;
            }
            $reason = trim((string) ($row['reason'] ?? ''));
            if ($reason !== '') {
                $warnings[$reason] = true;
            }
        }

        return ['fatal' => $fatal, 'warnings' => array_keys($warnings)];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $relaxedFields
     */
    private function mismatchIsTolerated(array $row, array $relaxedFields): bool
    {
        $id = (string) ($row['id'] ?? '');

        if (in_array($id, $relaxedFields, true)) {
            return true;
        }

        // Income and height only exclude at the strict tier, and only for a seeker who said so.
        if (in_array($id, ['income', 'height'], true)) {
            return ($row['declared_must_match'] ?? false) !== true;
        }

        // A derived preference is the engine's guess, never the seeker's requirement.
        return ($row['derived'] ?? false) === true;
    }

    /**
     * Near-misses that were admitted rather than excluded, so the UI can say what was overlooked.
     *
     * @return list<string>
     */
    private function toleratedMismatchWarnings(MatrimonyProfile $seeker, MatrimonyProfile $candidate): array
    {
        $warnings = [];

        foreach ([$this->directionalPreferenceBuild($seeker, $candidate), $this->directionalPreferenceBuild($candidate, $seeker)] as $build) {
            foreach ($this->evaluatePreferenceBuild($build, $this->activeTier)['warnings'] as $reason) {
                $warnings[$reason] = true;
            }
        }

        return array_keys($warnings);
    }

    /**
     * Preference fields neither side actually stated — the engine assumed them from the profile.
     *
     * @return list<string>
     */
    private function assumedPreferenceFields(MatrimonyProfile $seeker, MatrimonyProfile $candidate): array
    {
        $fields = [];
        foreach ([$this->directionalPreferenceBuild($seeker, $candidate), $this->directionalPreferenceBuild($candidate, $seeker)] as $build) {
            foreach ($build['assumed_fields'] ?? [] as $field) {
                $fields[(string) $field] = true;
            }
        }

        return array_keys($fields);
    }

    /**
     * @return array<string, mixed>
     */
    private function directionalPreferenceBuild(MatrimonyProfile $viewer, MatrimonyProfile $target): array
    {
        $cacheKey = $viewer->id.'>'.$target->id;
        if (isset($this->directionalBuildCache[$cacheKey])) {
            return $this->directionalBuildCache[$cacheKey];
        }

        $pref = $this->prefMap[$target->id] ?? $this->emptyPrefPayload();

        return $this->directionalBuildCache[$cacheKey] = ProfilePreferenceMatchService::build($viewer, $target, $pref);
    }

    /**
     * @return list<string>
     */
    private function explainScore(MatrimonyProfile $a, MatrimonyProfile $b): array
    {
        $parts = $this->scoreParts($a, $b);
        $reasons = [];
        foreach ($parts as $p) {
            foreach ($p['reasons'] as $r) {
                if ($r !== '') {
                    $reasons[] = $r;
                }
            }
        }

        return array_values(array_unique($reasons));
    }

    private function calculateScore(MatrimonyProfile $a, MatrimonyProfile $b): int
    {
        $parts = $this->scoreParts($a, $b);
        $total = 0;
        foreach ($parts as $p) {
            $total += $p['points'];
        }

        return min(100, max(0, $total));
    }

    /**
     * @return list<array{points: int, reasons: list<string>}>
     */
    private function scoreParts(MatrimonyProfile $a, MatrimonyProfile $b): array
    {
        $cacheKey = $a->id < $b->id ? $a->id.'|'.$b->id : $b->id.'|'.$a->id;
        if (isset($this->componentsCache[$cacheKey])) {
            return $this->componentsCache[$cacheKey];
        }

        $ab = $this->directionalPreferenceBuild($a, $b);
        $ba = $this->directionalPreferenceBuild($b, $a);

        $parts = [
            $this->scoreAgePart($ab, $ba),
            $this->scoreLocationPart($a, $b),
            $this->scoreEducationPart($a, $b),
            $this->scoreOccupationPart($a, $b),
            $this->scoreCommunityPart($a, $b),
            $this->scorePreferencesPart($ab, $ba),
            $this->scoreMaritalStatusPart($a, $b),
            $this->scoreHeightPart($a, $b),
            $this->scoreDietPart($a, $b),
        ];

        return $this->componentsCache[$cacheKey] = $parts;
    }

    /**
     * @param  array<string, mixed>  $ab
     * @param  array<string, mixed>  $ba
     * @return array{points: int, reasons: list<string>}
     */
    private function scoreAgePart(array $ab, array $ba): array
    {
        $sa = $this->preferenceRowStatus($ab, 'age');
        $sb = $this->preferenceRowStatus($ba, 'age');
        $points = 0;
        $reasons = [];

        if ($sa === ProfilePreferenceMatchService::STATUS_MATCH && $sb === ProfilePreferenceMatchService::STATUS_MATCH) {
            $points = $this->weight('age', self::WEIGHT_AGE);
            $reasons[] = __('matching.reason_age_both_in_range');
        } elseif (
            ($sa === ProfilePreferenceMatchService::STATUS_MATCH && $sb === ProfilePreferenceMatchService::STATUS_FLEXIBLE)
            || ($sa === ProfilePreferenceMatchService::STATUS_FLEXIBLE && $sb === ProfilePreferenceMatchService::STATUS_MATCH)
        ) {
            $points = (int) round($this->weight('age', self::WEIGHT_AGE) * 0.75);
            $reasons[] = __('matching.reason_age_compatible');
        } elseif ($sa === ProfilePreferenceMatchService::STATUS_FLEXIBLE && $sb === ProfilePreferenceMatchService::STATUS_FLEXIBLE) {
            $points = (int) round($this->weight('age', self::WEIGHT_AGE) * 0.6);
            $reasons[] = __('matching.reason_age_flexible');
        } else {
            $points = (int) round($this->weight('age', self::WEIGHT_AGE) * 0.45);
            $reasons[] = __('matching.reason_age_partial');
        }

        return ['points' => $points, 'reasons' => $reasons];
    }

    /**
     * @param  array<string, mixed>  $build
     */
    private function preferenceRowStatus(array $build, string $rowId): string
    {
        foreach ($build['rows'] ?? [] as $r) {
            if (($r['id'] ?? '') === $rowId) {
                return (string) ($r['status'] ?? ProfilePreferenceMatchService::STATUS_UNKNOWN);
            }
        }

        return ProfilePreferenceMatchService::STATUS_UNKNOWN;
    }

    /**
     * @return array{points: int, reasons: list<string>}
     */
    private function scoreLocationPart(MatrimonyProfile $a, MatrimonyProfile $b): array
    {
        $lidA = (int) ($a->location_id ?? 0);
        $lidB = (int) ($b->location_id ?? 0);
        if ($lidA > 0 && $lidA === $lidB) {
            return ['points' => $this->weight('location', self::WEIGHT_LOCATION), 'reasons' => [__('matching.reason_same_city')]];
        }

        $geoA = $a->residenceGeoAddressIds();
        $geoB = $b->residenceGeoAddressIds();
        $sidA = (int) ($geoA['state_id'] ?? 0);
        $sidB = (int) ($geoB['state_id'] ?? 0);
        if ($sidA > 0 && $sidA === $sidB) {
            return ['points' => (int) round($this->weight('location', self::WEIGHT_LOCATION) * 0.65), 'reasons' => [__('matching.reason_same_state')]];
        }

        $coidA = (int) ($geoA['country_id'] ?? 0);
        $coidB = (int) ($geoB['country_id'] ?? 0);
        if ($coidA > 0 && $coidA === $coidB) {
            return ['points' => (int) round($this->weight('location', self::WEIGHT_LOCATION) * 0.35), 'reasons' => [__('matching.reason_same_country')]];
        }

        return ['points' => 0, 'reasons' => []];
    }

    /**
     * @return array{points: int, reasons: list<string>}
     */
    private function scoreEducationPart(MatrimonyProfile $a, MatrimonyProfile $b): array
    {
        $idA = $this->resolveEducationDegreeId($a);
        $idB = $this->resolveEducationDegreeId($b);
        if ($idA === null || $idB === null) {
            return ['points' => (int) round($this->weight('education', self::WEIGHT_EDUCATION) * 0.35), 'reasons' => [__('matching.reason_education_unknown')]];
        }
        if ($idA === $idB) {
            return ['points' => $this->weight('education', self::WEIGHT_EDUCATION), 'reasons' => [__('matching.reason_education_match')]];
        }

        $sortA = (int) (EducationDegree::query()->whereKey($idA)->value('sort_order') ?? 0);
        $sortB = (int) (EducationDegree::query()->whereKey($idB)->value('sort_order') ?? 0);
        $diff = abs($sortA - $sortB);
        if ($diff <= 1) {
            return ['points' => (int) round($this->weight('education', self::WEIGHT_EDUCATION) * 0.8), 'reasons' => [__('matching.reason_education_close')]];
        }
        if ($diff <= 3) {
            return ['points' => (int) round($this->weight('education', self::WEIGHT_EDUCATION) * 0.55), 'reasons' => [__('matching.reason_education_similar')]];
        }

        return ['points' => (int) round($this->weight('education', self::WEIGHT_EDUCATION) * 0.25), 'reasons' => []];
    }

    private function resolveEducationDegreeId(MatrimonyProfile $profile): ?int
    {
        $fk = (int) ($profile->education_degree_id ?? 0);
        if ($fk > 0) {
            return $fk;
        }
        $raw = trim((string) ($profile->highest_education ?? ''));
        if ($raw === '') {
            return null;
        }
        $deg = app(EducationService::class)->findDegreeMatch($raw);

        return $deg?->id;
    }

    /**
     * @return array{points: int, reasons: list<string>}
     */
    private function scoreOccupationPart(MatrimonyProfile $a, MatrimonyProfile $b): array
    {
        $tbl = $a->getTable();
        $hasProfFk = Schema::hasColumn($tbl, 'profession_id');

        $midA = (int) ($a->occupation_master_id ?? 0);
        $midB = (int) ($b->occupation_master_id ?? 0);
        if ($midA > 0 && $midA === $midB) {
            return ['points' => $this->weight('occupation', self::WEIGHT_OCCUPATION), 'reasons' => [__('matching.reason_same_occupation')]];
        }

        $cidA = (int) ($a->occupation_custom_id ?? 0);
        $cidB = (int) ($b->occupation_custom_id ?? 0);
        if ($cidA > 0 && $cidA === $cidB) {
            return ['points' => $this->weight('occupation', self::WEIGHT_OCCUPATION), 'reasons' => [__('matching.reason_same_occupation')]];
        }

        $pA = $hasProfFk ? (int) ($a->getAttribute('profession_id') ?: 0) : 0;
        $pB = $hasProfFk ? (int) ($b->getAttribute('profession_id') ?: 0) : 0;
        if ($pA > 0 && $pA === $pB) {
            return ['points' => $this->weight('occupation', self::WEIGHT_OCCUPATION), 'reasons' => [__('matching.reason_same_occupation')]];
        }

        $a->loadMissing(['occupationMaster', 'occupationCustom']);
        $b->loadMissing(['occupationMaster', 'occupationCustom']);
        $resA = (int) ($a->resolvedProfession()?->id ?? 0);
        $resB = (int) ($b->resolvedProfession()?->id ?? 0);
        if ($resA > 0 && $resA === $resB) {
            return ['points' => $this->weight('occupation', self::WEIGHT_OCCUPATION), 'reasons' => [__('matching.reason_same_occupation')]];
        }

        $a->loadMissing(['occupationMaster.category.workingWithType']);
        $b->loadMissing(['occupationMaster.category.workingWithType']);
        $wA = (int) ($a->resolvedWorkingWithType()?->id ?? 0);
        $wB = (int) ($b->resolvedWorkingWithType()?->id ?? 0);
        if ($wA > 0 && $wA === $wB) {
            return ['points' => (int) round($this->weight('occupation', self::WEIGHT_OCCUPATION) * 0.65), 'reasons' => [__('matching.reason_similar_work_sector')]];
        }

        $signalsA = $midA || $cidA || $pA || $resA;
        $signalsB = $midB || $cidB || $pB || $resB;
        if ($signalsA && $signalsB) {
            return ['points' => (int) round($this->weight('occupation', self::WEIGHT_OCCUPATION) * 0.25), 'reasons' => []];
        }

        return ['points' => 0, 'reasons' => []];
    }

    /**
     * @return array{points: int, reasons: list<string>}
     */
    private function scoreCommunityPart(MatrimonyProfile $a, MatrimonyProfile $b): array
    {
        $subA = (int) ($a->sub_caste_id ?? 0);
        $subB = (int) ($b->sub_caste_id ?? 0);
        if ($subA > 0 && $subA === $subB) {
            return ['points' => $this->weight('community', self::WEIGHT_COMMUNITY), 'reasons' => [__('matching.reason_same_subcaste')]];
        }

        $casteA = (int) ($a->caste_id ?? 0);
        $casteB = (int) ($b->caste_id ?? 0);
        if ($casteA > 0 && $casteA === $casteB) {
            return ['points' => (int) round($this->weight('community', self::WEIGHT_COMMUNITY) * 0.8), 'reasons' => [__('matching.reason_same_caste')]];
        }

        $relA = (int) ($a->religion_id ?? 0);
        $relB = (int) ($b->religion_id ?? 0);
        if ($relA > 0 && $relA === $relB) {
            return ['points' => (int) round($this->weight('community', self::WEIGHT_COMMUNITY) * 0.5), 'reasons' => [__('matching.reason_same_religion')]];
        }

        return ['points' => (int) round($this->weight('community', self::WEIGHT_COMMUNITY) * 0.15), 'reasons' => []];
    }

    /**
     * @param  array<string, mixed>  $ab
     * @param  array<string, mixed>  $ba
     * @return array{points: int, reasons: list<string>}
     */
    private function scorePreferencesPart(array $ab, array $ba): array
    {
        $m = (int) ($ab['counts']['match'] ?? 0) + (int) ($ba['counts']['match'] ?? 0);
        $f = (int) ($ab['counts']['flexible'] ?? 0) + (int) ($ba['counts']['flexible'] ?? 0);
        $den = $m + $f;
        // Income / height / geography mismatches that reached scoring were TOLERATED — the fatal ones
        // never get here. Tolerating them must still cost the pair something, otherwise "soft filter"
        // would mean "no filter at all".
        $penalty = $this->softMismatchPenalty($ab) + $this->softMismatchPenalty($ba);

        if ($den <= 0) {
            $open = (int) round($this->weight('preferences', self::WEIGHT_PREFERENCES) * 0.5);

            return ['points' => $open - $penalty, 'reasons' => [__('matching.reason_prefs_open')]];
        }

        $points = (int) round($this->weight('preferences', self::WEIGHT_PREFERENCES) * ($m / $den));
        $reasons = [];
        if ($m >= 4) {
            $reasons[] = __('matching.reason_strong_pref_alignment');
        } elseif ($m >= 2) {
            $reasons[] = __('matching.reason_good_pref_alignment');
        }

        return ['points' => min($this->weight('preferences', self::WEIGHT_PREFERENCES), $points) - $penalty, 'reasons' => $reasons];
    }

    /**
     * @param  array<string, mixed>  $build
     */
    private function softMismatchPenalty(array $build): int
    {
        $perMiss = max(0, (int) config('matching.relaxation.soft_penalty_points', 6));
        if ($perMiss === 0) {
            return 0;
        }

        $misses = 0;
        foreach ($build['rows'] ?? [] as $row) {
            if (($row['status'] ?? '') !== ProfilePreferenceMatchService::STATUS_NOT_MATCHED) {
                continue;
            }
            if (($row['derived'] ?? false) === true) {
                continue;
            }
            $misses++;
        }

        return $misses * $perMiss;
    }

    /**
     * @return array{points: int, reasons: list<string>}
     */
    private function scoreMaritalStatusPart(MatrimonyProfile $a, MatrimonyProfile $b): array
    {
        $w = $this->weight('marital_status', 9);
        $ma = (int) ($a->marital_status_id ?? 0);
        $mb = (int) ($b->marital_status_id ?? 0);
        if ($ma > 0 && $ma === $mb) {
            return ['points' => $w, 'reasons' => []];
        }
        if ($ma > 0 && $mb > 0) {
            return ['points' => (int) round($w * 0.25), 'reasons' => []];
        }

        return ['points' => 0, 'reasons' => []];
    }

    /**
     * @return array{points: int, reasons: list<string>}
     */
    private function scoreHeightPart(MatrimonyProfile $a, MatrimonyProfile $b): array
    {
        $w = $this->weight('height', 8);
        $ha = (int) ($a->height_cm ?? 0);
        $hb = (int) ($b->height_cm ?? 0);
        if ($ha <= 0 || $hb <= 0) {
            return ['points' => 0, 'reasons' => []];
        }
        $diff = abs($ha - $hb);
        if ($diff <= 4) {
            return ['points' => $w, 'reasons' => []];
        }
        if ($diff <= 9) {
            return ['points' => (int) round($w * 0.6), 'reasons' => []];
        }
        if ($diff <= 14) {
            return ['points' => (int) round($w * 0.3), 'reasons' => []];
        }

        return ['points' => 0, 'reasons' => []];
    }

    /**
     * @return array{points: int, reasons: list<string>}
     */
    private function scoreDietPart(MatrimonyProfile $a, MatrimonyProfile $b): array
    {
        $w = $this->weight('diet', 6);
        $da = (int) ($a->diet_id ?? 0);
        $db = (int) ($b->diet_id ?? 0);
        if ($da > 0 && $da === $db) {
            return ['points' => $w, 'reasons' => []];
        }
        if ($da > 0 && $db > 0) {
            return ['points' => (int) round($w * 0.2), 'reasons' => []];
        }

        return ['points' => 0, 'reasons' => []];
    }

    private function weight(string $fieldKey, int $fallback): int
    {
        $w = $this->matchingConfig->weightFor($fieldKey);
        if ($w <= 0) {
            return $fallback;
        }

        return $w;
    }

    /**
     * Structured breakdown for {@see MatchingExplainService} (does not mutate long-lived scorer caches).
     *
     * @return array{
     *   field_parts: list<array{points: int, reasons: list<string>}>,
     *   preferred_penalties: list<array{reason: string, impact: int}>,
     *   behavior_delta: int,
     *   quality_delta: int,
     *   quality_signals: list<array{key: string, points: int, reason: string}>,
     *   before_boost: int,
     *   final_score: int
     * }
     */
    public function computeMatchBreakdown(
        MatrimonyProfile $seeker,
        MatrimonyProfile $candidate,
        bool $withActorAdjustments = true,
    ): array {
        $savedPref = $this->prefMap;
        $savedDir = $this->directionalBuildCache;
        $savedComp = $this->componentsCache;
        try {
            $this->directionalBuildCache = [];
            $this->componentsCache = [];
            ProfilePreferenceMatchService::flushRuntimeCaches();
            $this->prefMap = $this->bulkLoadTargetPreferences([(int) $seeker->id, (int) $candidate->id]);

            $parts = $this->scoreParts($seeker, $candidate);
            $fieldParts = [];
            $sumBase = 0;
            $fieldPoints = [
                'age' => 0,
                'location' => 0,
                'education' => 0,
                'occupation' => 0,
                'community' => 0,
                'preferences' => 0,
                'marital_status' => 0,
                'height' => 0,
                'diet' => 0,
            ];
            $keys = array_keys($fieldPoints);
            foreach ($parts as $p) {
                $pts = (int) ($p['points'] ?? 0);
                $sumBase += $pts;
                $fieldParts[] = [
                    'points' => $pts,
                    'reasons' => $p['reasons'] ?? [],
                ];
                $k = array_shift($keys);
                if ($k !== null) {
                    $fieldPoints[$k] = $pts;
                }
            }
            $baseScore = min(100, max(0, $sumBase));

            $seeker->loadMissing('user');
            $candidate->loadMissing('user');
            // Keep in sync with {@see collectTierRows}: field score then boost only (no behavior layer).
            // $withActorAdjustments = false is the Suchak-initiated case — the represented candidate's
            // dormant account has no activity of its own, so the ACTOR-keyed layer is skipped.
            $applyActor = $withActorAdjustments && $seeker->user && $candidate->user;
            $finalScore = $applyActor
                ? $this->matchBoost->applyBoost($seeker->user, $candidate->user, $baseScore)
                : $baseScore;
            $behaviorDelta = $applyActor
                ? $this->behaviorScoring->scoreAdjustment($seeker->user, $candidate)
                : 0;

            // ...but candidate-intrinsic QUALITY (KYC, photo, completeness, mobile, recency) is not an
            // actor signal at all, so it still applies when the actor layer is deliberately off. Without
            // it a Suchak saw an empty card tied with a verified, complete, recently-touched one.
            // When the actor layer IS on, applyBoost() already contains these signals — adding them here
            // would double-count them, hence the strict either/or.
            $qualitySignals = $withActorAdjustments
                ? []
                : $this->matchBoost->explainCandidateQuality($candidate, $candidate->user);
            $qualityDelta = 0;
            foreach ($qualitySignals as $signal) {
                $qualityDelta += (int) ($signal['points'] ?? 0);
            }

            $finalScore = max(0, min(100, $finalScore + $behaviorDelta + $qualityDelta));

            return [
                'field_parts' => $fieldParts,
                'field_points' => $fieldPoints,
                'preferred_penalties' => [],
                'behavior_delta' => $behaviorDelta,
                'quality_delta' => $qualityDelta,
                // list<array{key: string, points: int, reason: string}> — already trimmed to the
                // aggregate cap, so the reasons always add up to `quality_delta`.
                'quality_signals' => $qualitySignals,
                'before_boost' => $baseScore,
                'final_score' => $finalScore,
            ];
        } finally {
            $this->prefMap = $savedPref;
            $this->directionalBuildCache = $savedDir;
            $this->componentsCache = $savedComp;
        }
    }

    /**
     * @param  Collection<int, array{profile: MatrimonyProfile, score: int, base_score?: int, reasons: list<string>}>  $results
     */
    private function replacePersistedMatches(MatrimonyProfile $profile, Collection $results): void
    {
        DB::transaction(function () use ($profile, $results): void {
            ProfileMatch::query()->where('profile_id', $profile->id)->delete();
            foreach ($results as $row) {
                /** @var MatrimonyProfile $matched */
                $matched = $row['profile'];
                ProfileMatch::query()->create([
                    'profile_id' => $profile->id,
                    'matched_profile_id' => $matched->id,
                    'score' => (int) $row['score'],
                    'json_reasons' => $row['reasons'],
                ]);
            }
        });
    }
}
