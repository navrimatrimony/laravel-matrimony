<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\QuotaPolicySourceViolation;
use App\Http\Controllers\Controller;
use App\Models\Caste;
use App\Models\EducationDegree;
use App\Models\Location;
use App\Models\MasterIncomeCurrency;
use App\Models\MatrimonyProfile;
use App\Models\SubCaste;
use App\Models\User;
use App\Services\Api\MobileDiscoveryFilterService;
use App\Services\Api\MobileMoreMatchesSectionService;
use App\Services\Api\MobileProfileDisplayPresenter;
use App\Services\EducationService;
use App\Services\IncomeEngineService;
use App\Services\Matching\MatchingService;
use App\Services\MutationService;
use App\Services\OccupationService;
use App\Services\PartnerPreferenceSuggestionService;
use App\Services\PartnerPreferenceSnapshotBuilder;
use App\Services\Parsing\IntakeControlledFieldNormalizer;
use App\Services\ProfileFieldLockService;
use App\Services\ProfilePartnerCommunityFlagService;
use App\Services\ProfileRotationService;
use App\Services\ViewTrackingService;
use App\Support\MaritalDependencyRules;
use App\Support\MarriageAgePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MatrimonyProfileApiController extends Controller
{
    private const MOBILE_PARTNER_PREFERENCE_INPUT_KEYS = [
        'preferred_age_min',
        'preferred_age_max',
        'preferred_height_min_cm',
        'preferred_height_max_cm',
        'preferred_income_min',
        'preferred_income_max',
        'marriage_type_preference_id',
        'partner_profile_with_children',
        'preferred_profile_managed_by',
        'willing_to_relocate',
        'preferred_religion_ids',
        'preferred_caste_ids',
        'preferred_mother_tongue_ids',
        'preferred_education_degree_ids',
        'preferred_occupation_master_ids',
        'preferred_marital_status_ids',
        'preferred_diet_ids',
        'preferred_country_ids',
        'preferred_state_ids',
        'preferred_district_ids',
        'preferred_taluka_ids',
    ];

    private const MOBILE_INCOME_PERIODS = ['annual', 'monthly', 'weekly', 'daily'];

    private const MOBILE_INCOME_VALUE_TYPES = ['exact', 'approximate', 'range', 'undisclosed'];

    private const MOBILE_SIBLING_RELATION_TYPES = ['brother', 'sister', 'brother_wife', 'sister_husband'];

    private const MOBILE_SIBLING_MARITAL_STATUSES = ['unmarried', 'married'];

    private const MOBILE_MARRIAGE_DIVORCE_STATUSES = ['pending', 'finalized', 'mutual', 'contested'];

    /** @see MaritalDependencyRules::DETAIL_STATUS_KEYS — canonical source. */
    private const MOBILE_MARRIAGE_DETAIL_STATUS_KEYS = MaritalDependencyRules::DETAIL_STATUS_KEYS;

    private const MOBILE_CHILD_GENDERS = ['male', 'female', 'other', 'prefer_not_say'];

    private const MOBILE_ADDRESS_TYPE_KEYS = ['current', 'permanent', 'native', 'work', 'other'];

    /**
     * Approved 2026-07-21: sibling/relative contact numbers are editable (same rule shape
     * as mobileParentContactFields()). Privacy is enforced by authorization scoping
     * (owner/represented-candidate/admin), not by blocking the field.
     *
     * Schema reality check 2026-07-22: contact columns exist ONLY on profile_siblings
     * (contact_number, _2, _3) and profile_relatives (contact_number). profile_marriages,
     * profile_children, profile_addresses and profile_alliance_networks have NO contact
     * columns — accepting those keys would silently drop data at the MutationService
     * column-intersect, so they stay 'prohibited' (honest 422 instead of silent loss).
     * Consent-fallback numbers come from parent slots + profile_contacts + siblings +
     * relatives; the other tables were never meant to store phones.
     */
    private const MOBILE_CONTACT_NUMBER_RULES = ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{7,20}$/'];

    private const MOBILE_RELATIVE_RELATION_LABELS = [
        'paternal_grandfather' => 'Paternal Grandfather',
        'paternal_grandmother' => 'Paternal Grandmother',
        'paternal_uncle' => 'Paternal Uncle',
        'wife_paternal_uncle' => 'Wife of Paternal Uncle',
        'paternal_aunt' => 'Paternal Aunt',
        'husband_paternal_aunt' => 'Husband of Paternal Aunt',
        'Cousin' => 'Cousin',
        'maternal_address_ajol' => 'Maternal address (Ajol)',
        'maternal_grandfather' => 'Maternal Grandfather',
        'maternal_grandmother' => 'Maternal Grandmother',
        'maternal_uncle' => 'Maternal Uncle',
        'wife_maternal_uncle' => "Maternal Uncle's wife",
        'maternal_aunt' => 'Maternal Aunt',
        'husband_maternal_aunt' => 'Husband of Maternal Aunt',
        'maternal_cousin' => 'Cousin',
    ];

    /**
     * Phase-5B: Build snapshot from API request (same structure as manual). Only keys present in request.
     */
    private function buildMobileProfileSnapshotFromApi(Request $request, ?MatrimonyProfile $profile = null): array
    {
        $this->normalizeMobileEducationCareerInputs($request);

        $core = [];
        $coreFields = [
            'full_name',
            'gender_id',
            'date_of_birth',
            'birth_time',
            'birth_city_id',
            'birth_place_text',
            'caste',
            'highest_education',
            'location_id',
            'address_line',
            'religion_id',
            'caste_id',
            'sub_caste_id',
            'mother_tongue_id',
            'marital_status_id',
            'has_children',
            'height_cm',
            'weight_kg',
            'complexion_id',
            'blood_group_id',
            'physical_build_id',
            'spectacles_lens',
            'physical_condition',
            'diet_id',
            'smoking_status_id',
            'drinking_status_id',
            'occupation_master_id',
            'occupation_custom_id',
            'company_name',
            'work_location_text',
            'annual_income',
            'income_period',
            'income_value_type',
            'income_amount',
            'income_min_amount',
            'income_max_amount',
            'income_currency_id',
            'income_private',
            'father_name',
            'father_occupation',
            'father_occupation_master_id',
            'father_occupation_custom_id',
            'father_extra_info',
            'mother_name',
            'mother_occupation',
            'mother_occupation_master_id',
            'mother_occupation_custom_id',
            'mother_extra_info',
            'family_type_id',
            'family_status',
            'family_values',
            'family_income',
            'family_income_period',
            'family_income_value_type',
            'family_income_amount',
            'family_income_min_amount',
            'family_income_max_amount',
            'family_income_currency_id',
            'family_income_private',
            'has_siblings',
            'other_relatives_text',
            'property_details',
        ];
        $coreFields = array_values(array_unique(array_merge($coreFields, $this->mobileParentContactFields())));
        foreach ($coreFields as $key) {
            if (! $request->has($key)) {
                continue;
            }
            $val = $request->input($key);
            if ($val instanceof \Carbon\Carbon) {
                $val = $val->format('Y-m-d');
            }
            $core[$key] = $val === '' ? null : $val;
        }

        if ($core !== []) {
            $normalizedCore = app(IntakeControlledFieldNormalizer::class)->normalizeCore($core);
            foreach (['religion_id', 'caste_id', 'sub_caste_id'] as $key) {
                if (array_key_exists($key, $normalizedCore)) {
                    $core[$key] = $normalizedCore[$key];
                }
            }
        }

        foreach (['income', 'family_income'] as $prefix) {
            if ($this->mobileIncomeInputPresent($request, $prefix)) {
                $core = array_merge($core, $this->mobileIncomeEngineCoreFromApi($request, $prefix));
            }
        }

        $maritalStatusKey = $this->mobileMaritalStatusKeyForRequest($request, $profile);
        $marriageDetailsAllowed = $this->mobileMarriageDetailsAllowed($maritalStatusKey);
        if ($maritalStatusKey === 'never_married') {
            $core['has_children'] = false;
        }

        $snapshot = ['core' => $core];

        if ($request->has('birth_city_id')) {
            $snapshot['birth_place'] = [
                'city_id' => $request->input('birth_city_id') ? (int) $request->input('birth_city_id') : null,
                'taluka_id' => null,
                'district_id' => null,
                'state_id' => null,
            ];
        }

        $horoscope = $this->mobileHoroscopeSnapshotFromApi($request);
        if ($horoscope !== []) {
            $snapshot['horoscope'] = [$horoscope];
        }

        if ($this->mobilePartnerPreferenceInputPresent($request)) {
            $snapshot['preferences'] = [$this->mobilePartnerPreferenceSnapshotFromApi($request)];
        }

        if ($request->has('siblings')) {
            $snapshot['siblings'] = $this->mobileSiblingsSnapshotFromApi($request);
        }

        if ($request->has('relatives')) {
            $snapshot['relatives'] = $this->mobileRelativesSnapshotFromApi($request);
        }

        if ($request->has('alliance_networks')) {
            $snapshot['alliance_networks'] = $this->mobileAllianceNetworksSnapshotFromApi($request);
        }

        if ($request->has('self_addresses') || $request->has('parents_addresses')) {
            $snapshot['addresses'] = $this->mobileAddressesSnapshotFromApi($request, $profile);
            if ($request->has('self_addresses')) {
                $snapshot = $this->mergeMobileCurrentSelfAddressIntoCore($snapshot);
            }
        }

        if ($maritalStatusKey === 'never_married') {
            if ($request->hasAny(['marital_status_id', 'marriages'])) {
                $snapshot['marriages'] = [];
            }
            if ($request->hasAny(['marital_status_id', 'has_children', 'children'])) {
                $snapshot['children'] = [];
            }
        } elseif ($marriageDetailsAllowed) {
            if ($request->hasAny(['marital_status_id', 'marriages'])) {
                $snapshot['marriages'] = $this->mobileMarriagesSnapshotFromApi($request, $profile, $maritalStatusKey);
            }

            if ($this->mobileBooleanInput($request, 'has_children', $profile?->has_children) === true) {
                if ($request->has('children')) {
                    $snapshot['children'] = $this->mobileChildrenSnapshotFromApi($request);
                }
            } elseif ($request->hasAny(['marital_status_id', 'has_children', 'children'])) {
                $snapshot['children'] = [];
            }
        } else {
            if ($request->has('marriages')) {
                $snapshot['marriages'] = [];
            }
            if ($request->has('children')) {
                $snapshot['children'] = [];
            }
        }


        if ($this->requestInputKeyExists($request, 'narrative_about_me') || $this->requestInputKeyExists($request, 'narrative_expectations')) {
            $existing = $profile instanceof MatrimonyProfile && Schema::hasTable('profile_extended_attributes')
                ? DB::table('profile_extended_attributes')->where('profile_id', $profile->id)->first()
                : null;
            $snapshot['extended_narrative'] = [[
                'narrative_about_me' => $this->requestInputKeyExists($request, 'narrative_about_me')
                    ? trim((string) $request->input('narrative_about_me'))
                    : $existing?->narrative_about_me,
                'narrative_expectations' => $this->requestInputKeyExists($request, 'narrative_expectations')
                    ? trim((string) $request->input('narrative_expectations'))
                    : $existing?->narrative_expectations,
                'additional_notes' => $existing?->additional_notes,
            ]];
        }

        return $snapshot;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mobileSiblingsSnapshotFromApi(Request $request): array
    {
        $rows = $request->input('siblings', []);
        if (! is_array($rows)) {
            return [];
        }

        $siblings = [];
        foreach (array_values($rows) as $idx => $row) {
            if (! is_array($row)) {
                continue;
            }

            $relationType = $row['relation_type'] ?? null;
            $name = trim((string) ($row['name'] ?? ''));
            $occupation = trim((string) ($row['occupation'] ?? ''));
            $addressLine = trim((string) ($row['address_line'] ?? ''));
            $notes = trim((string) ($row['notes'] ?? ''));
            $cityId = $row['city_id'] ?? null;
            $contactNumbers = [];
            foreach (['contact_number', 'contact_number_2', 'contact_number_3'] as $contactField) {
                $value = trim((string) ($row[$contactField] ?? ''));
                $contactNumbers[$contactField] = $value !== '' ? $value : null;
            }
            $hasContactNumber = count(array_filter($contactNumbers)) > 0;
            $hasMeaningfulData = in_array($relationType, self::MOBILE_SIBLING_RELATION_TYPES, true)
                || $name !== ''
                || $occupation !== ''
                || $addressLine !== ''
                || $notes !== ''
                || $hasContactNumber
                || ($cityId !== null && $cityId !== '');

            if (! $hasMeaningfulData) {
                continue;
            }

            $sibling = [
                'relation_type' => $relationType,
                'name' => $name !== '' ? $name : null,
                'marital_status' => $row['marital_status'] ?? null,
                'occupation' => $occupation !== '' ? $occupation : null,
                'occupation_master_id' => $row['occupation_master_id'] ?? null,
                'occupation_custom_id' => $row['occupation_custom_id'] ?? null,
                'city_id' => ($cityId !== null && $cityId !== '') ? (int) $cityId : null,
                'address_line' => $addressLine !== '' ? $addressLine : null,
                'notes' => $notes !== '' ? $notes : null,
                'sort_order' => isset($row['sort_order']) && $row['sort_order'] !== '' ? (int) $row['sort_order'] : $idx,
            ] + $contactNumbers;

            if (! empty($row['id'])) {
                $sibling['id'] = (int) $row['id'];
            }

            $siblings[] = $sibling;
        }

        return $siblings;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mobileRelativesSnapshotFromApi(Request $request): array
    {
        $rows = $request->input('relatives', []);
        if (! is_array($rows)) {
            return [];
        }

        $relatives = [];
        foreach (array_values($rows) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $relationType = isset($row['relation_type']) ? trim((string) $row['relation_type']) : null;
            $relationType = $relationType !== '' ? $relationType : null;
            $relativeDetails = $this->relativeDetailsFromMobileRelativeRow($row);
            // profile_relatives stores a single contact_number (no _2/_3 columns).
            $contactValue = trim((string) ($row['contact_number'] ?? ''));
            $contactNumbers = ['contact_number' => $contactValue !== '' ? $contactValue : null];

            $hasMeaningfulData = ($relationType !== null && array_key_exists($relationType, self::MOBILE_RELATIVE_RELATION_LABELS))
                || $relativeDetails !== null
                || count(array_filter($contactNumbers)) > 0;

            if (! $hasMeaningfulData) {
                continue;
            }

            $relative = [
                'relation_type' => $relationType,
                'relative_details' => $relativeDetails,
            ] + $contactNumbers;

            if (! empty($row['id'])) {
                $relative['id'] = (int) $row['id'];
            }

            $relatives[] = $relative;
        }

        return $relatives;
    }

    private function relativeDetailsFromMobileRelativeRow(array $row): ?string
    {
        $direct = trim((string) ($row['relative_details'] ?? ''));
        if ($direct !== '') {
            return $direct;
        }

        $parts = [];
        foreach (['name', 'occupation', 'address_line', 'location_input', 'notes', 'additional_info'] as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        $parts = array_values(array_unique($parts));

        return $parts === [] ? null : implode("\n", $parts);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mobileAllianceNetworksSnapshotFromApi(Request $request): array
    {
        $rows = $request->input('alliance_networks', []);
        if (! is_array($rows)) {
            return [];
        }

        $allianceNetworks = [];
        foreach (array_values($rows) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $surname = trim((string) ($row['surname'] ?? ''));
            $notes = trim((string) ($row['notes'] ?? ''));
            $cityId = $row['city_id'] ?? null;
            $stateId = $row['state_id'] ?? null;
            $districtId = $row['district_id'] ?? null;
            $talukaId = $row['taluka_id'] ?? null;

            $hasMeaningfulData = $surname !== ''
                || $notes !== ''
                || ($cityId !== null && $cityId !== '')
                || ($stateId !== null && $stateId !== '')
                || ($districtId !== null && $districtId !== '')
                || ($talukaId !== null && $talukaId !== '');

            if (! $hasMeaningfulData) {
                continue;
            }

            $allianceNetwork = [
                'surname' => $surname,
                'city_id' => ($cityId !== null && $cityId !== '') ? (int) $cityId : null,
                'taluka_id' => ($talukaId !== null && $talukaId !== '') ? (int) $talukaId : null,
                'district_id' => ($districtId !== null && $districtId !== '') ? (int) $districtId : null,
                'state_id' => ($stateId !== null && $stateId !== '') ? (int) $stateId : null,
                'notes' => $notes !== '' ? $notes : null,
            ];

            if (! empty($row['id'])) {
                $allianceNetwork['id'] = (int) $row['id'];
            }

            $allianceNetworks[] = $allianceNetwork;
        }

        return $allianceNetworks;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mobileAddressesSnapshotFromApi(Request $request, ?MatrimonyProfile $profile): array
    {
        $hasSelf = $request->has('self_addresses');
        $hasParents = $request->has('parents_addresses');

        $selfRows = $hasSelf
            ? $this->mobileAddressRowsSnapshotFromApi($request, 'self_addresses', 'self', 'current')
            : $this->mobileExistingAddressSnapshotRowsForScope($profile, 'self');
        $parentsRows = $hasParents
            ? $this->mobileAddressRowsSnapshotFromApi($request, 'parents_addresses', 'parents', 'permanent')
            : $this->mobileExistingAddressSnapshotRowsForScope($profile, 'parents');

        return array_values(array_merge($selfRows, $parentsRows));
    }

    /**
     * Mobile edit sends the structured current address row, while profile-level
     * residence is still consumed as core.location_id by search, biodata, and
     * activation gates. Keep both surfaces in sync from the same addresses leaf.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function mergeMobileCurrentSelfAddressIntoCore(array $snapshot): array
    {
        $rows = $snapshot['addresses'] ?? [];
        if (! is_array($rows)) {
            return $snapshot;
        }

        $current = null;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $scope = trim((string) ($row['address_scope'] ?? 'self'));
            $type = trim((string) ($row['address_type'] ?? $row['address_type_key'] ?? ''));
            if ($type === '' && $scope === 'self') {
                $type = 'current';
            }
            if ($scope === 'self' && $type === 'current') {
                $current = $row;
                break;
            }
        }

        if (! is_array($current)) {
            return $snapshot;
        }

        if (! isset($snapshot['core']) || ! is_array($snapshot['core'])) {
            $snapshot['core'] = [];
        }

        $locationId = $this->positiveIntFromMixed($current['location_id'] ?? $current['city_id'] ?? null);
        if ($locationId !== null && empty($snapshot['core']['location_id'])) {
            $snapshot['core']['location_id'] = $locationId;
        }

        $addressLine = isset($current['address_line']) ? trim((string) $current['address_line']) : '';
        if ($addressLine !== '' && ! array_key_exists('address_line', $snapshot['core'])) {
            $snapshot['core']['address_line'] = mb_substr($addressLine, 0, 255);
        }

        return $snapshot;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mobileAddressRowsSnapshotFromApi(Request $request, string $inputKey, string $scope, string $defaultTypeKey): array
    {
        $rows = $request->input($inputKey, []);
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach (array_values($rows) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = ! empty($row['id']) ? (int) $row['id'] : null;
            $addressLine = trim((string) ($row['address_line'] ?? ''));
            $locationId = $row['location_id'] ?? $row['city_id'] ?? null;
            $locationId = ($locationId !== null && $locationId !== '') ? (int) $locationId : null;
            $typeKey = trim((string) ($row['address_type_key'] ?? $row['address_type'] ?? $row['type'] ?? ''));
            $typeId = $row['address_type_id'] ?? null;
            $typeId = ($typeId !== null && $typeId !== '') ? (int) $typeId : null;

            $hasMeaningfulData = $id !== null
                || $addressLine !== ''
                || $locationId !== null;
            if (! $hasMeaningfulData) {
                continue;
            }

            if ($typeKey === '' && $typeId === null) {
                $typeKey = $defaultTypeKey;
            }

            $mapped = [
                'address_scope' => $scope,
                'address_line' => $addressLine !== '' ? mb_substr($addressLine, 0, 255) : null,
                'location_id' => $locationId,
            ];
            if ($id !== null) {
                $mapped['id'] = $id;
            }
            if ($typeId !== null) {
                $mapped['address_type_id'] = $typeId;
            } else {
                $mapped['address_type'] = $typeKey;
            }

            $out[] = $mapped;
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mobileExistingAddressSnapshotRowsForScope(?MatrimonyProfile $profile, string $scope): array
    {
        if (! $profile instanceof MatrimonyProfile
            || ! $profile->exists
            || ! Schema::hasTable('profile_addresses')
            || ! Schema::hasTable('master_address_types')
            || ! Schema::hasColumn('profile_addresses', 'address_scope')) {
            return [];
        }

        $locationColumn = Schema::hasColumn('profile_addresses', 'location_id')
            ? 'location_id'
            : (Schema::hasColumn('profile_addresses', 'city_id') ? 'city_id' : null);
        $select = ['pa.id', 'pa.address_line', 'mat.key as address_type_key'];
        if ($locationColumn !== null) {
            $select[] = DB::raw('pa.'.$locationColumn.' as location_id');
        }

        $rows = DB::table('profile_addresses as pa')
            ->join('master_address_types as mat', 'mat.id', '=', 'pa.address_type_id')
            ->where('pa.profile_id', $profile->id)
            ->where('pa.address_scope', $scope)
            ->orderBy('pa.id')
            ->select($select)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $locationId = isset($row->location_id) && $row->location_id !== null ? (int) $row->location_id : null;
            $out[] = [
                'id' => (int) $row->id,
                'address_scope' => $scope,
                'address_type' => (string) ($row->address_type_key ?? ($scope === 'parents' ? 'permanent' : 'current')),
                'address_line' => isset($row->address_line) && trim((string) $row->address_line) !== '' ? trim((string) $row->address_line) : null,
                'location_id' => $locationId,
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mobileMarriagesSnapshotFromApi(Request $request, ?MatrimonyProfile $profile, ?string $statusKey): array
    {
        $rows = $request->input('marriages', []);
        if (! is_array($rows)) {
            $rows = [];
        }

        if (! $this->mobileMarriageDetailsAllowed($statusKey)) {
            return [];
        }

        $row = $this->mobileEffectiveMarriageInputRow($rows);
        $topLevelMaritalStatusId = $request->input('marital_status_id');
        $maritalStatusId = $row['marital_status_id'] ?? $topLevelMaritalStatusId ?? $profile?->marital_status_id;
        $latestMarriageId = $this->mobileLatestMarriageRowId($profile);
        $marriageId = ! empty($row['id']) ? (int) $row['id'] : $latestMarriageId;

        return [$this->mobileSanitizedMarriageRowForStatus($row, $statusKey, $maritalStatusId, $marriageId)];
    }

    private function mobileMarriageDetailsAllowed(?string $statusKey): bool
    {
        return in_array($statusKey, self::MOBILE_MARRIAGE_DETAIL_STATUS_KEYS, true);
    }

    private function mobileMaritalStatusKeyForRequest(Request $request, ?MatrimonyProfile $profile = null): ?string
    {
        $statusId = $request->input('marital_status_id');
        if ($statusId !== null && $statusId !== '') {
            return $this->mobileMaritalStatusKeyById((int) $statusId);
        }

        $profile?->loadMissing('maritalStatus');

        return $profile?->maritalStatus?->key;
    }

    private function mobileMaritalStatusKeyById(int $statusId): ?string
    {
        if ($statusId <= 0 || ! Schema::hasTable('master_marital_statuses')) {
            return null;
        }

        $key = DB::table('master_marital_statuses')->where('id', $statusId)->value('key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    private function mobileBooleanInput(Request $request, string $key, mixed $fallback = null): ?bool
    {
        $value = $request->has($key) ? $request->input($key) : $fallback;
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'y'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'n'], true)) {
            return false;
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<string, mixed>
     */
    private function mobileEffectiveMarriageInputRow(array $rows): array
    {
        $latestWithId = null;
        $latestId = -1;
        $lastMeaningful = null;

        foreach (array_values($rows) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $hasAnyValue = false;
            foreach (['id', 'marital_status_id', 'marriage_year', 'separation_year', 'divorce_year', 'spouse_death_year', 'divorce_status'] as $field) {
                if (($row[$field] ?? null) !== null && trim((string) $row[$field]) !== '') {
                    $hasAnyValue = true;
                    break;
                }
            }
            if (! $hasAnyValue) {
                continue;
            }

            $lastMeaningful = $row;
            if (! empty($row['id']) && (int) $row['id'] > $latestId) {
                $latestId = (int) $row['id'];
                $latestWithId = $row;
            }
        }

        return is_array($latestWithId) ? $latestWithId : (is_array($lastMeaningful) ? $lastMeaningful : []);
    }

    private function mobileLatestMarriageRowId(?MatrimonyProfile $profile): ?int
    {
        if (! $profile instanceof MatrimonyProfile || ! $profile->exists) {
            return null;
        }

        $id = $profile->marriages()->orderByDesc('id')->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mobileSanitizedMarriageRowForStatus(array $row, ?string $statusKey, mixed $maritalStatusId, ?int $marriageId): array
    {
        return [
            'id' => $marriageId,
            'marital_status_id' => ! empty($maritalStatusId) ? (int) $maritalStatusId : null,
            'marriage_year' => $this->nullableIntFromRow($row, 'marriage_year'),
            'separation_year' => $statusKey === 'separated' ? $this->nullableIntFromRow($row, 'separation_year') : null,
            'divorce_year' => in_array($statusKey, ['divorced', 'annulled'], true) ? $this->nullableIntFromRow($row, 'divorce_year') : null,
            'spouse_death_year' => $statusKey === 'widowed' ? $this->nullableIntFromRow($row, 'spouse_death_year') : null,
            'divorce_status' => in_array($statusKey, ['divorced', 'annulled', 'separated'], true) ? $this->nullableStringFromRow($row, 'divorce_status') : null,
            'remarriage_reason' => null,
            'notes' => null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mobileChildrenSnapshotFromApi(Request $request): array
    {
        $rows = $request->input('children', []);
        if (! is_array($rows)) {
            return [];
        }

        $children = [];
        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $hasAnyChildValue = false;
            foreach (['child_name', 'gender', 'age', 'child_living_with_id'] as $field) {
                if (($row[$field] ?? null) !== null && trim((string) $row[$field]) !== '') {
                    $hasAnyChildValue = true;
                    break;
                }
            }
            if (! $hasAnyChildValue) {
                continue;
            }

            $children[] = [
                'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                'child_name' => $this->nullableStringFromRow($row, 'child_name'),
                'gender' => $this->nullableStringFromRow($row, 'gender'),
                'age' => $this->nullableIntFromRow($row, 'age'),
                'child_living_with_id' => $this->nullableIntFromRow($row, 'child_living_with_id'),
                'sort_order' => isset($row['sort_order']) && $row['sort_order'] !== '' ? (int) $row['sort_order'] : $index,
            ];
        }

        return $children;
    }

    private function nullableIntFromRow(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableStringFromRow(array $row, string $key): ?string
    {
        $value = trim((string) ($row[$key] ?? ''));

        return $value !== '' ? $value : null;
    }

    private function mobilePartnerPreferenceInputPresent(Request $request): bool
    {
        foreach (self::MOBILE_PARTNER_PREFERENCE_INPUT_KEYS as $key) {
            if ($this->requestInputKeyExists($request, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function mobilePartnerPreferenceSnapshotFromApi(Request $request): array
    {
        $subset = [];
        foreach (self::MOBILE_PARTNER_PREFERENCE_INPUT_KEYS as $key) {
            if ($this->requestInputKeyExists($request, $key)) {
                $subset[$key] = $request->input($key);
            }
        }

        $preferenceRequest = Request::create('/api/v1/matrimony-profile/mobile-partner-preferences', 'POST', $subset);
        $preferenceRequest->setUserResolver(fn () => $request->user());

        $row = PartnerPreferenceSnapshotBuilder::validateAndBuildRow($preferenceRequest);

        $snapshot = [];
        foreach ([
            'preferred_age_min',
            'preferred_age_max',
            'preferred_height_min_cm',
            'preferred_height_max_cm',
            'preferred_income_min',
            'preferred_income_max',
            'willing_to_relocate',
            'marriage_type_preference_id',
            'partner_profile_with_children',
            'preferred_profile_managed_by',
        ] as $key) {
            if ($this->requestInputKeyExists($request, $key)) {
                $snapshot[$key] = $row[$key] ?? null;
            }
        }
        if ($this->requestInputKeyExists($request, 'preferred_marital_status_ids')) {
            $snapshot['preferred_marital_status_id'] = $row['preferred_marital_status_id'] ?? null;
            $snapshot['preferred_marital_status_ids'] = $row['preferred_marital_status_ids'] ?? [];
        }
        if ($this->requestInputKeyExists($request, 'preferred_diet_ids')) {
            $snapshot['preferred_diet_ids'] = $row['preferred_diet_ids'] ?? [];
        }
        foreach ([
            'preferred_religion_ids',
            'preferred_caste_ids',
            'preferred_mother_tongue_ids',
            'preferred_education_degree_ids',
            'preferred_occupation_master_ids',
        ] as $key) {
            if ($this->requestInputKeyExists($request, $key)) {
                $snapshot[$key] = $row[$key] ?? [];
            }
        }
        foreach ([
            'preferred_country_ids',
            'preferred_state_ids',
            'preferred_district_ids',
            'preferred_taluka_ids',
        ] as $key) {
            if ($this->requestInputKeyExists($request, $key)) {
                $snapshot[$key] = $row[$key] ?? [];
            }
        }

        return $snapshot;
    }

    private function requestInputKeyExists(Request $request, string $key): bool
    {
        return array_key_exists($key, $request->all());
    }

    private function mobileIncomeInputPresent(Request $request, string $prefix): bool
    {
        $fields = [
            $prefix.'_period',
            $prefix.'_value_type',
            $prefix.'_amount',
            $prefix.'_min_amount',
            $prefix.'_max_amount',
            $prefix.'_currency_id',
            $prefix.'_private',
        ];

        if ($prefix === 'income') {
            $fields[] = 'annual_income';
        } else {
            $fields[] = 'family_income';
        }

        foreach ($fields as $field) {
            if ($this->requestInputKeyExists($request, $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function mobileIncomeEngineCoreFromApi(Request $request, string $prefix): array
    {
        $period = $request->input($prefix.'_period') ?: 'annual';
        $valueType = $request->input($prefix.'_value_type');
        $amount = $request->filled($prefix.'_amount') ? (float) $request->input($prefix.'_amount') : null;
        $minAmount = $request->filled($prefix.'_min_amount') ? (float) $request->input($prefix.'_min_amount') : null;
        $maxAmount = $request->filled($prefix.'_max_amount') ? (float) $request->input($prefix.'_max_amount') : null;
        $defaultInr = MasterIncomeCurrency::query()->where('code', 'INR')->value('id');
        $currencyId = $request->filled($prefix.'_currency_id')
            ? (int) $request->input($prefix.'_currency_id')
            : ($defaultInr ? (int) $defaultInr : null);
        $normalized = app(IncomeEngineService::class)->normalizeToAnnual(
            $valueType,
            $period,
            $amount,
            $minAmount,
            $maxAmount
        );

        $core = [
            $prefix.'_period' => $period,
            $prefix.'_value_type' => $valueType,
            $prefix.'_amount' => $amount,
            $prefix.'_min_amount' => $minAmount,
            $prefix.'_max_amount' => $maxAmount,
            $prefix.'_currency_id' => $currencyId,
            $prefix.'_private' => $request->boolean($prefix.'_private'),
            $prefix.'_normalized_annual_amount' => $normalized,
        ];

        if ($prefix === 'income') {
            $core['annual_income'] = $request->filled('annual_income')
                ? (float) $request->input('annual_income')
                : $normalized;
        } else {
            $core['family_income'] = $request->filled('family_income')
                ? (float) $request->input('family_income')
                : $normalized;
        }

        return $core;
    }

    /**
     * @return array<string, mixed>
     */
    private function mobileHoroscopeSnapshotFromApi(Request $request): array
    {
        $fields = [
            'rashi_id',
            'nakshatra_id',
            'charan',
            'gan_id',
            'nadi_id',
            'yoni_id',
            'varna_id',
            'vashya_id',
            'rashi_lord_id',
            'mangal_dosh_type_id',
            'devak',
            'kul',
            'gotra',
            'navras_name',
            'birth_weekday',
        ];

        $hasAny = collect($fields)->contains(fn (string $field): bool => $request->has($field));
        if (! $hasAny) {
            return [];
        }

        $intFields = [
            'rashi_id',
            'nakshatra_id',
            'charan',
            'gan_id',
            'nadi_id',
            'yoni_id',
            'varna_id',
            'vashya_id',
            'rashi_lord_id',
            'mangal_dosh_type_id',
        ];

        $row = [];
        foreach ($fields as $field) {
            if (! $request->has($field)) {
                continue;
            }
            $value = $request->input($field);
            if ($value === '') {
                $row[$field] = null;
                continue;
            }
            $row[$field] = in_array($field, $intFields, true)
                ? ($value !== null ? (int) $value : null)
                : trim((string) $value);
        }

        return $row;
    }

    private function validateMobileProfileRequest(Request $request, bool $creating, ?MatrimonyProfile $profile = null): void
    {
        $this->normalizeMobileEducationCareerInputs($request);

        $rules = [
            'full_name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'gender_id' => [
                $creating ? 'required' : 'sometimes',
                'integer',
                Rule::exists('master_genders', 'id')->where('is_active', true),
            ],
            'date_of_birth' => [$creating ? 'required' : 'sometimes', 'required', 'date'],
            'caste' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'highest_education' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'location_id' => [$creating ? 'required' : 'sometimes', 'required', 'exists:'.Location::geoTable().',id'],
            'religion_id' => ['nullable', 'integer', 'exists:master_religions,id'],
            'caste_id' => ['nullable', 'integer', 'exists:master_castes,id'],
            'sub_caste_id' => ['nullable', 'integer', 'exists:master_sub_castes,id'],
            'birth_time' => ['nullable', 'string', 'max:20'],
            'birth_city_id' => ['nullable', 'integer', 'exists:'.Location::geoTable().',id'],
            'birth_place_text' => ['nullable', 'string', 'max:255'],
            'mother_tongue_id' => ['nullable', 'integer', Rule::exists('master_mother_tongues', 'id')->where('is_active', true)],
            'marital_status_id' => ['nullable', 'integer', Rule::exists('master_marital_statuses', 'id')->where('is_active', true)],
            'has_children' => ['nullable', 'boolean'],
            'height_cm' => ['nullable', 'integer', 'min:50', 'max:250'],
            'weight_kg' => ['nullable', 'integer', 'min:20', 'max:250'],
            'complexion_id' => ['nullable', 'integer', Rule::exists('master_complexions', 'id')->where('is_active', true)],
            'blood_group_id' => ['nullable', 'integer', Rule::exists('master_blood_groups', 'id')->where('is_active', true)],
            'physical_build_id' => ['nullable', 'integer', Rule::exists('master_physical_builds', 'id')->where('is_active', true)],
            'spectacles_lens' => ['nullable', 'string', 'max:50', Rule::in(['no', 'spectacles', 'contact_lens', 'both'])],
            'physical_condition' => ['nullable', 'string', 'max:50', Rule::in(['none', 'physically_challenged', 'hearing_condition', 'vision_condition', 'other', 'prefer_not_to_say'])],
            'diet_id' => ['nullable', 'integer', Rule::exists('master_diets', 'id')->where('is_active', true)],
            'smoking_status_id' => ['nullable', 'integer', Rule::exists('master_smoking_statuses', 'id')->where('is_active', true)],
            'drinking_status_id' => ['nullable', 'integer', Rule::exists('master_drinking_statuses', 'id')->where('is_active', true)],
            'education_slots' => ['nullable', 'string', 'max:8192'],
            'occupation_master_id' => ['nullable', 'integer', Rule::exists('master_occupations', 'id')],
            'occupation_custom_id' => [
                'nullable',
                'integer',
                Rule::exists('master_occupation_custom', 'id')->where(fn ($query) => $query->where('user_id', $request->user()?->id ?? 0)),
            ],
            'company_name' => ['nullable', 'string', 'max:255'],
            'work_location_text' => ['nullable', 'string', 'max:255'],
            'annual_income' => ['nullable', 'numeric', 'min:0'],
            'income_period' => ['nullable', Rule::in(self::MOBILE_INCOME_PERIODS)],
            'income_value_type' => ['nullable', Rule::in(self::MOBILE_INCOME_VALUE_TYPES)],
            'income_amount' => ['nullable', 'numeric', 'min:0'],
            'income_min_amount' => ['nullable', 'numeric', 'min:0'],
            'income_max_amount' => ['nullable', 'numeric', 'min:0', 'gte:income_min_amount'],
            'income_currency_id' => ['nullable', 'integer', Rule::exists('master_income_currencies', 'id')->where('is_active', true)],
            'income_private' => ['nullable', 'boolean'],
            'father_name' => ['nullable', 'string', 'max:255'],
            'father_occupation' => ['nullable', 'string', 'max:255'],
            'father_occupation_master_id' => ['nullable', 'integer', Rule::exists('master_occupations', 'id')],
            'father_occupation_custom_id' => [
                'nullable',
                'integer',
                Rule::exists('master_occupation_custom', 'id')->where(fn ($query) => $query->where('user_id', $request->user()?->id ?? 0)),
            ],
            'father_extra_info' => ['nullable', 'string', 'max:1000'],
            'mother_name' => ['nullable', 'string', 'max:255'],
            'mother_occupation' => ['nullable', 'string', 'max:255'],
            'mother_occupation_master_id' => ['nullable', 'integer', Rule::exists('master_occupations', 'id')],
            'mother_occupation_custom_id' => [
                'nullable',
                'integer',
                Rule::exists('master_occupation_custom', 'id')->where(fn ($query) => $query->where('user_id', $request->user()?->id ?? 0)),
            ],
            'mother_extra_info' => ['nullable', 'string', 'max:1000'],
            'family_type_id' => ['nullable', 'integer', Rule::exists('master_family_types', 'id')->where('is_active', true)],
            'family_status' => ['nullable', 'string', Rule::in($this->translatedOptionKeys('components.family.status_options'))],
            'family_values' => ['nullable', 'string', Rule::in($this->translatedOptionKeys('components.family.values_options'))],
            'family_income' => ['nullable', 'numeric', 'min:0'],
            'family_income_period' => ['nullable', Rule::in(self::MOBILE_INCOME_PERIODS)],
            'family_income_value_type' => ['nullable', Rule::in(self::MOBILE_INCOME_VALUE_TYPES)],
            'family_income_amount' => ['nullable', 'numeric', 'min:0'],
            'family_income_min_amount' => ['nullable', 'numeric', 'min:0'],
            'family_income_max_amount' => ['nullable', 'numeric', 'min:0', 'gte:family_income_min_amount'],
            'family_income_currency_id' => ['nullable', 'integer', Rule::exists('master_income_currencies', 'id')->where('is_active', true)],
            'family_income_private' => ['nullable', 'boolean'],
            'has_siblings' => ['nullable', 'boolean'],
            'siblings' => ['nullable', 'array', 'max:20'],
            'siblings.*' => ['array'],
            'siblings.*.id' => ['nullable', 'integer'],
            'siblings.*.relation_type' => ['nullable', Rule::in(self::MOBILE_SIBLING_RELATION_TYPES)],
            'siblings.*.name' => ['nullable', 'string', 'max:255'],
            'siblings.*.marital_status' => ['nullable', Rule::in(self::MOBILE_SIBLING_MARITAL_STATUSES)],
            'siblings.*.occupation' => ['nullable', 'string', 'max:255'],
            'siblings.*.occupation_master_id' => ['nullable', 'integer', Rule::exists('master_occupations', 'id')],
            'siblings.*.occupation_custom_id' => [
                'nullable',
                'integer',
                Rule::exists('master_occupation_custom', 'id')->where(fn ($query) => $query->where('user_id', $request->user()?->id ?? 0)),
            ],
            'siblings.*.city_id' => ['nullable', 'integer', 'exists:'.Location::geoTable().',id'],
            'siblings.*.address_line' => ['nullable', 'string', 'max:255'],
            'siblings.*.notes' => ['nullable', 'string', 'max:1000'],
            'siblings.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'siblings.*.contact_number' => self::MOBILE_CONTACT_NUMBER_RULES,
            'siblings.*.contact_number_2' => self::MOBILE_CONTACT_NUMBER_RULES,
            'siblings.*.contact_number_3' => self::MOBILE_CONTACT_NUMBER_RULES,
            'marriages' => ['nullable', 'array', 'max:10'],
            'marriages.*' => ['array'],
            'marriages.*.id' => ['nullable', 'integer'],
            'marriages.*.marital_status_id' => ['nullable', 'integer', Rule::exists('master_marital_statuses', 'id')->where('is_active', true)],
            'marriages.*.marriage_year' => ['nullable', 'integer', 'min:1901', 'max:'.(int) date('Y')],
            'marriages.*.separation_year' => ['nullable', 'integer', 'min:1901', 'max:'.(int) date('Y')],
            'marriages.*.divorce_year' => ['nullable', 'integer', 'min:1901', 'max:'.(int) date('Y')],
            'marriages.*.spouse_death_year' => ['nullable', 'integer', 'min:1901', 'max:'.(int) date('Y')],
            'marriages.*.divorce_status' => ['nullable', Rule::in(self::MOBILE_MARRIAGE_DIVORCE_STATUSES)],
            'marriages.*.remarriage_reason' => ['nullable', 'string', 'max:1000'],
            'marriages.*.notes' => ['nullable', 'string', 'max:1000'],
            // No contact columns on profile_marriages — see MOBILE_CONTACT_NUMBER_RULES note.
            'marriages.*.contact_number' => ['prohibited'],
            'marriages.*.contact_number_2' => ['prohibited'],
            'marriages.*.contact_number_3' => ['prohibited'],
            'marriages.*.phone_number' => ['prohibited'],
            'marriages.*.mobile_number' => ['prohibited'],
            'children' => ['nullable', 'array', 'max:20'],
            'children.*' => ['array'],
            'children.*.id' => ['nullable', 'integer'],
            'children.*.child_name' => ['nullable', 'string', 'max:255'],
            'children.*.gender' => ['nullable', Rule::in(self::MOBILE_CHILD_GENDERS)],
            'children.*.age' => ['nullable', 'integer', 'min:1', 'max:120'],
            'children.*.child_living_with_id' => [
                'nullable',
                'integer',
                Rule::exists('master_child_living_with', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'children.*.sort_order' => ['nullable', 'integer', 'min:0'],
            // No contact columns on profile_children — see MOBILE_CONTACT_NUMBER_RULES note.
            'children.*.contact_number' => ['prohibited'],
            'children.*.contact_number_2' => ['prohibited'],
            'children.*.contact_number_3' => ['prohibited'],
            'children.*.phone_number' => ['prohibited'],
            'children.*.mobile_number' => ['prohibited'],
            'self_addresses' => ['nullable', 'array', 'max:10'],
            'self_addresses.*' => ['array'],
            'self_addresses.*.id' => ['nullable', 'integer'],
            'self_addresses.*.address_type_id' => ['nullable', 'integer', Rule::exists('master_address_types', 'id')->where('is_active', true)],
            'self_addresses.*.address_type_key' => ['nullable', 'string', Rule::in(self::MOBILE_ADDRESS_TYPE_KEYS)],
            'self_addresses.*.address_line' => ['nullable', 'string', 'max:255'],
            'self_addresses.*.location_id' => ['nullable', 'integer', 'exists:'.Location::geoTable().',id'],
            'self_addresses.*.city_id' => ['nullable', 'integer', 'exists:'.Location::geoTable().',id'],
            // No contact columns on profile_addresses — see MOBILE_CONTACT_NUMBER_RULES note.
            'self_addresses.*.contact_number' => ['prohibited'],
            'self_addresses.*.contact_number_2' => ['prohibited'],
            'self_addresses.*.contact_number_3' => ['prohibited'],
            'self_addresses.*.phone_number' => ['prohibited'],
            'self_addresses.*.mobile_number' => ['prohibited'],
            'self_addresses.*.primary_contact_number' => ['prohibited'],
            'parents_addresses' => ['nullable', 'array', 'max:10'],
            'parents_addresses.*' => ['array'],
            'parents_addresses.*.id' => ['nullable', 'integer'],
            'parents_addresses.*.address_type_id' => ['nullable', 'integer', Rule::exists('master_address_types', 'id')->where('is_active', true)],
            'parents_addresses.*.address_type_key' => ['nullable', 'string', Rule::in(self::MOBILE_ADDRESS_TYPE_KEYS)],
            'parents_addresses.*.address_line' => ['nullable', 'string', 'max:255'],
            'parents_addresses.*.location_id' => ['nullable', 'integer', 'exists:'.Location::geoTable().',id'],
            'parents_addresses.*.city_id' => ['nullable', 'integer', 'exists:'.Location::geoTable().',id'],
            // No contact columns on profile_addresses — see MOBILE_CONTACT_NUMBER_RULES note.
            'parents_addresses.*.contact_number' => ['prohibited'],
            'parents_addresses.*.contact_number_2' => ['prohibited'],
            'parents_addresses.*.contact_number_3' => ['prohibited'],
            'parents_addresses.*.phone_number' => ['prohibited'],
            'parents_addresses.*.mobile_number' => ['prohibited'],
            'parents_addresses.*.primary_contact_number' => ['prohibited'],
            'relatives' => ['nullable', 'array', 'max:20'],
            'relatives.*' => ['array'],
            'relatives.*.id' => ['nullable', 'integer'],
            'relatives.*.relation_type' => ['nullable', Rule::in(array_keys(self::MOBILE_RELATIVE_RELATION_LABELS))],
            'relatives.*.relative_details' => ['nullable', 'string', 'max:2000'],
            'relatives.*.contact_number' => self::MOBILE_CONTACT_NUMBER_RULES,
            // profile_relatives has only contact_number — no _2/_3 columns.
            'relatives.*.contact_number_2' => ['prohibited'],
            'relatives.*.contact_number_3' => ['prohibited'],
            'alliance_networks' => ['nullable', 'array', 'max:20'],
            'alliance_networks.*' => ['array'],
            'alliance_networks.*.id' => ['nullable', 'integer'],
            'alliance_networks.*.surname' => ['nullable', 'string', 'max:255'],
            'alliance_networks.*.city_id' => ['nullable', 'integer', 'exists:'.Location::geoTable().',id'],
            'alliance_networks.*.state_id' => ['nullable', 'integer', 'exists:'.Location::geoTable().',id'],
            'alliance_networks.*.district_id' => ['nullable', 'integer', 'exists:'.Location::geoTable().',id'],
            'alliance_networks.*.taluka_id' => ['nullable', 'integer', 'exists:'.Location::geoTable().',id'],
            'alliance_networks.*.notes' => ['nullable', 'string', 'max:1000'],
            // No contact columns on profile_alliance_networks — see MOBILE_CONTACT_NUMBER_RULES note.
            'alliance_networks.*.contact_number' => ['prohibited'],
            'alliance_networks.*.contact_number_2' => ['prohibited'],
            'alliance_networks.*.contact_number_3' => ['prohibited'],
            'alliance_networks.*.phone_number' => ['prohibited'],
            'alliance_networks.*.mobile_number' => ['prohibited'],
            'alliance_networks.*.primary_contact_number' => ['prohibited'],
            'other_relatives_text' => ['nullable', 'string', 'max:4000'],
            'property_details' => ['nullable', 'string', 'max:4000'],
            'rashi_id' => ['nullable', 'integer', Rule::exists('master_rashis', 'id')->where('is_active', true)],
            'nakshatra_id' => ['nullable', 'integer', Rule::exists('master_nakshatras', 'id')->where('is_active', true)],
            'charan' => ['nullable', 'integer', 'min:1', 'max:4'],
            'gan_id' => ['nullable', 'integer', Rule::exists('master_gans', 'id')->where('is_active', true)],
            'nadi_id' => ['nullable', 'integer', Rule::exists('master_nadis', 'id')->where('is_active', true)],
            'yoni_id' => ['nullable', 'integer', Rule::exists('master_yonis', 'id')->where('is_active', true)],
            'varna_id' => ['nullable', 'integer', Rule::exists('master_varnas', 'id')->where('is_active', true)],
            'vashya_id' => ['nullable', 'integer', Rule::exists('master_vashyas', 'id')->where('is_active', true)],
            'rashi_lord_id' => ['nullable', 'integer', Rule::exists('master_rashi_lords', 'id')->where('is_active', true)],
            'mangal_dosh_type_id' => ['nullable', 'integer', Rule::exists('master_mangal_dosh_types', 'id')->where('is_active', true)],
            'devak' => ['nullable', 'string', 'max:255'],
            'kul' => ['nullable', 'string', 'max:255'],
            'gotra' => ['nullable', 'string', 'max:255'],
            'navras_name' => ['nullable', 'string', 'max:255'],
            'birth_weekday' => ['nullable', 'string', Rule::in($this->birthWeekdayValues())],
            'narrative_about_me' => ['nullable', 'string', 'max:5000'],
            'narrative_expectations' => ['nullable', 'string', 'max:5000'],
            'preferred_intercaste' => ['nullable', 'boolean'],
        ];

        foreach ($this->mobileParentContactFields() as $field) {
            $rules[$field] = self::MOBILE_CONTACT_NUMBER_RULES;
        }
        foreach ($this->mobileParentContactPreferenceFields() as $field) {
            $rules[$field] = ['prohibited'];
        }

        foreach (['income', 'family_income'] as $prefix) {
            $valueType = $request->input($prefix.'_value_type');
            if (in_array($valueType, ['exact', 'approximate'], true)) {
                $rules[$prefix.'_amount'] = ['required', 'numeric', 'min:0'];
            }
            if ($valueType === 'range') {
                $rules[$prefix.'_min_amount'] = ['required', 'numeric', 'min:0'];
                $rules[$prefix.'_max_amount'] = ['required', 'numeric', 'min:0', 'gte:'.$prefix.'_min_amount'];
            }
        }

        if (! $creating) {
            $rules['address_line'] = ['nullable', 'string', 'max:255'];
        }

        $validator = Validator::make($request->all(), $rules);
        $validator->after(function ($validator) use ($request, $profile): void {
            // Minimum marriage age (shared MarriageAgePolicy — PO 2026-07-22).
            if ($request->filled('date_of_birth')) {
                $genderKey = MarriageAgePolicy::genderKeyForId(
                    $request->input('gender_id') ?: $profile?->gender_id
                );
                $ageError = MarriageAgePolicy::dateOfBirthError($request->input('date_of_birth'), $genderKey);
                if ($ageError !== null) {
                    $validator->errors()->add('date_of_birth', $ageError);
                }
            }

            // Canonical marital year sanity (App\Support\MaritalDependencyRules).
            // The web wizard has enforced these since day one; the mobile path
            // silently accepted divorce-before-marriage until 2026-07-22.
            $marriageRowsForYears = $request->input('marriages', []);
            if (is_array($marriageRowsForYears)) {
                foreach (array_values($marriageRowsForYears) as $index => $marriageRow) {
                    if (! is_array($marriageRow)) {
                        continue;
                    }
                    foreach (MaritalDependencyRules::yearSanityErrors($marriageRow, 'marriages.'.$index) as $key => $message) {
                        $validator->errors()->add($key, $message);
                    }
                }
            }

            $religionId = $request->input('religion_id');
            $casteId = $request->input('caste_id');
            $subCasteId = $request->input('sub_caste_id');
            $occupationMasterId = $request->input('occupation_master_id');
            $occupationCustomId = $request->input('occupation_custom_id');
            $fatherOccupationMasterId = $request->input('father_occupation_master_id');
            $fatherOccupationCustomId = $request->input('father_occupation_custom_id');
            $motherOccupationMasterId = $request->input('mother_occupation_master_id');
            $motherOccupationCustomId = $request->input('mother_occupation_custom_id');
            $siblings = $request->input('siblings', []);
            $relatives = $request->input('relatives', []);
            $allianceNetworks = $request->input('alliance_networks', []);
            $marriages = $request->input('marriages', []);
            $children = $request->input('children', []);
            $selectedMaritalStatusKey = null;
            $selectedMaritalStatusId = $request->input('marital_status_id');
            if ($selectedMaritalStatusId !== null && $selectedMaritalStatusId !== '') {
                $selectedMaritalStatusKey = $this->mobileMaritalStatusKeyById((int) $selectedMaritalStatusId);
            }

            if ($religionId !== null && $religionId !== '' && $casteId !== null && $casteId !== '') {
                $casteReligionId = Caste::query()->whereKey((int) $casteId)->value('religion_id');
                if ($casteReligionId !== null && (int) $casteReligionId !== (int) $religionId) {
                    $validator->errors()->add('caste_id', 'The selected caste does not belong to the selected religion.');
                }
            }

            if ($casteId !== null && $casteId !== '' && $subCasteId !== null && $subCasteId !== '') {
                $subCasteCasteId = SubCaste::query()->whereKey((int) $subCasteId)->value('caste_id');
                if ($subCasteCasteId !== null && (int) $subCasteCasteId !== (int) $casteId) {
                    $validator->errors()->add('sub_caste_id', 'The selected sub-caste does not belong to the selected caste.');
                }
            }

            if ($occupationMasterId !== null && $occupationMasterId !== '' && $occupationCustomId !== null && $occupationCustomId !== '') {
                $validator->errors()->add('occupation_custom_id', 'Select either a listed occupation or a custom occupation, not both.');
            }

            if ($fatherOccupationMasterId !== null && $fatherOccupationMasterId !== '' && $fatherOccupationCustomId !== null && $fatherOccupationCustomId !== '') {
                $validator->errors()->add('father_occupation_custom_id', 'Select either a listed father occupation or a custom father occupation, not both.');
            }

            if ($motherOccupationMasterId !== null && $motherOccupationMasterId !== '' && $motherOccupationCustomId !== null && $motherOccupationCustomId !== '') {
                $validator->errors()->add('mother_occupation_custom_id', 'Select either a listed mother occupation or a custom mother occupation, not both.');
            }

            if (is_array($siblings)) {
                foreach ($siblings as $index => $sibling) {
                    if (! is_array($sibling)) {
                        continue;
                    }
                    $siblingOccupationMasterId = $sibling['occupation_master_id'] ?? null;
                    $siblingOccupationCustomId = $sibling['occupation_custom_id'] ?? null;
                    if ($siblingOccupationMasterId !== null && $siblingOccupationMasterId !== '' && $siblingOccupationCustomId !== null && $siblingOccupationCustomId !== '') {
                        $validator->errors()->add('siblings.'.$index.'.occupation_custom_id', 'Select either a listed sibling occupation or a custom sibling occupation, not both.');
                    }
                }
            }

            if (is_array($relatives)) {
                foreach ($relatives as $index => $relative) {
                    if (! is_array($relative)) {
                        continue;
                    }

                    $relationType = isset($relative['relation_type']) ? trim((string) $relative['relation_type']) : '';
                    $hasRelativeData = $this->relativeDetailsFromMobileRelativeRow($relative) !== null;

                    if ($hasRelativeData && $relationType === '') {
                        $validator->errors()->add('relatives.'.$index.'.relation_type', 'Select a relative relation type.');
                    }
                }
            }

            if (is_array($allianceNetworks)) {
                foreach ($allianceNetworks as $index => $allianceNetwork) {
                    if (! is_array($allianceNetwork)) {
                        continue;
                    }

                    $surname = trim((string) ($allianceNetwork['surname'] ?? ''));
                    $hasAllianceNetworkData = trim((string) ($allianceNetwork['notes'] ?? '')) !== ''
                        || (($allianceNetwork['city_id'] ?? null) !== null && ($allianceNetwork['city_id'] ?? '') !== '')
                        || (($allianceNetwork['state_id'] ?? null) !== null && ($allianceNetwork['state_id'] ?? '') !== '')
                        || (($allianceNetwork['district_id'] ?? null) !== null && ($allianceNetwork['district_id'] ?? '') !== '')
                        || (($allianceNetwork['taluka_id'] ?? null) !== null && ($allianceNetwork['taluka_id'] ?? '') !== '');

                    if ($hasAllianceNetworkData && $surname === '') {
                        $validator->errors()->add('alliance_networks.'.$index.'.surname', 'Enter alliance network surname.');
                    }
                }
            }

            if (is_array($marriages)) {
                foreach ($marriages as $index => $marriage) {
                    if (! is_array($marriage)) {
                        continue;
                    }

                    $marriageYear = $this->positiveIntFromMixed($marriage['marriage_year'] ?? null);
                    foreach (['separation_year', 'divorce_year', 'spouse_death_year'] as $field) {
                        $year = $this->positiveIntFromMixed($marriage[$field] ?? null);
                        if ($marriageYear !== null && $year !== null && $year < $marriageYear) {
                            $validator->errors()->add('marriages.'.$index.'.'.$field, ucfirst(str_replace('_', ' ', $field)).' must be greater than or equal to marriage year.');
                        }
                    }
                }
            }

            $shouldValidateChildren = $selectedMaritalStatusKey !== 'never_married'
                && $this->mobileBooleanInput($request, 'has_children', null) !== false;

            if ($shouldValidateChildren && is_array($children)) {
                foreach ($children as $index => $child) {
                    if (! is_array($child)) {
                        continue;
                    }

                    $hasAnyChildData = trim((string) ($child['child_name'] ?? '')) !== ''
                        || trim((string) ($child['gender'] ?? '')) !== ''
                        || (($child['age'] ?? null) !== null && ($child['age'] ?? '') !== '')
                        || (($child['child_living_with_id'] ?? null) !== null && ($child['child_living_with_id'] ?? '') !== '');
                    if (! $hasAnyChildData) {
                        continue;
                    }

                    if (trim((string) ($child['gender'] ?? '')) === '') {
                        $validator->errors()->add('children.'.$index.'.gender', 'Select child gender.');
                    }
                    if (($child['age'] ?? null) === null || ($child['age'] ?? '') === '') {
                        $validator->errors()->add('children.'.$index.'.age', 'Enter child age.');
                    }
                }
            }

            if ($request->has('education_slots') && $request->filled('education_slots')) {
                $degreeIds = $this->mobileEducationSlotDegreeIds($request->input('education_slots'));
                if ($degreeIds === null) {
                    $validator->errors()->add('education_slots', 'The education slots field must be valid JSON.');
                } elseif ($degreeIds !== []) {
                    $found = EducationDegree::query()->whereIn('id', $degreeIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
                    $missing = array_diff($degreeIds, $found);
                    if ($missing !== []) {
                        $validator->errors()->add('education_slots', 'The selected education degree is invalid.');
                    }
                }
            }
        });

        $validator->validate();
    }

    private function positiveIntFromMixed(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array<int, string>
     */
    private function translatedOptionKeys(string $translationKey): array
    {
        $options = Lang::get($translationKey, [], 'en');

        return is_array($options) ? array_values(array_map('strval', array_keys($options))) : [];
    }

    /**
     * @return array<int, string>
     */
    private function birthWeekdayValues(): array
    {
        $weekdays = Lang::get('components.horoscope.weekdays', [], 'en');
        if (! is_array($weekdays)) {
            return [];
        }

        return collect(array_keys($weekdays))
            ->map(fn (string $key): string => ucfirst($key))
            ->values()
            ->all();
    }

    private function normalizeMobileEducationCareerInputs(Request $request): void
    {
        if ($request->has('education_slots')) {
            app(EducationService::class)->mergeMultiselectEducationIntoRequest($request);
        }

        if (Schema::hasColumn('matrimony_profiles', 'occupation_master_id')
            && ($request->filled('occupation_master_id') || $request->filled('occupation_custom_id'))) {
            app(OccupationService::class)->mergeOccupationIntoRequest($request);
        }

        if (Schema::hasColumn('matrimony_profiles', 'father_occupation_master_id')
            && ($request->filled('father_occupation_master_id') || $request->filled('father_occupation_custom_id')
                || $request->filled('mother_occupation_master_id') || $request->filled('mother_occupation_custom_id'))) {
            app(OccupationService::class)->mergeParentOccupationTextIntoRequest($request);
        }
    }

    /**
     * @return array<int, string>
     */
    private function mobileParentContactFields(): array
    {
        $known = [
            'father_contact_1',
            'father_contact_2',
            'father_contact_3',
            'mother_contact_1',
            'mother_contact_2',
            'mother_contact_3',
        ];

        return array_values(array_filter(
            $known,
            fn (string $field): bool => Schema::hasColumn('matrimony_profiles', $field)
        ));
    }

    private function mobileParentContactMaxSlots(): int
    {
        return (
            Schema::hasColumn('matrimony_profiles', 'father_contact_3')
            || Schema::hasColumn('matrimony_profiles', 'mother_contact_3')
        ) ? 3 : 2;
    }

    /**
     * @return array<int, string>
     */
    private function mobileParentContactPreferenceFields(): array
    {
        return [
            'father_contact_whatsapp_1',
            'father_contact_whatsapp_2',
            'father_contact_whatsapp_3',
            'mother_contact_whatsapp_1',
            'mother_contact_whatsapp_2',
            'mother_contact_whatsapp_3',
        ];
    }

    /**
     * @return array<int, int>|null Null means malformed payload.
     */
    private function mobileEducationSlotDegreeIds(mixed $slotsRaw): ?array
    {
        if ($slotsRaw === null || $slotsRaw === '') {
            return [];
        }

        if (is_string($slotsRaw)) {
            $decoded = json_decode($slotsRaw, true);
            if (! is_array($decoded)) {
                return null;
            }
            $slotsRaw = $decoded;
        }

        if (! is_array($slotsRaw)) {
            return null;
        }

        $ids = [];
        foreach ($slotsRaw as $slot) {
            if (! is_array($slot)) {
                return null;
            }
            $type = $slot['t'] ?? $slot['type'] ?? null;
            $type = $type === 'degree' ? 'd' : $type;
            if ($type !== 'd') {
                continue;
            }
            $id = (int) ($slot['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Lock canonical keys after mobile writes. Raw legacy text keys such as caste are accepted for
     * compatibility, but MutationService writes canonical *_id fields when the value resolves.
     */
    private function lockKeysForMobileSnapshot(array $core): array
    {
        return array_values(array_diff(array_keys($core), ['caste']));
    }

    /**
     * Store matrimony profile for logged-in user
     * SSOT: User ≠ MatrimonyProfile
     * CREATE ONLY - returns 409 if profile already exists
     */
    public function store(Request $request)
    {
        // Phase-4 Day-8: Location hierarchy validation
        $this->validateMobileProfileRequest($request, creating: true);

        $user = $request->user(); // sanctum authenticated user

        // Check if profile already exists
        $existingProfile = MatrimonyProfile::where('user_id', $user->id)->first();

        if ($existingProfile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile already exists',
            ], 409);
        }

        $mutation = app(MutationService::class);
        $profile = $mutation->createDraftProfileForUser($user);
        $snapshot = $this->buildMobileProfileSnapshotFromApi($request, $profile);
        $mutation->applyManualSnapshot($profile, $snapshot, (int) $user->id, 'manual');
        $this->syncMobilePartnerCommunityFlags($profile, $request);

        $profileData = $this->buildGovernanceParityProfilePayload($this->freshMobileProfileResponseModel($profile));

        return response()->json([
            'success' => true,
            'message' => 'Matrimony profile created',
            'profile' => $profileData,
        ]);
    }

    /**
     * Get matrimony profile for logged-in user
     */
    public function show(Request $request)
    {
        $user = $request->user();

        $profile = MatrimonyProfile::where('user_id', $user->id)->first();

        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found',
            ], 404);
        }

        return $this->showForProfile($profile, $user);
    }

    /**
     * Shared read payload for member self-edit and Suchak represented edit.
     * Caller owns authorization / profile resolution.
     */
    public function showForProfile(MatrimonyProfile $profile, ?User $viewer = null)
    {
        $profileData = $this->buildGovernanceParityProfilePayload($profile);

        return response()->json([
            'success' => true,
            'profile' => $profileData,
            'display' => app(MobileProfileDisplayPresenter::class)->forProfile($profile, $viewer),
        ]);
    }

    /**
     * Update matrimony profile for logged-in user
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $profile = MatrimonyProfile::where('user_id', $user->id)->first();

        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found',
            ], 404);
        }

        return $this->updateForProfile($request, $profile, $user);
    }

    /**
     * Shared write path for member self-edit and Suchak represented edit.
     * Caller owns authorization / profile resolution. Actor is the mutating user.
     */
    public function updateForProfile(Request $request, MatrimonyProfile $profile, User $actor)
    {
        // Phase-4 Day-8: Location hierarchy validation
        $this->validateMobileProfileRequest($request, creating: false, profile: $profile);

        // Day 7: Archived/Suspended → edit blocked
        if (! \App\Services\ProfileLifecycleService::isEditable($profile)) {
            return response()->json([
                'success' => false,
                'message' => 'Your profile cannot be edited in its current state.',
            ], 403);
        }

        if (! $request->filled('gender_id') && ! $this->profileHasGovernedGender($profile)) {
            return response()->json([
                'success' => false,
                'message' => 'The gender id field is required.',
                'errors' => [
                    'gender_id' => ['The gender id field is required.'],
                ],
            ], 422);
        }

        // Phase-5B: All updates via MutationService (source=manual, profile_change_history)
        $snapshot = $this->buildMobileProfileSnapshotFromApi($request, $profile);
        if ($this->mobileSnapshotHasWritableData($snapshot)) {
            $changedFields = $this->lockKeysForMobileSnapshot($snapshot['core'] ?? []);
            ProfileFieldLockService::removeActorOwnedLocks($profile, $changedFields, $actor);
            app(MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $actor->id, 'manual');
            if (! empty($changedFields)) {
                ProfileFieldLockService::applyLocks($profile, $changedFields, 'CORE', $actor);
            }
        }
        $this->syncMobilePartnerCommunityFlags($profile, $request);

        $profileData = $this->buildGovernanceParityProfilePayload($this->freshMobileProfileResponseModel($profile));

        return response()->json([
            'success' => true,
            'message' => 'Matrimony profile updated',
            'profile' => $profileData,
        ]);
    }

    private function mobileSnapshotHasWritableData(array $snapshot): bool
    {
        foreach ($snapshot as $key => $value) {
            if ($key !== 'core') {
                return true;
            }
            if (is_array($value) && $value !== []) {
                return true;
            }
        }

        return false;
    }

    private function syncMobilePartnerCommunityFlags(MatrimonyProfile $profile, Request $request): void
    {
        if (! $this->requestInputKeyExists($request, 'preferred_intercaste')) {
            return;
        }

        ProfilePartnerCommunityFlagService::syncIntercasteIntentFromRequest((int) $profile->id, $request);
    }

    private function freshMobileProfileResponseModel(MatrimonyProfile $profile): MatrimonyProfile
    {
        $relations = [
            'user',
            'gender',
            'horoscope.rashi',
            'horoscope.nakshatra',
            'horoscope.gan',
            'horoscope.nadi',
            'horoscope.yoni',
            'horoscope.mangalDoshType',
            'preferenceCriteria.preferredMaritalStatus',
            'preferenceCriteria.marriageTypePreference',
            'religion',
            'caste',
            'subCaste',
            'birthCity',
            'motherTongue',
            'maritalStatus',
            'complexion',
            'bloodGroup',
            'physicalBuild',
            'diet',
            'smokingStatus',
            'drinkingStatus',
            'occupationMaster.category',
            'occupationCustom',
            'familyType',
            'fatherOccupationMaster',
            'fatherOccupationCustom',
            'motherOccupationMaster',
            'motherOccupationCustom',
            'incomeCurrency',
            'familyIncomeCurrency',
            'marriages.maritalStatus',
            'children.childLivingWith',
            'siblings.city',
            'siblings.occupationMaster',
            'siblings.occupationCustom',
            'relatives',
            'allianceNetworks.city',
            'allianceNetworks.state',
            'allianceNetworks.district',
            'allianceNetworks.taluka',
        ];

        $fresh = $profile->fresh($relations);
        if ($fresh instanceof MatrimonyProfile) {
            return $fresh;
        }

        $profile->unsetRelations();
        $profile->refresh();
        $profile->load($relations);

        return $profile;
    }

    /**
     * Upload or replace matrimony profile photo for logged-in user
     */
    public function uploadPhoto(Request $request)
    {
        Log::info('UPLOAD ENTRY HIT', [
            'controller' => __METHOD__,
            'user_id' => auth()->id() ?? null,
        ]);

        $request->validate([
            'profile_photo' => 'required|image|max:2048',
        ]);

        $user = $request->user();

        $profile = MatrimonyProfile::where('user_id', $user->id)->first();

        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found.',
            ], 404);
        }

        if (Schema::hasColumn('users', 'photo_uploads_suspended') && (bool) $user->photo_uploads_suspended) {
            return response()->json([
                'success' => false,
                'message' => 'Photo uploads have been suspended for your account.',
            ], 403);
        }

        $file = $request->file('profile_photo');
        $pending = app(\App\Services\Image\ImageProcessingService::class)
            ->enqueueProfilePhotoProcessing($file, (int) $profile->id);

        $snapshot = [
            'core' => [
                'profile_photo' => $pending,
            ],
        ];
        app(\App\Services\MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $user->id, 'manual');
        app(\App\Services\Image\ProfilePhotoPendingStateService::class)->applyPendingReviewState($profile);

        return response()->json([
            'success' => true,
            'message' => 'Profile photo uploaded. Processing will complete shortly.',
            'data' => [
                'profile_photo' => $pending,
                'status' => 'processing',
            ],
        ]);
    }

    /**
     * List all matrimony profiles with filters
     */
    public function index(Request $request, MobileDiscoveryFilterService $discovery, MatchingService $matching)
    {
        $viewer = $request->user();
        $relations = $this->mobileListRelations();
        $feed = $this->mobileListFeed($request);
        $profiles = $feed === null
            ? $this->legacyMobileListProfiles($request, $viewer, $discovery, $relations)
            : $this->feedMobileListProfiles($request, $viewer, $discovery, $matching, $relations, $feed);
        $presenter = app(MobileProfileDisplayPresenter::class);

        // Transform to include gender from the governed profile relation only.
        // PIR-006: Null-safe when profile's user is missing (orphaned profile)
        // Phase-4 Day-8: Use hierarchical location fields
        $profiles = $profiles->map(function ($profile) use ($presenter, $viewer) {
            $hints = $profile->residenceLocationHierarchyHints();
            $geo = $profile->residenceGeoAddressIds();
            $display = $presenter->forListCard($profile, $viewer);
            $primaryPhotoUrl = $display['card']['primary_photo_url'] ?? null;

            return [
                'id' => $profile->id,
                'user_id' => $profile->user_id,
                'full_name' => $profile->full_name,
                'gender' => $profile->gender?->key,
                'date_of_birth' => $profile->date_of_birth,
                'caste' => $profile->caste,
                'highest_education' => $profile->highest_education,
                'location_id' => $profile->location_id,
                'country_id' => $geo['country_id'],
                'state_id' => $geo['state_id'],
                'district_id' => $geo['district_id'],
                'taluka_id' => $hints['taluka_id'] !== '' ? (int) $hints['taluka_id'] : null,
                'profile_photo' => ($profile->profile_photo && $profile->photo_approved !== false) ? $profile->profile_photo : null,
                'primary_photo_url' => $primaryPhotoUrl,
                'profile_photo_url' => $primaryPhotoUrl,
                'approved_photo_url' => $primaryPhotoUrl,
                'created_at' => $profile->created_at,
                'updated_at' => $profile->updated_at,
                'display' => $display,
            ];
        });

        return response()->json([
            'success' => true,
            'profiles' => $profiles,
        ]);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function mobileListRelations(): array
    {
        $relations = [
            'user.activeSubscription.plan',
            'gender',
            'religion',
            'caste',
            'subCaste',
            'occupationMaster',
            'occupationCustom',
            'horoscope',
        ];
        if (Schema::hasTable('profile_photos')) {
            $relations['photos'] = fn ($query) => $query->effectivelyApproved();
        }

        return $relations;
    }

    private function mobileListFeed(Request $request): ?string
    {
        $feed = strtolower(trim((string) $request->query('feed', '')));

        return match ($feed) {
            'new' => 'new',
            'daily' => 'daily',
            'my_matches', 'my-matches', 'matches', 'perfect' => 'my_matches',
            'near_me', 'near-me', 'nearby' => 'nearby',
            default => null,
        };
    }

    /**
     * @param  array<int|string, mixed>  $relations
     * @return Collection<int, MatrimonyProfile>
     */
    private function legacyMobileListProfiles(
        Request $request,
        ?User $viewer,
        MobileDiscoveryFilterService $discovery,
        array $relations
    ): Collection {
        $query = MatrimonyProfile::with($relations);
        $this->applyMobileDiscoveryQuery($query, $viewer, $discovery);
        $this->applyMobileListFilters($query, $request);
        ProfileRotationService::applyApprovedPhotoOrdering($query);

        return $query->latest()->get();
    }

    /**
     * @param  array<int|string, mixed>  $relations
     * @return Collection<int, MatrimonyProfile>
     */
    private function feedMobileListProfiles(
        Request $request,
        ?User $viewer,
        MobileDiscoveryFilterService $discovery,
        MatchingService $matching,
        array $relations,
        string $feed
    ): Collection {
        if (! $viewer instanceof User || ! $discovery->viewerCanDiscover($viewer)) {
            return collect();
        }

        return match ($feed) {
            'daily' => $this->matchingFeedProfiles($request, $viewer, $discovery, $matching, $relations, MatchingService::TAB_DAILY),
            'my_matches' => $this->matchingFeedProfiles($request, $viewer, $discovery, $matching, $relations, MatchingService::TAB_PERFECT),
            'nearby' => $this->matchingFeedProfiles($request, $viewer, $discovery, $matching, $relations, MatchingService::TAB_NEAR),
            default => $this->newFeedProfiles($request, $viewer, $discovery, $relations),
        };
    }

    /**
     * @param  array<int|string, mixed>  $relations
     * @return Collection<int, MatrimonyProfile>
     */
    private function newFeedProfiles(
        Request $request,
        User $viewer,
        MobileDiscoveryFilterService $discovery,
        array $relations
    ): Collection {
        $query = MatrimonyProfile::with($relations);
        $this->applyMobileDiscoveryQuery($query, $viewer, $discovery);
        $this->applyMobileListFilters($query, $request);

        $viewer->loadMissing('matrimonyProfile.gender');
        $viewerProfile = $viewer->matrimonyProfile;
        if ($viewerProfile instanceof MatrimonyProfile && ProfileRotationService::isEnabled()) {
            ProfileRotationService::applyDiscoverScope($query, (int) $viewerProfile->id, (int) $viewer->id);
            ProfileRotationService::applyDiscoverOrdering(
                $query,
                (int) $viewerProfile->id,
                $this->mobileFeedSeed($viewerProfile, 'new'),
                true
            );

            return $query->get();
        }

        ProfileRotationService::applyApprovedPhotoOrdering($query);

        return $query->latest()->get();
    }

    /**
     * @param  array<int|string, mixed>  $relations
     * @return Collection<int, MatrimonyProfile>
     */
    private function matchingFeedProfiles(
        Request $request,
        User $viewer,
        MobileDiscoveryFilterService $discovery,
        MatchingService $matching,
        array $relations,
        string $matchingTab
    ): Collection {
        $viewer->loadMissing('matrimonyProfile.gender');
        $viewerProfile = $viewer->matrimonyProfile;
        if (! $viewerProfile instanceof MatrimonyProfile) {
            return collect();
        }

        $ids = $matching
            ->findMatchesForTab($viewerProfile, $matchingTab, 160)
            ->filter(function (array $row) use ($viewer, $discovery): bool {
                $profile = $row['profile'] ?? null;

                return $profile instanceof MatrimonyProfile
                    && $discovery->isAllowedTarget($viewer, $profile);
            })
            ->map(fn (array $row): int => (int) $row['profile']->id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        return $this->profilesByMobileFeedOrder($request, $viewer, $discovery, $relations, $ids);
    }

    private function mobileFeedSeed(MatrimonyProfile $viewerProfile, string $feed): string
    {
        return implode('|', [
            'mobile',
            $feed,
            (string) $viewerProfile->id,
            now()->toDateString(),
        ]);
    }

    /**
     * @param  array<int|string, mixed>  $relations
     * @param  list<int>  $profileIds
     * @return Collection<int, MatrimonyProfile>
     */
    private function profilesByMobileFeedOrder(
        Request $request,
        User $viewer,
        MobileDiscoveryFilterService $discovery,
        array $relations,
        array $profileIds
    ): Collection {
        $profileIds = array_values(array_unique(array_filter(array_map('intval', $profileIds))));
        if ($profileIds === []) {
            return collect();
        }

        $query = MatrimonyProfile::with($relations)->whereIn('id', $profileIds);
        $this->applyMobileDiscoveryQuery($query, $viewer, $discovery);
        $this->applyMobileListFilters($query, $request);

        $profiles = $query->get()->keyBy(fn (MatrimonyProfile $profile): int => (int) $profile->id);

        return collect($profileIds)
            ->map(fn (int $id) => $profiles->get($id))
            ->filter()
            ->values();
    }

    /**
     * @param  Builder<MatrimonyProfile>  $query
     */
    private function applyMobileDiscoveryQuery(Builder $query, ?User $viewer, MobileDiscoveryFilterService $discovery): void
    {
        if ($viewer instanceof User) {
            $discovery->applyCandidateQuery($query, $viewer);

            return;
        }

        $query->whereRaw('1 = 0');
    }

    /**
     * @param  Builder<MatrimonyProfile>  $query
     */
    private function applyMobileListFilters(Builder $query, Request $request): void
    {
        $table = $query->getModel()->getTable();

        // Soft deletes are automatically excluded by Laravel's SoftDeletes trait.
        if ($request->filled('religion_id') && Schema::hasColumn($table, 'religion_id')) {
            $query->where($table.'.religion_id', (int) $request->integer('religion_id'));
        }

        if ($request->filled('caste_id') && Schema::hasColumn($table, 'caste_id')) {
            $query->where($table.'.caste_id', (int) $request->integer('caste_id'));
        } elseif ($request->filled('caste')) {
            $this->applyLegacyCasteFilter($query, $table, (string) $request->query('caste'));
        }

        // Residence geo: filter by ancestor {@code addresses.id} (canonical leaf is {@see location_id}).
        if ($request->filled('country_id')) {
            $query->whereResidenceUnderAncestor((int) $request->country_id);
        }
        if ($request->filled('state_id')) {
            $query->whereResidenceUnderAncestor((int) $request->state_id);
        }
        if ($request->filled('district_id')) {
            $query->whereResidenceUnderAncestor((int) $request->district_id);
        }
        if ($request->filled('taluka_id')) {
            $query->whereResidenceUnderAncestor((int) $request->taluka_id);
        }
        if ($request->filled('location_id')) {
            $query->where('location_id', $request->location_id);
        }

        if ($request->filled('height_from_cm') && Schema::hasColumn($table, 'height_cm')) {
            $query->whereNotNull($table.'.height_cm')
                ->where($table.'.height_cm', '>=', (int) $request->integer('height_from_cm'));
        }
        if ($request->filled('height_to_cm') && Schema::hasColumn($table, 'height_cm')) {
            $query->whereNotNull($table.'.height_cm')
                ->where($table.'.height_cm', '<=', (int) $request->integer('height_to_cm'));
        }

        if ($request->boolean('photo_available')) {
            $this->applyPhotoAvailableFilter($query, $table);
        }

        if ($request->boolean('verified_photo')) {
            $this->applyVerifiedProfileFilter($query, $table);
        }

        if ($request->boolean('recently_active')) {
            $this->applyRecentlyActiveFilter($query);
        }

        if ($request->filled('education_id')) {
            $this->applyEducationFilter($query, $table, (int) $request->integer('education_id'));
        }

        if ($request->filled('occupation_id') && Schema::hasColumn($table, 'occupation_master_id')) {
            $query->where($table.'.occupation_master_id', (int) $request->integer('occupation_id'));
        }

        if ($request->filled('marital_status_id') && Schema::hasColumn($table, 'marital_status_id')) {
            $query->where($table.'.marital_status_id', (int) $request->integer('marital_status_id'));
        }

        // Age filter (from date_of_birth).
        if ($request->filled('age_from') || $request->filled('age_to')) {
            $query->whereNotNull('date_of_birth');

            if ($request->filled('age_from')) {
                $minDate = now()->subYears((int) $request->age_from)->format('Y-m-d');
                $query->whereDate('date_of_birth', '<=', $minDate);
            }

            if ($request->filled('age_to')) {
                $maxDate = now()->subYears(((int) $request->age_to) + 1)->addDay()->format('Y-m-d');
                $query->whereDate('date_of_birth', '>=', $maxDate);
            }
        }
    }

    /**
     * @param  Builder<MatrimonyProfile>  $query
     */
    private function applyLegacyCasteFilter(Builder $query, string $table, string $rawCaste): void
    {
        $caste = trim($rawCaste);
        if ($caste === '') {
            return;
        }

        $casteIds = [];
        if (Schema::hasTable('master_castes')) {
            $casteIds = Caste::query()
                ->where(function (Builder $builder) use ($caste): void {
                    $builder->where('label', $caste)
                        ->orWhere('key', $caste);

                    foreach (['label_en', 'label_mr'] as $column) {
                        if (Schema::hasColumn('master_castes', $column)) {
                            $builder->orWhere($column, $caste);
                        }
                    }
                })
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        $query->where(function (Builder $builder) use ($table, $caste, $casteIds): void {
            $hasCondition = false;

            if (Schema::hasColumn($table, 'caste')) {
                $builder->where($table.'.caste', $caste);
                $hasCondition = true;
            }

            if ($casteIds !== [] && Schema::hasColumn($table, 'caste_id')) {
                $method = $hasCondition ? 'orWhereIn' : 'whereIn';
                $builder->{$method}($table.'.caste_id', $casteIds);
                $hasCondition = true;
            }

            if (! $hasCondition) {
                $builder->whereRaw('1 = 0');
            }
        });
    }

    /**
     * @param  Builder<MatrimonyProfile>  $query
     */
    private function applyPhotoAvailableFilter(Builder $query, string $table): void
    {
        if (Schema::hasTable('profile_photos')) {
            $query->whereHas('photos', fn (Builder $photoQuery): Builder => $photoQuery->effectivelyApproved());

            return;
        }

        if (Schema::hasColumn($table, 'profile_photo')) {
            $query->whereNotNull($table.'.profile_photo')
                ->where($table.'.profile_photo', '!=', '');
        }
        if (Schema::hasColumn($table, 'photo_approved')) {
            $query->where($table.'.photo_approved', true);
        }
    }

    /**
     * @param  Builder<MatrimonyProfile>  $query
     */
    private function applyVerifiedProfileFilter(Builder $query, string $table): void
    {
        $hasUserVerificationColumn = Schema::hasColumn('users', 'mobile_verified_at') || Schema::hasColumn('users', 'email_verified_at');
        $hasProfileVerificationTag = Schema::hasTable('profile_verification_tag');
        if (! $hasUserVerificationColumn && ! $hasProfileVerificationTag) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $builder) use ($table, $hasUserVerificationColumn, $hasProfileVerificationTag): void {
            if ($hasUserVerificationColumn) {
                $builder->whereHas('user', function (Builder $userQuery): void {
                    $userQuery->where(function (Builder $verificationQuery): void {
                        if (Schema::hasColumn('users', 'mobile_verified_at')) {
                            $verificationQuery->orWhereNotNull('mobile_verified_at');
                        }
                        if (Schema::hasColumn('users', 'email_verified_at')) {
                            $verificationQuery->orWhereNotNull('email_verified_at');
                        }
                    });
                });
            }

            if ($hasProfileVerificationTag) {
                $method = $hasUserVerificationColumn ? 'orWhereExists' : 'whereExists';
                $builder->{$method}(function ($subQuery) use ($table): void {
                    $subQuery->selectRaw('1')
                        ->from('profile_verification_tag')
                        ->whereColumn('profile_verification_tag.matrimony_profile_id', $table.'.id')
                        ->whereNull('profile_verification_tag.deleted_at');
                });
            }
        });
    }

    /**
     * @param  Builder<MatrimonyProfile>  $query
     */
    private function applyRecentlyActiveFilter(Builder $query): void
    {
        if (! Schema::hasColumn('users', 'last_seen_at')) {
            return;
        }

        $query->whereHas('user', function (Builder $userQuery): void {
            $userQuery->where('last_seen_at', '>=', now()->subDays(30));
        });
    }

    /**
     * @param  Builder<MatrimonyProfile>  $query
     */
    private function applyEducationFilter(Builder $query, string $table, int $educationId): void
    {
        if ($educationId <= 0) {
            return;
        }

        $degree = Schema::hasTable('master_education') ? EducationDegree::query()->find($educationId) : null;
        $labels = [];
        if ($degree instanceof EducationDegree) {
            $labels = collect([
                $degree->code,
                $degree->code_mr,
                $degree->full_form,
            ])
                ->map(fn ($value): string => trim((string) $value))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        $hasDegreeColumn = Schema::hasColumn($table, 'education_degree_id');
        $hasTextColumn = Schema::hasColumn($table, 'highest_education');
        if (! $hasDegreeColumn && (! $hasTextColumn || $labels === [])) {
            return;
        }

        $query->where(function (Builder $builder) use ($table, $educationId, $hasDegreeColumn, $hasTextColumn, $labels): void {
            $hasCondition = false;
            if ($hasDegreeColumn) {
                $builder->where($table.'.education_degree_id', $educationId);
                $hasCondition = true;
            }

            if ($hasTextColumn && $labels !== []) {
                foreach ($labels as $label) {
                    $method = $hasCondition ? 'orWhere' : 'where';
                    $builder->{$method}($table.'.highest_education', 'like', '%'.addcslashes($label, '%_\\').'%');
                    $hasCondition = true;
                }
            }
        });
    }

    /**
     * Mobile More Matches sections for the Flutter discovery screen.
     */
    public function moreSections(Request $request, MobileMoreMatchesSectionService $sections)
    {
        return response()->json($sections->forUser($request->user()));
    }

    /**
     * Get matrimony profile by ID
     * PIR-005: Visibility parity with Web — 404 when not visible to others or blocked.
     * PIR-006: Null-safe when profile's user is missing.
     */
    public function showById($id, MobileDiscoveryFilterService $discovery)
    {
        $profile = MatrimonyProfile::with('user')->find($id);

        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found',
            ], 404);
        }

        $user = request()->user();
        if (! $user instanceof User || ! $discovery->isAllowedTarget($user, $profile)) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found',
            ], 404);
        }

        $viewerProfile = $user->matrimonyProfile;
        $this->recordMobileProfileViewIfEligible($user, $viewerProfile, $profile, false);

        // Transform to include gender from the governed profile relation only.
        // PIR-006: Null-safe when user relation is missing
        // Phase-4 Day-8: Use hierarchical location fields
        $profileData = $this->buildGovernanceParityProfilePayload($profile);
        if ((int) ($viewerProfile?->id ?? 0) !== (int) $profile->id) {
            unset($profileData['address_line']);
            unset($profileData['self_addresses'], $profileData['parents_addresses']);
            $this->forgetParentContactPayloadKeys($profileData);
            $this->sanitizeAllianceNetworkRowsForOtherProfile($profileData);
            $this->sanitizeSubRecordContactNumbersForOtherProfile($profileData);
            if ((bool) ($profile->income_private ?? false)) {
                $this->forgetIncomePayloadKeys($profileData, 'income');
                unset($profileData['annual_income']);
            }
            if ((bool) ($profile->family_income_private ?? false)) {
                $this->forgetIncomePayloadKeys($profileData, 'family_income');
            }
        }

        return response()->json([
            'success' => true,
            'profile' => $profileData,
            'display' => app(MobileProfileDisplayPresenter::class)->forProfile($profile, $user),
        ]);
    }

    private function profileHasGovernedGender(MatrimonyProfile $profile): bool
    {
        return $profile->gender_id !== null && (int) $profile->gender_id > 0;
    }

    private function recordMobileProfileViewIfEligible(
        ?User $user,
        ?MatrimonyProfile $viewerProfile,
        MatrimonyProfile $profile,
        bool $isOwnProfile
    ): void {
        if (! $user || ! $viewerProfile || $isOwnProfile || $this->shouldSkipMobileProfileViewTracking($user)) {
            return;
        }

        if (ViewTrackingService::recordView($viewerProfile, $profile)) {
            try {
                ViewTrackingService::consumeDailyProfileViewUsageForViewer($viewerProfile);
            } catch (QuotaPolicySourceViolation $exception) {
                Log::warning('Mobile profile view quota consumption skipped because quota policy is incomplete.', [
                    'viewer_profile_id' => $viewerProfile->id,
                    'viewed_profile_id' => $profile->id,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
            ViewTrackingService::maybeTriggerViewBack($viewerProfile, $profile);
        }
    }

    private function shouldSkipMobileProfileViewTracking(User $user): bool
    {
        if ($user->isAnyAdmin()) {
            return true;
        }

        return $user->suchakAccount()->exists();
    }

    /**
     * Deterministic API payload aligned with snapshot / governance canonical registry (Phase-6E).
     * Includes explicit *_id columns and legacy aliases where snapshots compare logical keys.
     *
     * @return array<string, mixed>
     */
    private function buildGovernanceParityProfilePayload(MatrimonyProfile $profile): array
    {
        $profile->loadMissing([
            'user',
            'gender',
            'horoscope',
            'preferenceCriteria.preferredMaritalStatus',
            'preferenceCriteria.marriageTypePreference',
            'religion',
            'caste',
            'subCaste',
            'birthCity',
            'motherTongue',
            'maritalStatus',
            'complexion',
            'bloodGroup',
            'physicalBuild',
            'diet',
            'smokingStatus',
            'drinkingStatus',
            'occupationMaster.category',
            'occupationCustom',
            'familyType',
            'fatherOccupationMaster',
            'fatherOccupationCustom',
            'motherOccupationMaster',
            'motherOccupationCustom',
            'incomeCurrency',
            'familyIncomeCurrency',
            'marriages.maritalStatus',
            'children.childLivingWith',
            'siblings.city',
            'siblings.occupationMaster',
            'siblings.occupationCustom',
            'relatives',
            'allianceNetworks.city',
            'allianceNetworks.state',
            'allianceNetworks.district',
            'allianceNetworks.taluka',
            'horoscope.rashi',
            'horoscope.nakshatra',
            'horoscope.gan',
            'horoscope.nadi',
            'horoscope.yoni',
            'horoscope.mangalDoshType',
        ]);

        $hints = $profile->residenceLocationHierarchyHints();
        $geo = $profile->residenceGeoAddressIds();
        $horoscope = $profile->horoscope;
        $locationLabel = trim($profile->residenceLocationDisplayLine());

        $criteria = $profile->preferenceCriteria;
        $partnerPreferences = $criteria !== null ? $criteria->toArray() : null;
        $partnerPreferenceSuggestions = PartnerPreferenceSuggestionService::suggestForProfile($profile);
        $preferredMaritalStatusIds = $this->partnerPreferencePivotIds('profile_preferred_marital_statuses', 'marital_status_id', (int) $profile->id);
        if ($preferredMaritalStatusIds === [] && $criteria?->preferred_marital_status_id !== null) {
            $preferredMaritalStatusIds = [(int) $criteria->preferred_marital_status_id];
        }
        $preferredDietIds = $this->partnerPreferencePivotIds('profile_preferred_diets', 'diet_id', (int) $profile->id);
        $preferredCountryIds = $this->partnerPreferencePivotIds('profile_preferred_countries', 'country_id', (int) $profile->id);
        $preferredStateIds = $this->partnerPreferencePivotIds('profile_preferred_states', 'state_id', (int) $profile->id);
        $preferredDistrictIds = $this->partnerPreferencePivotIds('profile_preferred_districts', 'district_id', (int) $profile->id);
        $preferredTalukaIds = $this->partnerPreferencePivotIds('profile_preferred_talukas', 'taluka_id', (int) $profile->id);
        $preferredReligionIds = $this->partnerPreferencePivotIds('profile_preferred_religions', 'religion_id', (int) $profile->id);
        $preferredCasteIds = $this->partnerPreferencePivotIds('profile_preferred_castes', 'caste_id', (int) $profile->id);
        $preferredMotherTongueIds = $this->partnerPreferencePivotIds('profile_preferred_mother_tongues', 'mother_tongue_id', (int) $profile->id);
        $preferredEducationDegreeIds = $this->partnerPreferencePivotIds('profile_preferred_education_degrees', 'education_degree_id', (int) $profile->id);
        $preferredOccupationMasterIds = $this->partnerPreferencePivotIds('profile_preferred_occupation_master', 'occupation_master_id', (int) $profile->id);
        $preferredIntercaste = ProfilePartnerCommunityFlagService::interestedInIntercaste((int) $profile->id);
        $birthPlaceLabel = trim($profile->birthLocationDisplayLine());
        if ($birthPlaceLabel === '') {
            $birthPlaceLabel = trim((string) ($profile->birth_place_text ?? ''));
        }
        $incomeCurrency = $profile->incomeCurrency;
        $familyIncomeCurrency = $profile->familyIncomeCurrency ?? $incomeCurrency;
        $maritalStatusKey = $profile->maritalStatus?->key;
        $hasChildren = $maritalStatusKey === 'never_married' ? false : $profile->has_children;
        $marriages = $this->mobileMarriageRows($profile, $maritalStatusKey);
        $children = $this->mobileMarriageDetailsAllowed($maritalStatusKey) && (bool) $hasChildren
            ? $this->mobileChildRows($profile)
            : [];
        $selfAddresses = $this->mobileAddressRows($profile, 'self');
        $parentsAddresses = $this->mobileAddressRows($profile, 'parents');
        $siblings = $this->mobileSiblingRows($profile);
        $relatives = $this->mobileRelativeRows($profile);
        $allianceNetworks = $this->mobileAllianceNetworkRows($profile);

        $base = $profile->toArray();
        $parity = [
            'gender' => $profile->gender?->key,
            'gender_id' => $profile->gender_id,
            'caste_id' => $profile->caste_id,
            'sub_caste_id' => $profile->sub_caste_id,
            'location_id' => $profile->location_id,
            'address_line' => $profile->address_line,
            'country_id' => $geo['country_id'],
            'state_id' => $geo['state_id'],
            'district_id' => $geo['district_id'],
            'taluka_id' => $hints['taluka_id'] !== '' ? (int) $hints['taluka_id'] : null,
            'height_cm' => $profile->height_cm,
            'weight_kg' => $profile->weight_kg,
            'birth_time' => $profile->birth_time,
            'birth_city_id' => $profile->birth_city_id,
            'birth_place_text' => $profile->birth_place_text,
            'birth_place_label' => $birthPlaceLabel !== '' ? $birthPlaceLabel : null,
            'religion_id' => $profile->religion_id,
            'religion_label' => $profile->getRelation('religion')?->display_label,
            'caste_label' => $profile->getRelation('caste')?->display_label,
            'sub_caste_label' => $profile->getRelation('subCaste')?->display_label,
            'location_label' => $locationLabel !== '' ? $locationLabel : null,
            'mother_tongue_id' => $profile->mother_tongue_id,
            'mother_tongue_label' => $this->masterLookupLabel($profile->getRelation('motherTongue')),
            'marital_status_id' => $profile->marital_status_id,
            'marital_status_key' => $profile->maritalStatus?->key,
            'marital_status_label' => $this->masterLookupLabel($profile->getRelation('maritalStatus')),
            'has_children' => $hasChildren,
            'marriages' => $marriages,
            'children' => $children,
            'self_addresses' => $selfAddresses,
            'parents_addresses' => $parentsAddresses,
            'occupation_title' => $profile->occupation_title,
            'annual_income' => $profile->annual_income,
            'income_period' => $profile->income_period,
            'income_value_type' => $profile->income_value_type,
            'income_amount' => $profile->income_amount,
            'income_min_amount' => $profile->income_min_amount,
            'income_max_amount' => $profile->income_max_amount,
            'income_currency_id' => $profile->income_currency_id,
            'income_currency_code' => $incomeCurrency?->code,
            'income_currency_symbol' => $incomeCurrency?->displaySymbol(),
            'income_currency_label' => $this->incomeCurrencyDisplayLabel($incomeCurrency),
            'income_private' => (bool) ($profile->income_private ?? false),
            'income_display_label' => $this->mobileIncomeDisplayLabel($profile, 'income', $incomeCurrency),
            'family_type_id' => $profile->family_type_id,
            'complexion_id' => $profile->complexion_id,
            'complexion_label' => $this->masterLookupLabel($profile->getRelation('complexion')),
            'blood_group_id' => $profile->blood_group_id,
            'blood_group_label' => $this->masterLookupLabel($profile->getRelation('bloodGroup')),
            'physical_build_id' => $profile->physical_build_id,
            'physical_build_label' => $this->masterLookupLabel($profile->getRelation('physicalBuild')),
            'spectacles_lens' => $profile->spectacles_lens,
            'physical_condition' => $profile->physical_condition,
            'diet_id' => $profile->diet_id,
            'diet_label' => $this->masterLookupLabel($profile->getRelation('diet')),
            'smoking_status_id' => $profile->smoking_status_id,
            'smoking_status_label' => $this->masterLookupLabel($profile->getRelation('smokingStatus')),
            'drinking_status_id' => $profile->drinking_status_id,
            'drinking_status_label' => $this->masterLookupLabel($profile->getRelation('drinkingStatus')),
            'occupation_master_id' => $profile->occupation_master_id,
            'occupation_master_label' => $this->masterLookupLabel($profile->getRelation('occupationMaster')),
            'occupation_custom_id' => $profile->occupation_custom_id,
            'occupation_custom_label' => $this->masterLookupLabel($profile->getRelation('occupationCustom')),
            'company_name' => $profile->company_name,
            'work_location_text' => $profile->work_location_text,
            'work_location_label' => trim($profile->workLocationDisplayLine()) ?: null,
            'father_name' => $profile->father_name,
            'father_occupation' => $profile->father_occupation,
            'father_occupation_master_id' => $profile->father_occupation_master_id,
            'father_occupation_master_label' => $this->masterLookupLabel($profile->getRelation('fatherOccupationMaster')),
            'father_occupation_custom_id' => $profile->father_occupation_custom_id,
            'father_occupation_custom_label' => $this->masterLookupLabel($profile->getRelation('fatherOccupationCustom')),
            'father_extra_info' => $profile->father_extra_info,
            'father_contact_1' => $profile->father_contact_1,
            'father_contact_2' => $profile->father_contact_2,
            'mother_name' => $profile->mother_name,
            'mother_occupation' => $profile->mother_occupation,
            'mother_occupation_master_id' => $profile->mother_occupation_master_id,
            'mother_occupation_master_label' => $this->masterLookupLabel($profile->getRelation('motherOccupationMaster')),
            'mother_occupation_custom_id' => $profile->mother_occupation_custom_id,
            'mother_occupation_custom_label' => $this->masterLookupLabel($profile->getRelation('motherOccupationCustom')),
            'mother_extra_info' => $profile->mother_extra_info,
            'mother_contact_1' => $profile->mother_contact_1,
            'mother_contact_2' => $profile->mother_contact_2,
            'family_type_id' => $profile->family_type_id,
            'family_type_label' => $this->masterLookupLabel($profile->getRelation('familyType')),
            'family_status' => $profile->family_status,
            'family_values' => $profile->family_values,
            'family_income' => $profile->family_income,
            'family_income_period' => $profile->family_income_period,
            'family_income_value_type' => $profile->family_income_value_type,
            'family_income_amount' => $profile->family_income_amount,
            'family_income_min_amount' => $profile->family_income_min_amount,
            'family_income_max_amount' => $profile->family_income_max_amount,
            'family_income_currency_id' => $profile->family_income_currency_id,
            'family_income_currency_code' => $familyIncomeCurrency?->code,
            'family_income_currency_symbol' => $familyIncomeCurrency?->displaySymbol(),
            'family_income_currency_label' => $this->incomeCurrencyDisplayLabel($familyIncomeCurrency),
            'family_income_private' => (bool) ($profile->family_income_private ?? false),
            'family_income_display_label' => $this->mobileIncomeDisplayLabel($profile, 'family_income', $familyIncomeCurrency),
            'has_siblings' => $profile->has_siblings,
            'siblings' => $siblings,
            'relatives' => $relatives,
            'alliance_networks' => $allianceNetworks,
            'other_relatives_text' => $profile->other_relatives_text,
            'property_details' => $profile->property_details,
            'income_range_id' => $profile->income_range_id,
            'nakshatra_id' => $horoscope ? $horoscope->nakshatra_id : null,
            'nakshatra_label' => $this->masterLookupLabel($horoscope?->nakshatra),
            'rashi_id' => $horoscope ? $horoscope->rashi_id : null,
            'rashi_label' => $this->masterLookupLabel($horoscope?->rashi),
            'charan' => $horoscope ? $horoscope->charan : null,
            'gan_id' => $horoscope ? $horoscope->gan_id : null,
            'gan_label' => $this->masterLookupLabel($horoscope?->gan),
            'nadi_id' => $horoscope ? $horoscope->nadi_id : null,
            'nadi_label' => $this->masterLookupLabel($horoscope?->nadi),
            'yoni_id' => $horoscope ? $horoscope->yoni_id : null,
            'yoni_label' => $this->masterLookupLabel($horoscope?->yoni),
            'varna_id' => $horoscope ? $horoscope->varna_id : null,
            'varna_label' => $this->masterTableLookupLabel('master_varnas', $horoscope?->varna_id),
            'vashya_id' => $horoscope ? $horoscope->vashya_id : null,
            'vashya_label' => $this->masterTableLookupLabel('master_vashyas', $horoscope?->vashya_id),
            'rashi_lord_id' => $horoscope ? $horoscope->rashi_lord_id : null,
            'rashi_lord_label' => $this->masterTableLookupLabel('master_rashi_lords', $horoscope?->rashi_lord_id),
            'mangal_dosh_type_id' => $horoscope ? $horoscope->mangal_dosh_type_id : null,
            'mangal_dosh_type_label' => $this->masterLookupLabel($horoscope?->mangalDoshType),
            'devak' => $horoscope ? $horoscope->devak : null,
            'kul' => $horoscope ? $horoscope->kul : null,
            'gotra' => $horoscope ? $horoscope->gotra : null,
            'navras_name' => $horoscope ? $horoscope->navras_name : null,
            'birth_weekday' => $horoscope ? $horoscope->birth_weekday : null,
            'narrative_about_me' => $this->profileNarrativeAboutMe($profile),
            'narrative_expectations' => $this->profileNarrativeExpectations($profile),
            'preferred_age_min' => $criteria?->preferred_age_min,
            'preferred_age_max' => $criteria?->preferred_age_max,
            'preferred_height_min_cm' => $criteria?->preferred_height_min_cm,
            'preferred_height_max_cm' => $criteria?->preferred_height_max_cm,
            'preferred_income_min' => $this->numericPreferenceValue($criteria?->preferred_income_min),
            'preferred_income_max' => $this->numericPreferenceValue($criteria?->preferred_income_max),
            'preferred_income_label' => $this->incomePreferenceLabel($criteria?->preferred_income_min, $criteria?->preferred_income_max),
            'marriage_type_preference_id' => $criteria?->marriage_type_preference_id,
            'marriage_type_preference_label' => $this->masterLookupLabel($criteria?->marriageTypePreference),
            'partner_profile_with_children' => $criteria?->partner_profile_with_children,
            'partner_profile_with_children_label' => $this->partnerProfileWithChildrenLabel($criteria?->partner_profile_with_children),
            'preferred_profile_managed_by' => $criteria?->preferred_profile_managed_by,
            'preferred_profile_managed_by_label' => $this->preferredProfileManagedByLabel($criteria?->preferred_profile_managed_by),
            'willing_to_relocate' => $criteria?->willing_to_relocate,
            'preferred_marital_status_id' => $criteria?->preferred_marital_status_id,
            'preferred_marital_status_label' => $this->masterLookupLabel($criteria?->preferredMaritalStatus),
            'preferred_marital_status_ids' => $preferredMaritalStatusIds,
            'preferred_marital_status_labels' => $this->masterTableLabelsByIds('master_marital_statuses', $preferredMaritalStatusIds),
            'preferred_diet_ids' => $preferredDietIds,
            'preferred_diet_labels' => $this->masterTableLabelsByIds('master_diets', $preferredDietIds),
            'preferred_religion_ids' => $preferredReligionIds,
            'preferred_religion_labels' => $this->masterTableLabelsByIds('master_religions', $preferredReligionIds),
            'preferred_caste_ids' => $preferredCasteIds,
            'preferred_caste_labels' => $this->masterTableLabelsByIds('master_castes', $preferredCasteIds),
            'preferred_mother_tongue_ids' => $preferredMotherTongueIds,
            'preferred_mother_tongue_labels' => $this->masterTableLabelsByIds('master_mother_tongues', $preferredMotherTongueIds),
            'preferred_intercaste' => $preferredIntercaste,
            'preferred_education_degree_ids' => $preferredEducationDegreeIds,
            'preferred_education_degree_labels' => $this->masterTableLabelsByIds('master_education', $preferredEducationDegreeIds),
            'preferred_occupation_master_ids' => $preferredOccupationMasterIds,
            'preferred_occupation_master_labels' => $this->masterTableLabelsByIds('master_occupations', $preferredOccupationMasterIds),
            'preferred_country_ids' => $preferredCountryIds,
            'preferred_state_ids' => $preferredStateIds,
            'preferred_district_ids' => $preferredDistrictIds,
            'preferred_taluka_ids' => $preferredTalukaIds,
            'partner_preferences' => $partnerPreferences,
            'partner_preference_suggestions' => $partnerPreferenceSuggestions,
        ];

        $profileData = array_merge($base, $parity);
        $profileData['parent_contact_max_slots'] = $this->mobileParentContactMaxSlots();
        foreach (['father_contact_3', 'mother_contact_3'] as $contactKey) {
            if (Schema::hasColumn('matrimony_profiles', $contactKey)) {
                $profileData[$contactKey] = $profile->getAttribute($contactKey);
            }
        }
        foreach ([
            'user',
            'contact_number',
            'primary_contact_number',
            'addresses',
        ] as $privateKey) {
            unset($profileData[$privateKey]);
        }

        if ($profile->photo_approved === false || ! $profile->profile_photo) {
            $profileData['profile_photo'] = null;
        }

        return $profileData;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mobileAddressRows(MatrimonyProfile $profile, string $scope): array
    {
        if (! Schema::hasTable('profile_addresses')
            || ! Schema::hasTable('master_address_types')
            || ! Schema::hasColumn('profile_addresses', 'address_scope')) {
            return [];
        }

        $locationColumn = Schema::hasColumn('profile_addresses', 'location_id')
            ? 'location_id'
            : (Schema::hasColumn('profile_addresses', 'city_id') ? 'city_id' : null);
        $select = [
            'pa.id',
            'pa.address_scope',
            'pa.address_type_id',
            'pa.address_line',
            'mat.key as address_type_key',
            'mat.label as address_type_label',
        ];
        if ($locationColumn !== null) {
            $select[] = DB::raw('pa.'.$locationColumn.' as location_id');
        }

        return DB::table('profile_addresses as pa')
            ->leftJoin('master_address_types as mat', 'mat.id', '=', 'pa.address_type_id')
            ->where('pa.profile_id', $profile->id)
            ->where('pa.address_scope', $scope)
            ->orderBy('pa.id')
            ->select($select)
            ->get()
            ->map(function ($row) use ($scope): array {
                $locationId = isset($row->location_id) && $row->location_id !== null ? (int) $row->location_id : null;
                $locationLabel = $locationId !== null && $locationId > 0
                    ? trim(MatrimonyProfile::residenceLocationDisplayLineFor((object) ['location_id' => $locationId]))
                    : '';
                $typeKey = is_string($row->address_type_key ?? null) && trim($row->address_type_key) !== ''
                    ? trim($row->address_type_key)
                    : ($scope === 'parents' ? 'permanent' : 'current');
                $typeLabel = is_string($row->address_type_label ?? null) && trim($row->address_type_label) !== ''
                    ? trim($row->address_type_label)
                    : ucfirst(str_replace('_', ' ', $typeKey));

                return [
                    'id' => (int) $row->id,
                    'address_scope' => (string) ($row->address_scope ?? $scope),
                    'address_type_id' => $row->address_type_id !== null ? (int) $row->address_type_id : null,
                    'address_type_key' => $typeKey,
                    'address_type_label' => $typeLabel,
                    'address_line' => isset($row->address_line) && trim((string) $row->address_line) !== '' ? trim((string) $row->address_line) : null,
                    'location_id' => $locationId,
                    'location_label' => $locationLabel !== '' ? $locationLabel : null,
                    'display' => $locationLabel !== '' ? $locationLabel : null,
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mobileMarriageRows(MatrimonyProfile $profile, ?string $statusKey): array
    {
        if (! $this->mobileMarriageDetailsAllowed($statusKey)) {
            return [];
        }

        $profile->loadMissing('marriages.maritalStatus');

        return $profile->marriages
            ->sortByDesc(fn ($marriage): int => (int) ($marriage->id ?? 0))
            ->take(1)
            ->values()
            ->map(function ($marriage) use ($statusKey): array {
                return [
                    'id' => $marriage->id !== null ? (int) $marriage->id : null,
                    'marital_status_id' => $marriage->marital_status_id !== null ? (int) $marriage->marital_status_id : null,
                    'marital_status_label' => $this->masterLookupLabel($marriage->maritalStatus),
                    'marriage_year' => $marriage->marriage_year !== null ? (int) $marriage->marriage_year : null,
                    'separation_year' => $statusKey === 'separated' && $marriage->separation_year !== null ? (int) $marriage->separation_year : null,
                    'divorce_year' => in_array($statusKey, ['divorced', 'annulled'], true) && $marriage->divorce_year !== null ? (int) $marriage->divorce_year : null,
                    'spouse_death_year' => $statusKey === 'widowed' && $marriage->spouse_death_year !== null ? (int) $marriage->spouse_death_year : null,
                    'divorce_status' => in_array($statusKey, ['divorced', 'annulled', 'separated'], true) ? $marriage->divorce_status : null,
                    'divorce_status_label' => in_array($statusKey, ['divorced', 'annulled', 'separated'], true) ? $this->mobileMarriageDivorceStatusLabel($marriage->divorce_status) : null,
                    'remarriage_reason' => null,
                    'notes' => null,
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mobileChildRows(MatrimonyProfile $profile): array
    {
        $profile->loadMissing('children.childLivingWith');

        return $profile->children
            ->sortBy(fn ($child): string => sprintf('%010d-%010d', (int) ($child->sort_order ?? 0), (int) ($child->id ?? 0)))
            ->values()
            ->map(function ($child): array {
                return [
                    'id' => $child->id !== null ? (int) $child->id : null,
                    'child_name' => $child->child_name,
                    'gender' => $child->gender,
                    'gender_label' => $this->mobileChildGenderLabel($child->gender),
                    'age' => $child->age !== null ? (int) $child->age : null,
                    'child_living_with_id' => $child->child_living_with_id !== null ? (int) $child->child_living_with_id : null,
                    'child_living_with_label' => $this->masterLookupLabel($child->childLivingWith),
                    'sort_order' => $child->sort_order !== null ? (int) $child->sort_order : 0,
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mobileSiblingRows(MatrimonyProfile $profile): array
    {
        $profile->loadMissing([
            'siblings.city',
            'siblings.occupationMaster',
            'siblings.occupationCustom',
        ]);

        return $profile->siblings
            ->sortBy(fn ($sibling): string => sprintf('%010d-%010d', (int) ($sibling->sort_order ?? 0), (int) ($sibling->id ?? 0)))
            ->values()
            ->map(function ($sibling): array {
                return [
                    'id' => $sibling->id !== null ? (int) $sibling->id : null,
                    'relation_type' => $sibling->relation_type,
                    'relation_type_label' => $this->siblingRelationTypeLabel($sibling->relation_type),
                    'name' => $sibling->name,
                    'marital_status' => $sibling->marital_status,
                    'marital_status_label' => $this->siblingMaritalStatusLabel($sibling->marital_status),
                    'occupation' => $sibling->occupation,
                    'occupation_master_id' => $sibling->occupation_master_id !== null ? (int) $sibling->occupation_master_id : null,
                    'occupation_master_label' => $this->masterLookupLabel($sibling->occupationMaster),
                    'occupation_custom_id' => $sibling->occupation_custom_id !== null ? (int) $sibling->occupation_custom_id : null,
                    'occupation_custom_label' => $this->masterLookupLabel($sibling->occupationCustom),
                    'city_id' => $sibling->city_id !== null ? (int) $sibling->city_id : null,
                    'city_label' => $this->masterLookupLabel($sibling->city),
                    'address_line' => $sibling->address_line,
                    'notes' => $sibling->notes,
                    'contact_number' => $sibling->contact_number,
                    'contact_number_2' => $sibling->contact_number_2,
                    'contact_number_3' => $sibling->contact_number_3,
                    'sort_order' => $sibling->sort_order !== null ? (int) $sibling->sort_order : 0,
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mobileRelativeRows(MatrimonyProfile $profile): array
    {
        $profile->loadMissing(['relatives']);

        return $profile->relatives
            ->sortBy(fn ($relative): int => (int) ($relative->id ?? 0))
            ->values()
            ->map(function ($relative): array {
                return [
                    'id' => $relative->id !== null ? (int) $relative->id : null,
                    'relation_type' => $relative->relation_type,
                    'relation_type_label' => $this->relativeRelationTypeLabel($relative->relation_type),
                    'relative_details' => $relative->relative_details,
                    'contact_number' => $relative->contact_number,
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mobileAllianceNetworkRows(MatrimonyProfile $profile): array
    {
        $profile->loadMissing([
            'allianceNetworks.city',
            'allianceNetworks.state',
            'allianceNetworks.district',
            'allianceNetworks.taluka',
        ]);

        return $profile->allianceNetworks
            ->sortBy(fn ($allianceNetwork): int => (int) ($allianceNetwork->id ?? 0))
            ->values()
            ->map(function ($allianceNetwork): array {
                return [
                    'id' => $allianceNetwork->id !== null ? (int) $allianceNetwork->id : null,
                    'surname' => $allianceNetwork->surname,
                    'city_id' => $allianceNetwork->city_id !== null ? (int) $allianceNetwork->city_id : null,
                    'city_label' => $this->masterLookupLabel($allianceNetwork->city),
                    'state_id' => $allianceNetwork->state_id !== null ? (int) $allianceNetwork->state_id : null,
                    'state_label' => $this->masterLookupLabel($allianceNetwork->state),
                    'district_id' => $allianceNetwork->district_id !== null ? (int) $allianceNetwork->district_id : null,
                    'district_label' => $this->masterLookupLabel($allianceNetwork->district),
                    'taluka_id' => $allianceNetwork->taluka_id !== null ? (int) $allianceNetwork->taluka_id : null,
                    'taluka_label' => $this->masterLookupLabel($allianceNetwork->taluka),
                    'notes' => $allianceNetwork->notes,
                ];
            })
            ->all();
    }

    private function relativeRelationTypeLabel(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::MOBILE_RELATIVE_RELATION_LABELS[$value] ?? null;
    }

    private function siblingRelationTypeLabel(?string $value): ?string
    {
        return match ($value) {
            'brother' => 'Brother',
            'sister' => 'Sister',
            'brother_wife' => "Brother's wife",
            'sister_husband' => "Sister's husband",
            default => null,
        };
    }

    private function siblingMaritalStatusLabel(?string $value): ?string
    {
        return match ($value) {
            'married' => 'Married',
            'unmarried' => 'Unmarried',
            default => null,
        };
    }

    private function mobileMarriageDivorceStatusLabel(?string $value): ?string
    {
        return match ($value) {
            'pending' => 'Pending',
            'finalized' => 'Finalized',
            'mutual' => 'Mutual',
            'contested' => 'Contested',
            default => null,
        };
    }

    private function mobileChildGenderLabel(?string $value): ?string
    {
        return match ($value) {
            'male' => 'Male',
            'female' => 'Female',
            'other' => 'Other',
            'prefer_not_say' => 'Prefer not to say',
            default => null,
        };
    }

    private function profileNarrativeAboutMe(MatrimonyProfile $profile): ?string
    {
        if (! Schema::hasTable('profile_extended_attributes')) {
            return null;
        }

        $value = DB::table('profile_extended_attributes')
            ->where('profile_id', $profile->id)
            ->value('narrative_about_me');
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }

    private function profileNarrativeExpectations(MatrimonyProfile $profile): ?string
    {
        if (! Schema::hasTable('profile_extended_attributes')) {
            return null;
        }

        $value = DB::table('profile_extended_attributes')
            ->where('profile_id', $profile->id)
            ->value('narrative_expectations');
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }

    /**
     * @return array<int, int>
     */
    private function partnerPreferencePivotIds(string $table, string $column, int $profileId): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)
            ->where('profile_id', $profileId)
            ->orderBy($column)
            ->pluck($column)
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    private function masterTableLabelsByIds(string $table, array $ids): array
    {
        $labels = [];
        foreach ($ids as $id) {
            $label = $this->masterTableLookupLabel($table, $id);
            if ($label !== null) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    private function partnerProfileWithChildrenLabel(?string $value): ?string
    {
        return match ($value) {
            'no' => __('wizard.partner_children_no'),
            'yes_if_live_separate' => __('wizard.partner_children_yes_if_live_separate'),
            'yes' => __('wizard.partner_children_yes'),
            default => null,
        };
    }

    private function preferredProfileManagedByLabel(?string $value): ?string
    {
        return match ($value) {
            'self' => __('onboarding.registering_for_self'),
            'parent_guardian' => __('onboarding.registering_for_parent_guardian'),
            'sibling' => __('onboarding.registering_for_sibling'),
            'relative' => __('onboarding.registering_for_relative'),
            'friend' => __('onboarding.registering_for_friend'),
            'other' => __('onboarding.registering_for_other'),
            default => null,
        };
    }

    private function incomePreferenceLabel(mixed $min, mixed $max): ?string
    {
        if ($min === null && $max === null) {
            return null;
        }

        $format = static function (mixed $value): string {
            if ($value === null || $value === '') {
                return 'Any';
            }

            return '₹'.number_format((float) $value, 0);
        };

        return $format($min).' - '.$format($max);
    }

    private function numericPreferenceValue(mixed $value): int|float|null
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return floor($float) === $float ? (int) $float : $float;
    }

    private function incomeCurrencyDisplayLabel(?MasterIncomeCurrency $currency): ?string
    {
        if (! $currency) {
            return null;
        }

        return trim($currency->displaySymbol().' '.($currency->code ?? '')) ?: null;
    }

    private function mobileIncomeDisplayLabel(MatrimonyProfile $profile, string $prefix, ?MasterIncomeCurrency $currency): ?string
    {
        $display = app(IncomeEngineService::class)->formatForDisplay($profile->toArray(), $prefix, $currency);
        $display = trim($display);

        return $display !== '' && strcasecmp($display, 'Not disclosed') !== 0 ? $display : null;
    }

    /**
     * @param  array<string, mixed>  $profileData
     */
    private function forgetIncomePayloadKeys(array &$profileData, string $prefix): void
    {
        unset($profileData[$prefix]);

        foreach ([
            $prefix.'_period',
            $prefix.'_value_type',
            $prefix.'_amount',
            $prefix.'_min_amount',
            $prefix.'_max_amount',
            $prefix.'_currency_id',
            $prefix.'_currency_code',
            $prefix.'_currency_symbol',
            $prefix.'_currency_label',
            $prefix.'_display_label',
            $prefix.'_normalized_annual_amount',
        ] as $key) {
            unset($profileData[$key]);
        }
    }

    /**
     * @param  array<string, mixed>  $profileData
     */
    private function forgetParentContactPayloadKeys(array &$profileData): void
    {
        foreach ([
            'father_contact_1',
            'father_contact_2',
            'father_contact_3',
            'mother_contact_1',
            'mother_contact_2',
            'mother_contact_3',
            'parent_contact_max_slots',
        ] as $key) {
            unset($profileData[$key]);
        }
    }

    /**
     * @param  array<string, mixed>  $profileData
     */
    private function sanitizeAllianceNetworkRowsForOtherProfile(array &$profileData): void
    {
        $this->forgetPrivateRowKeys($profileData, 'alliance_networks', [
            'notes', 'contact_number', 'contact_number_2', 'contact_number_3',
            'phone_number', 'mobile_number', 'primary_contact_number',
        ]);
    }

    /**
     * Contact numbers on sub-records are owner/authorized-agent data. They are
     * editable (approved 2026-07-21) but must never reach a viewer looking at
     * someone else's profile — that scoping IS the privacy control.
     */
    private function sanitizeSubRecordContactNumbersForOtherProfile(array &$profileData): void
    {
        $contactKeys = [
            'contact_number', 'contact_number_2', 'contact_number_3',
            'phone_number', 'mobile_number', 'primary_contact_number',
        ];

        foreach (['siblings', 'relatives', 'marriages', 'children'] as $collection) {
            $this->forgetPrivateRowKeys($profileData, $collection, $contactKeys);
        }
    }

    /**
     * @param  array<int, string>  $privateKeys
     */
    private function forgetPrivateRowKeys(array &$profileData, string $collection, array $privateKeys): void
    {
        if (! isset($profileData[$collection]) || ! is_array($profileData[$collection])) {
            return;
        }

        $profileData[$collection] = array_values(array_map(static function (mixed $row) use ($privateKeys): mixed {
            if (! is_array($row)) {
                return $row;
            }

            foreach ($privateKeys as $privateKey) {
                unset($row[$privateKey]);
            }

            return $row;
        }, $profileData[$collection]));
    }

    private function masterTableLookupLabel(string $table, mixed $id): ?string
    {
        if ($id === null || $id === '' || ! Schema::hasTable($table)) {
            return null;
        }

        $row = DB::table($table)->where('id', (int) $id)->first();
        if (! $row) {
            return null;
        }

        foreach (['display_label', 'label_mr', 'label_en', 'label', 'name', 'name_mr', 'code', 'code_mr', 'full_form', 'raw_name', 'key'] as $key) {
            if (property_exists($row, $key)) {
                $value = trim((string) ($row->{$key} ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function masterLookupLabel(mixed $row): ?string
    {
        if (! $row) {
            return null;
        }

        foreach (['display_label', 'label_mr', 'label_en', 'label', 'name', 'name_mr', 'code', 'code_mr', 'full_form', 'raw_name', 'key'] as $key) {
            $value = trim((string) ($row->getAttribute($key) ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

}
