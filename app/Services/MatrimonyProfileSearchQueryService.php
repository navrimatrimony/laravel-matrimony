<?php

namespace App\Services;

use App\Models\EducationDegree;
use App\Models\HiddenProfile;
use App\Models\Location;
use App\Models\MasterMaritalStatus;
use App\Models\MatrimonyProfile;
use App\Services\Location\LocationService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Member search listing filters — shared so list counts, strict-match counts, and auto-showcase stay aligned.
 */
class MatrimonyProfileSearchQueryService
{
    /**
     * @param  Builder<MatrimonyProfile>  $query
     */
    public static function applyCoreListingScope(Builder $query, ?int $excludeViewerProfileId): void
    {
        $query->where(function ($q) {
            $q->where('lifecycle_state', 'active')->orWhereNull('lifecycle_state');
        })->where('is_suspended', false);

        $query->whereMemberAccountsOnly();

        if ($excludeViewerProfileId) {
            $query->whereKeyNot((int) $excludeViewerProfileId);
        }
    }

    /**
     * @param  Builder<MatrimonyProfile>  $query
     */
    public static function applyRequestSearchFilters(Builder $query, Request $request): void
    {
        $searchableFields = ProfileFieldConfigurationService::getSearchableFieldKeys();
        $enabledFields = ProfileFieldConfigurationService::getEnabledFieldKeys();
        $enabledSearchableFields = array_intersect($searchableFields, $enabledFields);
        $isSearchable = fn (string $fieldKey) => in_array($fieldKey, $enabledSearchableFields, true);

        self::applyRequestSearchFiltersWithChecker($query, $request, $isSearchable);
    }

