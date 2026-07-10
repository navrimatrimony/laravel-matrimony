<?php

namespace App\Services\Intake;

use App\Models\BulkIntakeBatchItem;
use App\Models\City;
use App\Models\Location;
use App\Models\OccupationCategory;
use App\Services\PartnerPreferenceNavService;
use App\Services\PartnerPreferencePresetService;
use App\Services\PartnerPreferenceSnapshotBuilder;
use App\Services\PartnerPreferenceSuggestionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class BulkIntakeRegistrationPreferencesBridgeService
{
    private const INCOME_OPEN_MIN_LAKHS = 0;

    private const INCOME_OPEN_MAX_LAKHS = 500;

    public function __construct(
        private readonly BulkIntakeRegistrationFormBridgeService $formBridge,
    ) {}

    /**
     * Bulk biodata registration: only fill clear-cut fields from own profile.
     * Education, occupation, income, diet, and other ambiguous pivots stay open to all.
     *
     * @return array<string, mixed>
     */
    public function suggestForBulkRegistration(\App\Models\MatrimonyProfile $profile): array
    {
        $full = PartnerPreferenceSuggestionService::suggestForProfile($profile);

        return [
            'preferred_age_min' => $full['preferred_age_min'] ?? null,
            'preferred_age_max' => $full['preferred_age_max'] ?? null,
            'preferred_height_min_cm' => $full['preferred_height_min_cm'] ?? null,
            'preferred_height_max_cm' => $full['preferred_height_max_cm'] ?? null,
            'preferred_religion_ids' => $this->intIds($full['preferred_religion_ids'] ?? []),
            'preferred_caste_ids' => $this->intIds($full['preferred_caste_ids'] ?? []),
            'preferred_country_ids' => $this->intIds($full['preferred_country_ids'] ?? []),
            'preferred_state_ids' => $this->intIds($full['preferred_state_ids'] ?? []),
            'preferred_district_ids' => $this->intIds($full['preferred_district_ids'] ?? []),
            'preferred_taluka_ids' => $this->intIds($full['preferred_taluka_ids'] ?? []),
            'preferred_marital_status_id' => $full['preferred_marital_status_id'] ?? null,
            'preferred_marital_status_ids' => $this->intIds($full['preferred_marital_status_ids'] ?? []),
            'preferred_income_min' => null,
            'preferred_income_max' => null,
            'preferred_education_degree_ids' => [],
            'preferred_occupation_master_ids' => [],
            'preferred_diet_ids' => [],
            'preferred_mother_tongue_ids' => [],
            'willing_to_relocate' => null,
            'marriage_type_preference_id' => null,
            'partner_profile_with_children' => null,
            'preferred_profile_managed_by' => null,
            'preference_preset' => 'balanced',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function viewContext(BulkIntakeBatchItem $item, array $snapshot): array
    {
        $profile = $this->formBridge->profileFromSnapshot($snapshot, $item);
        $prefs = is_array($snapshot['preferences'] ?? null) ? $snapshot['preferences'] : [];
        $bulkDefaults = $this->suggestForBulkRegistration($profile);

        $criteria = $this->criteriaFromPreferencesRow($prefs);
        $preferredReligionIds = $this->intIds($prefs['preferred_religion_ids'] ?? []);
        $preferredCasteIds = $this->intIds($prefs['preferred_caste_ids'] ?? []);
        $preferredDistrictIds = $this->intIds($prefs['preferred_district_ids'] ?? []);
        $preferredCountryIds = $this->intIds($prefs['preferred_country_ids'] ?? []);
        $preferredStateIds = $this->intIds($prefs['preferred_state_ids'] ?? []);
        $preferredTalukaIds = $this->intIds($prefs['preferred_taluka_ids'] ?? []);
        $preferredEducationDegreeIds = $this->intIds($prefs['preferred_education_degree_ids'] ?? []);
        $preferredOccupationMasterIds = $this->intIds($prefs['preferred_occupation_master_ids'] ?? []);
        $preferredDietIds = $this->intIds($prefs['preferred_diet_ids'] ?? []);
        $preferredMaritalStatusIdsFromDb = $this->intIds($prefs['preferred_marital_status_ids'] ?? []);

        $wasCompletelyEmpty = ! $criteria && $preferredReligionIds === [] && $preferredCasteIds === []
            && $preferredDistrictIds === [] && $preferredCountryIds === [] && $preferredStateIds === []
            && $preferredTalukaIds === [] && $preferredEducationDegreeIds === []
            && $preferredOccupationMasterIds === [] && $preferredDietIds === []
            && $preferredMaritalStatusIdsFromDb === []
            && ($criteria?->preferred_marital_status_id ?? null) === null;

        if ($wasCompletelyEmpty) {
            $criteria = $this->criteriaFromPreferencesRow($bulkDefaults);
            $preferredReligionIds = $this->intIds($bulkDefaults['preferred_religion_ids'] ?? []);
            $preferredCasteIds = $this->intIds($bulkDefaults['preferred_caste_ids'] ?? []);
            $preferredCountryIds = $this->intIds($bulkDefaults['preferred_country_ids'] ?? []);
            $preferredStateIds = $this->intIds($bulkDefaults['preferred_state_ids'] ?? []);
            $preferredDistrictIds = $this->intIds($bulkDefaults['preferred_district_ids'] ?? []);
            $preferredTalukaIds = $this->intIds($bulkDefaults['preferred_taluka_ids'] ?? []);
            $preferredEducationDegreeIds = [];
            $preferredOccupationMasterIds = [];
            $preferredDietIds = [];
            $preferredMaritalStatusIdsMerged = $this->intIds($bulkDefaults['preferred_marital_status_ids'] ?? []);
        } else {
            $merged = PartnerPreferenceSuggestionService::mergePartnerPreferencesForDisplay(
                $profile,
                $criteria,
                $preferredReligionIds,
                $preferredCasteIds,
                $preferredCountryIds,
                $preferredStateIds,
                $preferredDistrictIds,
                $preferredTalukaIds,
                [],
                $preferredMaritalStatusIdsFromDb,
            );

            $criteria = $merged['criteria'];
            $preferredReligionIds = $merged['preferredReligionIds'];
            $preferredCasteIds = $merged['preferredCasteIds'];
            $preferredCountryIds = $merged['preferredCountryIds'];
            $preferredStateIds = $merged['preferredStateIds'];
            $preferredDistrictIds = $merged['preferredDistrictIds'];
            $preferredTalukaIds = $merged['preferredTalukaIds'];
            $preferredMaritalStatusIdsMerged = $merged['preferredMaritalStatusIds'] ?? [];
        }

        $criteria = $this->criteriaWithOpenIncome($criteria);

        $base = $bulkDefaults;
        $base['preferred_income_min'] = null;
        $base['preferred_income_max'] = null;
        if (! empty($base['preferred_city_id'])) {
            $cityName = City::where('id', $base['preferred_city_id'])->value('name');
            if ($cityName) {
                $base['preferred_city_name'] = $cityName;
            }
        }

        $preferredCountryIds = array_values(array_unique(array_map('intval', $preferredCountryIds)));
        $preferredStateIds = array_values(array_unique(array_map('intval', $preferredStateIds)));
        $preferredDistrictIds = array_values(array_unique(array_map('intval', $preferredDistrictIds)));
        $preferredTalukaIds = array_values(array_unique(array_map('intval', $preferredTalukaIds)));

        $data = [
            'profile' => $profile,
            'preferencePreset' => $wasCompletelyEmpty ? ($bulkDefaults['preference_preset'] ?? 'balanced') : 'custom',
            'preferencePresetDefaults' => [
                'traditional' => PartnerPreferencePresetService::applyPreset('traditional', $base),
                'balanced' => PartnerPreferencePresetService::applyPreset('balanced', $base),
                'broad' => PartnerPreferencePresetService::applyPreset('broad', $base),
            ],
            'preferenceCriteria' => $criteria,
            'preferredReligionIds' => $preferredReligionIds,
            'preferredCasteIds' => $preferredCasteIds,
            'preferredDistrictIds' => $preferredDistrictIds,
            'preferredCountryIds' => $preferredCountryIds,
            'preferredStateIds' => $preferredStateIds,
            'preferredTalukaIds' => $preferredTalukaIds,
            'preferredEducationDegreeIds' => $preferredEducationDegreeIds,
            'preferredOccupationMasterIds' => $preferredOccupationMasterIds,
            'preferredDietIds' => $preferredDietIds,
            'partnerDietOptions' => \App\Models\MasterDiet::where('is_active', true)->orderBy('sort_order')->orderBy('label')->get(),
            'allCountries' => Location::query()
                ->where('hierarchy', 'country')
                ->where(function ($q) {
                    $q->whereNull('is_active')->orWhere('is_active', true);
                })
                ->orderBy('name')
                ->get(),
            'partnerLocationInitialStates' => $preferredStateIds !== [] || $preferredCountryIds !== []
                ? \App\Models\State::query()
                    ->where(function ($q) use ($preferredCountryIds, $preferredStateIds) {
                        if ($preferredCountryIds !== []) {
                            $q->whereIn('parent_id', $preferredCountryIds);
                        }
                        if ($preferredStateIds !== []) {
                            $q->orWhereIn('id', $preferredStateIds);
                        }
                    })
                    ->orderBy('name')
                    ->get()
                : collect(),
            'partnerLocationInitialDistricts' => $preferredDistrictIds !== [] || $preferredStateIds !== []
                ? \App\Models\District::query()
                    ->where(function ($q) use ($preferredStateIds, $preferredDistrictIds) {
                        if ($preferredStateIds !== []) {
                            $q->whereIn('parent_id', $preferredStateIds);
                        }
                        if ($preferredDistrictIds !== []) {
                            $q->orWhereIn('id', $preferredDistrictIds);
                        }
                    })
                    ->orderBy('name')
                    ->get()
                : collect(),
            'partnerLocationInitialTalukas' => $preferredTalukaIds !== [] || $preferredDistrictIds !== []
                ? \App\Models\Taluka::query()
                    ->where(function ($q) use ($preferredDistrictIds, $preferredTalukaIds) {
                        if ($preferredDistrictIds !== []) {
                            $q->whereIn('parent_id', $preferredDistrictIds);
                        }
                        if ($preferredTalukaIds !== []) {
                            $q->orWhereIn('id', $preferredTalukaIds);
                        }
                    })
                    ->orderBy('name')
                    ->get()
                : collect(),
            'educationCategoriesPartnerPrefs' => \App\Models\EducationCategory::query()
                ->where(function ($q) {
                    $q->whereNull('is_active')->orWhere('is_active', true);
                })
                ->orderBy('sort_order')
                ->orderBy('name')
                ->with(['degrees' => function ($q) {
                    $q->orderBy('sort_order')->orderBy('code');
                }])
                ->get(),
            'occupationCategoriesPartnerPrefs' => Schema::hasTable('master_occupation_categories') && Schema::hasTable('master_occupations')
                ? OccupationCategory::query()
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->with(['occupations' => function ($q) {
                        $q->orderBy('sort_order')->orderBy('name');
                    }])
                    ->get()
                : collect(),
            'preferredMaritalStatusIds' => collect(old('preferred_marital_status_ids', $preferredMaritalStatusIdsMerged))
                ->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->unique()->values()->all(),
            'neverMarriedMaritalStatusId' => \App\Models\MasterMaritalStatus::where('key', 'never_married')->where('is_active', true)->value('id'),
            'partnerProfileWithChildren' => old(
                'partner_profile_with_children',
                $criteria->partner_profile_with_children ?? null
            ),
            'allReligions' => \App\Models\Religion::where('is_active', true)->orderBy('label')->get(),
            'marriageTypePreferences' => \App\Models\MasterMarriageTypePreference::where('is_active', true)->orderBy('sort_order')->get(),
            'allMaritalStatuses' => \App\Models\MasterMaritalStatus::where('is_active', true)->orderBy('label')->get(),
            'interestedInIntercaste' => false,
            'bulkIntakePreferencesMode' => true,
            'bulkPreferencesIncomeOpenToAll' => true,
            'partnerPrefTabMode' => true,
            'currentSection' => 'full',
        ];

        $partnerCastes = \App\Models\Caste::where('is_active', true)->orderBy('label')->get();
        $data['partnerCastesByReligion'] = $partnerCastes->groupBy('religion_id')->map(
            fn ($group) => $group->map(fn ($c) => ['id' => $c->id, 'label' => $c->display_label])->values()->all()
        )->all();
        $data['partnerCasteById'] = $partnerCastes->keyBy('id')->map(
            fn ($c) => ['id' => $c->id, 'religion_id' => $c->religion_id, 'label' => $c->display_label]
        )->all();

        $data['partnerLocationStateById'] = $data['partnerLocationInitialStates']->mapWithKeys(
            fn ($s) => [$s->id => ['id' => $s->id, 'name' => $s->name, 'parent_id' => (int) $s->parent_id]]
        )->all();
        $data['partnerLocationDistrictById'] = $data['partnerLocationInitialDistricts']->mapWithKeys(
            fn ($d) => [$d->id => ['id' => $d->id, 'name' => $d->name, 'parent_id' => (int) $d->parent_id]]
        )->all();
        $data['partnerLocationTalukaById'] = $data['partnerLocationInitialTalukas']->mapWithKeys(
            fn ($t) => [$t->id => ['id' => $t->id, 'name' => $t->name, 'parent_id' => (int) $t->parent_id]]
        )->all();
        $data['partnerLocationApiBase'] = url('/api/internal/location');

        $data['preferredMaritalStatusId'] = count($data['preferredMaritalStatusIds']) === 1
            ? $data['preferredMaritalStatusIds'][0]
            : null;

        $data['partnerPrefNavItems'] = PartnerPreferenceNavService::navItems($profile, $data);

        return $data;
    }

    /**
     * @return array{preferences: array<string, mixed>}
     */
    public function buildPreferencesSnapshotFromRequest(Request $request): array
    {
        $row = PartnerPreferenceSnapshotBuilder::validateAndBuildRow($request);

        return [
            'preferences' => $this->normalizeBulkOpenToAllOnSave($row),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeBulkOpenToAllOnSave(array $row): array
    {
        if ($this->isIncomeOpenToAll($row['preferred_income_min'] ?? null, $row['preferred_income_max'] ?? null)) {
            $row['preferred_income_min'] = null;
            $row['preferred_income_max'] = null;
        }

        return $row;
    }

    private function isIncomeOpenToAll(mixed $min, mixed $max): bool
    {
        if ($min === null && $max === null) {
            return true;
        }

        $minLakhs = $min === null || $min === '' ? null : (int) round((float) $min / 100000);
        $maxLakhs = $max === null || $max === '' ? null : (int) round((float) $max / 100000);

        return $minLakhs === self::INCOME_OPEN_MIN_LAKHS
            && $maxLakhs === self::INCOME_OPEN_MAX_LAKHS;
    }

    private function criteriaWithOpenIncome(?object $criteria): ?object
    {
        if ($criteria === null) {
            return null;
        }

        $criteria->preferred_income_min = null;
        $criteria->preferred_income_max = null;

        return $criteria;
    }

    /**
     * @param  array<string, mixed>  $prefs
     */
    private function criteriaFromPreferencesRow(array $prefs): ?object
    {
        if ($prefs === []) {
            return null;
        }

        return (object) [
            'preferred_age_min' => $prefs['preferred_age_min'] ?? null,
            'preferred_age_max' => $prefs['preferred_age_max'] ?? null,
            'preferred_height_min_cm' => $prefs['preferred_height_min_cm'] ?? null,
            'preferred_height_max_cm' => $prefs['preferred_height_max_cm'] ?? null,
            'preferred_income_min' => $prefs['preferred_income_min'] ?? null,
            'preferred_income_max' => $prefs['preferred_income_max'] ?? null,
            'willing_to_relocate' => $prefs['willing_to_relocate'] ?? null,
            'marriage_type_preference_id' => $prefs['marriage_type_preference_id'] ?? null,
            'preferred_marital_status_id' => $prefs['preferred_marital_status_id'] ?? null,
            'partner_profile_with_children' => $prefs['partner_profile_with_children'] ?? null,
            'preferred_profile_managed_by' => $prefs['preferred_profile_managed_by'] ?? null,
        ];
    }

    /**
     * @param  mixed  $value
     * @return array<int, int>
     */
    private function intIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', array_filter($value, fn ($id) => (int) $id > 0))));
    }
}
