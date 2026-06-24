<?php

namespace App\Services\Onboarding;

use App\Models\Caste;
use App\Models\EducationDegree;
use App\Models\Location;
use App\Models\MatrimonyProfile;
use App\Models\SubCaste;
use App\Models\User;
use App\Services\EducationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MobileProfileStepSnapshotService
{
    public const PROFILE_STEPS = [
        'profile_for_whom',
        'basic_info',
        'religion_caste',
        'location',
        'education',
        'career',
        'lifestyle',
        'family',
    ];

    private const FORBIDDEN_PHASE_2_KEYS = [
        'mother_tongue',
        'mother_tongue_id',
        'family_type',
        'family_type_id',
        'horoscope',
        'astrology',
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
        'biodata',
        'biodata_file',
        'ocr',
    ];

    private const STEP_FIELDS = [
        'profile_for_whom' => ['profile_for_whom'],
        'basic_info' => [
            'full_name',
            'gender_id',
            'date_of_birth',
            'height_cm',
            'marital_status_id',
            'has_children',
            'children',
            'children_count',
            'children_living_with',
            'children_living_with_id',
        ],
        'religion_caste' => [
            'religion_id',
            'caste_id',
            'sub_caste_id',
            'same_religion_expected',
            'same_caste_expected',
            'same_sub_caste_required',
        ],
        'location' => [
            'location_id',
            'address_line',
        ],
        'education' => [
            'education_slots',
            'education_degree_ids',
        ],
        'career' => [
            'working_with',
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
        ],
        'lifestyle' => [
            'diet_id',
            'smoking_status_id',
            'drinking_status_id',
        ],
        'family' => [
            'father_name',
            'father_occupation_master_id',
            'father_occupation_custom_id',
            'father_extra_info',
            'mother_name',
            'mother_occupation_master_id',
            'mother_occupation_custom_id',
            'mother_extra_info',
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
        ],
    ];

    public function __construct(private readonly MobileOnboardingDraftService $draftService) {}

    public function buildSnapshot(string $step, array $data, User $user, ?MatrimonyProfile $profile = null): array
    {
        $data = $this->validatedData($step, $data, $user);
        $data = $this->applyDependentClears($step, $data);

        if ($step === 'profile_for_whom') {
            return [];
        }

        if ($step === 'education') {
            return $this->educationSnapshot($data);
        }

        $core = [];
        foreach (self::STEP_FIELDS[$step] ?? [] as $field) {
            if (in_array($field, [
                'same_religion_expected',
                'same_caste_expected',
                'same_sub_caste_required',
                'working_with',
                'children',
                'children_count',
                'children_living_with',
                'children_living_with_id',
            ], true)) {
                continue;
            }
            if (array_key_exists($field, $data)) {
                $core[$field] = $data[$field] === '' ? null : $data[$field];
            }
        }

        $snapshot = ['core' => $core];
        if ($step === 'basic_info' && $this->draftService->isNeverMarriedValue($data['marital_status_id'] ?? null)) {
            $snapshot['core']['has_children'] = false;
            $snapshot['children'] = [];
            $snapshot['marriages'] = [];
        }

        return $this->stripEmptyCore($snapshot);
    }

    public function validatedData(string $step, array $data, User $user): array
    {
        if (! in_array($step, self::PROFILE_STEPS, true)) {
            throw ValidationException::withMessages([
                'step' => 'Unsupported onboarding profile step.',
            ]);
        }

        $this->rejectForbiddenKeys($data);
        $this->rejectUnexpectedKeys($step, $data);

        $rules = $this->rulesFor($step, $user);
        $validator = Validator::make($data, $rules);
        $validator->after(function ($validator) use ($step, $data): void {
            if ($step === 'religion_caste') {
                $this->validateReligionCasteDependency($validator, $data);
            }
            if ($step === 'education') {
                $this->validateEducationSlots($validator, $data);
            }
            if ($step === 'career') {
                $this->validateCareer($validator, $data);
            }
            if ($step === 'family') {
                $this->validateFamily($validator, $data);
            }
        });

        return $validator->validate();
    }

    private function rulesFor(string $step, User $user): array
    {
        $locationTable = Location::geoTable();
        $rules = [
            'profile_for_whom' => [
                'profile_for_whom' => ['required', 'string', Rule::in(MobileOnboardingDraftService::PROFILE_FOR_WHOM_VALUES)],
            ],
            'basic_info' => [
                'full_name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'gender_id' => ['sometimes', 'nullable', 'integer', Rule::exists('master_genders', 'id')->where('is_active', true)],
                'date_of_birth' => ['sometimes', 'nullable', 'date'],
                'height_cm' => ['sometimes', 'nullable', 'integer', 'min:50', 'max:250'],
                'marital_status_id' => ['sometimes', 'nullable', 'integer', Rule::exists('master_marital_statuses', 'id')->where('is_active', true)],
                'has_children' => ['sometimes', 'nullable', 'boolean'],
                'children' => ['sometimes', 'nullable', 'array', 'max:20'],
                'children_count' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:20'],
                'children_living_with' => ['sometimes', 'nullable', 'string', 'max:64'],
                'children_living_with_id' => ['sometimes', 'nullable', 'integer'],
            ],
            'religion_caste' => [
                'religion_id' => ['sometimes', 'nullable', 'integer', Rule::exists('master_religions', 'id')->where('is_active', true)],
                'caste_id' => ['sometimes', 'nullable', 'integer', Rule::exists('master_castes', 'id')->where('is_active', true)],
                'sub_caste_id' => ['sometimes', 'nullable', 'integer', Rule::exists('master_sub_castes', 'id')->where('is_active', true)],
                'same_religion_expected' => ['sometimes', 'nullable', 'boolean'],
                'same_caste_expected' => ['sometimes', 'nullable', 'boolean'],
                'same_sub_caste_required' => ['sometimes', 'nullable', 'boolean'],
            ],
            'location' => [
                'location_id' => ['sometimes', 'nullable', 'integer', 'exists:'.$locationTable.',id'],
                'address_line' => ['sometimes', 'nullable', 'string', 'max:255'],
            ],
            'education' => [
                'education_slots' => ['sometimes', 'nullable'],
                'education_degree_ids' => ['sometimes', 'nullable', 'array', 'max:20'],
                'education_degree_ids.*' => ['integer', Rule::exists('master_education', 'id')],
            ],
            'career' => [
                'working_with' => ['sometimes', 'nullable', 'string', 'max:64'],
                'occupation_master_id' => ['sometimes', 'nullable', 'integer', Rule::exists('master_occupations', 'id')],
                'occupation_custom_id' => [
                    'sometimes',
                    'nullable',
                    'integer',
                    Rule::exists('master_occupation_custom', 'id')->where(fn ($query) => $query->where('user_id', $user->id)),
                ],
                'company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'work_location_text' => ['sometimes', 'nullable', 'string', 'max:255'],
                'annual_income' => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'income_period' => ['sometimes', 'nullable', Rule::in(['annual', 'monthly', 'weekly', 'daily'])],
                'income_value_type' => ['sometimes', 'nullable', Rule::in(['exact', 'approximate', 'range', 'undisclosed'])],
                'income_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'income_min_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'income_max_amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'gte:income_min_amount'],
                'income_currency_id' => ['sometimes', 'nullable', 'integer', Rule::exists('master_income_currencies', 'id')->where('is_active', true)],
                'income_private' => ['sometimes', 'nullable', 'boolean'],
            ],
            'lifestyle' => [
                'diet_id' => ['sometimes', 'nullable', 'integer', Rule::exists('master_diets', 'id')->where('is_active', true)],
                'smoking_status_id' => ['sometimes', 'nullable', 'integer', Rule::exists('master_smoking_statuses', 'id')->where('is_active', true)],
                'drinking_status_id' => ['sometimes', 'nullable', 'integer', Rule::exists('master_drinking_statuses', 'id')->where('is_active', true)],
            ],
            'family' => [
                'father_name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'father_occupation_master_id' => ['sometimes', 'nullable', 'integer', Rule::exists('master_occupations', 'id')],
                'father_occupation_custom_id' => [
                    'sometimes',
                    'nullable',
                    'integer',
                    Rule::exists('master_occupation_custom', 'id')->where(fn ($query) => $query->where('user_id', $user->id)),
                ],
                'father_extra_info' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'mother_name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'mother_occupation_master_id' => ['sometimes', 'nullable', 'integer', Rule::exists('master_occupations', 'id')],
                'mother_occupation_custom_id' => [
                    'sometimes',
                    'nullable',
                    'integer',
                    Rule::exists('master_occupation_custom', 'id')->where(fn ($query) => $query->where('user_id', $user->id)),
                ],
                'mother_extra_info' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'family_status' => ['sometimes', 'nullable', 'string', 'max:64'],
                'family_values' => ['sometimes', 'nullable', 'string', 'max:64'],
                'family_income' => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'family_income_period' => ['sometimes', 'nullable', Rule::in(['annual', 'monthly', 'weekly', 'daily'])],
                'family_income_value_type' => ['sometimes', 'nullable', Rule::in(['exact', 'approximate', 'range', 'undisclosed'])],
                'family_income_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'family_income_min_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'family_income_max_amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'gte:family_income_min_amount'],
                'family_income_currency_id' => ['sometimes', 'nullable', 'integer', Rule::exists('master_income_currencies', 'id')->where('is_active', true)],
                'family_income_private' => ['sometimes', 'nullable', 'boolean'],
            ],
        ];

        return $rules[$step] ?? [];
    }

    private function rejectForbiddenKeys(array $data): void
    {
        foreach (self::FORBIDDEN_PHASE_2_KEYS as $key) {
            if (array_key_exists($key, $data)) {
                throw ValidationException::withMessages([
                    $key => 'This field is not accepted in onboarding Phase 2.',
                ]);
            }
        }

        foreach (['highest_education', 'education_custom', 'occupation', 'occupation_title', 'father_occupation', 'mother_occupation'] as $key) {
            if (array_key_exists($key, $data)) {
                throw ValidationException::withMessages([
                    $key => 'Direct custom education or occupation text is not accepted in onboarding Phase 2.',
                ]);
            }
        }
    }

    private function rejectUnexpectedKeys(string $step, array $data): void
    {
        $allowed = array_flip(self::STEP_FIELDS[$step] ?? []);
        foreach (array_keys($data) as $key) {
            if (! is_string($key) || ! isset($allowed[$key])) {
                throw ValidationException::withMessages([
                    $key => 'This field is not supported for this onboarding step.',
                ]);
            }
        }
    }

    private function validateReligionCasteDependency($validator, array $data): void
    {
        $religionId = $data['religion_id'] ?? null;
        $casteId = $data['caste_id'] ?? null;
        $subCasteId = $data['sub_caste_id'] ?? null;

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
    }

    private function validateEducationSlots($validator, array $data): void
    {
        if (! array_key_exists('education_slots', $data) || $data['education_slots'] === null || $data['education_slots'] === '') {
            return;
        }

        $slots = $this->decodeEducationSlots($data['education_slots']);
        if ($slots === null) {
            $validator->errors()->add('education_slots', 'The education slots field must be valid JSON.');
            return;
        }

        $degreeIds = [];
        foreach ($slots as $index => $slot) {
            if (! is_array($slot)) {
                $validator->errors()->add('education_slots', 'The education slots field must contain objects only.');
                continue;
            }
            $type = $slot['t'] ?? $slot['type'] ?? null;
            $type = $type === 'degree' ? 'd' : $type;
            if ($type !== 'd') {
                $validator->errors()->add('education_slots.'.$index, 'Custom education text is not accepted in onboarding Phase 2.');
                continue;
            }
            $id = (int) ($slot['id'] ?? 0);
            if ($id <= 0) {
                $validator->errors()->add('education_slots.'.$index.'.id', 'Education degree id is required.');
                continue;
            }
            $degreeIds[] = $id;
        }

        if ($degreeIds !== []) {
            $found = EducationDegree::query()->whereIn('id', $degreeIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
            foreach (array_diff($degreeIds, $found) as $missing) {
                $validator->errors()->add('education_slots', 'Invalid education degree id: '.$missing);
            }
        }
    }

    private function validateCareer($validator, array $data): void
    {
        if (($data['occupation_master_id'] ?? null) && ($data['occupation_custom_id'] ?? null)) {
            $validator->errors()->add('occupation_custom_id', 'Select either a listed occupation or a custom occupation, not both.');
        }
    }

    private function validateFamily($validator, array $data): void
    {
        if (($data['father_occupation_master_id'] ?? null) && ($data['father_occupation_custom_id'] ?? null)) {
            $validator->errors()->add('father_occupation_custom_id', 'Select either a listed father occupation or a custom father occupation, not both.');
        }
        if (($data['mother_occupation_master_id'] ?? null) && ($data['mother_occupation_custom_id'] ?? null)) {
            $validator->errors()->add('mother_occupation_custom_id', 'Select either a listed mother occupation or a custom mother occupation, not both.');
        }
    }

    private function applyDependentClears(string $step, array $data): array
    {
        if ($step === 'basic_info' && $this->draftService->isNeverMarriedValue($data['marital_status_id'] ?? null)) {
            $data['has_children'] = false;
        }
        return $data;
    }

    private function educationSnapshot(array $data): array
    {
        if (! array_key_exists('education_slots', $data) && ! array_key_exists('education_degree_ids', $data)) {
            return ['core' => []];
        }

        $requestData = [];
        if (array_key_exists('education_slots', $data)) {
            $requestData['education_slots'] = is_array($data['education_slots'])
                ? json_encode($data['education_slots'])
                : $data['education_slots'];
        } elseif (array_key_exists('education_degree_ids', $data)) {
            $requestData['education_degree_ids'] = $data['education_degree_ids'];
        }

        $request = Request::create('/api/v1/onboarding/profile/save-step', 'POST', $requestData);
        app(EducationService::class)->mergeMultiselectEducationIntoRequest($request);

        return $this->stripEmptyCore([
            'core' => [
                'highest_education' => $request->input('highest_education'),
            ],
        ]);
    }

    private function stripEmptyCore(array $snapshot): array
    {
        if (isset($snapshot['core']) && is_array($snapshot['core'])) {
            $snapshot['core'] = array_filter(
                $snapshot['core'],
                fn ($value): bool => $value !== '' && $value !== []
            );
        }

        return $snapshot;
    }

    private function decodeEducationSlots(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
