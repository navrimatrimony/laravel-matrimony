<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\QuotaPolicySourceViolation;
use App\Http\Controllers\Controller;
use App\Models\Caste;
use App\Models\EducationDegree;
use App\Models\Location;
use App\Models\MatrimonyProfile;
use App\Models\SubCaste;
use App\Models\User;
use App\Services\Api\MobileDiscoveryFilterService;
use App\Services\Api\MobileMoreMatchesSectionService;
use App\Services\Api\MobileProfileDisplayPresenter;
use App\Services\EducationService;
use App\Services\Matching\MatchingService;
use App\Services\MutationService;
use App\Services\OccupationService;
use App\Services\PartnerPreferenceSuggestionService;
use App\Services\PartnerPreferenceSnapshotBuilder;
use App\Services\Parsing\IntakeControlledFieldNormalizer;
use App\Services\ProfileFieldLockService;
use App\Services\ProfileRotationService;
use App\Services\ViewTrackingService;
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
        'marriage_type_preference_id',
        'partner_profile_with_children',
        'preferred_profile_managed_by',
        'willing_to_relocate',
        'preferred_marital_status_ids',
        'preferred_diet_ids',
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
            'has_siblings',
            'other_relatives_text',
            'property_details',
        ];
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

        return $snapshot;
    }

    private function requestInputKeyExists(Request $request, string $key): bool
    {
        return array_key_exists($key, $request->all());
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

    private function validateMobileProfileRequest(Request $request, bool $creating): void
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
            'has_siblings' => ['nullable', 'boolean'],
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
        ];

        if (! $creating) {
            $rules['address_line'] = ['nullable', 'string', 'max:255'];
        }

        $validator = Validator::make($request->all(), $rules);
        $validator->after(function ($validator) use ($request): void {
            $religionId = $request->input('religion_id');
            $casteId = $request->input('caste_id');
            $subCasteId = $request->input('sub_caste_id');
            $occupationMasterId = $request->input('occupation_master_id');
            $occupationCustomId = $request->input('occupation_custom_id');
            $fatherOccupationMasterId = $request->input('father_occupation_master_id');
            $fatherOccupationCustomId = $request->input('father_occupation_custom_id');
            $motherOccupationMasterId = $request->input('mother_occupation_master_id');
            $motherOccupationCustomId = $request->input('mother_occupation_custom_id');

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

        $profileData = $this->buildGovernanceParityProfilePayload($profile->fresh(['user', 'horoscope', 'preferenceCriteria']));

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

        $profileData = $this->buildGovernanceParityProfilePayload($profile);

        return response()->json([
            'success' => true,
            'profile' => $profileData,
            'display' => app(MobileProfileDisplayPresenter::class)->forProfile($profile, $user),
        ]);
    }

    /**
     * Update matrimony profile for logged-in user
     */
    public function update(Request $request)
    {
        // Phase-4 Day-8: Location hierarchy validation
        $this->validateMobileProfileRequest($request, creating: false);

        $user = $request->user();

        $profile = MatrimonyProfile::where('user_id', $user->id)->first();

        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found',
            ], 404);
        }

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
            $changedFields = $this->lockKeysForMobileSnapshot($snapshot['core']);
            ProfileFieldLockService::removeActorOwnedLocks($profile, $changedFields, $user);
            app(MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $user->id, 'manual');
            if (! empty($changedFields)) {
                ProfileFieldLockService::applyLocks($profile, $changedFields, 'CORE', $user);
            }
        }

        $profileData = $this->buildGovernanceParityProfilePayload($profile->fresh(['user', 'horoscope', 'preferenceCriteria']));

        return response()->json([
            'success' => true,
            'message' => 'Matrimony profile updated',
            'profile' => $profileData,
        ]);
    }

    private function mobileSnapshotHasWritableData(array $snapshot): bool
    {
        foreach ($snapshot as $value) {
            if (is_array($value) && $value !== []) {
                return true;
            }
        }

        return false;
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
                'created_at' => $profile->created_at,
                'updated_at' => $profile->updated_at,
                'display' => $presenter->forListCard($profile, $viewer),
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
        // Soft deletes are automatically excluded by Laravel's SoftDeletes trait.
        if ($request->filled('caste')) {
            $query->where('caste', $request->caste);
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
        $birthPlaceLabel = trim($profile->birthLocationDisplayLine());
        if ($birthPlaceLabel === '') {
            $birthPlaceLabel = trim((string) ($profile->birth_place_text ?? ''));
        }

        $base = $profile->toArray();
        $parity = [
            'gender' => $profile->gender?->key,
            'gender_id' => $profile->gender_id,
            'caste_id' => $profile->caste_id,
            'sub_caste_id' => $profile->sub_caste_id,
            'location_id' => $profile->location_id,
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
            'marital_status_label' => $this->masterLookupLabel($profile->getRelation('maritalStatus')),
            'has_children' => $profile->has_children,
            'occupation_title' => $profile->occupation_title,
            'annual_income' => $profile->annual_income,
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
            'mother_name' => $profile->mother_name,
            'mother_occupation' => $profile->mother_occupation,
            'mother_occupation_master_id' => $profile->mother_occupation_master_id,
            'mother_occupation_master_label' => $this->masterLookupLabel($profile->getRelation('motherOccupationMaster')),
            'mother_occupation_custom_id' => $profile->mother_occupation_custom_id,
            'mother_occupation_custom_label' => $this->masterLookupLabel($profile->getRelation('motherOccupationCustom')),
            'mother_extra_info' => $profile->mother_extra_info,
            'family_type_id' => $profile->family_type_id,
            'family_type_label' => $this->masterLookupLabel($profile->getRelation('familyType')),
            'family_status' => $profile->family_status,
            'family_values' => $profile->family_values,
            'has_siblings' => $profile->has_siblings,
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
            'partner_preferences' => $partnerPreferences,
            'partner_preference_suggestions' => $partnerPreferenceSuggestions,
        ];

        $profileData = array_merge($base, $parity);
        foreach ([
            'user',
            'contact_number',
            'primary_contact_number',
            'father_contact_1',
            'father_contact_2',
            'father_contact_3',
            'mother_contact_1',
            'mother_contact_2',
            'mother_contact_3',
        ] as $privateKey) {
            unset($profileData[$privateKey]);
        }

        if ($profile->photo_approved === false || ! $profile->profile_photo) {
            $profileData['profile_photo'] = null;
        }

        return $profileData;
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

    private function masterTableLookupLabel(string $table, mixed $id): ?string
    {
        if ($id === null || $id === '' || ! Schema::hasTable($table)) {
            return null;
        }

        $row = DB::table($table)->where('id', (int) $id)->first();
        if (! $row) {
            return null;
        }

        foreach (['display_label', 'label_mr', 'label_en', 'label', 'name', 'raw_name', 'key'] as $key) {
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

        foreach (['display_label', 'label_mr', 'label_en', 'label', 'name', 'raw_name', 'key'] as $key) {
            $value = trim((string) ($row->getAttribute($key) ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

}