    /**
     * @param  Builder<MatrimonyProfile>  $query
     * @param  callable(string): bool  $isSearchable
     */
    public static function applyRequestSearchFiltersWithChecker(
        Builder $query,
        Request $request,
        callable $isSearchable,
        ?array $strictKeysNormalized = null
    ): void {
        $strict = $strictKeysNormalized !== null;
        $inStrict = function (string $key) use ($strict, $strictKeysNormalized): bool {
            if (! $strict || $strictKeysNormalized === null) {
                return true;
            }
            $k = strtolower($key);

            return in_array($k, $strictKeysNormalized, true);
        };

        if ((! $strict || $inStrict('religion_id')) && $isSearchable('religion_id') && $request->filled('religion_id')) {
            $query->where('religion_id', $request->input('religion_id'));
        }

        $advancedProfileSearch = (bool) $request->attributes->get('advanced_profile_search', false);

        if ($advancedProfileSearch && (! $strict || $inStrict('caste_id')) && $isSearchable('caste') && $request->filled('caste_id')) {
            $query->where('caste_id', $request->input('caste_id'));
        }

        if ($advancedProfileSearch && (! $strict || $inStrict('sub_caste_id')) && $isSearchable('sub_caste_id') && $request->filled('sub_caste_id')) {
            $query->where('sub_caste_id', $request->input('sub_caste_id'));
        }

        $geoActive = function (string $dim) use ($strict, $strictKeysNormalized, $inStrict): bool {
            if (! $strict || $strictKeysNormalized === null) {
                return true;
            }
            if ($inStrict('location')) {
                return true;
            }

            return $inStrict($dim);
        };

        if ($isSearchable('location')) {
            if ($request->filled('country_id') && $geoActive('country_id')) {
                $query->whereResidenceUnderAncestor((int) $request->country_id);
            }
            if ($request->filled('state_id') && $geoActive('state_id')) {
                $query->whereResidenceUnderAncestor((int) $request->state_id);
            }
            if ($request->filled('district_id') && $geoActive('district_id')) {
                $query->whereResidenceUnderAncestor((int) $request->district_id);
            }
            if ($request->filled('taluka_id') && $geoActive('taluka_id')) {
                $query->whereResidenceUnderAncestor((int) $request->taluka_id);
            }
            $cityId = $request->input('location_id') ?: $request->input('city_id');
            if ($cityId && $geoActive('city_id')) {
                self::whereResidenceLeafIn($query, [(int) $cityId]);
            }
            if ($request->filled('nearby_location_id') && $geoActive('nearby_location_id')) {
                $distanceByLocation = self::nearbyDistanceMapFromRequest($request);
                if ($distanceByLocation === []) {
                    $query->whereRaw('1 = 0');
                } else {
                    self::whereResidenceLeafIn($query, array_keys($distanceByLocation));
                }
            }
        }

        if ((! $strict || $inStrict('date_of_birth')) && $isSearchable('date_of_birth') && ($request->filled('age_from') || $request->filled('age_to'))) {
            $query->whereNotNull('date_of_birth');
            if ($request->filled('age_from')) {
                $minDate = now()->subYears((int) $request->age_from)->format('Y-m-d');
                $query->whereDate('date_of_birth', '<=', $minDate);
            }
            if ($request->filled('age_to')) {
                $maxDate = now()->subYears((int) $request->age_to + 1)->addDay()->format('Y-m-d');
                $query->whereDate('date_of_birth', '>=', $maxDate);
            }
        }

        if ((! $strict || $inStrict('height_cm')) && $isSearchable('height_cm')) {
            if ($request->filled('height_from')) {
                $query->whereNotNull('height_cm')->where('height_cm', '>=', (int) $request->height_from);
            }
            if ($request->filled('height_to')) {
                $query->whereNotNull('height_cm')->where('height_cm', '<=', (int) $request->height_to);
            }
        }

        if ((! $strict || $inStrict('marital_status_id')) && $isSearchable('marital_status_id') && ($request->filled('marital_status_id') || $request->filled('marital_status'))) {
            $msId = $request->input('marital_status_id') ?: ($request->input('marital_status') === 'single'
                ? MasterMaritalStatus::where('key', 'never_married')->value('id')
                : MasterMaritalStatus::where('key', $request->input('marital_status'))->value('id'));
            if ($msId) {
                $query->where('marital_status_id', $msId);
            }
        }

        if ($advancedProfileSearch && (! $strict || $inStrict('education')) && $isSearchable('education')) {
            if ($request->filled('education_degree_id')) {
                $deg = EducationDegree::query()->find((int) $request->input('education_degree_id'));
                if ($deg) {
                    $labels = array_unique(array_filter(array_map('trim', [
                        (string) ($deg->code ?? ''),
                        (string) ($deg->code_mr ?? ''),
                        (string) ($deg->full_form ?? ''),
                    ]), static fn ($s) => $s !== ''));
                    $query->where(function ($q) use ($labels): void {
                        foreach ($labels as $lab) {
                            $q->orWhere('highest_education', 'like', '%'.addcslashes($lab, '%_\\').'%');
                        }
                    });
                }
            } elseif ($request->filled('education_category_id')) {
                $catId = (int) $request->input('education_category_id');
                $degrees = EducationDegree::query()->where('category_id', $catId)->get(['code', 'code_mr', 'full_form']);
                $query->where(function ($q) use ($degrees): void {
                    foreach ($degrees as $deg) {
                        foreach (array_unique(array_filter(array_map('trim', [
                            (string) ($deg->code ?? ''),
                            (string) ($deg->code_mr ?? ''),
                            (string) ($deg->full_form ?? ''),
                        ]))) as $lab) {
                            if ($lab !== '') {
                                $q->orWhere('highest_education', 'like', '%'.addcslashes($lab, '%_\\').'%');
                            }
                        }
                    }
                });
            } elseif ($request->filled('education')) {
                $query->where('highest_education', $request->input('education'));
            }
        }

        if ((! $strict || $inStrict('profession_id')) && $isSearchable('profession_id') && $request->filled('profession_id')) {
            $profId = (int) $request->input('profession_id');
            if (Schema::hasColumn((new MatrimonyProfile)->getTable(), 'profession_id')) {
                $query->where('profession_id', $profId);
            } elseif (Schema::hasColumn((new MatrimonyProfile)->getTable(), 'occupation_master_id')) {
                $mid = app(OccupationService::class)->occupationMasterIdForProfessionId($profId);
                if ($mid !== null && $mid > 0) {
                    $query->where('occupation_master_id', $mid);
                }
            }
        }

        if ($advancedProfileSearch && ! $strict && ($request->filled('income_min') || $request->filled('income_max'))) {
            $query->whereNotNull('income_normalized_annual_amount');
            if ($request->filled('income_min')) {
                $query->where('income_normalized_annual_amount', '>=', max(0, (int) $request->input('income_min')));
            }
            if ($request->filled('income_max')) {
                $query->where('income_normalized_annual_amount', '<=', max(0, (int) $request->input('income_max')));
            }
        }

        if ((! $strict || $inStrict('serious_intent_id')) && $isSearchable('serious_intent_id') && $request->filled('serious_intent_id')) {
            $query->where('serious_intent_id', $request->input('serious_intent_id'));
        }

        if (! $strict && $request->boolean('has_photo')) {
            $query->whereNotNull('profile_photo')
                ->where(function ($q) {
                    $q->whereNull('photo_approved')->orWhere('photo_approved', 1);
                });
        }

        if (! $strict && $request->boolean('verified_only')) {
            $query->whereHas('user', function ($q) {
                $q->whereNotNull('email_verified_at');
            });
        }

        if (! $strict) {
            $query->whereRaw(ProfileCompletenessService::sqlSearchVisible('matrimony_profiles'));

            $showcaseVisibleInSearch = \App\Models\AdminSetting::getBool('showcase_profiles_visible_in_search', true);
            if (! $showcaseVisibleInSearch) {
                $query->whereNonShowcase();
            }

            if (\App\Models\AdminSetting::getBool('search_opposite_gender_only', false) && auth()->check()) {
                $viewerProfile = auth()->user()->matrimonyProfile;
                if ($viewerProfile) {
                    $viewerProfile->loadMissing('gender');
                    $viewerGenderKey = $viewerProfile->gender?->key ?? null;
                    if ($viewerGenderKey === 'male') {
                        $query->whereHas('gender', fn ($g) => $g->where('key', 'female'));
                    } elseif ($viewerGenderKey === 'female') {
                        $query->whereHas('gender', fn ($g) => $g->where('key', 'male'));
                    }
                }
            }

            $myId = auth()->user()?->matrimonyProfile?->id;
            if ($myId) {
                $blockedIds = ViewTrackingService::getBlockedProfileIds($myId);
                $hiddenIds = HiddenProfile::query()
                    ->where('owner_profile_id', $myId)
                    ->pluck('hidden_profile_id');
                $excludeIds = $blockedIds->merge($hiddenIds)->unique()->values();
                if ($excludeIds->isNotEmpty()) {
                    $query->whereNotIn('id', $excludeIds);
                }
            }
        }
    }

