<?php

namespace App\Http\Controllers;

use App\Models\MasterMaritalStatus;
use App\Models\MatrimonyProfile;
use App\Models\Profession;
use App\Models\ProfileMarriage;
use App\Models\WorkingWithType;
use App\Services\AboutMeQuickTemplateService;
use App\Services\MutationService;
use App\Services\ProfileLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Card onboarding (steps 2–7).
 * Step 7 completion sends user to centralized photo upload page.
 * Finish: GET matrimony.onboarding.complete (from photo page when coming from onboarding).
 */
class OnboardingController extends Controller
{
    private const LAST_STEP = 7;

    public function show(int $step)
    {
        if ($step < 2 || $step > self::LAST_STEP) {
            abort(404);
        }
        $user = auth()->user();
        $profile = $this->ensureProfile($user);
        if (! $profile) {
            return redirect()->route('login');
        }
        if (! ProfileLifecycleService::isEditableForManual($profile)) {
            return redirect()->route('matrimony.profile.show', $profile->id)->with('error', __('wizard.profile_not_editable_current_state'));
        }

        $this->syncCardOnboardingResumeStep($profile, $step);

        $data = [
            'step' => $step,
            'profile' => $profile,
            'totalSteps' => self::LAST_STEP,
        ];

        switch ($step) {
            case 2:
                $data['genders'] = \App\Models\MasterGender::where('is_active', true)->whereIn('key', ['male', 'female'])->orderByRaw("CASE WHEN `key` = 'male' THEN 1 ELSE 2 END")->get();
                $maritalKeys = ['never_married', 'divorced', 'annulled', 'separated', 'widowed'];
                $data['maritalStatuses'] = MasterMaritalStatus::where('is_active', true)
                    ->whereIn('key', $maritalKeys)
                    ->get()
                    ->sortBy(fn ($s) => array_search($s->key, $maritalKeys, true) !== false ? array_search($s->key, $maritalKeys, true) : 999)
                    ->values();
                if ($data['maritalStatuses']->isEmpty()) {
                    $data['maritalStatuses'] = MasterMaritalStatus::where('is_active', true)->get();
                }
                $data['profileMarriages'] = \App\Models\ProfileMarriage::where('profile_id', $profile->id)->orderBy('id')->get();
                $data['profileChildren'] = \Illuminate\Support\Facades\DB::table('profile_children')
                    ->where('profile_id', $profile->id)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get();
                $livingKeys = ['with_parent', 'with_other_parent', 'joint', 'other'];
                $data['childLivingWithOptions'] = \App\Models\MasterChildLivingWith::where('is_active', true)
                    ->whereIn('key', $livingKeys)
                    ->get()
                    ->sortBy(fn ($o) => array_search($o->key, $livingKeys, true) !== false ? array_search($o->key, $livingKeys, true) : 999)
                    ->values();
                if ($data['childLivingWithOptions']->isEmpty()) {
                    $data['childLivingWithOptions'] = \App\Models\MasterChildLivingWith::where('is_active', true)->get();
                }
                break;
            case 3:
                $data['religions'] = \App\Models\Religion::where('is_active', true)->orderBy('label')->get(['id', 'label']);
                $data['profile'] = $profile->load(['religion', 'caste', 'subCaste']);
                break;
            case 4:
                $data['workingWithTypes'] = WorkingWithType::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
                $data['professions'] = Profession::where('is_active', true)->with('workingWithType')->orderBy('sort_order')->orderBy('name')->get();
                $data['currencies'] = \App\Models\MasterIncomeCurrency::where('is_active', true)->get();
                $data['educationExamples'] = __('onboarding.education_examples');
                $deg = $profile->highest_education
                    ? \App\Models\EducationDegree::where('code', $profile->highest_education)->with('category')->first()
                    : null;
                $data['selectedEducationCategory'] = $deg?->category?->name;
                break;
            case 5:
                break;
            case 6:
                $data['extendedAttrs'] = DB::table('profile_extended_attributes')
                    ->where('profile_id', $profile->id)
                    ->first();
                $profile->loadMissing(['user', 'religion', 'caste', 'profession']);
                $data['aboutMeQuickTemplates'] = app(AboutMeQuickTemplateService::class)
                    ->resolvedAboutTemplatesForProfile($profile);
                break;
            case 7:
                $this->loadStep7PartnerPreferenceData($data, $profile);
                break;
        }

        return view('matrimony.onboarding.show', $data);
    }

