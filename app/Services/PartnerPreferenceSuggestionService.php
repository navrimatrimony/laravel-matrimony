<?php

namespace App\Services;

use App\Models\District;
use App\Models\MasterMaritalStatus;
use App\Models\MatrimonyProfile;
use Illuminate\Support\Facades\DB;

/**
 * Compute non-persistent partner preference suggestions from the profile.
 * No DB writes – controller uses this only for default form values.
 */
class PartnerPreferenceSuggestionService
{
    /** Canonical centimetres for a 4-inch span (4 × 2.54, rounded). */
    public static function fourInchesCm(): int
    {
        return (int) round(4 * 2.54);
    }

    /**
     * Suggested partner height range from profile gender + height_cm (not persisted).
     * Male: min = height − 4 in, max = height. Female: min = height, max = height + 4 in.
     *
     * @return array{min: int, max: int}|null
     */
    public static function defaultPreferredHeightRangeCm(MatrimonyProfile $profile): ?array
    {
        $profile->loadMissing('gender');
        $h = $profile->height_cm;
        if ($h === null || $h === '') {
            return null;
        }
        $h = (int) $h;
        if ($h < 1) {
            return null;
        }
        $genderKey = $profile->gender?->key ?? null;
        if ($genderKey !== 'male' && $genderKey !== 'female') {
            return null;
        }
        $delta = self::fourInchesCm();
        if ($genderKey === 'male') {
            $min = $h - $delta;
            $max = $h;
        } else {
            $min = $h;
            $max = $h + $delta;
        }
        $min = max(1, $min);
        $max = max(1, $max);
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        return ['min' => $min, 'max' => $max];
    }

    /**
     * Default partner marital status preference from the member's own marital status (not persisted here).
     * never_married → prefer never_married; any other known status → open to all (null).
     * Missing/invalid member marital status → null (no default forced).
     *
     * @return int|null master_marital_statuses.id for never_married, or null for open-to-all
     */
    public static function defaultPreferredMaritalStatusId(MatrimonyProfile $profile): ?int
    {
        $profile->loadMissing('maritalStatus');
        $key = $profile->maritalStatus?->key;
        if ($key === null || $key === '') {
            return null;
        }
        if ($key === 'never_married') {
            return MasterMaritalStatus::query()
                ->where('key', 'never_married')
                ->where('is_active', true)
                ->value('id');
        }

        return null;
    }