    /**
     * Normalize strict dimension keys from admin JSON (supports legacy `location` as one bucket).
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function normalizeStrictKeys(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $k = strtolower(trim((string) $k));
            if ($k === '') {
                continue;
            }
            if ($k === 'location') {
                $out[] = 'location';
            } else {
                $out[] = $k;
            }
        }

        return array_values(array_unique($out));
    }

    public static function nearbyLocationIdFromRequest(Request $request): ?int
    {
        $id = (int) $request->input('nearby_location_id', 0);

        return $id > 0 ? $id : null;
    }

    public static function nearbyRadiusFromRequest(Request $request): int
    {
        $radius = (int) $request->input('nearby_radius_km', 25);

        return max(1, min(200, $radius));
    }

    /**
     * @return array<int, float>
     */
    public static function nearbyDistanceMapFromRequest(Request $request): array
    {
        $locationId = self::nearbyLocationIdFromRequest($request);
        if ($locationId === null) {
            return [];
        }

        $source = Location::query()->find($locationId);
        if (! $source || $source->lat === null || $source->lng === null) {
            return [];
        }

        $distances = [(int) $source->id => 0.0];
        foreach (app(LocationService::class)->getNearbyLocations((int) $source->id, self::nearbyRadiusFromRequest($request)) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $distances[$id] = round((float) ($row['distance_km'] ?? 0), 2);
            }
        }

        asort($distances, SORT_NUMERIC);