    public function complete(): RedirectResponse
    {
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        $profile = auth()->user()->matrimonyProfile;
        if ($profile) {
            $profile->forceFill(['card_onboarding_resume_step' => null])->saveQuietly();
        }

        session()->forget('wizard_minimal');

        if ($profile) {
            return redirect()->route('matrimony.profile.show', $profile->id)
                ->with('success', __('onboarding.all_set'));
        }

        return redirect()->route('matrimony.profiles.index')
            ->with('success', __('onboarding.all_set'));
    }

    public function store(Request $request, int $step): RedirectResponse
    {
        if ($step < 2 || $step > self::LAST_STEP) {
            abort(404);
        }
        $user = auth()->user();
        $profile = $this->ensureProfile($user);
        if (! $profile) {
            return redirect()->route('login');
        }
        if (! ProfileLifecycleService::isEditableForManual($profile)) {
            return redirect()->route('matrimony.profile.show', $profile->id)->with('error', __('wizard.profile_not_editable_current_state'));
        }

        $wizard = app(ProfileWizardController::class);

        try {
            DB::transaction(function () use ($request, $profile, $user, $wizard, $step): void {
                if ($step === 5) {
                    $this->hydratePhysicalAddressContext($request, $profile);
                    $snapshot = $wizard->buildOnboardingPhysicalAddressSnapshot($request, $profile);
                } else {
                    $snapshot = match ($step) {
                        2 => $this->snapshotStep2($request, $profile, $wizard),
                        3 => $this->snapshotStep3($request, $profile, $wizard),
                        4 => $this->snapshotStep4($request, $profile, $wizard),
                        6 => $wizard->buildSnapshotForSection($request, 'about-me', $profile),
                        7 => $this->snapshotStep7($request, $profile, $wizard),
                        default => null,
                    };
                }
                if ($snapshot === null) {
                    throw new \RuntimeException('Invalid onboarding step');
                }
                app(MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $user->id, 'manual');
                if ($step === 7) {
                    \App\Services\ProfilePartnerCommunityFlagService::syncIntercasteIntentFromRequest((int) $profile->id, $request);
                }
            });
        } catch (ValidationException $e) {
            $redirectStep = $this->onboardingStepForValidationErrors($e->errors(), $step);

            return redirect()->route('matrimony.onboarding.show', ['step' => $redirectStep])
                ->withErrors($e->errors())
                ->withInput();
        }

        if ($step === 7) {
            $profile->forceFill(['card_onboarding_resume_step' => MatrimonyProfile::CARD_ONBOARDING_PHOTO_RESUME_STEP])->saveQuietly();

            return redirect()->route('matrimony.profile.upload-photo', ['from' => 'onboarding'])
                ->with('info', __('onboarding.after_step7_redirect_photos'));
        }

        return redirect()->route('matrimony.onboarding.show', ['step' => $step + 1])
            ->with('success', __('onboarding.saved_continue'));
    }

    /**
     * Persist last-opened card step so returning users resume here (DB survives new sessions).
     */
    private function syncCardOnboardingResumeStep(MatrimonyProfile $profile, int $step): void
    {
        if ($step < 2 || $step > self::LAST_STEP) {
            return;
        }

        if (! $this->shouldTrackCardOnboardingProgress($profile)) {
            return;
        }

        $profile->forceFill(['card_onboarding_resume_step' => $step])->saveQuietly();
    }

    private function shouldTrackCardOnboardingProgress(MatrimonyProfile $profile): bool
    {
        if ($profile->card_onboarding_resume_step !== null) {
            return true;
        }
        if (session('wizard_minimal', false)) {
            return true;
        }
        if (($profile->lifecycle_state ?? null) === 'draft') {
            return true;
        }

        return false;
    }

    private function snapshotStep2(Request $request, MatrimonyProfile $profile, ProfileWizardController $wizard): array
    {
        $this->hydrateBasicInfoContext($request, $profile);

        return $wizard->buildSnapshotForSection($request, 'basic-info', $profile);
    }

