<?php

namespace App\Services;

use App\Models\HiddenProfile;
use App\Models\MasterMaritalStatus;
use App\Models\MatrimonyProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

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
                $query->where('country_id', (int) $request->country_id);
            }
            if ($request->filled('state_id') && $geoActive('state_id')) {
                $query->where('state_id', (int) $request->state_id);
            }
            if ($request->filled('district_id') && $geoActive('district_id')) {
                $query->where('district_id', (int) $request->district_id);
            }
            if ($request->filled('taluka_id') && $geoActive('taluka_id')) {
                $query->where('taluka_id', (int) $request->taluka_id);
            }
            if ($request->filled('city_id') && $geoActive('city_id')) {
                $query->where('city_id', (int) $request->city_id);
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

        if ($advancedProfileSearch && (! $strict || $inStrict('education')) && $isSearchable('education') && $request->filled('education')) {
            $query->where('highest_education', $request->input('education'));
        }

        if ((! $strict || $inStrict('profession_id')) && $isSearchable('profession_id') && $request->filled('profession_id')) {
            $query->where('profession_id', $request->input('profession_id'));
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

            $showcaseVisibleInSearch = \App\Models\AdminSetting::getBool('showcase_profiles_visible_in_search', \App\Models\AdminSetting::getBool('demo_profiles_visible_in_search', true));
            if (! $showcaseVisibleInSearch) {
                $query->where(function ($q) {
                    $q->where('is_demo', false)->orWhereNull('is_demo');
                });
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

        $showcaseVisibleInSearch = \App\Models\AdminSetting::getBool('showcase_profiles_visible_in_search', \App\Models\AdminSetting::getBool('demo_profiles_visible_in_search', true));
        if (! $showcaseVisibleInSearch) {
            $query->where(function ($q) {
                $q->where('is_demo', false)->orWhereNull('is_demo');
            });
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