        return $distances;
    }

    /**
     * @param  array<int, float>  $distanceByLocation
     */
    public static function applyNearbyDistanceSelect(Builder $query, array $distanceByLocation): void
    {
        $distanceByLocation = self::normalizeDistanceMap($distanceByLocation);
        if ($distanceByLocation === []) {
            return;
        }

        [$leafExpression, $leafBindings] = self::residenceLeafSqlExpression();
        if ($leafExpression === null) {
            return;
        }

        $caseSql = 'CASE '.$leafExpression;
        $bindings = $leafBindings;
        foreach ($distanceByLocation as $locationId => $distanceKm) {
            $caseSql .= ' WHEN ? THEN ?';
            $bindings[] = (int) $locationId;
            $bindings[] = (float) $distanceKm;
        }
        $caseSql .= ' ELSE NULL END as nearby_distance_km';

        $query->addSelect('matrimony_profiles.*');
        $query->selectRaw($caseSql, $bindings);
    }

    public static function applyNearbyOrdering(Builder $query): void
    {
        $query->orderByRaw('nearby_distance_km IS NULL ASC')
            ->orderBy('nearby_distance_km', 'asc');
    }

    /**
     * @param  Builder<MatrimonyProfile>  $query
     */
    public static function applyStrictSubsetFilters(Builder $query, Request $request, array $strictKeysFromAdmin): void
    {
        $searchableFields = ProfileFieldConfigurationService::getSearchableFieldKeys();
        $enabledFields = ProfileFieldConfigurationService::getEnabledFieldKeys();
        $enabledSearchableFields = array_intersect($searchableFields, $enabledFields);
        $isSearchable = fn (string $fieldKey) => in_array($fieldKey, $enabledSearchableFields, true);

        $normalized = self::normalizeStrictKeys($strictKeysFromAdmin);
        if ($normalized === []) {
            return;
        }

        self::applyRequestSearchFiltersWithChecker($query, $request, $isSearchable, $normalized);
    }

    /**
     * @param  array<int, int>  $locationIds
     */
    private static function whereResidenceLeafIn(Builder $query, array $locationIds): void
    {
        $locationIds = array_values(array_unique(array_filter(array_map('intval', $locationIds), static fn (int $id): bool => $id > 0)));
        if ($locationIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        if (Schema::hasColumn('matrimony_profiles', 'location_id')) {
            $query->whereIn('location_id', $locationIds);

            return;
        }

        $tid = ProfileCanonicalResidenceService::currentAddressTypeId();
        if ($tid === null || ! Schema::hasTable('profile_addresses')) {
            $query->whereRaw('1 = 0');

            return;
        }

        $leafColumn = Schema::hasColumn('profile_addresses', 'location_id') ? 'profile_addresses.location_id' : 'profile_addresses.city_id';
        $query->whereExists(function ($q) use ($locationIds, $tid, $leafColumn): void {
            $q->selectRaw('1')
                ->from('profile_addresses')
                ->whereColumn('profile_addresses.profile_id', 'matrimony_profiles.id')
                ->where('profile_addresses.address_scope', 'self')
                ->where('profile_addresses.address_type_id', $tid)
                ->whereIn($leafColumn, $locationIds);
        });
    }

    /**
     * @param  array<int, float>  $distanceByLocation
     * @return array<int, float>
     */
    private static function normalizeDistanceMap(array $distanceByLocation): array
    {
        $out = [];
        foreach ($distanceByLocation as $locationId => $distanceKm) {
            $id = (int) $locationId;
            if ($id > 0) {
                $out[$id] = round((float) $distanceKm, 2);
            }
        }

        asort($out, SORT_NUMERIC);

        return $out;
    }

    /**
     * @return array{0: string|null, 1: list<mixed>}
     */
    private static function residenceLeafSqlExpression(): array
    {
        if (Schema::hasColumn('matrimony_profiles', 'location_id')) {
            return ['matrimony_profiles.location_id', []];
        }

        $tid = ProfileCanonicalResidenceService::currentAddressTypeId();
        if ($tid === null || ! Schema::hasTable('profile_addresses')) {
            return [null, []];
        }

        $leafColumn = Schema::hasColumn('profile_addresses', 'location_id') ? 'pa.location_id' : 'pa.city_id';

        return [
            '(select '.$leafColumn.' from profile_addresses pa where pa.profile_id = matrimony_profiles.id and pa.address_scope = ? and pa.address_type_id = ? and '.$leafColumn.' is not null limit 1)',
            ['self', (int) $tid],
        ];
    }

    /**
     * Full filtered listing query (no ordering / spotlight) — use for counts and as base before sort.
     *
     * @return Builder<MatrimonyProfile>
     */
    public static function newFilteredListingQuery(Request $request, ?int $excludeViewerProfileId): Builder
    {
        $query = MatrimonyProfile::query();
        self::applyCoreListingScope($query, $excludeViewerProfileId);
        self::applyRequestSearchFilters($query, $request);

        return $query;
    }

    /**
     * Strict-match count for auto-showcase AND leg (subset of filters).
     */
    public static function countStrictMatches(Request $request, ?int $excludeViewerProfileId, array $strictKeysFromAdmin): int
    {
        $query = MatrimonyProfile::query();
        self::applyCoreListingScope($query, $excludeViewerProfileId);
        $query->whereRaw(ProfileCompletenessService::sqlSearchVisible('matrimony_profiles'));

        $showcaseVisibleInSearch = \App\Models\AdminSetting::getBool('showcase_profiles_visible_in_search', true);
        if (! $showcaseVisibleInSearch) {
            $query->whereNonShowcase();
        }

        if (\App\Models\AdminSetting::getBool('search_opposite_gender_only', false) && auth()->check()) {
            $viewerProfile = auth()->user()->matrimonyProfile;
            if ($viewerProfile) {
                $viewerProfile->loadMissing('gender');
                $viewerGenderKey = $viewerProfile->gender?->key ?? null;
                if ($viewerGenderKey === 'male') {
                    $query->whereHas('gender', fn ($g) => $g->where('key', 'female'));
                } elseif ($viewerGenderKey === 'female') {
                    $query->whereHas('gender', fn ($g) => $g->where('key', 'male'));
                }
            }
        }

        $myId = auth()->user()?->matrimonyProfile?->id;
        if ($myId) {
            $blockedIds = ViewTrackingService::getBlockedProfileIds($myId);
            $hiddenIds = HiddenProfile::query()
                ->where('owner_profile_id', $myId)
                ->pluck('hidden_profile_id');
            $excludeIds = $blockedIds->merge($hiddenIds)->unique()->values();
            if ($excludeIds->isNotEmpty()) {
                $query->whereNotIn('id', $excludeIds);
            }
        }

        self::applyStrictSubsetFilters($query, $request, $strictKeysFromAdmin);

        return (int) $query->count();
    }
}