    private function snapshotStep3(Request $request, MatrimonyProfile $profile, ProfileWizardController $wizard): array
    {
        $this->hydrateBasicInfoContext($request, $profile);

        return $wizard->buildSnapshotForSection($request, 'basic-info', $profile);
    }

    private function snapshotStep4(Request $request, MatrimonyProfile $profile, ProfileWizardController $wizard): array
    {
        $this->hydrateEducationCareerContext($request, $profile);

        return $wizard->buildSnapshotForSection($request, 'education-career', $profile);
    }

    /**
     * Merge current profile + defaults so basic-info snapshot validates when only a subset of fields is posted.
     */
    private function hydrateBasicInfoContext(Request $request, MatrimonyProfile $profile): void
    {
        $dob = $profile->date_of_birth;
        $dobStr = $dob instanceof \Carbon\CarbonInterface ? $dob->format('Y-m-d') : (is_string($dob) ? $dob : null);

        $neverId = MasterMaritalStatus::where('key', 'never_married')->where('is_active', true)->value('id');
        $maritalId = $request->filled('marital_status_id')
            ? $request->input('marital_status_id')
            : ($profile->marital_status_id ?? $neverId);

        $marriage = ProfileMarriage::where('profile_id', $profile->id)->orderBy('id')->first();
        $defaultMarriageRow = [
            'id' => $marriage?->id,
            'marriage_year' => $marriage?->marriage_year ?? '',
            'divorce_year' => $marriage?->divorce_year ?? '',
            'separation_year' => $marriage?->separation_year ?? '',
            'spouse_death_year' => $marriage?->spouse_death_year ?? '',
            'divorce_status' => $marriage?->divorce_status ?? '',
            'remarriage_reason' => $marriage?->remarriage_reason ?? '',
            'notes' => $marriage?->notes ?? '',
        ];

        // Use filled() so empty strings from partial posts (e.g. hidden inputs) do not override saved profile values.
        $request->merge([
            'full_name' => $request->filled('full_name') ? $request->input('full_name') : $profile->full_name,
            'gender_id' => $request->filled('gender_id') ? $request->input('gender_id') : $profile->gender_id,
            'date_of_birth' => $request->filled('date_of_birth') ? $request->input('date_of_birth') : $dobStr,
            'birth_time' => $request->filled('birth_time') ? $request->input('birth_time') : $profile->birth_time,
            'religion_id' => $request->filled('religion_id') ? $request->input('religion_id') : $profile->religion_id,
            'caste_id' => $request->filled('caste_id') ? $request->input('caste_id') : $profile->caste_id,
            'sub_caste_id' => $request->filled('sub_caste_id') ? $request->input('sub_caste_id') : $profile->sub_caste_id,
            'mother_tongue_id' => $request->filled('mother_tongue_id') ? $request->input('mother_tongue_id') : $profile->mother_tongue_id,
            'marital_status_id' => $maritalId,
            'country_id' => $request->filled('country_id') ? $request->input('country_id') : $profile->country_id,
            'state_id' => $request->filled('state_id') ? $request->input('state_id') : $profile->state_id,
            'district_id' => $request->filled('district_id') ? $request->input('district_id') : $profile->district_id,
            'taluka_id' => $request->filled('taluka_id') ? $request->input('taluka_id') : $profile->taluka_id,
            'city_id' => $request->filled('city_id') ? $request->input('city_id') : $profile->city_id,
            'address_line' => $request->filled('address_line') ? $request->input('address_line') : $profile->address_line,
        ]);

        if (! $request->has('marriages')) {
            $request->merge(['marriages' => [$defaultMarriageRow]]);
        }
        if ($request->input('has_children') === null && $profile->has_children !== null) {
            $request->merge(['has_children' => $profile->has_children ? '1' : '0']);
        }

        // Step 3+ posts omit marital/children inputs; re-apply saved rows so basic-info snapshot validation
        // does not fail with "children required" while updating religion/caste only.
        if (! $request->has('children')) {
            $maritalId = (int) $request->input('marital_status_id', $profile->marital_status_id ?? 0);
            $statusKey = MasterMaritalStatus::where('id', $maritalId)->value('key');
            $statusesRequiringChildren = ['divorced', 'separated', 'widowed'];
            $needsChildrenBlock = $statusKey && in_array($statusKey, $statusesRequiringChildren, true);
            $hc = $request->input('has_children');
            $hasChildrenYes = $hc === '1' || $hc === 1 || $hc === true;

            if ($needsChildrenBlock && $hasChildrenYes) {
                $request->merge(['children' => $this->existingChildrenPayloadFromProfile($profile)]);
            } else {
                $request->merge(['children' => []]);
            }
        }
    }

