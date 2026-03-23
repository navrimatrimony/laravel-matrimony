<?php

namespace App\Services;

use App\Models\Caste;
use App\Models\District;
use App\Models\MasterEducation;
use App\Models\MatrimonyProfile;
use App\Models\Religion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only comparison: how well the viewer's profile fits the target profile's partner preferences.
 * No DB writes, no scores, no ranking engine.
 */
class ProfilePreferenceMatchService
{
    public const STATUS_MATCH = 'match';

    public const STATUS_FLEXIBLE = 'flexible';

    public const STATUS_NOT_MATCHED = 'not_matched';

    public const STATUS_UNKNOWN = 'unknown';

    public const STRICT_OPEN = 'open';

    public const STRICT_PREFERRED = 'preferred';

    public const STRICT_MUST_MATCH = 'must_match';

    /**
     * @return array<string, mixed>
     */
    public static function build(MatrimonyProfile $viewerProfile, MatrimonyProfile $targetProfile): array
    {
        $viewerProfile->loadMissing([
            'gender', 'maritalStatus', 'religion', 'caste', 'subCaste', 'diet', 'profession',
            'country', 'state', 'district', 'taluka', 'city',
        ]);

        $pref = self::loadTargetPreferences($targetProfile->id);
        $criteria = $pref['criteria'];

        $groups = [
            'basic' => [],
            'community' => [],
            'location' => [],
            'education_career' => [],
            'lifestyle' => [],
        ];

        $groups['basic'][] = self::rowAge($viewerProfile, $criteria);
        $groups['basic'][] = self::rowHeight($viewerProfile, $criteria);
        $groups['basic'][] = self::rowMaritalStatus($viewerProfile, $criteria);

        $groups['community'][] = self::rowReligion($viewerProfile, $pref['religion_ids']);
        $groups['community'][] = self::rowCaste($viewerProfile, $pref['caste_ids']);

        $groups['location'][] = self::rowLocation($viewerProfile, $pref);

        $groups['education_career'][] = self::rowEducation($viewerProfile, $pref['master_education_ids']);
        $groups['education_career'][] = self::rowProfession($viewerProfile, $pref['profession_ids']);
        $groups['education_career'][] = self::rowIncome($viewerProfile, $criteria);

        $groups['lifestyle'][] = self::rowDiet($viewerProfile, $pref['diet_ids']);

        $groups = array_map(fn ($rows) => array_values(array_filter($rows)), $groups);

        $flat = [];
        foreach ($groups as $rows) {
            foreach ($rows as $r) {
                $flat[] = $r;
            }
        }

        $counts = ['match' => 0, 'flexible' => 0, 'not_matched' => 0, 'unknown' => 0];
        foreach ($flat as $r) {
            $s = $r['status'] ?? self::STATUS_UNKNOWN;
            if (isset($counts[$s])) {
                $counts[$s]++;
            }
        }

        $fitBadge = self::resolveFitBadge($counts);
        $discussion = self::discussionTopics($flat);
        $helper = self::helperText($fitBadge, $counts);

        $targetHasPreference = self::targetHasAnyPreference($pref, $criteria);

        return [
            'groups' => $groups,
            'rows' => $flat,
            'counts' => $counts,
            'fit_badge' => $fitBadge,
            'discussion_topics' => $discussion,
            'helper_text' => $helper,
            'target_has_preferences' => $targetHasPreference,
            'viewer_profile_incomplete' => in_array(self::STATUS_UNKNOWN, array_column($flat, 'status'), true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadTargetPreferences(int $targetProfileId): array
    {
        $criteria = DB::table('profile_preference_criteria')->where('profile_id', $targetProfileId)->first();

        $religionIds = DB::table('profile_preferred_religions')->where('profile_id', $targetProfileId)->pluck('religion_id')->map(fn ($id) => (int) $id)->all();
        $casteIds = DB::table('profile_preferred_castes')->where('profile_id', $targetProfileId)->pluck('caste_id')->map(fn ($id) => (int) $id)->all();
        $districtIds = DB::table('profile_preferred_districts')->where('profile_id', $targetProfileId)->pluck('district_id')->map(fn ($id) => (int) $id)->all();

        $countryIds = Schema::hasTable('profile_preferred_countries')
            ? DB::table('profile_preferred_countries')->where('profile_id', $targetProfileId)->pluck('country_id')->map(fn ($id) => (int) $id)->all()
            : [];
        $stateIds = Schema::hasTable('profile_preferred_states')
            ? DB::table('profile_preferred_states')->where('profile_id', $targetProfileId)->pluck('state_id')->map(fn ($id) => (int) $id)->all()
            : [];
        $talukaIds = Schema::hasTable('profile_preferred_talukas')
            ? DB::table('profile_preferred_talukas')->where('profile_id', $targetProfileId)->pluck('taluka_id')->map(fn ($id) => (int) $id)->all()
            : [];

        $masterEducationIds = Schema::hasTable('profile_preferred_master_education')
            ? DB::table('profile_preferred_master_education')->where('profile_id', $targetProfileId)->pluck('master_education_id')->map(fn ($id) => (int) $id)->all()
            : [];
        $professionIds = Schema::hasTable('profile_preferred_professions')
            ? DB::table('profile_preferred_professions')->where('profile_id', $targetProfileId)->pluck('profession_id')->map(fn ($id) => (int) $id)->all()
            : [];
        $dietIds = Schema::hasTable('profile_preferred_diets')
            ? DB::table('profile_preferred_diets')->where('profile_id', $targetProfileId)->pluck('diet_id')->map(fn ($id) => (int) $id)->all()
            : [];

        return [
            'criteria' => $criteria,
            'religion_ids' => $religionIds,
            'caste_ids' => $casteIds,
            'district_ids' => $districtIds,
            'country_ids' => $countryIds,
            'state_ids' => $stateIds,
            'taluka_ids' => $talukaIds,
            'master_education_ids' => $masterEducationIds,
            'profession_ids' => $professionIds,
            'diet_ids' => $dietIds,
        ];
    }

    private static function targetHasAnyPreference(array $pref, ?object $criteria): bool
    {
        foreach (['religion_ids', 'caste_ids', 'district_ids', 'country_ids', 'state_ids', 'taluka_ids', 'master_education_ids', 'profession_ids', 'diet_ids'] as $k) {
            if (! empty($pref[$k])) {
                return true;
            }
        }
        if (! $criteria) {
            return false;
        }
        $c = (array) $criteria;

        return ($c['preferred_age_min'] ?? null) !== null
            || ($c['preferred_age_max'] ?? null) !== null
            || ($c['preferred_height_min_cm'] ?? null) !== null
            || ($c['preferred_height_max_cm'] ?? null) !== null
            || ($c['preferred_marital_status_id'] ?? null) !== null
            || ($c['preferred_income_min'] ?? null) !== null
            || ($c['preferred_income_max'] ?? null) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function rowAge(MatrimonyProfile $viewer, ?object $criteria): array
    {
        $min = $criteria?->preferred_age_min ?? null;
        $max = $criteria?->preferred_age_max ?? null;
        $their = ($min !== null && $max !== null)
            ? (string) $min.' – '.$max
            : __('preference_match.no_preference_set');

        $age = self::viewerAge($viewer);
        $yours = $age !== null ? (string) $age : __('preference_match.value_unknown');

        if ($age === null) {
            return self::row('age', __('preference_match.field_age'), $their, $yours, self::STRICT_OPEN, self::STATUS_UNKNOWN, __('preference_match.reason_missing_dob'));
        }
        if ($min === null || $max === null) {
            return self::row('age', __('preference_match.field_age'), $their, $yours, self::STRICT_OPEN, self::STATUS_FLEXIBLE, __('preference_match.reason_no_age_range'));
        }

        if ($age >= (int) $min && $age <= (int) $max) {
            return self::row('age', __('preference_match.field_age'), $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_MATCH, null);
        }
        if ($age >= (int) $min - 2 && $age <= (int) $max + 2) {
            return self::row('age', __('preference_match.field_age'), $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_FLEXIBLE, __('preference_match.reason_age_near_range'));
        }

        return self::row('age', __('preference_match.field_age'), $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_NOT_MATCHED, __('preference_match.reason_age_outside'));
    }

    private static function viewerAge(MatrimonyProfile $viewer): ?int
    {
        if (! $viewer->date_of_birth) {
            return null;
        }
        try {
            return max(0, now()->diffInYears($viewer->date_of_birth));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function rowHeight(MatrimonyProfile $viewer, ?object $criteria): array
    {
        $min = $criteria?->preferred_height_min_cm ?? null;
        $max = $criteria?->preferred_height_max_cm ?? null;
        $their = ($min !== null && $max !== null)
            ? (string) $min.' – '.$max.' cm'
            : __('preference_match.no_preference_set');

        $h = $viewer->height_cm;
        $yours = ($h !== null && $h !== '') ? (string) (int) $h.' cm' : __('preference_match.value_unknown');

        if ($h === null || $h === '') {
            return self::row('height', __('preference_match.field_height'), $their, $yours, self::STRICT_OPEN, self::STATUS_UNKNOWN, __('preference_match.reason_missing_height'));
        }
        $hc = (int) $h;
        if ($min === null || $max === null) {
            return self::row('height', __('preference_match.field_height'), $their, $yours, self::STRICT_OPEN, self::STATUS_FLEXIBLE, __('preference_match.reason_no_height_range'));
        }
        $mn = (int) $min;
        $mx = (int) $max;
        if ($hc >= $mn && $hc <= $mx) {
            return self::row('height', __('preference_match.field_height'), $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_MATCH, null);
        }
        if ($hc >= $mn - 3 && $hc <= $mx + 3) {
            return self::row('height', __('preference_match.field_height'), $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_FLEXIBLE, __('preference_match.reason_height_near_range'));
        }

        return self::row('height', __('preference_match.field_height'), $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_NOT_MATCHED, __('preference_match.reason_height_outside'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function rowMaritalStatus(MatrimonyProfile $viewer, ?object $criteria): array
    {
        $prefId = $criteria?->preferred_marital_status_id ?? null;
        $their = $prefId
            ? (string) (DB::table('master_marital_statuses')->where('id', $prefId)->value('label') ?? __('preference_match.preferred_status'))
            : __('preference_match.open_to_all');

        $yours = $viewer->maritalStatus?->label ?? __('preference_match.value_unknown');
        if (! $viewer->marital_status_id) {
            return self::row('marital_status', __('preference_match.field_marital'), $their, $yours, self::STRICT_OPEN, self::STATUS_UNKNOWN, __('preference_match.reason_missing_marital'));
        }
        if ($prefId === null) {
            return self::row('marital_status', __('preference_match.field_marital'), $their, $yours, self::STRICT_OPEN, self::STATUS_FLEXIBLE, __('preference_match.reason_marital_open'));
        }
        if ((int) $viewer->marital_status_id === (int) $prefId) {
            return self::row('marital_status', __('preference_match.field_marital'), $their, $yours, self::STRICT_PREFERRED, self::STATUS_MATCH, null);
        }

        return self::row('marital_status', __('preference_match.field_marital'), $their, $yours, self::STRICT_PREFERRED, self::STATUS_FLEXIBLE, __('preference_match.reason_marital_differs'));
    }

    /**
     * @param  array<int, int>  $religionIds
     * @return array<string, mixed>
     */
    private static function rowReligion(MatrimonyProfile $viewer, array $religionIds): array
    {
        $their = $religionIds === []
            ? ''
            : Religion::query()->whereIn('id', $religionIds)->get()->map(fn ($r) => $r->display_label)->implode(', ');
        if ($their === '') {
            $their = __('preference_match.open_to_all');
        }
        $yours = $viewer->religion?->label ?? __('preference_match.value_unknown');
        if (! $viewer->religion_id) {
            return self::row('religion', __('preference_match.field_religion'), $their, $yours, self::STRICT_OPEN, self::STATUS_UNKNOWN, __('preference_match.reason_missing_religion'));
        }
        if ($religionIds === []) {
            return self::row('religion', __('preference_match.field_religion'), $their, $yours, self::STRICT_OPEN, self::STATUS_FLEXIBLE, __('preference_match.reason_pref_open'));
        }
        if (in_array((int) $viewer->religion_id, $religionIds, true)) {
            return self::row('religion', __('preference_match.field_religion'), $their, $yours, self::STRICT_PREFERRED, self::STATUS_MATCH, null);
        }

        return self::row('religion', __('preference_match.field_religion'), $their, $yours, self::STRICT_PREFERRED, self::STATUS_FLEXIBLE, __('preference_match.reason_religion_not_listed'));
    }

    /**
     * @param  array<int, int>  $casteIds
     * @return array<string, mixed>
     */
    private static function rowCaste(MatrimonyProfile $viewer, array $casteIds): array
    {
        $their = $casteIds === []
            ? ''
            : Caste::query()->whereIn('id', $casteIds)->get()->map(fn ($c) => $c->display_label)->implode(', ');
        if ($their === '') {
            $their = __('preference_match.open_to_all');
        }
        $yours = $viewer->caste?->display_label ?? $viewer->caste?->label ?? __('preference_match.value_unknown');
        if (! $viewer->caste_id) {
            return self::row('caste', __('preference_match.field_caste'), $their, $yours, self::STRICT_OPEN, self::STATUS_UNKNOWN, __('preference_match.reason_missing_caste'));
        }
        if ($casteIds === []) {
            return self::row('caste', __('preference_match.field_caste'), $their, $yours, self::STRICT_OPEN, self::STATUS_FLEXIBLE, __('preference_match.reason_pref_open'));
        }
        if (in_array((int) $viewer->caste_id, $casteIds, true)) {
            return self::row('caste', __('preference_match.field_caste'), $their, $yours, self::STRICT_PREFERRED, self::STATUS_MATCH, null);
        }

        return self::row('caste', __('preference_match.field_caste'), $their, $yours, self::STRICT_PREFERRED, self::STATUS_FLEXIBLE, __('preference_match.reason_caste_not_listed'));
    }

    /**
     * @param  array<string, mixed>  $pref
     * @return array<string, mixed>
     */
    private static function rowLocation(MatrimonyProfile $viewer, array $pref): array
    {
        $dIds = $pref['district_ids'];
        $sIds = $pref['state_ids'];
        $cIds = $pref['country_ids'];
        $tIds = $pref['taluka_ids'];

        $their = self::describeLocationPreference($pref);
        if ($their === '') {
            $their = __('preference_match.open_to_all');
        }

        $yParts = array_filter([
            $viewer->country?->name,
            $viewer->state?->name,
            $viewer->district?->name,
            $viewer->city?->name,
        ]);
        $yours = $yParts !== [] ? implode(', ', $yParts) : __('preference_match.value_unknown');

        $hasAny = $dIds !== [] || $sIds !== [] || $cIds !== [] || $tIds !== [];
        if (! $viewer->country_id && ! $viewer->state_id && ! $viewer->district_id && ! $viewer->city_id) {
            return self::row('location', __('preference_match.field_location'), $their, $yours, self::STRICT_OPEN, self::STATUS_UNKNOWN, __('preference_match.reason_missing_location'));
        }
        if (! $hasAny) {
            return self::row('location', __('preference_match.field_location'), $their, $yours, self::STRICT_OPEN, self::STATUS_FLEXIBLE, __('preference_match.reason_pref_open'));
        }

        $vd = (int) ($viewer->district_id ?? 0);
        if ($vd > 0 && $dIds !== [] && in_array($vd, $dIds, true)) {
            return self::row('location', __('preference_match.field_location'), $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_MATCH, null);
        }

        $vs = (int) ($viewer->state_id ?? 0);
        if ($vs > 0 && $sIds !== [] && in_array($vs, $sIds, true)) {
            return self::row('location', __('preference_match.field_location'), $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_FLEXIBLE, __('preference_match.reason_location_state_aligns'));
        }
        if ($vd > 0 && $dIds !== []) {
            $dStateIds = District::query()->whereIn('id', $dIds)->pluck('state_id')->unique()->filter()->all();
            if ($vs > 0 && in_array($vs, array_map('intval', $dStateIds), true)) {
                return self::row('location', __('preference_match.field_location'), $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_FLEXIBLE, __('preference_match.reason_location_same_state'));
            }
        }

        $vc = (int) ($viewer->country_id ?? 0);
        if ($vc > 0 && $cIds !== [] && in_array($vc, $cIds, true)) {
            return self::row('location', __('preference_match.field_location'), $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_FLEXIBLE, __('preference_match.reason_location_country_aligns'));
        }

        if ($vd > 0 && $dIds !== []) {
            $prefCountries = DB::table('districts')
                ->join('states', 'states.id', '=', 'districts.state_id')
                ->whereIn('districts.id', $dIds)
                ->pluck('states.country_id')
                ->unique()
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->all();
            if ($vc > 0 && $prefCountries !== [] && in_array($vc, $prefCountries, true)) {
                return self::row('location', __('preference_match.field_location'), $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_FLEXIBLE, __('preference_match.reason_location_same_country'));
            }
        }

        return self::row('location', __('preference_match.field_location'), $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_NOT_MATCHED, __('preference_match.reason_location_mismatch'));
    }

    /**
     * @param  array<string, mixed>  $pref
     */
    private static function describeLocationPreference(array $pref): string
    {
        $parts = [];
        if ($pref['country_ids'] !== []) {
            $parts[] = DB::table('countries')->whereIn('id', $pref['country_ids'])->pluck('name')->filter()->implode(', ');
        }
        if ($pref['state_ids'] !== []) {
            $parts[] = DB::table('states')->whereIn('id', $pref['state_ids'])->pluck('name')->filter()->implode(', ');
        }
        if ($pref['district_ids'] !== []) {
            $parts[] = DB::table('districts')->whereIn('id', $pref['district_ids'])->pluck('name')->filter()->implode(', ');
        }
        if ($pref['taluka_ids'] !== []) {
            $parts[] = DB::table('talukas')->whereIn('id', $pref['taluka_ids'])->pluck('name')->filter()->implode(', ');
        }

        return trim(implode(' · ', array_filter($parts)));
    }

    /**
     * @param  array<int, int>  $masterEducationIds
     * @return array<string, mixed>
     */
    private static function rowEducation(MatrimonyProfile $viewer, array $masterEducationIds): array
    {
        $their = self::labelsForIds('master_education', $masterEducationIds, 'name');
        if ($their === '') {
            $their = __('preference_match.open_to_all');
        }

        $viewerMeId = self::resolveViewerMasterEducationId($viewer);
        $yours = $viewerMeId
            ? (string) (MasterEducation::query()->whereKey($viewerMeId)->value('name') ?? $viewer->highest_education)
            : (string) ($viewer->highest_education ?: __('preference_match.value_unknown'));

        if ($masterEducationIds === []) {
            return self::row('education', __('preference_match.field_education'), $their, $yours, self::STRICT_OPEN, self::STATUS_FLEXIBLE, __('preference_match.reason_pref_open'));
        }
        if ($viewerMeId === null) {
            return self::row('education', __('preference_match.field_education'), $their, $yours, self::STRICT_PREFERRED, self::STATUS_UNKNOWN, __('preference_match.reason_education_not_mapped'));
        }
        if (in_array($viewerMeId, $masterEducationIds, true)) {
            return self::row('education', __('preference_match.field_education'), $their, $yours, self::STRICT_PREFERRED, self::STATUS_MATCH, null);
        }

        $vSort = (int) (MasterEducation::query()->whereKey($viewerMeId)->value('sort_order') ?? 0);
        $minPrefSort = (int) MasterEducation::query()->whereIn('id', $masterEducationIds)->min('sort_order');
        if ($vSort > 0 && $minPrefSort > 0 && $vSort >= $minPrefSort - 1) {
            return self::row('education', __('preference_match.field_education'), $their, $yours, self::STRICT_PREFERRED, self::STATUS_FLEXIBLE, __('preference_match.reason_education_close'));
        }
        if ($vSort > 0 && $minPrefSort > 0 && $vSort < $minPrefSort - 2) {
            return self::row('education', __('preference_match.field_education'), $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_NOT_MATCHED, __('preference_match.reason_education_below'));
        }

        return self::row('education', __('preference_match.field_education'), $their, $yours, self::STRICT_PREFERRED, self::STATUS_FLEXIBLE, __('preference_match.reason_education_not_listed'));
    }

    private static function resolveViewerMasterEducationId(MatrimonyProfile $viewer): ?int
    {
        $raw = trim((string) ($viewer->highest_education ?? ''));
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
     * @param  array<int, int>  $professionIds
     * @return array<string, mixed>
     */
    private static function rowProfession(MatrimonyProfile $viewer, array $professionIds): array
    {
        $their = self::labelsForIds('professions', $professionIds, 'name');
        if ($their === '') {
            $their = __('preference_match.open_to_all');
        }
        $yours = $viewer->profession_id
            ? (string) ($viewer->profession?->name ?? DB::table('professions')->where('id', $viewer->profession_id)->value('name') ?? __('preference_match.value_unknown'))
            : __('preference_match.value_unknown');

        if (! $viewer->profession_id) {
            return self::row('profession', __('preference_match.field_profession'), $their, $yours, self::STRICT_OPEN, self::STATUS_UNKNOWN, __('preference_match.reason_missing_profession'));
        }
        if ($professionIds === []) {
            return self::row('profession', __('preference_match.field_profession'), $their, $yours, self::STRICT_OPEN, self::STATUS_FLEXIBLE, __('preference_match.reason_pref_open'));
        }
        if (in_array((int) $viewer->profession_id, $professionIds, true)) {
            return self::row('profession', __('preference_match.field_profession'), $their, $yours, self::STRICT_PREFERRED, self::STATUS_MATCH, null);
        }

        return self::row('profession', __('preference_match.field_profession'), $their, $yours, self::STRICT_PREFERRED, self::STATUS_FLEXIBLE, __('preference_match.reason_profession_not_listed'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function rowIncome(MatrimonyProfile $viewer, ?object $criteria): array
    {
        $minR = $criteria?->preferred_income_min ?? null;
        $maxR = $criteria?->preferred_income_max ?? null;
        $their = ($minR !== null || $maxR !== null)
            ? self::formatIncomePair($minR, $maxR)
            : __('preference_match.open_to_all');

        $annual = self::viewerAnnualIncomeRupees($viewer);
        $yours = $annual !== null ? self::formatRupeesLakh($annual) : __('preference_match.value_unknown');

        if ($annual === null) {
            return self::row('income', __('preference_match.field_income'), $their, $yours, self::STRICT_OPEN, self::STATUS_UNKNOWN, __('preference_match.reason_missing_income'));
        }
        if ($minR === null && $maxR === null) {
            return self::row('income', __('preference_match.field_income'), $their, $yours, self::STRICT_OPEN, self::STATUS_FLEXIBLE, __('preference_match.reason_pref_open'));
        }
        $mn = $minR !== null ? (float) $minR : null;
        $mx = $maxR !== null ? (float) $maxR : null;
        if ($mn !== null && $annual < $mn) {
            if ($annual >= $mn * 0.85) {
                return self::row('income', __('preference_match.field_income'), $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_FLEXIBLE, __('preference_match.reason_income_slightly_low'));
            }

            return self::row('income', __('preference_match.field_income'), $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_NOT_MATCHED, __('preference_match.reason_income_low'));
        }
        if ($mx !== null && $annual > $mx) {
            return self::row('income', __('preference_match.field_income'), $their, $yours, self::STRICT_PREFERRED, self::STATUS_FLEXIBLE, __('preference_match.reason_income_above_range'));
        }

        return self::row('income', __('preference_match.field_income'), $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_MATCH, null);
    }

    private static function viewerAnnualIncomeRupees(MatrimonyProfile $viewer): ?float
    {
        if ($viewer->income_normalized_annual_amount !== null && $viewer->income_normalized_annual_amount !== '') {
            return (float) $viewer->income_normalized_annual_amount;
        }
        if ($viewer->annual_income !== null && $viewer->annual_income !== '') {
            return (float) $viewer->annual_income;
        }

        return null;
    }

    private static function formatIncomePair($minR, $maxR): string
    {
        $a = $minR !== null ? self::formatRupeesLakh((float) $minR) : '—';
        $b = $maxR !== null ? self::formatRupeesLakh((float) $maxR) : '—';

        return $a.' – '.$b;
    }

    private static function formatRupeesLakh(float $rupees): string
    {
        $l = $rupees / 100000.0;

        return '₹'.(round($l, 2) === floor($l) ? (string) (int) $l : number_format($l, 1)).' L';
    }

    /**
     * @param  array<int, int>  $dietIds
     * @return array<string, mixed>
     */
    private static function rowDiet(MatrimonyProfile $viewer, array $dietIds): array
    {
        $their = self::labelsForIds('master_diets', $dietIds, 'label');
        if ($their === '') {
            $their = __('preference_match.open_to_all');
        }
        $yours = $viewer->diet?->label ?? __('preference_match.value_unknown');
        if (! $viewer->diet_id) {
            return self::row('diet', __('preference_match.field_diet'), $their, $yours, self::STRICT_OPEN, self::STATUS_UNKNOWN, __('preference_match.reason_missing_diet'));
        }
        if ($dietIds === []) {
            return self::row('diet', __('preference_match.field_diet'), $their, $yours, self::STRICT_OPEN, self::STATUS_FLEXIBLE, __('preference_match.reason_pref_open'));
        }
        if (in_array((int) $viewer->diet_id, $dietIds, true)) {
            return self::row('diet', __('preference_match.field_diet'), $their, $yours, self::STRICT_PREFERRED, self::STATUS_MATCH, null);
        }

        return self::row('diet', __('preference_match.field_diet'), $their, $yours, self::STRICT_PREFERRED, self::STATUS_FLEXIBLE, __('preference_match.reason_diet_not_listed'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(
        string $id,
        string $label,
        string $theirPreference,
        string $yourValue,
        string $strictness,
        string $status,
        ?string $reason
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'their_preference' => $theirPreference,
            'your_value' => $yourValue,
            'strictness' => $strictness,
            'status' => $status,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<int, int>  $ids
     */
    private static function labelsForIds(string $table, array $ids, string $labelColumn): string
    {
        if ($ids === []) {
            return '';
        }
        $rows = DB::table($table)->whereIn('id', $ids)->pluck($labelColumn);

        return $rows->implode(', ');
    }

    /**
     * @param  array<int, array<string, mixed>>  $flat
     * @return array<int, string>
     */
    private static function discussionTopics(array $flat): array
    {
        $topics = [];
        foreach ($flat as $r) {
            $st = $r['status'] ?? '';
            if ($st !== self::STATUS_FLEXIBLE && $st !== self::STATUS_NOT_MATCHED) {
                continue;
            }
            $id = $r['id'] ?? '';
            if (in_array($id, ['location', 'state', 'country', 'district'], true)) {
                $topics[__('preference_match.topic_location')] = true;
            } elseif (in_array($id, ['income', 'profession', 'education'], true)) {
                $topics[__('preference_match.topic_career')] = true;
            } elseif (in_array($id, ['religion', 'caste', 'marital_status'], true)) {
                $topics[__('preference_match.topic_family')] = true;
            } elseif ($id === 'diet') {
                $topics[__('preference_match.topic_lifestyle')] = true;
            }
        }

        return array_keys($topics);
    }

    /**
     * @param  array<string, int>  $counts
     */
    private static function resolveFitBadge(array $counts): string
    {
        $nm = $counts['not_matched'] ?? 0;
        $m = $counts['match'] ?? 0;
        $f = $counts['flexible'] ?? 0;
        if ($nm >= 2) {
            return 'needs_discussion';
        }
        if ($nm === 1) {
            return 'partial_fit';
        }
        if ($m >= 3 && $f <= 2) {
            return 'strong_fit';
        }
        if ($f >= 3 && $nm === 0) {
            return 'good_fit';
        }
        if ($m === 0 && $f > 0 && $nm === 0) {
            return 'good_fit';
        }

        return 'partial_fit';
    }

    /**
     * @param  array<string, int>  $counts
     */
    private static function helperText(string $fitBadge, array $counts): string
    {
        if ($fitBadge === 'strong_fit') {
            return __('preference_match.helper_strong');
        }
        if (in_array($fitBadge, ['partial_fit', 'needs_discussion'], true) || ($counts['not_matched'] ?? 0) > 0) {
            return __('preference_match.helper_discussion');
        }

        return __('preference_match.helper_good');
    }
}
