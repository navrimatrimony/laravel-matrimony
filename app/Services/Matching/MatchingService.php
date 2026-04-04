<?php

namespace App\Services\Matching;

use App\Models\MasterEducation;
use App\Models\MatrimonyProfile;
use App\Models\ProfileMatch;
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

    /** @var array<string, array<string, mixed>> */
    private array $prefMap = [];

    /** @var array<string, array<string, mixed>> */
    private array $directionalBuildCache = [];

    /** @var array<string, array<string, mixed>> */
    private array $componentsCache = [];

    /**
     * @return Collection<int, array{profile: MatrimonyProfile, score: int, reasons: list<string>}>
     */
    public function findMatches(MatrimonyProfile $profile, int $limit = 20): Collection
    {
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

        $poolLimit = max(1, (int) config('matching.candidate_pool_limit', 200));
        $candidates = $this->baseCandidateQuery($profile, $oppositeKey)->limit($poolLimit)->get();

        $this->eagerLoadMatchingRelations($candidates);

        $ids = $candidates->pluck('id')->push($profile->id)->unique()->values()->all();
        $this->prefMap = $this->bulkLoadTargetPreferences($ids);

        $out = collect();
        foreach ($candidates as $candidate) {
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

            $out->push([
                'profile' => $candidate,
                'score' => $score,
                'reasons' => $reasons,
            ]);
        }

        $sorted = $out->sortByDesc('score')->values()->take($limit);

        if (config('matching.persist_cache', false) && Schema::hasTable('profile_matches')) {
            $this->replacePersistedMatches($profile, $sorted);
        }

        return $sorted;
    }

    /**
     * @return Builder<MatrimonyProfile>
     */
    private function baseCandidateQuery(MatrimonyProfile $profile, string $oppositeGenderKey): Builder
    {
        $q = MatrimonyProfile::query()
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

            if (config('matching.strict_marital_filter', false) && $pc->preferred_marital_status_id) {
                $q->where('marital_status_id', (int) $pc->preferred_marital_status_id);
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

        return $q->orderByDesc('updated_at');
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
     * @param  Collection<int, array{profile: MatrimonyProfile, score: int, reasons: list<string>}>  $results
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