    /**
     * Rows in the shape expected by ProfileWizardController::buildMarriagesSnapshot.
     *
     * @return array<int, array<string, mixed>>
     */
    private function existingChildrenPayloadFromProfile(MatrimonyProfile $profile): array
    {
        $rows = DB::table('profile_children')
            ->where('profile_id', $profile->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($rows as $i => $c) {
            $out[] = [
                'id' => $c->id ?? null,
                'gender' => (string) ($c->gender ?? ''),
                'age' => isset($c->age) && $c->age !== '' ? (int) $c->age : '',
                'child_living_with_id' => $c->child_living_with_id ?? '',
                'sort_order' => isset($c->sort_order) ? (int) $c->sort_order : $i,
            ];
        }

        return $out;
    }

    /**
     * Send the user to the onboarding card that actually contains the failing fields.
     *
     * @param  array<string, array<int, string>>  $errors
     */
    private function onboardingStepForValidationErrors(array $errors, int $submittedStep): int
    {
        $steps = [];
        foreach (array_keys($errors) as $key) {
            if ($this->validationErrorKeyBelongsToOnboardingStep($key, 2)) {
                $steps[2] = true;
            }
            if ($this->validationErrorKeyBelongsToOnboardingStep($key, 3)) {
                $steps[3] = true;
            }
            if ($this->validationErrorKeyBelongsToOnboardingStep($key, 4)) {
                $steps[4] = true;
            }
            if ($this->validationErrorKeyBelongsToOnboardingStep($key, 5)) {
                $steps[5] = true;
            }
            if ($this->validationErrorKeyBelongsToOnboardingStep($key, 6)) {
                $steps[6] = true;
            }
            if ($this->validationErrorKeyBelongsToOnboardingStep($key, 7)) {
                $steps[7] = true;
            }
        }

        if ($steps === []) {
            return $submittedStep;
        }

        return (int) min(array_keys($steps));
    }

    private function validationErrorKeyBelongsToOnboardingStep(string $key, int $step): bool
    {
        return match ($step) {
            2 => (bool) preg_match(
                '/^(full_name|gender_id|date_of_birth|birth_time|mother_tongue_id|marital_status_id|has_children|marriages|children)(\.|$)/',
                $key
            ),
            3 => (bool) preg_match('/^(religion_id|caste_id|sub_caste_id)(\.|$)/', $key),
            4 => (bool) preg_match(
                '/^(highest_education|highest_education_other|specialization|working_with_type_id|profession_id|company_name|annual_income|income_range_id|income_currency_id|income_private|college_id|work_city_id|work_state_id|income_period|income_value_type|income_amount|income_min_amount|income_max_amount)(\.|$)/',
                $key
            ),
            5 => (bool) preg_match(
                '/^(height_cm|complexion_id|blood_group_id|physical_build_id|spectacles_lens|physical_condition|diet_id|smoking_status_id|drinking_status_id|weight_kg|country_id|state_id|district_id|taluka_id|city_id|address_line|birth_.*)(\.|$)/',
                $key
            ),
            6 => (bool) preg_match('/^(extended_narrative)(\.|$)/', $key),
            7 => (bool) preg_match('/^(preferred_|willing_to_relocate|marriage_type_preference_id|partner_profile_with_children|preference_preset|settled_.*|extended_narrative)(\.|$)/', $key),
            default => false,
        };
    }

    private function snapshotStep7(Request $request, MatrimonyProfile $profile, ProfileWizardController $wizard): array
    {
        // On onboarding we treat unchecked "open to all" toggles as empty preference arrays.
        if ($request->boolean('education_open_all')) {
            $request->merge(['preferred_master_education_ids' => []]);
        }
        if ($request->boolean('diet_open_all')) {
            $request->merge(['preferred_diet_ids' => []]);
        }

        $pref = $wizard->buildSnapshotForSection($request, 'about-preferences', $profile);
        $expectations = trim((string) $request->input('extended_narrative.narrative_expectations', ''));
        if (! $request->has('extended_narrative.narrative_expectations')) {
            return $pref;
        }

        $existing = DB::table('profile_extended_attributes')
            ->where('profile_id', $profile->id)
            ->first();

        $pref['extended_narrative'] = [[
            'id' => $existing?->id ? (int) $existing->id : null,
            'narrative_about_me' => trim((string) ($existing?->narrative_about_me ?? '')),
            'narrative_expectations' => $expectations,
        ]];

        return $pref;
    }

    private function loadStep7PartnerPreferenceData(array &$data, MatrimonyProfile $profile): void
    {
        $criteria = DB::table('profile_preference_criteria')->where('profile_id', $profile->id)->first();
        $preferredReligionIds = DB::table('profile_preferred_religions')->where('profile_id', $profile->id)->pluck('religion_id')->all();
        $preferredCasteIds = DB::table('profile_preferred_castes')->where('profile_id', $profile->id)->pluck('caste_id')->all();
        $preferredDistrictIds = DB::table('profile_preferred_districts')->where('profile_id', $profile->id)->pluck('district_id')->all();
        $preferredCountryIds = Schema::hasTable('profile_preferred_countries')
            ? DB::table('profile_preferred_countries')->where('profile_id', $profile->id)->pluck('country_id')->all()
            : [];
        $preferredStateIds = Schema::hasTable('profile_preferred_states')
            ? DB::table('profile_preferred_states')->where('profile_id', $profile->id)->pluck('state_id')->all()
            : [];
        $preferredTalukaIds = Schema::hasTable('profile_preferred_talukas')
            ? DB::table('profile_preferred_talukas')->where('profile_id', $profile->id)->pluck('taluka_id')->all()
            : [];
        $preferredMasterEducationIds = Schema::hasTable('profile_preferred_master_education')
            ? DB::table('profile_preferred_master_education')->where('profile_id', $profile->id)->pluck('master_education_id')->all()
            : [];
        $preferredWorkingWithTypeIds = Schema::hasTable('profile_preferred_working_with_types')
            ? DB::table('profile_preferred_working_with_types')->where('profile_id', $profile->id)->pluck('working_with_type_id')->all()
            : [];
        $preferredProfessionIds = Schema::hasTable('profile_preferred_professions')
            ? DB::table('profile_preferred_professions')->where('profile_id', $profile->id)->pluck('profession_id')->all()
            : [];
        $preferredDietIds = Schema::hasTable('profile_preferred_diets')
            ? DB::table('profile_preferred_diets')->where('profile_id', $profile->id)->pluck('diet_id')->all()
            : [];
        $preferredMaritalStatusIdsFromDb = Schema::hasTable('profile_preferred_marital_statuses')
            ? DB::table('profile_preferred_marital_statuses')->where('profile_id', $profile->id)->pluck('marital_status_id')->all()
            : [];

        $suggestions = \App\Services\PartnerPreferenceSuggestionService::suggestForProfile($profile);

        $wasCompletelyEmpty = ! $criteria && empty($preferredReligionIds) && empty($preferredCasteIds) && empty($preferredDistrictIds)
            && empty($preferredCountryIds) && empty($preferredStateIds) && empty($preferredTalukaIds)
            && empty($preferredMasterEducationIds) && empty($preferredWorkingWithTypeIds) && empty($preferredProfessionIds)
            && empty($preferredDietIds) && empty($preferredMaritalStatusIdsFromDb)
            && ($criteria?->preferred_marital_status_id ?? null) === null;

        $merged = \App\Services\PartnerPreferenceSuggestionService::mergePartnerPreferencesForDisplay(
            $profile,
            $criteria,
            $preferredReligionIds,
            $preferredCasteIds,
            $preferredCountryIds,
            $preferredStateIds,
            $preferredDistrictIds,
            $preferredTalukaIds,
            $preferredDietIds,
            $preferredMaritalStatusIdsFromDb
        );
        $criteria = $merged['criteria'];
        $preferredReligionIds = $merged['preferredReligionIds'];
        $preferredCasteIds = $merged['preferredCasteIds'];
        $preferredCountryIds = $merged['preferredCountryIds'];
        $preferredStateIds = $merged['preferredStateIds'];
        $preferredDistrictIds = $merged['preferredDistrictIds'];
        $preferredTalukaIds = $merged['preferredTalukaIds'];
        $preferredDietIds = $merged['preferredDietIds'];
        $preferredMaritalStatusIdsMerged = $merged['preferredMaritalStatusIds'] ?? [];

        $data['preferencePreset'] = $wasCompletelyEmpty ? ($suggestions['preference_preset'] ?? 'balanced') : 'custom';

        $base = $suggestions;
        $base['preferred_income_min'] = null;
        $base['preferred_income_max'] = null;
        if (! empty($base['preferred_city_id'])) {
            $cityName = \App\Models\City::where('id', $base['preferred_city_id'])->value('name');
            if ($cityName) {
                $base['preferred_city_name'] = $cityName;
            }
        }

        $data['preferencePresetDefaults'] = [
            'traditional' => \App\Services\PartnerPreferencePresetService::applyPreset('traditional', $base),
            'balanced' => \App\Services\PartnerPreferencePresetService::applyPreset('balanced', $base),
            'broad' => \App\Services\PartnerPreferencePresetService::applyPreset('broad', $base),
        ];

        $data['preferenceCriteria'] = $criteria;
        $data['preferredReligionIds'] = $preferredReligionIds;
        $data['preferredCasteIds'] = $preferredCasteIds;
        $data['preferredDistrictIds'] = $preferredDistrictIds;
        $data['preferredCountryIds'] = $preferredCountryIds;
        $data['preferredStateIds'] = $preferredStateIds;
        $data['preferredTalukaIds'] = $preferredTalukaIds;
        $data['preferredMasterEducationIds'] = $preferredMasterEducationIds;
        $data['preferredWorkingWithTypeIds'] = $preferredWorkingWithTypeIds;
        $data['preferredProfessionIds'] = $preferredProfessionIds;
        $data['preferredDietIds'] = $preferredDietIds;
        $data['marriageTypePreferences'] = \App\Models\MasterMarriageTypePreference::where('is_active', true)->orderBy('sort_order')->get();
        $data['allMaritalStatuses'] = \App\Models\MasterMaritalStatus::where('is_active', true)->orderBy('label')->get();
        $data['allReligions'] = \App\Models\Religion::where('is_active', true)->orderBy('label')->get();
        $data['allCastes'] = \App\Models\Caste::where('is_active', true)->orderBy('label')->get();
        $data['allDistricts'] = \App\Models\District::orderBy('name')->get();
        $data['masterEducationOptions'] = \App\Models\MasterEducation::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
        $data['partnerDietOptions'] = \App\Models\MasterDiet::where('is_active', true)->orderBy('sort_order')->orderBy('label')->get();
        $data['preferredMaritalStatusIds'] = collect(old('preferred_marital_status_ids', $preferredMaritalStatusIdsMerged))
            ->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->unique()->values()->all();
        $data['preferredMaritalStatusId'] = count($data['preferredMaritalStatusIds']) === 1
            ? $data['preferredMaritalStatusIds'][0]
            : null;
        $data['neverMarriedMaritalStatusId'] = \App\Models\MasterMaritalStatus::where('key', 'never_married')->where('is_active', true)->value('id');
        $data['partnerProfileWithChildren'] = $criteria->partner_profile_with_children ?? null;
        $data['extendedAttrs'] = DB::table('profile_extended_attributes')
            ->where('profile_id', $profile->id)
            ->first();
        $data['interestedInIntercaste'] = \App\Services\ProfilePartnerCommunityFlagService::interestedInIntercaste($profile->id);
    }

    private function hydratePhysicalAddressContext(Request $request, MatrimonyProfile $profile): void
    {
        // Empty string from hidden inputs is still "present" for input($key, $default) — do not drop saved residence IDs.
        $coalesce = static function (mixed $posted, mixed $fallback): mixed {
            if ($posted === null || $posted === '') {
                return $fallback;
            }

            return $posted;
        };

        $request->merge([
            'height_cm' => $coalesce($request->input('height_cm'), $profile->height_cm),
            'complexion_id' => $coalesce($request->input('complexion_id'), $profile->complexion_id),
            'blood_group_id' => $coalesce($request->input('blood_group_id'), $profile->blood_group_id),
            'physical_build_id' => $coalesce($request->input('physical_build_id'), $profile->physical_build_id),
            'spectacles_lens' => $coalesce($request->input('spectacles_lens'), $profile->spectacles_lens),
            'physical_condition' => $coalesce($request->input('physical_condition'), $profile->physical_condition),
            'diet_id' => $coalesce($request->input('diet_id'), $profile->diet_id),
            'smoking_status_id' => $coalesce($request->input('smoking_status_id'), $profile->smoking_status_id),
            'drinking_status_id' => $coalesce($request->input('drinking_status_id'), $profile->drinking_status_id),
            'weight_kg' => $coalesce($request->input('weight_kg'), $profile->weight_kg),
            'country_id' => $coalesce($request->input('country_id'), $profile->country_id),
            'state_id' => $coalesce($request->input('state_id'), $profile->state_id),
            'district_id' => $coalesce($request->input('district_id'), $profile->district_id),
            'taluka_id' => $coalesce($request->input('taluka_id'), $profile->taluka_id),
            'city_id' => $coalesce($request->input('city_id'), $profile->city_id),
            'address_line' => $request->filled('address_line') ? trim((string) $request->input('address_line')) : $profile->address_line,
        ]);
    }

    private function hydrateEducationCareerContext(Request $request, MatrimonyProfile $profile): void
    {
        $defaultInr = \App\Models\MasterIncomeCurrency::where('code', 'INR')->value('id');
        $request->merge([
            'highest_education' => $request->input('highest_education', $profile->highest_education),
            'highest_education_other' => $request->input('highest_education_other', $profile->highest_education_other),
            'specialization' => $request->input('specialization', $profile->specialization),
            'working_with_type_id' => $request->input('working_with_type_id', $profile->working_with_type_id),
            'profession_id' => $request->input('profession_id', $profile->profession_id),
            'company_name' => $request->input('company_name', $profile->company_name),
            'annual_income' => $request->input('annual_income', $profile->annual_income),
            'income_range_id' => $request->input('income_range_id', $profile->income_range_id),
            'income_currency_id' => $request->input('income_currency_id', $profile->income_currency_id ?? $defaultInr),
            'income_private' => $request->has('income_private') ? $request->boolean('income_private') : (bool) $profile->income_private,
            'college_id' => $request->input('college_id', $profile->college_id),
            'work_city_id' => $request->input('work_city_id', $profile->work_city_id),
            'work_state_id' => $request->input('work_state_id', $profile->work_state_id),
            'income_period' => $request->input('income_period', $profile->income_period),
            'income_value_type' => $request->input('income_value_type', $profile->income_value_type),
            'income_amount' => $request->input('income_amount', $profile->income_amount),
            'income_min_amount' => $request->input('income_min_amount', $profile->income_min_amount),
            'income_max_amount' => $request->input('income_max_amount', $profile->income_max_amount),
        ]);
    }

    private function ensureProfile($user): ?MatrimonyProfile
    {
        if (! $user) {
            return null;
        }
        $profile = MatrimonyProfile::query()->where('user_id', $user->id)->first();
        if ($profile) {
            return $profile;
        }
        $manualActivation = \App\Services\Admin\AdminSettingService::isManualProfileActivationRequired();
        $genderId = null;
        if (! empty($user->gender)) {
            $genderId = \App\Models\MasterGender::where('key', $user->gender)->where('is_active', true)->value('id');
        }
        $profile = MatrimonyProfile::create([
            'user_id' => $user->id,
            'lifecycle_state' => 'draft',
            'full_name' => $user->defaultBootstrapProfileFullName(),
            'gender_id' => $genderId,
            'is_suspended' => $manualActivation,
        ]);

        return $profile;
    }
}