    public static function profileAge(MatrimonyProfile $profile): ?int
    {
        if (! $profile->date_of_birth) {
            return null;
        }
        try {
            $a = \Carbon\Carbon::parse($profile->date_of_birth)->age;

            return $a > 0 ? $a : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Default partner age range from member gender + age (slider domain 18–80).
     * Male: min = user age − 5, max = user age. Female: min = user age, max = user age + 5.
     *
     * @return array{min: int, max: int}|null
     */
    public static function defaultPreferredAgeRange(MatrimonyProfile $profile): ?array
    {
        $profile->loadMissing('gender');
        $age = self::profileAge($profile);
        if ($age === null) {
            return null;
        }
        $isFemale = ($profile->gender?->key ?? null) === 'female';
        if ($isFemale) {
            $min = $age;
            $max = $age + 5;
        } else {
            $min = $age - 5;
            $max = $age;
        }
        $min = max(18, min(80, $min));
        $max = max(18, min(80, $max));
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        return ['min' => $min, 'max' => $max];
    }

    /**
     * Member's residence district ({@code addresses}.id) derived from {@see MatrimonyProfile::location_id}.
     */
    public static function resolveProfileDistrictId(MatrimonyProfile $profile): ?int
    {
        $id = $profile->residenceGeoAddressIds()['district_id'] ?? null;

        return ($id !== null && $id > 0) ? $id : null;
    }

    /**
     * Default partner location pivots from member district (minimal valid country→state→district chain).
     * If district cannot be resolved, returns empty arrays ("open to all" in UI).
     *
     * @return array{
     *   preferred_country_ids: array<int, int>,
     *   preferred_state_ids: array<int, int>,
     *   preferred_district_ids: array<int, int>,
     *   preferred_taluka_ids: array<int, int>
     * }
     */
    public static function defaultLocationPivotsFromOwnDistrict(MatrimonyProfile $profile): array
    {
        $empty = [
            'preferred_country_ids' => [],
            'preferred_state_ids' => [],
            'preferred_district_ids' => [],
            'preferred_taluka_ids' => [],
        ];
        $districtId = self::resolveProfileDistrictId($profile);
        if ($districtId === null) {
            return $empty;
        }
        $district = District::query()->with('state')->whereKey($districtId)->first();
        if ($district === null) {
            return $empty;
        }
        $state = $district->state;
        if ($state === null || $state->country_id === null || $state->country_id === '') {
            return $empty;
        }

        return [
            'preferred_country_ids' => [(int) $state->country_id],
            'preferred_state_ids' => [(int) $state->id],
            'preferred_district_ids' => [(int) $district->id],
            'preferred_taluka_ids' => [],
        ];
    }

    /**
     * Default partner diet pivot IDs from member's own diet_id (single id in pivot). Not persisted here.
     *
     * @return array<int, int>
     */
    public static function defaultPreferredDietIds(MatrimonyProfile $profile): array
    {
        $did = $profile->diet_id;
        if ($did === null || $did === '') {
            return [];
        }
        $id = (int) $did;
        if ($id < 1) {
            return [];
        }
        $exists = DB::table('master_diets')->where('id', $id)->where('is_active', true)->exists();

        return $exists ? [$id] : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function suggestForProfile(MatrimonyProfile $profile): array
    {
        $location = self::defaultLocationPivotsFromOwnDistrict($profile);

        $out = [
            'preferred_age_min' => null,
            'preferred_age_max' => null,
            'preferred_height_min_cm' => null,
            'preferred_height_max_cm' => null,
            'preferred_income_min' => null,
            'preferred_income_max' => null,
            'preferred_city_id' => null,
            'preferred_religion_ids' => [],
            'preferred_caste_ids' => [],
            'preferred_country_ids' => $location['preferred_country_ids'],
            'preferred_state_ids' => $location['preferred_state_ids'],
            'preferred_district_ids' => $location['preferred_district_ids'],
            'preferred_taluka_ids' => $location['preferred_taluka_ids'],
            'preferred_education_degree_ids' => [],
            'preferred_occupation_master_ids' => [],
            'preferred_diet_ids' => self::defaultPreferredDietIds($profile),
            'preferred_marital_status_id' => null,
            'preferred_marital_status_ids' => [],
            'preference_preset' => 'balanced',
        ];

        $ageRange = self::defaultPreferredAgeRange($profile);
        if ($ageRange !== null) {
            $out['preferred_age_min'] = $ageRange['min'];
            $out['preferred_age_max'] = $ageRange['max'];
        }

        $heightRange = self::defaultPreferredHeightRangeCm($profile);
        if ($heightRange !== null) {
            $out['preferred_height_min_cm'] = $heightRange['min'];
            $out['preferred_height_max_cm'] = $heightRange['max'];
        }

        // Income: prefer normalized annual amount from income engine; fallback to legacy annual_income.
        $income = null;
        if (! empty($profile->income_normalized_annual_amount)) {
            $income = (float) $profile->income_normalized_annual_amount;
        } elseif (! empty($profile->annual_income)) {
            $income = (float) $profile->annual_income;
        }
        if ($income !== null) {
            $out['preferred_income_min'] = max(0, round($income * 0.7, 2));
            $out['preferred_income_max'] = null;
        }

        if (! empty($profile->native_city_id)) {
            $out['preferred_city_id'] = (int) $profile->native_city_id;
        }

        if (! empty($profile->religion_id)) {
            $out['preferred_religion_ids'] = [(int) $profile->religion_id];
        }

        if (! empty($profile->caste_id)) {
            $out['preferred_caste_ids'] = [(int) $profile->caste_id];
        }

        $mid = self::defaultPreferredMaritalStatusId($profile);
        $out['preferred_marital_status_id'] = $mid;
        $out['preferred_marital_status_ids'] = $mid !== null ? [(int) $mid] : [];

        return $out;
    }

    /**
     * Merge saved DB partner preference row + pivot selections with engine defaults for any missing slots (UI load only).
     * Income min/max are never filled from the income suggestion — only preserved when already saved.
     *
     * @return array{
     *   criteria: object,
     *   preferredReligionIds: array<int, int>,
     *   preferredCasteIds: array<int, int>,
     *   preferredCountryIds: array<int, int>,
     *   preferredStateIds: array<int, int>,
     *   preferredDistrictIds: array<int, int>,
     *   preferredTalukaIds: array<int, int>,
     *   preferredDietIds: array<int, int>,
     *   preferredMaritalStatusIds: array<int, int>,
     * }
     */
    public static function mergePartnerPreferencesForDisplay(
        MatrimonyProfile $profile,
        ?object $criteria,
        array $religionIds,
        array $casteIds,
        array $countryIds,
        array $stateIds,
        array $districtIds,
        array $talukaIds,
        array $dietIds,
        array $maritalStatusIds = [],
    ): array {
        $s = self::suggestForProfile($profile);

        $row = $criteria !== null
            ? json_decode(json_encode($criteria), true)
            : [];
        if (! is_array($row)) {
            $row = [];
        }

        $age = self::defaultPreferredAgeRange($profile);
        if ($age !== null) {
            if (($row['preferred_age_min'] ?? null) === null) {
                $row['preferred_age_min'] = $age['min'];
            }
            if (($row['preferred_age_max'] ?? null) === null) {
                $row['preferred_age_max'] = $age['max'];
            }
        }

        $height = self::defaultPreferredHeightRangeCm($profile);
        if ($height !== null) {
            if (($row['preferred_height_min_cm'] ?? null) === null) {
                $row['preferred_height_min_cm'] = $height['min'];
            }
            if (($row['preferred_height_max_cm'] ?? null) === null) {
                $row['preferred_height_max_cm'] = $height['max'];
            }
        }

        $row['preferred_income_min'] = $row['preferred_income_min'] ?? null;
        $row['preferred_income_max'] = $row['preferred_income_max'] ?? null;

        if (($row['preferred_marital_status_id'] ?? null) === null) {
            $row['preferred_marital_status_id'] = self::defaultPreferredMaritalStatusId($profile);
        }

        $normalize = static fn (array $ids): array => array_values(array_unique(array_filter(array_map('intval', $ids))));

        $religionIds = $normalize($religionIds);
        $casteIds = $normalize($casteIds);
        $countryIds = $normalize($countryIds);
        $stateIds = $normalize($stateIds);
        $districtIds = $normalize($districtIds);
        $talukaIds = $normalize($talukaIds);
        $dietIds = $normalize($dietIds);

        if ($religionIds === []) {
            $religionIds = $normalize($s['preferred_religion_ids'] ?? []);
        }
        if ($casteIds === []) {
            $casteIds = $normalize($s['preferred_caste_ids'] ?? []);
        }
        if ($countryIds === [] && $stateIds === [] && $districtIds === [] && $talukaIds === []) {
            $countryIds = $normalize($s['preferred_country_ids'] ?? []);
            $stateIds = $normalize($s['preferred_state_ids'] ?? []);
            $districtIds = $normalize($s['preferred_district_ids'] ?? []);
            $talukaIds = $normalize($s['preferred_taluka_ids'] ?? []);
        }
        if ($dietIds === []) {
            $dietIds = $normalize($s['preferred_diet_ids'] ?? []);
        }

        $maritalIds = $normalize($maritalStatusIds);
        if ($maritalIds === [] && ($row['preferred_marital_status_id'] ?? null) !== null) {
            $maritalIds = [(int) $row['preferred_marital_status_id']];
        }

        return [
            'criteria' => (object) $row,
            'preferredReligionIds' => $religionIds,
            'preferredCasteIds' => $casteIds,
            'preferredCountryIds' => $countryIds,
            'preferredStateIds' => $stateIds,
            'preferredDistrictIds' => $districtIds,
            'preferredTalukaIds' => $talukaIds,
            'preferredDietIds' => $dietIds,
            'preferredMaritalStatusIds' => $maritalIds,
        ];
    }
}
