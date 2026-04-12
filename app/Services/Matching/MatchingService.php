<?php

namespace App\Services\Matching;

use App\Models\Interest;
use App\Models\MasterEducation;
use App\Models\MatrimonyProfile;
use App\Models\ProfileMatch;
use App\Models\ProfileView;
use App\Services\MatchBoostService;
use App\Services\ProfilePreferenceMatchService;
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
            self::TAB_VIEWED,
            self::TAB_INTERESTED,
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

    /**
     * @param  bool  $withExplain  When true, each row includes an `explain` array (admin preview / JSON API).
     * @return Collection<int, array{profile: MatrimonyProfile, score: int, base_score: int, reasons: list<string>, explain?: list<array{reason: string, impact: int}>}>
     */
    public function findMatches(MatrimonyProfile $profile, int $limit = 20, bool $withExplain = false): Collection
    {
        return $this->findMatchesForTab($profile, self::TAB_PERFECT, $limit, $withExplain);
    }

    /**
     * Tab-specific lists reuse the same scoring and preference gates; only the candidate pool and ordering differ.
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

        $profile->loadMissing([
            'gender', 'preferenceCriteria',
            'religion', 'caste', 'subCaste', 'profession',
            'country', 'state', 'district', 'city',
            'user',
        ]);

        $oppositeKey = $this->oppositeGenderKey($profile);
        if ($oppositeKey === null) {
            return collect();
        }

        $candidates = match ($tab) {
            self::TAB_VIEWED => $this->candidatesWhoViewedMe($profile, $oppositeKey),
            self::TAB_INTERESTED => $this->candidatesInterestedInMe($profile, $oppositeKey),
            self::TAB_SECOND_CHANCE => $this->candidatesSecondChance($profile, $oppositeKey),
            default => $this->baseCandidateQuery($profile, $oppositeKey)
                ->limit(max(1, (int) config('matching.candidate_pool_limit', 200)))
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
            $score = ($seekerUser && $candidateUser)
                ? $this->matchBoost->applyBoost($seekerUser, $candidateUser, $baseScore)
                : $baseScore;
            $reasons = $this->explainScore($profile, $candidate);

            $row = [
                'profile' => $candidate,
                'score' => $score,
                'base_score' => $baseScore,
                'reasons' => $reasons,
            ];
            if ($withExplain) {
                $row['explain'] = app(MatchingExplainService::class)->explainPair($profile, $candidate);
            }
            $out->push($row);
        }

        $sorted = $this->applyTabOrdering($out, $tab, $profile)->values();
        $sorted = $this->dedupeMatchRowsByPerson($sorted)->take($limit)->values();

        if ($tab === self::TAB_PERFECT && config('matching.persist_cache', false) && Schema::hasTable('profile_matches')) {
            $this->replacePersistedMatches($profile, $sorted);
        }

        return $sorted;
    }

    /**
     * One row per profile id, per member account (user_id), and per “surface clone” (same display identity).
     * Intake/demo data often creates many active rows with the same Marathi full_name and DOB under different
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
    private function candidatesSecondChance(MatrimonyProfile $profile, string $oppositeGenderKey): Collection
    {
        if (! Schema::hasTable('profile_views')) {
            return collect();
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
            return collect();
        }

        $messaged = [];
        if (Schema::hasTable('interests')) {
            $messaged = Interest::query()
                ->where('sender_profile_id', $profile->id)
                ->whereIn('receiver_profile_id', $viewedIds)
                ->pluck('receiver_profile_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->all();
        }

        $candidateIds = array_values(array_diff($viewedIds, $messaged));
        if ($candidateIds === []) {
            return collect();
        }

        $q = MatrimonyProfile::query()->whereIn('id', $candidateIds);
        $this->applyBaseCandidateFilters($q, $profile, $oppositeGenderKey);

        return $q->get()->sortByDesc('updated_at')->values();
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
            return $rows->sort(function (array $a, array $b) use ($profile) {
                /** @var MatrimonyProfile $pa */
                $pa = $a['profile'];
                /** @var MatrimonyProfile $pb */
                $pb = $b['profile'];
                $ta = $this->locationProximityTier($profile, $pa);
                $tb = $this->locationProximityTier($profile, $pb);
                if ($ta !== $tb) {
                    return $tb <=> $ta;
                }

                return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            })->values();
        }

        if ($tab === self::TAB_FRESH) {
            return $rows->sort(function (array $a, array $b) {
                $ua = $a['profile']->updated_at?->timestamp ?? 0;
                $ub = $b['profile']->updated_at?->timestamp ?? 0;
                if ($ua !== $ub) {
                    return $ub <=> $ua;
                }

                return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            })->values();
        }

        if ($tab === self::TAB_DAILY) {
            $dateKey = now()->toDateString();
            $top = $rows->sortByDesc('score')->take(100)->values();

            return $top->sort(function (array $a, array $b) use ($profile, $dateKey) {
                $ha = crc32($profile->id.'|'.$dateKey.'|'.$a['profile']->id);
                $hb = crc32($profile->id.'|'.$dateKey.'|'.$b['profile']->id);
                if ($ha !== $hb) {
                    return $ha <=> $hb;
                }

                return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            })->values();
        }

        if ($tab === self::TAB_CURATED) {
            return $rows->sort(function (array $a, array $b) {
                $liftA = (int) ($a['score'] ?? 0) - (int) ($a['base_score'] ?? 0);
                $liftB = (int) ($b['score'] ?? 0) - (int) ($b['base_score'] ?? 0);
                if ($liftA !== $liftB) {
                    return $liftB <=> $liftA;
                }

                return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            })->values();
        }

        return $rows->sortByDesc('score')->values();
    }

    private function locationProximityTier(MatrimonyProfile $seeker, MatrimonyProfile $candidate): int
    {
        $cidS = (int) ($seeker->city_id ?? 0);
        $cidC = (int) ($candidate->city_id ?? 0);
        if ($cidS > 0 && $cidS === $cidC) {
            return 3;
        }
        $sidS = (int) ($seeker->state_id ?? 0);
        $sidC = (int) ($candidate->state_id ?? 0);
        if ($sidS > 0 && $sidS === $sidC) {
            return 2;
        }
        $coidS = (int) ($seeker->country_id ?? 0);
        $coidC = (int) ($candidate->country_id ?? 0);
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
            ->where('lifecycle_state', 'active')
            ->where('is_suspended', false)
            ->where('is_demo', false)
            ->whereHas('gender', static fn ($g) => $g->where('key', $oppositeGenderKey));

        $pc = $profile->preferenceCriteria;
        if ($pc !== null) {
            if ($pc->preferred_age_min !== null && $pc->preferred_age_max !== null) {
                $minAge = (int) $pc->preferred_age_min;
                $maxAge = (int) $pc->preferred_age_max;
                $q->whereNotNull('date_of_birth')
                    ->where('date_of_birth', '<=', now()->subYears($minAge))
                    ->where('date_of_birth', '>=', now()->subYears($maxAge));
            }

            if (config('matching.strict_marital_filter', false)) {
                $maritalIds = [];
                if (Schema::hasTable('profile_preferred_marital_statuses')) {
                    $maritalIds = DB::table('profile_preferred_marital_statuses')
                        ->where('profile_id', $profile->id)
                        ->pluck('marital_status_id')
                        ->map(fn ($id) => (int) $id)
                        ->all();
                }
                if ($maritalIds === [] && $pc->preferred_marital_status_id) {
                    $maritalIds = [(int) $pc->preferred_marital_status_id];
                }
                if ($maritalIds !== []) {
                    $q->whereIn('marital_status_id', $maritalIds);
                }
            }
        }

        if (config('matching.strict_religion_filter', false)) {
            $relIds = DB::table('profile_preferred_religions')
                ->where('profile_id', $profile->id)
                ->pluck('religion_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            if ($relIds !== []) {
                $q->whereIn('religion_id', $relIds);
            }
        }
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
            'gender', 'maritalStatus', 'religion', 'caste', 'subCaste', 'diet', 'profession',
            'country', 'state', 'district', 'taluka', 'city',
            'preferenceCriteria',
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
        if (Schema::hasTable('profile_preferred_master_education')) {
            $this->mergePivotIds($map, 'profile_preferred_master_education', $profileIds, 'master_education_id', 'master_education_ids');
        }
        if (Schema::hasTable('profile_preferred_professions')) {
            $this->mergePivotIds($map, 'profile_preferred_professions', $profileIds, 'profession_id', 'profession_ids');
        }
        if (Schema::hasTable('profile_preferred_diets')) {
            $this->mergePivotIds($map, 'profile_preferred_diets', $profileIds, 'diet_id', 'diet_ids');
        }
        if (Schema::hasTable('profile_preferred_marital_statuses')) {
            $this->mergePivotIds($map, 'profile_preferred_marital_statuses', $profileIds, 'marital_status_id', 'marital_status_ids');
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
            'master_education_ids' => [],
            'profession_ids' => [],
            'diet_ids' => [],
            'marital_status_ids' => [],
        ];
    }

    private function mutuallyPreferenceCompatible(MatrimonyProfile $a, MatrimonyProfile $b): bool
    {
        $ab = $this->directionalPreferenceBuild($a, $b);
        $ba = $this->directionalPreferenceBuild($b, $a);

        return ($ab['counts']['not_matched'] ?? 0) === 0
            && ($ba['counts']['not_matched'] ?? 0) === 0;
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
            $points = self::WEIGHT_AGE;
            $reasons[] = __('matching.reason_age_both_in_range');
        } elseif (
            ($sa === ProfilePreferenceMatchService::STATUS_MATCH && $sb === ProfilePreferenceMatchService::STATUS_FLEXIBLE)
            || ($sa === ProfilePreferenceMatchService::STATUS_FLEXIBLE && $sb === ProfilePreferenceMatchService::STATUS_MATCH)
        ) {
            $points = (int) round(self::WEIGHT_AGE * 0.75);
            $reasons[] = __('matching.reason_age_compatible');
        } elseif ($sa === ProfilePreferenceMatchService::STATUS_FLEXIBLE && $sb === ProfilePreferenceMatchService::STATUS_FLEXIBLE) {
            $points = (int) round(self::WEIGHT_AGE * 0.6);
            $reasons[] = __('matching.reason_age_flexible');
        } else {
            $points = (int) round(self::WEIGHT_AGE * 0.45);
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
        $cidA = (int) ($a->city_id ?? 0);
        $cidB = (int) ($b->city_id ?? 0);
        if ($cidA > 0 && $cidA === $cidB) {
            return ['points' => self::WEIGHT_LOCATION, 'reasons' => [__('matching.reason_same_city')]];
        }

        $sidA = (int) ($a->state_id ?? 0);
        $sidB = (int) ($b->state_id ?? 0);
        if ($sidA > 0 && $sidA === $sidB) {
            return ['points' => (int) round(self::WEIGHT_LOCATION * 0.65), 'reasons' => [__('matching.reason_same_state')]];
        }

        $coidA = (int) ($a->country_id ?? 0);
        $coidB = (int) ($b->country_id ?? 0);
        if ($coidA > 0 && $coidA === $coidB) {
            return ['points' => (int) round(self::WEIGHT_LOCATION * 0.35), 'reasons' => [__('matching.reason_same_country')]];
        }

        return ['points' => 0, 'reasons' => []];
    }

    /**
     * @return array{points: int, reasons: list<string>}
     */
    private function scoreEducationPart(MatrimonyProfile $a, MatrimonyProfile $b): array
    {
        $idA = $this->resolveMasterEducationId($a);
        $idB = $this->resolveMasterEducationId($b);
        if ($idA === null || $idB === null) {
            return ['points' => (int) round(self::WEIGHT_EDUCATION * 0.35), 'reasons' => [__('matching.reason_education_unknown')]];
        }
        if ($idA === $idB) {
            return ['points' => self::WEIGHT_EDUCATION, 'reasons' => [__('matching.reason_education_match')]];
        }

        $sortA = (int) (MasterEducation::query()->whereKey($idA)->value('sort_order') ?? 0);
        $sortB = (int) (MasterEducation::query()->whereKey($idB)->value('sort_order') ?? 0);
        $diff = abs($sortA - $sortB);
        if ($diff <= 1) {
            return ['points' => (int) round(self::WEIGHT_EDUCATION * 0.8), 'reasons' => [__('matching.reason_education_close')]];
        }
        if ($diff <= 3) {
            return ['points' => (int) round(self::WEIGHT_EDUCATION * 0.55), 'reasons' => [__('matching.reason_education_similar')]];
        }

        return ['points' => (int) round(self::WEIGHT_EDUCATION * 0.25), 'reasons' => []];
    }

    private function resolveMasterEducationId(MatrimonyProfile $profile): ?int
    {
        $raw = trim((string) ($profile->highest_education ?? ''));
        if ($raw === '') {
            return null;
        }
        $lower = mb_strtolower($raw);
        $bestId = null;
        $bestOrder = -1;
        foreach (MasterEducation::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'sort_order']) as $me) {
            $name = mb_strtolower((string) $me->name);
            if ($name !== '' && (str_contains($lower, $name) || str_contains($name, $lower))) {
                $so = (int) $me->sort_order;
                if ($so >= $bestOrder) {
                    $bestOrder = $so;
                    $bestId = (int) $me->id;
                }
            }
        }

        return $bestId;
    }

    /**
     * @return array{points: int, reasons: list<string>}
     */
    private function scoreOccupationPart(MatrimonyProfile $a, MatrimonyProfile $b): array
    {
        $pA = (int) ($a->profession_id ?? 0);
        $pB = (int) ($b->profession_id ?? 0);
        if ($pA > 0 && $pA === $pB) {
            return ['points' => self::WEIGHT_OCCUPATION, 'reasons' => [__('matching.reason_same_occupation')]];
        }

        $a->loadMissing('profession');
        $b->loadMissing('profession');
        $wA = (int) ($a->profession?->working_with_type_id ?? 0);
        $wB = (int) ($b->profession?->working_with_type_id ?? 0);
        if ($wA > 0 && $wA === $wB) {
            return ['points' => (int) round(self::WEIGHT_OCCUPATION * 0.65), 'reasons' => [__('matching.reason_similar_work_sector')]];
        }

        if ($pA > 0 && $pB > 0) {
            return ['points' => (int) round(self::WEIGHT_OCCUPATION * 0.25), 'reasons' => []];
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
            return ['points' => self::WEIGHT_COMMUNITY, 'reasons' => [__('matching.reason_same_subcaste')]];
        }

        $casteA = (int) ($a->caste_id ?? 0);
        $casteB = (int) ($b->caste_id ?? 0);
        if ($casteA > 0 && $casteA === $casteB) {
            return ['points' => (int) round(self::WEIGHT_COMMUNITY * 0.8), 'reasons' => [__('matching.reason_same_caste')]];
        }

        $relA = (int) ($a->religion_id ?? 0);
        $relB = (int) ($b->religion_id ?? 0);
        if ($relA > 0 && $relA === $relB) {
            return ['points' => (int) round(self::WEIGHT_COMMUNITY * 0.5), 'reasons' => [__('matching.reason_same_religion')]];
        }

        return ['points' => (int) round(self::WEIGHT_COMMUNITY * 0.15), 'reasons' => []];
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
        if ($den <= 0) {
            return ['points' => (int) round(self::WEIGHT_PREFERENCES * 0.5), 'reasons' => [__('matching.reason_prefs_open')]];
        }

        $points = (int) round(self::WEIGHT_PREFERENCES * ($m / $den));
        $reasons = [];
        if ($m >= 4) {
            $reasons[] = __('matching.reason_strong_pref_alignment');
        } elseif ($m >= 2) {
            $reasons[] = __('matching.reason_good_pref_alignment');
        }

        return ['points' => min(self::WEIGHT_PREFERENCES, $points), 'reasons' => $reasons];
    }

    /**
     * Structured breakdown for {@see MatchingExplainService} (does not mutate long-lived scorer caches).
     *
     * @return array{
     *   field_parts: list<array{points: int, reasons: list<string>}>,
     *   preferred_penalties: list<array{reason: string, impact: int}>,
     *   behavior_delta: int,
     *   before_boost: int,
     *   final_score: int
     * }
     */
    public function computeMatchBreakdown(MatrimonyProfile $seeker, MatrimonyProfile $candidate): array
    {
        $savedPref = $this->prefMap;
        $savedDir = $this->directionalBuildCache;
        $savedComp = $this->componentsCache;
        try {
            $this->directionalBuildCache = [];
            $this->componentsCache = [];
            $this->prefMap = $this->bulkLoadTargetPreferences([(int) $seeker->id, (int) $candidate->id]);

            $parts = $this->scoreParts($seeker, $candidate);
            $fieldParts = [];
            $sumBase = 0;
            foreach ($parts as $p) {
                $pts = (int) ($p['points'] ?? 0);
                $sumBase += $pts;
                $fieldParts[] = [
                    'points' => $pts,
                    'reasons' => $p['reasons'] ?? [],
                ];
            }
            $baseScore = min(100, max(0, $sumBase));

            $seeker->loadMissing('user');
            $candidate->loadMissing('user');
            // Keep in sync with {@see findMatchesForTab}: field score then boost only (no behavior layer).
            $finalScore = ($seeker->user && $candidate->user)
                ? $this->matchBoost->applyBoost($seeker->user, $candidate->user, $baseScore)
                : $baseScore;

            return [
                'field_parts' => $fieldParts,
                'preferred_penalties' => [],
                'behavior_delta' => 0,
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
