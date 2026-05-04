<?php

namespace App\Services;

use App\Models\Caste;
use App\Models\District;
use App\Models\MasterMaritalStatus;
use App\Models\State;
use App\Models\Taluka;
use App\Support\Validation\AddressHierarchyRules;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validates HTTP input for partner preferences and builds the flat snapshot row
 * consumed by MutationService::syncPreferencesFromSnapshot.
 */
class PartnerPreferenceSnapshotBuilder
{
    /**
     * True when the request carries partner-preference fields posted by the wizard / full form (flat keys).
     */
    public static function requestHasFlatPartnerPreferenceFields(Request $request): bool
    {
        return $request->hasAny([
            'preferred_age_min',
            'preferred_age_max',
            'preferred_height_min_cm',
            'preferred_height_max_cm',
            'preferred_income_min',
            'preferred_income_max',
            'preferred_religion_ids',
            'preferred_caste_ids',
            'preferred_district_ids',
            'preferred_country_ids',
            'preferred_state_ids',
            'preferred_taluka_ids',
            'willing_to_relocate',
            'marriage_type_preference_id',
            'preferred_marital_status_id',
            'preferred_marital_status_ids',
            'partner_profile_with_children',
            'preference_preset',
            'preferred_education_degree_ids',
            'preferred_occupation_master_ids',
            'preferred_profile_managed_by',
            'preferred_diet_ids',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function validateAndBuildRow(Request $request): array
    {
        if ($request->has('preferred_marital_status_id') && $request->input('preferred_marital_status_id') === '') {
            $request->merge(['preferred_marital_status_id' => null]);
        }
        if ($request->has('partner_profile_with_children') && $request->input('partner_profile_with_children') === '') {
            $request->merge(['partner_profile_with_children' => null]);
        }

        $validated = $request->validate([
            'preferred_age_min' => ['nullable', 'integer', 'min:18', 'max:80'],
            'preferred_age_max' => ['nullable', 'integer', 'min:18', 'max:80'],
            'preferred_height_min_cm' => ['nullable', 'integer', 'min:1'],
            'preferred_height_max_cm' => ['nullable', 'integer', 'min:1'],
            'preferred_income_min' => ['nullable', 'numeric', 'min:0'],
            'preferred_income_max' => ['nullable', 'numeric', 'min:0'],
            'preferred_city_id' => ['nullable', 'integer', AddressHierarchyRules::existsCityId()],
            'preferred_religion_ids' => ['nullable', 'array'],
            'preferred_religion_ids.*' => ['integer', Rule::exists('religions', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'preferred_caste_ids' => ['nullable', 'array'],
            'preferred_caste_ids.*' => ['integer', Rule::exists('castes', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'preferred_district_ids' => ['nullable', 'array'],
            'preferred_district_ids.*' => ['integer', AddressHierarchyRules::existsDistrictId()],
            'preferred_country_ids' => ['nullable', 'array'],
            'preferred_country_ids.*' => ['integer', AddressHierarchyRules::existsCountryId()],
            'preferred_state_ids' => ['nullable', 'array'],
            'preferred_state_ids.*' => ['integer', AddressHierarchyRules::existsStateId()],
            'preferred_taluka_ids' => ['nullable', 'array'],
            'preferred_taluka_ids.*' => ['integer', AddressHierarchyRules::existsTalukaId()],
            'preferred_education_degree_ids' => ['nullable', 'array'],
            'preferred_education_degree_ids.*' => ['integer', 'exists:education_degrees,id'],
            'preferred_occupation_master_ids' => ['nullable', 'array'],
            'preferred_occupation_master_ids.*' => ['integer', 'exists:occupation_master,id'],
            'preferred_profile_managed_by' => ['nullable', 'string', Rule::in(['', 'self', 'parent_guardian', 'sibling', 'relative', 'friend', 'other'])],
            'preferred_diet_ids' => ['nullable', 'array'],
            'preferred_diet_ids.*' => ['integer', Rule::exists('master_diets', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'willing_to_relocate' => ['nullable', 'boolean'],
            'settled_city_preference_id' => ['nullable', 'integer', AddressHierarchyRules::existsCityId()],
            'settled_preference' => ['nullable', 'array'],
            'settled_preference.city_id' => ['nullable', 'integer', AddressHierarchyRules::existsCityId()],
            'marriage_type_preference_id' => ['nullable', 'integer', Rule::exists('master_marriage_type_preferences', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'preferred_marital_status_id' => ['nullable', 'integer', Rule::exists('master_marital_statuses', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'preferred_marital_status_ids' => ['nullable', 'array'],
            'preferred_marital_status_ids.*' => ['integer', Rule::exists('master_marital_statuses', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'partner_profile_with_children' => ['nullable', 'string', Rule::in(['no', 'yes_if_live_separate', 'yes'])],
        ]);

        if (
            isset($validated['preferred_age_min'], $validated['preferred_age_max']) &&
            $validated['preferred_age_min'] !== null &&
            $validated['preferred_age_max'] !== null &&
            $validated['preferred_age_min'] > $validated['preferred_age_max']
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'preferred_age_min' => ['Minimum age must be less than or equal to maximum age.'],
            ]);
        }
        if (
            isset($validated['preferred_income_min'], $validated['preferred_income_max']) &&
            $validated['preferred_income_min'] !== null &&
            $validated['preferred_income_max'] !== null &&
            (float) $validated['preferred_income_min'] > (float) $validated['preferred_income_max']
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'preferred_income_min' => ['Minimum income must be less than or equal to maximum income.'],
            ]);
        }
        if (
            isset($validated['preferred_height_min_cm'], $validated['preferred_height_max_cm']) &&
            $validated['preferred_height_min_cm'] !== null &&
            $validated['preferred_height_max_cm'] !== null &&
            (int) $validated['preferred_height_min_cm'] > (int) $validated['preferred_height_max_cm']
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'preferred_height_min_cm' => ['Minimum height must be less than or equal to maximum height.'],
            ]);
        }

        $districtIds = $validated['preferred_district_ids'] ?? [];

        $countryIds = array_values(array_unique(array_map('intval', $validated['preferred_country_ids'] ?? [])));
        $stateIds = array_values(array_unique(array_map('intval', $validated['preferred_state_ids'] ?? [])));
        $talukaIds = array_values(array_unique(array_map('intval', array_filter($validated['preferred_taluka_ids'] ?? []))));

        self::expandPartnerLocationParents($countryIds, $stateIds, $districtIds, $talukaIds);
        self::validatePreferredLocationHierarchyFromArrays($countryIds, $stateIds, $districtIds, $talukaIds);
        self::validatePreferredCasteReligionCompatibility($validated);

        $educationDegreeIds = array_values(array_unique(array_map('intval', array_filter($validated['preferred_education_degree_ids'] ?? []))));
        $occupationMasterIds = array_values(array_unique(array_map('intval', array_filter($validated['preferred_occupation_master_ids'] ?? []))));

        $dietIds = array_values(array_unique(array_map('intval', array_filter($validated['preferred_diet_ids'] ?? []))));

        $maritalIds = array_values(array_unique(array_map('intval', array_filter($validated['preferred_marital_status_ids'] ?? []))));
        if ($maritalIds === [] && ($validated['preferred_marital_status_id'] ?? null) !== null) {
            $maritalIds = [(int) $validated['preferred_marital_status_id']];
        }
        if ($maritalIds !== []) {
            $allActiveMaritalIds = MasterMaritalStatus::query()
                ->where('is_active', true)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->sort()
                ->values()
                ->all();
            $sortedPosted = collect($maritalIds)->sort()->values()->all();
            if ($allActiveMaritalIds !== [] && $sortedPosted === $allActiveMaritalIds) {
                $maritalIds = [];
            }
        }
        $maritalColumn = count($maritalIds) === 1 ? $maritalIds[0] : null;

        $row = [
            'preferred_age_min' => $validated['preferred_age_min'] ?? null,
            'preferred_age_max' => $validated['preferred_age_max'] ?? null,
            'preferred_height_min_cm' => $validated['preferred_height_min_cm'] ?? null,
            'preferred_height_max_cm' => $validated['preferred_height_max_cm'] ?? null,
            'preferred_income_min' => $validated['preferred_income_min'] ?? null,
            'preferred_income_max' => $validated['preferred_income_max'] ?? null,
            'willing_to_relocate' => $request->boolean('willing_to_relocate') ? true : null,
            'marriage_type_preference_id' => $validated['marriage_type_preference_id'] ?? null,
            'preferred_marital_status_id' => $maritalColumn,
            'preferred_marital_status_ids' => $maritalIds,
            'preferred_religion_ids' => $validated['preferred_religion_ids'] ?? [],
            'preferred_caste_ids' => $validated['preferred_caste_ids'] ?? [],
            'preferred_country_ids' => $countryIds,
            'preferred_state_ids' => $stateIds,
            'preferred_district_ids' => $districtIds,
            'preferred_taluka_ids' => $talukaIds,
            'preferred_education_degree_ids' => $educationDegreeIds,
            'preferred_occupation_master_ids' => $occupationMasterIds,
            'preferred_diet_ids' => $dietIds,
        ];

        // Only when posted (visible, enabled inputs). Omit when hidden/disabled so DB value is not cleared.
        if ($request->has('partner_profile_with_children')) {
            $row['partner_profile_with_children'] = $validated['partner_profile_with_children'] ?? null;
        }
        if ($request->has('preferred_city_id')) {
            $row['preferred_city_id'] = $validated['preferred_city_id'] ?? null;
        }
        if ($request->has('settled_city_preference_id') || $request->has('settled_preference')) {
            $row['settled_city_preference_id'] = $validated['settled_city_preference_id'] ?? (isset($validated['settled_preference']['city_id']) ? (int) $validated['settled_preference']['city_id'] : null);
        }
        if ($request->has('preferred_profile_managed_by')) {
            $v = $validated['preferred_profile_managed_by'] ?? null;
            $row['preferred_profile_managed_by'] = ($v === '' || $v === null) ? null : (string) $v;
        }

        return $row;
    }

    /**
     * After merge + expand, location selections must form a consistent country → state → district → taluka chain.
     *
     * @param  array<int>  $countryIds
     * @param  array<int>  $stateIds
     * @param  array<int>  $districtIds
     * @param  array<int>  $talukaIds
     */
    private static function validatePreferredLocationHierarchyFromArrays(array $countryIds, array $stateIds, array $districtIds, array $talukaIds): void
    {
        $countryIds = array_values(array_unique(array_map('intval', $countryIds)));
        $stateIds = array_values(array_unique(array_map('intval', $stateIds)));
        $districtIds = array_values(array_unique(array_map('intval', array_filter($districtIds))));
        $talukaIds = array_values(array_unique(array_map('intval', array_filter($talukaIds))));

        if ($stateIds !== [] && $countryIds === []) {
            throw ValidationException::withMessages([
                'preferred_country_ids' => [__('wizard.preferred_location_country_required_for_state')],
            ]);
        }
        foreach ($stateIds as $sid) {
            $s = State::query()->find($sid);
            if (! $s || ! in_array((int) $s->parent_id, $countryIds, true)) {
                throw ValidationException::withMessages([
                    'preferred_state_ids' => [__('wizard.preferred_location_state_not_in_country')],
                ]);
            }
        }
        if ($districtIds !== [] && $stateIds === []) {
            throw ValidationException::withMessages([
                'preferred_state_ids' => [__('wizard.preferred_location_state_required_for_district')],
            ]);
        }
        foreach ($districtIds as $did) {
            $d = District::query()->find($did);
            if (! $d || ! in_array((int) $d->parent_id, $stateIds, true)) {
                throw ValidationException::withMessages([
                    'preferred_district_ids' => [__('wizard.preferred_location_district_not_in_state')],
                ]);
            }
        }
        if ($talukaIds !== [] && $districtIds === []) {
            throw ValidationException::withMessages([
                'preferred_district_ids' => [__('wizard.preferred_location_district_required_for_taluka')],
            ]);
        }
        foreach ($talukaIds as $tid) {
            $t = Taluka::query()->find($tid);
            if (! $t || ! in_array((int) $t->parent_id, $districtIds, true)) {
                throw ValidationException::withMessages([
                    'preferred_taluka_ids' => [__('wizard.preferred_location_taluka_not_in_district')],
                ]);
            }
        }
    }

    /**
     * Add implied parents for districts/talukas (e.g. city-derived districts) so pivots stay consistent.
     *
     * @param  array<int>  $countryIds
     * @param  array<int>  $stateIds
     * @param  array<int>  $districtIds
     * @param  array<int>  $talukaIds
     */
    private static function expandPartnerLocationParents(array &$countryIds, array &$stateIds, array &$districtIds, array &$talukaIds): void
    {
        foreach ($talukaIds as $tid) {
            $t = Taluka::query()->find($tid);
            if ($t && $t->parent_id && ! in_array((int) $t->parent_id, $districtIds, true)) {
                $districtIds[] = (int) $t->parent_id;
            }
        }
        $districtIds = array_values(array_unique($districtIds));

        foreach ($districtIds as $did) {
            $d = District::query()->find($did);
            if ($d && $d->parent_id && ! in_array((int) $d->parent_id, $stateIds, true)) {
                $stateIds[] = (int) $d->parent_id;
            }
        }
        $stateIds = array_values(array_unique($stateIds));

        foreach ($stateIds as $sid) {
            $s = State::query()->find($sid);
            if ($s && $s->parent_id && ! in_array((int) $s->parent_id, $countryIds, true)) {
                $countryIds[] = (int) $s->parent_id;
            }
        }
        $countryIds = array_values(array_unique($countryIds));
    }

    private static function validatePreferredCasteReligionCompatibility(array $validated): void
    {
        $relIds = array_values(array_unique(array_map('intval', $validated['preferred_religion_ids'] ?? [])));
        $casteIds = array_values(array_unique(array_map('intval', array_filter($validated['preferred_caste_ids'] ?? []))));

        if ($casteIds === []) {
            return;
        }
        if ($relIds === []) {
            throw ValidationException::withMessages([
                'preferred_caste_ids' => [__('wizard.preferred_caste_requires_religion')],
            ]);
        }
        $invalid = Caste::query()
            ->whereIn('id', $casteIds)
            ->whereNotIn('religion_id', $relIds)
            ->exists();
        if ($invalid) {
            throw ValidationException::withMessages([
                'preferred_caste_ids' => [__('wizard.preferred_caste_invalid_for_religion')],
            ]);
        }
    }
}
