<?php

namespace App\Services;

use App\Models\MatrimonyProfile;
use App\Models\SystemRule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Centralized, DB-driven compatibility scoring (Phase 3 SSOT).
 * Weights and thresholds come from {@see SystemRule} rows with keys prefixed {@code matching_}.
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

    private const RULES_CACHE_KEY = 'matching_engine:system_rules:v1';

    private const RULES_CACHE_TTL_SECONDS = 120;

    public function __construct(
        private readonly ProfileCompletionEngine $profileCompletionEngine,
    ) {}

    /**
     * @return array{
     *     score: int,
     *     grade: string,
     *     breakdown: array<string, int>,
     *     is_compatible: bool,
     * }
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
     * Same scoring as {@see for()} when only profiles are available (e.g. showcase / missing owner user).
     *
     * @return array{
     *     score: int,
     *     grade: string,
     *     breakdown: array<string, int>,
     *     is_compatible: bool,
     * }
     */
    public function scoreBetweenProfiles(MatrimonyProfile $profileA, MatrimonyProfile $profileB): array
    {
        $rules = $this->getDimensionRules();
        $score = 0;
        $breakdown = [];

        foreach ($rules as $rule) {
            $value = $this->applyRule($rule, $profileA, $profileB);
            $breakdown[$rule['key']] = $value;
            $score += $value;
        }

        return [
            'score' => $score,
            'grade' => $this->grade($score),
            'breakdown' => $breakdown,
            'is_compatible' => $score >= $this->minimumScore(),
        ];
    }

    /**
     * Full payload for profile show + search card summaries (chips, rows, engine block).
     *
     * @return array{
     *     matches: list<array{field: string, label: string, icon: string, matched: bool}>,
     *     commonGround: list<array{field: string, label: string, icon: string, value: string}>,
     *     matchedCount: int,
     *     totalCount: int,
     *     summaryText: string,
     *     celebrationText: string|null,
     *     engine: array{score: int, grade: string, breakdown: array<string, int>, is_compatible: bool},
     *     row_groups: array<string, list<array{label: string, their: string, yours: string, status: string, note: string}>>,
     *     all_rows: list<array{label: string, their: string, yours: string, status: string, note: string}>,
     *     status_counts: array{match: int, close: int, mismatch: int, open: int},
     *     smart_chips: list<array{label: string, tone: string, status: string}>,
     *     footer_line: string,
     *     preference_side_label: string,
     *     your_side_label: string,
     * }
     */
    public function profileShowMatchData(User $viewerUser, MatrimonyProfile $viewedProfile): array
    {
        $viewerProfile = $viewerUser->matrimonyProfile;
        if (! $viewerProfile instanceof MatrimonyProfile) {
            return $this->emptyUiPayload();
        }

        $viewerProfile->loadMissing([
            'gender', 'maritalStatus', 'religion', 'motherTongue', 'city', 'state', 'profession', 'diet', 'familyType', 'caste', 'subCaste',
        ]);
        $viewedProfile->loadMissing([
            'gender', 'maritalStatus', 'religion', 'motherTongue', 'city', 'state', 'profession', 'diet', 'familyType', 'caste', 'subCaste',
        ]);

        $viewedUser = $viewedProfile->relationLoaded('user')
            ? $viewedProfile->user
            : $viewedProfile->user()->first();

        $engine = $viewedUser instanceof User
            ? $this->for($viewerUser, $viewedUser)
            : $this->scoreBetweenProfiles($viewerProfile, $viewedProfile);

        $rowGroups = $this->buildComparisonRowGroups($viewerProfile, $viewedProfile);
        $allRows = [];
        foreach ($rowGroups as $rows) {
            foreach ($rows as $r) {
                $allRows[] = $r;
            }
        }

        $statusCounts = ['match' => 0, 'close' => 0, 'mismatch' => 0, 'open' => 0];
        foreach ($allRows as $r) {
            $statusCounts[$r['status']]++;
        }

        $matches = $this->buildChipMatchesFromBreakdown($engine['breakdown']);
        $matchedCount = count(array_filter($matches, fn ($m) => $m['matched']));
        $totalCount = count($matches);

        $summaryText = $totalCount > 0 && $matchedCount > 0
            ? __('Your profile matches :matched of :total expectations', ['matched' => $matchedCount, 'total' => $totalCount])
            : __('Some match with this profile.');

        $celebrationText = null;
        if ($matchedCount >= 3) {
            $celebrationText = __('Many things match!');
        } elseif ($matchedCount > 0) {
            $celebrationText = __('Good start 👍');
        }

        $commonGround = $this->buildCommonGroundFromBreakdown($viewedProfile, $engine['breakdown']);

        $chipPriority = ['location', 'age', 'highest_education', 'caste_id'];
        $chipMap = [];
        foreach ($matches as $m) {
            $chipMap[$m['field']] = [
                'label' => $m['label'],
                'tone' => $m['matched'] ? 'match' : 'mismatch',
                'status' => $m['matched'] ? 'Aligned' : 'Different',
            ];
        }
        $smartChips = [];
        foreach ($chipPriority as $f) {
            if (isset($chipMap[$f])) {
                $smartChips[] = $chipMap[$f];
            }
        }
        $smartChips = array_slice($smartChips, 0, 5);

        $footerLine = $this->buildFooterLine($allRows);

        $viewedGenderKey = strtolower((string) ($viewedProfile->gender?->key ?? ''));
        $preferenceSideLabel = $viewedGenderKey === 'female'
            ? 'Her preference'
            : ($viewedGenderKey === 'male' ? 'His preference' : 'Preferred');

        return [
            'matches' => $matches,
            'commonGround' => $commonGround,
            'matchedCount' => $matchedCount,
            'totalCount' => $totalCount,
            'summaryText' => $summaryText,
            'celebrationText' => $celebrationText,
            'engine' => $engine,
            'row_groups' => $rowGroups,
            'all_rows' => $allRows,
            'status_counts' => $statusCounts,
            'smart_chips' => $smartChips,
            'footer_line' => $footerLine,
            'preference_side_label' => $preferenceSideLabel,
            'your_side_label' => 'Your profile',
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
        return match ($rule['key']) {
            self::RULE_MATCHING_AGE => $this->matchAge($profileA, $profileB, $rule),
            self::RULE_MATCHING_LOCATION => $this->matchLocation($profileA, $profileB, $rule),
            self::RULE_MATCHING_EDUCATION => $this->matchEducation($profileA, $profileB, $rule),
            self::RULE_MATCHING_CASTE => $this->matchCaste($profileA, $profileB, $rule),
            self::RULE_MATCHING_PROFILE_COMPLETION => $this->matchProfileCompletion($profileA, $profileB, $rule),
            default => 0,
        };
    }

    private function matchAge(MatrimonyProfile $a, MatrimonyProfile $b, array $rule): int
    {
        if (! $a->date_of_birth || ! $b->date_of_birth) {
            return 0;
        }
        $maxDiff = (int) ($rule['meta']['max_age_diff_years'] ?? 5);
        $maxDiff = $maxDiff > 0 ? $maxDiff : 5;
        $ageA = Carbon::parse($a->date_of_birth)->age;
        $ageB = Carbon::parse($b->date_of_birth)->age;

        return abs($ageA - $ageB) <= $maxDiff ? $rule['weight'] : 0;
    }

    private function matchLocation(MatrimonyProfile $a, MatrimonyProfile $b, array $rule): int
    {
        $sameCity = $a->city_id && $b->city_id && (int) $a->city_id === (int) $b->city_id;
        $sameState = $a->state_id && $b->state_id && (int) $a->state_id === (int) $b->state_id;

        return ($sameCity || $sameState) ? $rule['weight'] : 0;
    }

    private function matchEducation(MatrimonyProfile $a, MatrimonyProfile $b, array $rule): int
    {
        $ea = trim((string) ($a->highest_education ?? ''));
        $eb = trim((string) ($b->highest_education ?? ''));
        if ($ea === '' || $eb === '') {
            return 0;
        }

        return strcasecmp($ea, $eb) === 0 ? $rule['weight'] : 0;
    }

    private function matchCaste(MatrimonyProfile $a, MatrimonyProfile $b, array $rule): int
    {
        if (! $a->caste_id || ! $b->caste_id) {
            return 0;
        }

        return (int) $a->caste_id === (int) $b->caste_id ? $rule['weight'] : 0;
    }

    private function matchProfileCompletion(MatrimonyProfile $a, MatrimonyProfile $b, array $rule): int
    {
        $minPct = (int) ($rule['meta']['min_mandatory_pct'] ?? 80);
        $minPct = max(0, min(100, $minPct));
        $aPct = $this->profileCompletionEngine->forProfile($a)['mandatory_core'];
        $bPct = $this->profileCompletionEngine->forProfile($b)['mandatory_core'];

        return ($aPct >= $minPct && $bPct >= $minPct) ? $rule['weight'] : 0;
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

    /**
     * @return array{score: int, grade: string, breakdown: array<string, int>, is_compatible: bool}
     */
    private function emptyScorePayload(): array
    {
        return [
            'score' => 0,
            'grade' => 'Low',
            'breakdown' => [],
            'is_compatible' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyUiPayload(): array
    {
        return [
            'matches' => [],
            'commonGround' => [],
            'matchedCount' => 0,
            'totalCount' => 0,
            'summaryText' => __('Some match with this profile.'),
            'celebrationText' => null,
            'engine' => $this->emptyScorePayload(),
            'row_groups' => [],
            'all_rows' => [],
            'status_counts' => ['match' => 0, 'close' => 0, 'mismatch' => 0, 'open' => 0],
            'smart_chips' => [],
            'footer_line' => 'Some preferences remain open and can be discussed.',
            'preference_side_label' => 'Preferred',
            'your_side_label' => 'Your profile',
        ];
    }

    /**
     * @param  array<string, int>  $breakdown
     * @return list<array{field: string, label: string, icon: string, matched: bool}>
     */
    private function buildChipMatchesFromBreakdown(array $breakdown): array
    {
        $map = [
            self::RULE_MATCHING_LOCATION => ['field' => 'location', 'label' => 'Location', 'icon' => '📍'],
            self::RULE_MATCHING_AGE => ['field' => 'age', 'label' => 'Age', 'icon' => '🎂'],
            self::RULE_MATCHING_EDUCATION => ['field' => 'highest_education', 'label' => 'Education', 'icon' => '🎓'],
            self::RULE_MATCHING_CASTE => ['field' => 'caste_id', 'label' => 'Caste', 'icon' => '🗣️'],
            self::RULE_MATCHING_PROFILE_COMPLETION => ['field' => 'profile_completion', 'label' => 'Profile completeness', 'icon' => '✅'],
        ];
        $out = [];
        foreach ($map as $key => $meta) {
            if (! array_key_exists($key, $breakdown)) {
                continue;
            }
            $out[] = [
                'field' => $meta['field'],
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'matched' => $breakdown[$key] > 0,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $breakdown
     * @return list<array{field: string, label: string, icon: string, value: string}>
     */
    private function buildCommonGroundFromBreakdown(MatrimonyProfile $viewed, array $breakdown): array
    {
        $cg = [];
        if (($breakdown[self::RULE_MATCHING_LOCATION] ?? 0) > 0) {
            $cg[] = [
                'field' => 'location',
                'label' => 'Location',
                'icon' => '📍',
                'value' => $viewed->city_id ? (string) ($viewed->city?->name ?? '—') : (string) ($viewed->state?->name ?? '—'),
            ];
        }
        if (($breakdown[self::RULE_MATCHING_EDUCATION] ?? 0) > 0) {
            $cg[] = [
                'field' => 'highest_education',
                'label' => 'Education',
                'icon' => '🎓',
                'value' => (string) ($viewed->highest_education ?? ''),
            ];
        }
        if (($breakdown[self::RULE_MATCHING_CASTE] ?? 0) > 0) {
            $cg[] = [
                'field' => 'caste_id',
                'label' => 'Caste',
                'icon' => '🗣️',
                'value' => (string) ($viewed->caste?->display_label ?? $viewed->caste_id),
            ];
        }

        return $cg;
    }

    /**
     * @return array<string, list<array{label: string, their: string, yours: string, status: string, note: string}>>
     */
    private function buildComparisonRowGroups(MatrimonyProfile $viewer, MatrimonyProfile $viewed): array
    {
        $rowGroups = [];

        $addRow = function (string $group, string $label, string $their, string $yours, string $status, string $note = '') use (&$rowGroups): void {
            if (! isset($rowGroups[$group])) {
                $rowGroups[$group] = [];
            }
            $rowGroups[$group][] = [
                'label' => $label,
                'their' => $their !== '' ? $their : 'Not specified',
                'yours' => $yours !== '' ? $yours : 'Not specified',
                'status' => $status,
                'note' => $note,
            ];
        };

        $safeAge = function ($dob): ?int {
            if (empty($dob)) {
                return null;
            }
            try {
                $age = Carbon::parse($dob)->age;
                if (! is_numeric($age)) {
                    return null;
                }
                $age = (int) floor((float) $age);

                return $age >= 0 ? $age : null;
            } catch (\Throwable) {
                return null;
            }
        };

        $viewerAge = $safeAge($viewer->date_of_birth ?? null);
        $viewedAge = $safeAge($viewed->date_of_birth ?? null);
        if ($viewedAge !== null || $viewerAge !== null) {
            $ageDiff = ($viewedAge !== null && $viewerAge !== null) ? abs($viewedAge - $viewerAge) : null;
            $ageStatus = ($ageDiff === null)
                ? 'open'
                : ($ageDiff <= 5 ? 'match' : ($ageDiff <= 8 ? 'close' : 'mismatch'));
            $ageNote = ($viewedAge !== null && $viewerAge !== null) ? "You are {$viewerAge} years; profile age is {$viewedAge} years" : '';
            $addRow('Basic fit', 'Age', $viewedAge !== null ? (string) $viewedAge : '', $viewerAge !== null ? (string) $viewerAge : '', $ageStatus, $ageNote);
        }

        if (($viewed->maritalStatus?->label ?? '') !== '' || ($viewer->maritalStatus?->label ?? '') !== '') {
            $their = (string) ($viewed->maritalStatus?->label ?? '');
            $yours = (string) ($viewer->maritalStatus?->label ?? '');
            $status = ($their !== '' && $yours !== '') ? (strcasecmp($their, $yours) === 0 ? 'match' : 'mismatch') : 'open';
            $addRow('Basic fit', 'Marital status', $their, $yours, $status);
        }

        if (($viewed->height_cm ?? null) || ($viewer->height_cm ?? null)) {
            $their = ($viewed->height_cm ?? null) ? ((string) $viewed->height_cm.' cm') : '';
            $yours = ($viewer->height_cm ?? null) ? ((string) $viewer->height_cm.' cm') : '';
            $heightDiff = ($their !== '' && $yours !== '') ? abs((int) $viewed->height_cm - (int) $viewer->height_cm) : null;
            $status = ($heightDiff === null) ? 'open' : ($heightDiff <= 8 ? 'match' : ($heightDiff <= 12 ? 'close' : 'open'));
            $addRow('Basic fit', 'Height', $their, $yours, $status);
        }

        $theirReligion = (string) ($viewed->religion?->label ?? '');
        $yourReligion = (string) ($viewer->religion?->label ?? '');
        if ($theirReligion !== '' || $yourReligion !== '') {
            $status = ($theirReligion !== '' && $yourReligion !== '') ? (strcasecmp($theirReligion, $yourReligion) === 0 ? 'match' : 'mismatch') : 'open';
            $addRow('Community & background', 'Religion', $theirReligion, $yourReligion, $status);
        }

        $theirMotherTongue = (string) ($viewed->motherTongue?->label ?? '');
        $yourMotherTongue = (string) ($viewer->motherTongue?->label ?? '');
        if ($theirMotherTongue !== '' || $yourMotherTongue !== '') {
            $status = ($theirMotherTongue !== '' && $yourMotherTongue !== '') ? (strcasecmp($theirMotherTongue, $yourMotherTongue) === 0 ? 'match' : 'open') : 'open';
            $addRow('Community & background', 'Mother tongue', $theirMotherTongue, $yourMotherTongue, $status);
        }

        $theirEducation = trim((string) ($viewed->highest_education ?? ''));
        $yourEducation = trim((string) ($viewer->highest_education ?? ''));
        if ($theirEducation !== '' || $yourEducation !== '') {
            $status = ($theirEducation !== '' && $yourEducation !== '') ? (strcasecmp($theirEducation, $yourEducation) === 0 ? 'match' : 'mismatch') : 'open';
            $addRow('Career & location', 'Education', $theirEducation, $yourEducation, $status);
        }

        $theirOccupation = trim((string) (($viewed->occupation_title ?? '') !== '' ? $viewed->occupation_title : ($viewed->profession?->name ?? '')));
        $yourOccupation = trim((string) (($viewer->occupation_title ?? '') !== '' ? $viewer->occupation_title : ($viewer->profession?->name ?? '')));
        if ($theirOccupation !== '' || $yourOccupation !== '') {
            $status = ($theirOccupation !== '' && $yourOccupation !== '') ? (strcasecmp($theirOccupation, $yourOccupation) === 0 ? 'match' : 'open') : 'open';
            $addRow('Career & location', 'Occupation', $theirOccupation, $yourOccupation, $status);
        }

        $viewerLocation = implode(', ', array_filter([$viewer->city?->name, $viewer->state?->name]));
        $viewedLocation = implode(', ', array_filter([$viewed->city?->name, $viewed->state?->name]));
        if ($viewedLocation !== '' || $viewerLocation !== '') {
            $sameCity = ($viewed->city_id && $viewer->city_id) ? ((int) $viewed->city_id === (int) $viewer->city_id) : false;
            $sameState = ($viewed->state_id && $viewer->state_id) ? ((int) $viewed->state_id === (int) $viewer->state_id) : false;
            $status = $sameCity ? 'match' : ($sameState ? 'close' : (($viewedLocation !== '' && $viewerLocation !== '') ? 'mismatch' : 'open'));
            $note = $sameCity ? 'Lives in the same city' : ($sameState ? 'Lives in the same state' : '');
            $addRow('Career & location', 'Location', $viewedLocation, $viewerLocation, $status, $note);
        }

        $theirDiet = (string) ($viewed->diet?->label ?? '');
        $yourDiet = (string) ($viewer->diet?->label ?? '');
        if ($theirDiet !== '' || $yourDiet !== '') {
            $status = ($theirDiet !== '' && $yourDiet !== '') ? (strcasecmp($theirDiet, $yourDiet) === 0 ? 'match' : 'open') : 'open';
            $addRow('Lifestyle & family', 'Diet', $theirDiet, $yourDiet, $status);
        }

        $theirFamilyType = (string) ($viewed->familyType?->label ?? '');
        $yourFamilyType = (string) ($viewer->familyType?->label ?? '');
        if ($theirFamilyType !== '' || $yourFamilyType !== '') {
            $status = ($theirFamilyType !== '' && $yourFamilyType !== '') ? (strcasecmp($theirFamilyType, $yourFamilyType) === 0 ? 'match' : 'open') : 'open';
            $addRow('Lifestyle & family', 'Family type', $theirFamilyType, $yourFamilyType, $status);
        }

        return $rowGroups;
    }

    /**
     * @param  list<array{label: string, their: string, yours: string, status: string, note: string}>  $allRows
     */
    private function buildFooterLine(array $allRows): string
    {
        $strongest = [];
        foreach ($allRows as $r) {
            if ($r['status'] === 'match') {
                $strongest[] = $r['label'];
            }
        }
        $needsAttention = [];
        foreach ($allRows as $r) {
            if ($r['status'] === 'mismatch') {
                $needsAttention[] = $r['label'];
            }
        }
        $footerLine = $strongest !== []
            ? ('This match is strongest in '.implode(', ', array_slice($strongest, 0, 2)).'.')
            : 'Some preferences remain open and can be discussed.';
        if ($needsAttention !== []) {
            $footerLine .= ' '.implode(', ', array_slice($needsAttention, 0, 2)).' need attention.';
        }

        return $footerLine;
    }
}
