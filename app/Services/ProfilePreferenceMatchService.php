<?php

namespace App\Services;

use App\Models\Caste;
use App\Models\District;
use App\Models\EducationDegree;
use App\Models\Location;
use App\Models\MasterMaritalStatus;
use App\Models\MatrimonyProfile;
use App\Models\OccupationMaster;
use App\Models\Religion;
use App\Services\Matching\CommunityLockResolver;
use App\Services\Matching\NearbyGeographyResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
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
     * Per-run memo for residence geography. A matching run compares up to 200 candidates in BOTH
     * directions, and resolving a profile's district/state/country/taluka walks the address hierarchy
     * — without this the same walk ran 400+ times. Flushed by
     * {@see \App\Services\Matching\MatchingService::findMatchesForTab()} at the start of every run.
     *
     * @var array<int, array{district_id: int, state_id: int, country_id: int, taluka_id: int}>
     */
    private static array $residenceGeoCache = [];

    /**
     * Per-run memo for the residence display line. {@see build()} renders it for the VIEWER on every
     * call, and a run builds both directions for every candidate — so the uncached line meant one
     * `LocationFormatterService::formatLocation()` (leaf lookup + full ancestor chain hydration) per
     * pair instead of one per profile.
     *
     * @var array<int, string>
     */
    private static array $residenceDisplayCache = [];

    /**
     * Per-run memo for "which degree does this profile hold". Resolving it walks the alias table and
     * can fall back to a LIKE scan over `master_education`; it too was being asked once per pair.
     *
     * @var array<int, int|null>
     */
    private static array $viewerDegreeIdCache = [];

    /**
     * Drops every per-run memo owned by this service (and the shared geography resolver).
     */
    public static function flushRuntimeCaches(): void
    {
        self::$residenceGeoCache = [];
        self::$residenceDisplayCache = [];
        self::$viewerDegreeIdCache = [];
        NearbyGeographyResolver::flush();
    }

    /**
     * @param  array<string, mixed>|null  $targetPreferencesOverride  Same shape as loadTargetPreferences(); skips DB when provided (batch matching).
     * @return array<string, mixed>
     */
    public static function build(MatrimonyProfile $viewerProfile, MatrimonyProfile $targetProfile, ?array $targetPreferencesOverride = null): array
    {
        $viewerProfile->loadMissing([
            'gender', 'maritalStatus', 'religion', 'caste', 'subCaste', 'diet', 'occupationMaster', 'occupationCustom',
            'location',
        ]);

        $targetProfile->loadMissing(['gender', 'maritalStatus', 'religion', 'caste']);

        $pref = $targetPreferencesOverride ?? self::loadTargetPreferences($targetProfile->id);
        $criteria = $pref['criteria'];
        $lock = is_array($pref['community_lock'] ?? null) ? $pref['community_lock'] : CommunityLockResolver::open();
        $strictness = is_array($pref['strictness'] ?? null) ? $pref['strictness'] : [];

        $groups = [
            'basic' => [],
            'community' => [],
            'location' => [],
            'education_career' => [],
            'lifestyle' => [],
        ];

        $groups['basic'][] = self::rowAge($viewerProfile, $criteria, $targetProfile);
        $groups['basic'][] = self::rowHeight($viewerProfile, $criteria, $targetProfile, $strictness);
        $groups['basic'][] = self::rowMaritalStatus($viewerProfile, $criteria, $pref['marital_status_ids'] ?? [], $targetProfile);

        $groups['community'][] = self::rowReligion($viewerProfile, $pref['religion_ids'], $lock);
        $groups['community'][] = self::rowCaste($viewerProfile, $pref['caste_ids'], $lock);

        $groups['location'][] = self::rowLocation($viewerProfile, $pref, $targetProfile);

        $groups['education_career'][] = self::rowEducation($viewerProfile, $pref, $targetProfile);
        $groups['education_career'][] = self::rowProfession($viewerProfile, $pref);
        $groups['education_career'][] = self::rowIncome($viewerProfile, $criteria, $strictness);

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

        $assumedFields = [];
        foreach ($flat as $r) {
            if (($r['derived'] ?? false) === true) {
                $assumedFields[] = (string) ($r['id'] ?? '');
            }
        }

        return [
            'groups' => $groups,
            'rows' => $flat,
            'counts' => $counts,
            'fit_badge' => $fitBadge,
            'discussion_topics' => $discussion,
            'helper_text' => $helper,
            'target_has_preferences' => $targetHasPreference,
            'viewer_profile_incomplete' => in_array(self::STATUS_UNKNOWN, array_column($flat, 'status'), true),
            // Fields the target never stated: the engine assumed a sensible default from their own
            // profile (PO-approved 2026-07-26). The app can label these "assumed", not "requested".
            'assumed_fields' => array_values(array_filter(array_unique($assumedFields))),
            'community_lock' => $lock,
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

        $educationDegreeIds = Schema::hasTable('profile_preferred_education_degrees')
            ? DB::table('profile_preferred_education_degrees')->where('profile_id', $targetProfileId)->pluck('education_degree_id')->map(fn ($id) => (int) $id)->all()
            : [];
        $occupationMasterIds = Schema::hasTable('profile_preferred_occupation_master')
            ? DB::table('profile_preferred_occupation_master')->where('profile_id', $targetProfileId)->pluck('occupation_master_id')->map(fn ($id) => (int) $id)->all()
            : [];
        $dietIds = Schema::hasTable('profile_preferred_diets')
            ? DB::table('profile_preferred_diets')->where('profile_id', $targetProfileId)->pluck('diet_id')->map(fn ($id) => (int) $id)->all()
            : [];

        $maritalStatusIds = Schema::hasTable('profile_preferred_marital_statuses')
            ? DB::table('profile_preferred_marital_statuses')->where('profile_id', $targetProfileId)->pluck('marital_status_id')->map(fn ($id) => (int) $id)->all()
            : [];

        return [
            'criteria' => $criteria,
            'religion_ids' => $religionIds,
            'caste_ids' => $casteIds,
            'district_ids' => $districtIds,
            'country_ids' => $countryIds,
            'state_ids' => $stateIds,
            'taluka_ids' => $talukaIds,
            'education_degree_ids' => $educationDegreeIds,
            'occupation_master_ids' => $occupationMasterIds,
            'diet_ids' => $dietIds,
            'marital_status_ids' => $maritalStatusIds,
            // Community intent + declared strictness. The batch path
            // ({@see \App\Services\Matching\MatchingService::bulkLoadTargetPreferences()}) fills these
            // in bulk; this single-profile path is only used by non-feed callers.
            'community_lock' => CommunityLockResolver::resolveOne($targetProfileId, $casteIds, $religionIds),
            'strictness' => CommunityLockResolver::strictnessMapFor([$targetProfileId])[$targetProfileId] ?? [],
        ];
    }

    private static function targetHasAnyPreference(array $pref, ?object $criteria): bool
    {
        foreach (['religion_ids', 'caste_ids', 'district_ids', 'country_ids', 'state_ids', 'taluka_ids', 'education_degree_ids', 'occupation_master_ids', 'diet_ids', 'marital_status_ids'] as $k) {
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
    private static function rowAge(MatrimonyProfile $viewer, ?object $criteria, ?MatrimonyProfile $target = null): array
    {
        $min = $criteria?->preferred_age_min ?? null;
        $max = $criteria?->preferred_age_max ?? null;
        $label = __('preference_match.field_age');

        $age = self::viewerAge($viewer);
        $yours = $age !== null ? (string) $age : __('preference_match.value_unknown');

        if ($age === null) {
            $their = ($min !== null || $max !== null)
                ? self::formatRangePair($min, $max)
                : __('preference_match.no_preference_set');

            return self::row('age', $label, $their, $yours, self::STRICT_OPEN, self::STATUS_UNKNOWN, __('preference_match.reason_missing_dob'));
        }

        // A one-sided bound is a real, stated preference — it must filter on its own. Requiring BOTH
        // ends meant a "at least 25" preference was silently ignored.
        if ($min !== null || $max !== null) {
            $their = self::formatRangePair($min, $max);
            $lo = $min !== null ? (int) $min : null;
            $hi = $max !== null ? (int) $max : null;

            $inside = ($lo === null || $age >= $lo) && ($hi === null || $age <= $hi);
            if ($inside) {
                return self::row('age', $label, $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_MATCH, null);
            }
            $near = ($lo === null || $age >= $lo - 2) && ($hi === null || $age <= $hi + 2);
            if ($near) {
                return self::row('age', $label, $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_FLEXIBLE, __('preference_match.reason_age_near_range'));
            }

            return self::row('age', $label, $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_NOT_MATCHED, __('preference_match.reason_age_outside'));
        }

        // Nothing stated — assume a sensible range from the target's own profile. Derived values are
        // a guess, so they may never produce not_matched.
        $derived = $target !== null ? PartnerPreferenceSuggestionService::derivedPartnerAgeRange($target) : null;
        if ($derived === null) {
            return self::row('age', $label, __('preference_match.no_preference_set'), $yours, self::STRICT_OPEN, self::STATUS_FLEXIBLE, __('preference_match.reason_no_age_range'));
        }

        $their = self::formatRangePair($derived['min'], $derived['max']);
        if ($age >= $derived['min'] && $age <= $derived['max']) {
            return self::row('age', $label, $their, $yours, self::STRICT_OPEN, self::STATUS_MATCH, __('preference_match.reason_age_within_assumed'), true);
        }
        if ($age >= $derived['min'] - 2 && $age <= $derived['max'] + 2) {
            return self::row('age', $label, $their, $yours, self::STRICT_OPEN, self::STATUS_FLEXIBLE, __('preference_match.reason_age_near_assumed'), true);
        }

        return self::row('age', $label, $their, $yours, self::STRICT_OPEN, self::STATUS_FLEXIBLE, __('preference_match.reason_age_outside_assumed'), true);
    }

    private static function formatRangePair(mixed $min, mixed $max, string $suffix = ''): string
    {
        $a = ($min !== null && $min !== '') ? (string) (int) $min : '—';
        $b = ($max !== null && $max !== '') ? (string) (int) $max : '—';

        return $a.' – '.$b.$suffix;
    }

    private static function viewerAge(MatrimonyProfile $viewer): ?int
    {
        if (! $viewer->date_of_birth) {
            return null;
        }
        try {
            $dateOfBirth = $viewer->date_of_birth instanceof CarbonInterface
                ? $viewer->date_of_birth
                : Carbon::parse($viewer->date_of_birth);

            if ($dateOfBirth->isFuture()) {
                return null;
            }

            return (int) $dateOfBirth->age;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $strictness  Target's declared strictness (partner_preference_metadata).
     * @return array<string, mixed>
     */
    private static function rowHeight(MatrimonyProfile $viewer, ?object $criteria, ?MatrimonyProfile $target = null, array $strictness = []): array
    {
        $min = $criteria?->preferred_height_min_cm ?? null;
        $max = $criteria?->preferred_height_max_cm ?? null;
        $label = __('preference_match.field_height');

        $h = $viewer->height_cm;
        $yours = ($h !== null && $h !== '') ? (string) (int) $h.' cm' : __('preference_match.value_unknown');

        if ($h === null || $h === '') {
            $their = ($min !== null || $max !== null)
                ? self::formatRangePair($min, $max, ' cm')
                : __('preference_match.no_preference_set');

            return self::row('height', $label, $their, $yours, self::STRICT_OPEN, self::STATUS_UNKNOWN, __('preference_match.reason_missing_height'));
        }
        $hc = (int) $h;

        if ($min !== null || $max !== null) {
            $their = self::formatRangePair($min, $max, ' cm');
            $mn = $min !== null ? (int) $min : null;
            $mx = $max !== null ? (int) $max : null;
            // Height is SOFT by default: a 4 cm miss must not delete the candidate. It stays
            // excludable only when the seeker explicitly declared it must-match — the tolerance
            // decision itself lives in {@see \App\Services\Matching\MatchingService}.
            $mustMatch = CommunityLockResolver::declaredMustMatch($strictness, 'height');
            $strict = $mustMatch ? self::STRICT_MUST_MATCH : self::STRICT_PREFERRED;

            $inside = ($mn === null || $hc >= $mn) && ($mx === null || $hc <= $mx);
            if ($inside) {
                return self::row('height', $label, $their, $yours, $strict, self::STATUS_MATCH, null, false, $mustMatch);
            }
            $near = ($mn === null || $hc >= $mn - 3) && ($mx === null || $hc <= $mx + 3);
            if ($near) {
                return self::row('height', $label, $their, $yours, $strict, self::STATUS_FLEXIBLE, __('preference_match.reason_height_near_range'), false, $mustMatch);
            }

            return self::row('height', $label, $their, $yours, $strict, self::STATUS_NOT_MATCHED, __('preference_match.reason_height_outside'), false, $mustMatch);
        }

        $derived = $target !== null ? PartnerPreferenceSuggestionService::derivedPartnerHeightRangeCm($target) : null;
        if ($derived === null) {
            return self::row('height', $label, __('preference_match.no_preference_set'), $yours, self::STRICT_OPEN, self::STATUS_FLEXIBLE, __('preference_match.reason_no_height_range'));
        }

        $their = self::formatRangePair($derived['min'], $derived['max'], ' cm');
        if ($hc >= $derived['min'] && $hc <= $derived['max']) {
            return self::row('height', $label, $their, $yours, self::STRICT_OPEN, self::STATUS_MATCH, __('preference_match.reason_height_within_assumed'), true);
        }

        return self::row('height', $label, $their, $yours, self::STRICT_OPEN, self::STATUS_FLEXIBLE, __('preference_match.reason_height_outside_assumed'), true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function rowMaritalStatus(MatrimonyProfile $viewer, ?object $criteria, array $pivotMaritalIds = [], ?MatrimonyProfile $target = null): array
    {
        $label = __('preference_match.field_marital');
        $allowed = array_values(array_unique(array_map('intval', $pivotMaritalIds)));
        if ($allowed === [] && ($criteria?->preferred_marital_status_id ?? null) !== null) {
            $allowed = [(int) $criteria->preferred_marital_status_id];
        }

        $derived = false;
        if ($allowed === [] && $target !== null) {
            $assumed = PartnerPreferenceSuggestionService::derivedPartnerMaritalStatusIds($target);
            if ($assumed !== []) {
                $allowed = $assumed;
                $derived = true;
            }
        }

        $their = $allowed === []
            ? __('preference_match.open_to_all')
            : (string) MasterMaritalStatus::query()->whereIn('id', $allowed)->orderBy('label')->pluck('label')->filter()->implode(', ');

        $yours = $viewer->maritalStatus?->label ?? __('preference_match.value_unknown');
        if (! $viewer->marital_status_id) {
            return self::row('marital_status', $label, $their, $yours, self::STRICT_OPEN, self::STATUS_UNKNOWN, __('preference_match.reason_missing_marital'));
        }
        if ($allowed === []) {
            return self::row('marital_status', $label, $their, $yours, self::STRICT_OPEN, self::STATUS_FLEXIBLE, __('preference_match.reason_marital_open'));
        }

        $strict = $derived ? self::STRICT_OPEN : self::STRICT_PREFERRED;
        if (in_array((int) $viewer->marital_status_id, $allowed, true)) {
            return self::row('marital_status', $label, $their, $yours, $strict, self::STATUS_MATCH, $derived ? __('preference_match.reason_marital_matches_assumed') : null, $derived);
        }

        return self::row('marital_status', $label, $their, $yours, $strict, self::STATUS_FLEXIBLE, $derived ? __('preference_match.reason_marital_differs_assumed') : __('preference_match.reason_marital_differs'), $derived);
    }

    /**
     * @param  array<int, int>  $religionIds
     * @return array<string, mixed>
     */
    private static function rowReligion(MatrimonyProfile $viewer, array $religionIds, array $lock = []): array
    {
        $label = __('preference_match.field_religion');
        $locked = ($lock['religion_locked'] ?? false) === true;
        $allowed = $locked && ($lock['allowed_religion_ids'] ?? []) !== []
            ? array_map('intval', $lock['allowed_religion_ids'])
            : $religionIds;

        $their = $allowed === []
            ? ''
            : Religion::query()->whereIn('id', $allowed)->get()->map(fn ($r) => $r->display_label)->implode(', ');
        if ($their === '') {
            $their = __('preference_match.open_to_all');
        }
        $yours = $viewer->religion?->label ?? __('preference_match.value_unknown');
        if (! $viewer->religion_id) {
            return self::row('religion', $label, $their, $yours, self::STRICT_OPEN, self::STATUS_UNKNOWN, __('preference_match.reason_missing_religion'));
        }
        if ($allowed === []) {
            return self::row('religion', $label, $their, $yours, self::STRICT_OPEN, self::STATUS_FLEXIBLE, __('preference_match.reason_pref_open'));
        }
        if (in_array((int) $viewer->religion_id, $allowed, true)) {
            return self::row('religion', $label, $their, $yours, $locked ? self::STRICT_MUST_MATCH : self::STRICT_PREFERRED, self::STATUS_MATCH, null);
        }

        // Owner ruling 2026-07-26: an explicitly stated community requirement is a real requirement.
        // Without an explicit signal the row stays flexible exactly as before.
        if ($locked) {
            return self::row('religion', $label, $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_NOT_MATCHED, __('preference_match.reason_religion_locked'));
        }

        return self::row('religion', $label, $their, $yours, self::STRICT_PREFERRED, self::STATUS_FLEXIBLE, __('preference_match.reason_religion_not_listed'));
    }

    /**
     * @param  array<int, int>  $casteIds
     * @return array<string, mixed>
     */
    private static function rowCaste(MatrimonyProfile $viewer, array $casteIds, array $lock = []): array
    {
        $label = __('preference_match.field_caste');
        $locked = ($lock['caste_locked'] ?? false) === true;
        $allowed = $locked && ($lock['allowed_caste_ids'] ?? []) !== []
            ? array_map('intval', $lock['allowed_caste_ids'])
            : $casteIds;

        $their = $allowed === []
            ? ''
            : Caste::query()->whereIn('id', $allowed)->get()->map(fn ($c) => $c->display_label)->implode(', ');
        if ($their === '') {
            $their = __('preference_match.open_to_all');
        }
        $yours = $viewer->caste?->display_label ?? $viewer->caste?->label ?? __('preference_match.value_unknown');
        if (! $viewer->caste_id) {
            return self::row('caste', $label, $their, $yours, self::STRICT_OPEN, self::STATUS_UNKNOWN, __('preference_match.reason_missing_caste'));
        }
        if ($allowed === []) {
            return self::row('caste', $label, $their, $yours, self::STRICT_OPEN, self::STATUS_FLEXIBLE, __('preference_match.reason_pref_open'));
        }
        if (in_array((int) $viewer->caste_id, $allowed, true)) {
            return self::row('caste', $label, $their, $yours, $locked ? self::STRICT_MUST_MATCH : self::STRICT_PREFERRED, self::STATUS_MATCH, null);
        }

        if ($locked) {
            return self::row('caste', $label, $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_NOT_MATCHED, __('preference_match.reason_caste_locked'));
        }

        return self::row('caste', $label, $their, $yours, self::STRICT_PREFERRED, self::STATUS_FLEXIBLE, __('preference_match.reason_caste_not_listed'));
    }

    /**
     * @param  array<string, mixed>  $pref
     * @return array<string, mixed>
     */
    private static function rowLocation(MatrimonyProfile $viewer, array $pref, ?MatrimonyProfile $target = null): array
    {
        $dIds = $pref['district_ids'];
        $sIds = $pref['state_ids'];
        $cIds = $pref['country_ids'];
        $tIds = array_values(array_filter(array_map('intval', $pref['taluka_ids'] ?? [])));

        $label = __('preference_match.field_location');
        $their = self::describeLocationPreference($pref);
        if ($their === '') {
            $their = __('preference_match.open_to_all');
        }

        $yours = self::residenceDisplayLine($viewer);
        if ($yours === '') {
            $yours = __('preference_match.value_unknown');
        }

        $hasAny = $dIds !== [] || $sIds !== [] || $cIds !== [] || $tIds !== [];
        if (! $viewer->location_id) {
            return self::row('location', $label, $their, $yours, self::STRICT_OPEN, self::STATUS_UNKNOWN, __('preference_match.reason_missing_location'));
        }

        $g = self::residenceGeoWithTaluka($viewer);
        $vd = (int) ($g['district_id'] ?? 0);
        $vs = (int) ($g['state_id'] ?? 0);
        $vc = (int) ($g['country_id'] ?? 0);
        $vt = (int) ($g['taluka_id'] ?? 0);

        if (! $hasAny) {
            return self::derivedLocationRow($viewer, $target, $label, $yours, $vd);
        }

        if ($vd > 0 && $dIds !== [] && in_array($vd, $dIds, true)) {
            return self::row('location', $label, $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_MATCH, null);
        }

        // A taluka-only preference used to fall through every branch to not_matched, which the mutual
        // gate then turned into an EMPTY feed for that seeker. Exact taluka is the tightest possible
        // location match, so it ranks with an exact district.
        if ($vt > 0 && $tIds !== [] && in_array($vt, $tIds, true)) {
            return self::row('location', $label, $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_MATCH, null);
        }

        // Owner's rule: district proximity matters, and a nearby taluka is even better — so the
        // nearby-taluka verdict is reported ahead of the plain same-district fallback.
        if ($vt > 0 && $tIds !== [] && array_intersect([$vt], NearbyGeographyResolver::nearbyTalukaIdsForAny($tIds)) !== []) {
            return self::row('location', $label, $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_FLEXIBLE, __('preference_match.reason_location_nearby_taluka'));
        }
        if ($vd > 0 && $tIds !== [] && in_array($vd, NearbyGeographyResolver::districtIdsForTalukas($tIds), true)) {
            return self::row('location', $label, $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_FLEXIBLE, __('preference_match.reason_location_same_district'));
        }

        if ($vs > 0 && $sIds !== [] && in_array($vs, $sIds, true)) {
            return self::row('location', $label, $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_FLEXIBLE, __('preference_match.reason_location_state_aligns'));
        }
        if ($vd > 0 && $dIds !== []) {
            if (array_intersect(NearbyGeographyResolver::nearbyDistrictIds($vd), $dIds) !== []) {
                return self::row('location', $label, $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_FLEXIBLE, __('preference_match.reason_location_nearby_district'));
            }

            $dStateIds = District::query()->whereIn('id', $dIds)->pluck('parent_id')->unique()->filter()->all();
            if ($vs > 0 && in_array($vs, array_map('intval', $dStateIds), true)) {
                return self::row('location', $label, $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_FLEXIBLE, __('preference_match.reason_location_same_state'));
            }
        }

        if ($vc > 0 && $cIds !== [] && in_array($vc, $cIds, true)) {
            return self::row('location', $label, $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_FLEXIBLE, __('preference_match.reason_location_country_aligns'));
        }

        if ($vd > 0 && $dIds !== []) {
            $geo = Location::geoTable();
            $prefCountries = DB::table($geo.' as d')
                ->join($geo.' as s', function ($join): void {
                    $join->on('s.id', '=', 'd.parent_id')->where('s.hierarchy', '=', 'state');
                })
                ->where('d.hierarchy', 'district')
                ->whereIn('d.id', $dIds)
                ->pluck('s.parent_id')
                ->unique()
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->all();
            if ($vc > 0 && $prefCountries !== [] && in_array($vc, $prefCountries, true)) {
                return self::row('location', $label, $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_FLEXIBLE, __('preference_match.reason_location_same_country'));
            }
        }

        return self::row('location', $label, $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_NOT_MATCHED, __('preference_match.reason_location_mismatch'));
    }

    /**
     * No location preference stated at all: assume the target's own district, then nearby districts.
     * Derived, therefore never not_matched.
     *
     * @return array<string, mixed>
     */
    private static function derivedLocationRow(MatrimonyProfile $viewer, ?MatrimonyProfile $target, string $label, string $yours, int $viewerDistrictId): array
    {
        $derived = $target !== null
            ? PartnerPreferenceSuggestionService::derivedPartnerDistrictIds($target)
            : ['own' => [], 'nearby' => []];

        if ($derived['own'] === []) {
            return self::row('location', $label, __('preference_match.open_to_all'), $yours, self::STRICT_OPEN, self::STATUS_FLEXIBLE, __('preference_match.reason_pref_open'));
        }

        $geo = Location::geoTable();
        $their = (string) DB::table($geo)->whereIn('id', $derived['own'])->pluck('name')->filter()->implode(', ');
        if ($their === '') {
            $their = __('preference_match.open_to_all');
        }

        if ($viewerDistrictId > 0 && in_array($viewerDistrictId, $derived['own'], true)) {
            return self::row('location', $label, $their, $yours, self::STRICT_OPEN, self::STATUS_MATCH, __('preference_match.reason_location_same_district_assumed'), true);
        }
        if ($viewerDistrictId > 0 && in_array($viewerDistrictId, $derived['nearby'], true)) {
            return self::row('location', $label, $their, $yours, self::STRICT_OPEN, self::STATUS_FLEXIBLE, __('preference_match.reason_location_nearby_district_assumed'), true);
        }

        return self::row('location', $label, $their, $yours, self::STRICT_OPEN, self::STATUS_FLEXIBLE, __('preference_match.reason_location_far_assumed'), true);
    }

    /**
     * Residence district/state/country (unchanged source: {@see MatrimonyProfile::residenceGeoAddressIds()})
     * plus the taluka the district-level helper never returned, memoised per profile for the run.
     *
     * @return array{district_id: int, state_id: int, country_id: int, taluka_id: int}
     */
    /**
     * Same string {@see MatrimonyProfile::residenceLocationDisplayLineFor()} returns, resolved once per
     * profile per run instead of once per compared pair.
     */
    private static function residenceDisplayLine(MatrimonyProfile $viewer): string
    {
        $pid = (int) $viewer->getKey();
        if ($pid <= 0) {
            return MatrimonyProfile::residenceLocationDisplayLineFor($viewer);
        }
        if (isset(self::$residenceDisplayCache[$pid])) {
            return self::$residenceDisplayCache[$pid];
        }

        return self::$residenceDisplayCache[$pid] = MatrimonyProfile::residenceLocationDisplayLineFor($viewer);
    }

    private static function residenceGeoWithTaluka(MatrimonyProfile $viewer): array
    {
        $pid = (int) $viewer->getKey();
        if ($pid > 0 && isset(self::$residenceGeoCache[$pid])) {
            return self::$residenceGeoCache[$pid];
        }

        $g = $viewer->residenceGeoAddressIds();
        $out = [
            'district_id' => (int) ($g['district_id'] ?? 0),
            'state_id' => (int) ($g['state_id'] ?? 0),
            'country_id' => (int) ($g['country_id'] ?? 0),
            'taluka_id' => 0,
        ];

        if ($viewer->location_id && Schema::hasTable(Location::geoTable())) {
            $hints = $viewer->residenceLocationHierarchyHints();
            $out['taluka_id'] = (int) ($hints['taluka_id'] !== '' ? $hints['taluka_id'] : 0);
            if ($out['taluka_id'] <= 0) {
                // The residence leaf may itself be the taluka.
                $leafHierarchy = DB::table(Location::geoTable())->where('id', (int) $viewer->location_id)->value('hierarchy');
                if ($leafHierarchy === 'taluka') {
                    $out['taluka_id'] = (int) $viewer->location_id;
                }
            }
        }

        if ($pid > 0) {
            self::$residenceGeoCache[$pid] = $out;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $pref
     */
    private static function describeLocationPreference(array $pref): string
    {
        $parts = [];
        $geo = Location::geoTable();
        if ($pref['country_ids'] !== []) {
            $parts[] = DB::table($geo)->where('hierarchy', 'country')->whereIn('id', $pref['country_ids'])->pluck('name')->filter()->implode(', ');
        }
        if ($pref['state_ids'] !== []) {
            $parts[] = DB::table($geo)->where('hierarchy', 'state')->whereIn('id', $pref['state_ids'])->pluck('name')->filter()->implode(', ');
        }
        if ($pref['district_ids'] !== []) {
            $parts[] = DB::table($geo)->where('hierarchy', 'district')->whereIn('id', $pref['district_ids'])->pluck('name')->filter()->implode(', ');
        }
        if ($pref['taluka_ids'] !== []) {
            $parts[] = DB::table($geo)->where('hierarchy', 'taluka')->whereIn('id', $pref['taluka_ids'])->pluck('name')->filter()->implode(', ');
        }

        return trim(implode(' · ', array_filter($parts)));
    }

    /**
     * @param  array<string, mixed>  $pref
     * @return array<string, mixed>
     */
    private static function rowEducation(MatrimonyProfile $viewer, array $pref, ?MatrimonyProfile $target = null): array
    {
        $degreeIds = array_values(array_filter(array_map('intval', $pref['education_degree_ids'] ?? [])));
        if ($degreeIds !== []) {
            return self::rowEducationDegrees($viewer, $degreeIds);
        }

        $assumed = $target !== null
            ? PartnerPreferenceSuggestionService::derivedPartnerEducationDegreeIds($target)
            : [];
        if ($assumed === []) {
            return self::rowEducationDegrees($viewer, []);
        }

        return self::rowEducationDegrees($viewer, $assumed, true);
    }

    /**
     * Best-effort primary degree id. Delegates to the single shared resolver so this surface and the
     * preference-suggestion surface cannot drift apart.
     */
    private static function resolveViewerPrimaryDegreeId(MatrimonyProfile $viewer): ?int
    {
        $pid = (int) $viewer->getKey();
        if ($pid <= 0) {
            return PartnerPreferenceSuggestionService::resolveProfileEducationDegreeId($viewer);
        }
        if (array_key_exists($pid, self::$viewerDegreeIdCache)) {
            return self::$viewerDegreeIdCache[$pid];
        }

        return self::$viewerDegreeIdCache[$pid] = PartnerPreferenceSuggestionService::resolveProfileEducationDegreeId($viewer);
    }

    /**
     * @param  array<int, int>  $degreeIds
     * @return array<string, mixed>
     */
    private static function rowEducationDegrees(MatrimonyProfile $viewer, array $degreeIds, bool $derived = false): array
    {
        $degreeIds = array_values(array_unique(array_filter($degreeIds, fn ($id) => (int) $id > 0)));
        if ($degreeIds === []) {
            $their = __('preference_match.open_to_all');
            $viewerDegreeId = self::resolveViewerPrimaryDegreeId($viewer);
            $viewerDegree = $viewerDegreeId ? EducationDegree::query()->find($viewerDegreeId) : null;
            $yours = $viewerDegree
                ? $viewerDegree->shortDisplayLabel()
                : trim((string) ($viewer->highest_education ?? ''));
            if ($yours === '') {
                $yours = __('preference_match.value_unknown');
            }
            if ($viewerDegreeId === null) {
                return self::row('education', __('preference_match.field_education'), $their, $yours, self::STRICT_OPEN, self::STATUS_UNKNOWN, __('preference_match.reason_education_not_mapped'));
            }

            return self::row('education', __('preference_match.field_education'), $their, $yours, self::STRICT_OPEN, self::STATUS_FLEXIBLE, __('preference_match.reason_pref_open'));
        }

        $their = self::labelsForIds('master_education', $degreeIds, 'code');
        if ($their === '') {
            $their = __('preference_match.open_to_all');
        }

        $viewerDegreeId = self::resolveViewerPrimaryDegreeId($viewer);
        $viewerDegree = $viewerDegreeId ? EducationDegree::query()->find($viewerDegreeId) : null;
        $yours = $viewerDegree
            ? $viewerDegree->shortDisplayLabel()
            : trim((string) ($viewer->highest_education ?? ''));
        if ($yours === '') {
            $yours = __('preference_match.value_unknown');
        }

        if ($viewerDegreeId === null) {
            return self::row('education', __('preference_match.field_education'), $their, $yours, self::STRICT_PREFERRED, self::STATUS_UNKNOWN, __('preference_match.reason_education_not_mapped'));
        }

        $strict = $derived ? self::STRICT_OPEN : self::STRICT_PREFERRED;

        if (in_array($viewerDegreeId, $degreeIds, true)) {
            return self::row('education', __('preference_match.field_education'), $their, $yours, $strict, self::STATUS_MATCH, $derived ? __('preference_match.reason_education_within_assumed') : null, $derived);
        }

        $vSort = (int) ($viewerDegree->sort_order ?? 0);
        $minPrefSort = (int) EducationDegree::query()->whereIn('id', $degreeIds)->min('sort_order');
        if ($vSort > 0 && $minPrefSort > 0 && $vSort >= $minPrefSort - 1) {
            return self::row('education', __('preference_match.field_education'), $their, $yours, $strict, self::STATUS_FLEXIBLE, __('preference_match.reason_education_close'), $derived);
        }
        // A derived education band is a guess, so it may never exclude — it only softens the score.
        if (! $derived && $vSort > 0 && $minPrefSort > 0 && $vSort < $minPrefSort - 2) {
            return self::row('education', __('preference_match.field_education'), $their, $yours, self::STRICT_MUST_MATCH, self::STATUS_NOT_MATCHED, __('preference_match.reason_education_below'));
        }

        return self::row('education', __('preference_match.field_education'), $their, $yours, $strict, self::STATUS_FLEXIBLE, $derived ? __('preference_match.reason_education_outside_assumed') : __('preference_match.reason_education_not_listed'), $derived);
    }

    /**
     * @param  array<string, mixed>  $pref
     * @return array<string, mixed>
     */
    private static function rowProfession(MatrimonyProfile $viewer, array $pref): array
    {
        $occIds = array_map('intval', $pref['occupation_master_ids'] ?? []);

        return self::rowOccupationMasterPreferences($viewer, $occIds);
    }

    /**
     * @param  array<int, int>  $occupationMasterIds
     * @return array<string, mixed>
     */
    private static function rowOccupationMasterPreferences(MatrimonyProfile $viewer, array $occupationMasterIds): array
    {
        $occupationMasterIds = array_values(array_unique(array_filter($occupationMasterIds, fn ($id) => (int) $id > 0)));
        $their = self::labelsForIds('master_occupations', $occupationMasterIds, 'name');
        if ($their === '') {
            $their = __('preference_match.open_to_all');
        }

        $viewer->loadMissing(['occupationMaster', 'occupationCustom']);
        $viewerOccId = isset($viewer->occupation_master_id) ? (int) $viewer->occupation_master_id : null;
        $yours = $viewerOccId
            ? (string) (OccupationMaster::query()->whereKey($viewerOccId)->value('name') ?? $viewer->occupationMaster?->name ?? '')
            : trim((string) ($viewer->occupation_title ?: ($viewer->resolvedProfession()?->name ?? '')));
        if ($yours === '') {
            $yours = __('preference_match.value_unknown');
        }

        if ($viewerOccId === null || $viewerOccId <= 0) {
            return self::row('profession', __('preference_match.field_profession'), $their, $yours, self::STRICT_PREFERRED, self::STATUS_UNKNOWN, __('preference_match.reason_missing_profession'));
        }
        if ($occupationMasterIds === []) {
            return self::row('profession', __('preference_match.field_profession'), $their, $yours, self::STRICT_OPEN, self::STATUS_FLEXIBLE, __('preference_match.reason_pref_open'));
        }
        if (in_array($viewerOccId, $occupationMasterIds, true)) {
            return self::row('profession', __('preference_match.field_profession'), $their, $yours, self::STRICT_PREFERRED, self::STATUS_MATCH, null);
        }

        return self::row('profession', __('preference_match.field_profession'), $their, $yours, self::STRICT_PREFERRED, self::STATUS_FLEXIBLE, __('preference_match.reason_profession_not_listed'));
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $strictness  Target's declared strictness (partner_preference_metadata).
     * @return array<string, mixed>
     */
    private static function rowIncome(MatrimonyProfile $viewer, ?object $criteria, array $strictness = []): array
    {
        $label = __('preference_match.field_income');
        $minR = $criteria?->preferred_income_min ?? null;
        $maxR = $criteria?->preferred_income_max ?? null;
        $their = ($minR !== null || $maxR !== null)
            ? self::formatIncomePair($minR, $maxR)
            : __('preference_match.open_to_all');

        $annual = self::viewerAnnualIncomeRupees($viewer);
        $yours = $annual !== null ? self::formatRupeesLakh($annual) : __('preference_match.value_unknown');

        if ($annual === null) {
            return self::row('income', $label, $their, $yours, self::STRICT_OPEN, self::STATUS_UNKNOWN, __('preference_match.reason_missing_income'));
        }
        if ($minR === null && $maxR === null) {
            return self::row('income', $label, $their, $yours, self::STRICT_OPEN, self::STATUS_FLEXIBLE, __('preference_match.reason_pref_open'));
        }

        // Income is SOFT by default: a candidate ₹1 short must not be deleted from the feed. It stays
        // excludable only when the seeker explicitly declared income must-match.
        $mustMatch = CommunityLockResolver::declaredMustMatch($strictness, 'income');
        $strict = $mustMatch ? self::STRICT_MUST_MATCH : self::STRICT_PREFERRED;

        $mn = $minR !== null ? (float) $minR : null;
        $mx = $maxR !== null ? (float) $maxR : null;
        if ($mn !== null && $annual < $mn) {
            if ($annual >= $mn * 0.85) {
                return self::row('income', $label, $their, $yours, $strict, self::STATUS_FLEXIBLE, __('preference_match.reason_income_slightly_low'), false, $mustMatch);
            }

            return self::row('income', $label, $their, $yours, $strict, self::STATUS_NOT_MATCHED, __('preference_match.reason_income_low'), false, $mustMatch);
        }
        if ($mx !== null && $annual > $mx) {
            return self::row('income', $label, $their, $yours, self::STRICT_PREFERRED, self::STATUS_FLEXIBLE, __('preference_match.reason_income_above_range'), false, $mustMatch);
        }

        return self::row('income', $label, $their, $yours, $strict, self::STATUS_MATCH, null, false, $mustMatch);
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
    /**
     * @param  bool  $derived  The preference was never stated — the engine assumed it from the
     *                         target's own profile. Derived rows are advisory: they influence the
     *                         score and are labelled for the app, but never exclude a candidate.
     * @param  bool  $declaredMustMatch  The seeker explicitly declared this field must-match. Only
     *                                   these keep income/height excludable at the strict tier.
     */
    private static function row(
        string $id,
        string $label,
        string $theirPreference,
        string $yourValue,
        string $strictness,
        string $status,
        ?string $reason,
        bool $derived = false,
        bool $declaredMustMatch = false
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'their_preference' => $theirPreference,
            'your_value' => $yourValue,
            'strictness' => $strictness,
            'status' => $status,
            'reason' => $reason,
            'derived' => $derived,
            'declared_must_match' => $declaredMustMatch,
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
