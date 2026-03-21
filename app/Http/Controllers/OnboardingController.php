<?php

namespace App\Http\Controllers;

use App\Models\MatrimonyProfile;
use App\Models\MasterMaritalStatus;
use App\Models\ProfileMarriage;
use App\Models\Profession;
use App\Models\WorkingWithType;
use App\Services\MutationService;
use App\Services\ProfileLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Card onboarding (steps 2–5): delegates snapshots to ProfileWizardController + MutationService.
 * Step 1 (registration) is handled by RegisteredUserController.
 */
class OnboardingController extends Controller
{
    public function show(int $step)
    {
        if ($step < 2 || $step > 5) {
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

        $data = [
            'step' => $step,
            'profile' => $profile,
            'totalSteps' => 5,
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
                $data['workingWithTypes'] = WorkingWithType::where('is_active', true)->orderBy('sort_order')->orderBy('label')->get();
                $data['professions'] = Profession::where('is_active', true)->with('workingWithType')->orderBy('sort_order')->orderBy('label')->get();
                $data['educationExamples'] = __('onboarding.education_examples');
                $deg = $profile->highest_education
                    ? \App\Models\EducationDegree::where('code', $profile->highest_education)->with('category')->first()
                    : null;
                $data['selectedEducationCategory'] = $deg?->category?->name;
                break;
            case 5:
                break;
        }

        return view('matrimony.onboarding.show', $data);
    }

    public function store(Request $request, int $step): RedirectResponse
    {
        if ($step < 2 || $step > 5) {
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
                        default => null,
                    };
                }
                if ($snapshot === null) {
                    throw new \RuntimeException('Invalid onboarding step');
                }
                app(MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $user->id, 'manual');
            });
        } catch (ValidationException $e) {
            return redirect()->route('matrimony.onboarding.show', ['step' => $step])
                ->withErrors($e->errors())
                ->withInput();
        }

        if ($step === 5) {
            session()->forget('wizard_minimal');

            return redirect()->route('matrimony.profiles.index')->with('success', __('onboarding.all_set'));
        }

        return redirect()->route('matrimony.onboarding.show', ['step' => $step + 1])
            ->with('success', __('onboarding.saved_continue'));
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
        $maritalId = $request->input('marital_status_id', $profile->marital_status_id ?? $neverId);

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

        $request->merge([
            'full_name' => $request->input('full_name', $profile->full_name),
            'gender_id' => $request->input('gender_id', $profile->gender_id),
            'date_of_birth' => $request->input('date_of_birth', $dobStr),
            'birth_time' => $request->input('birth_time', $profile->birth_time),
            'religion_id' => $request->input('religion_id', $profile->religion_id),
            'caste_id' => $request->input('caste_id', $profile->caste_id),
            'sub_caste_id' => $request->input('sub_caste_id', $profile->sub_caste_id),
            'mother_tongue_id' => $request->input('mother_tongue_id', $profile->mother_tongue_id),
            'marital_status_id' => $maritalId,
            'country_id' => $request->input('country_id', $profile->country_id),
            'state_id' => $request->input('state_id', $profile->state_id),
            'district_id' => $request->input('district_id', $profile->district_id),
            'taluka_id' => $request->input('taluka_id', $profile->taluka_id),
            'city_id' => $request->input('city_id', $profile->city_id),
            'address_line' => $request->input('address_line', $profile->address_line),
        ]);

        if (! $request->has('marriages')) {
            $request->merge(['marriages' => [$defaultMarriageRow]]);
        }
        if ($request->input('has_children') === null && $profile->has_children !== null) {
            $request->merge(['has_children' => $profile->has_children ? '1' : '0']);
        }
    }

    private function hydratePhysicalAddressContext(Request $request, MatrimonyProfile $profile): void
    {
        $request->merge([
            'height_cm' => $request->input('height_cm', $profile->height_cm),
            'complexion_id' => $request->input('complexion_id', $profile->complexion_id),
            'blood_group_id' => $request->input('blood_group_id', $profile->blood_group_id),
            'physical_build_id' => $request->input('physical_build_id', $profile->physical_build_id),
            'spectacles_lens' => $request->input('spectacles_lens', $profile->spectacles_lens),
            'physical_condition' => $request->input('physical_condition', $profile->physical_condition),
            'diet_id' => $request->input('diet_id', $profile->diet_id),
            'smoking_status_id' => $request->input('smoking_status_id', $profile->smoking_status_id),
            'drinking_status_id' => $request->input('drinking_status_id', $profile->drinking_status_id),
            'weight_kg' => $request->input('weight_kg', $profile->weight_kg),
            'country_id' => $request->input('country_id', $profile->country_id),
            'state_id' => $request->input('state_id', $profile->state_id),
            'district_id' => $request->input('district_id', $profile->district_id),
            'taluka_id' => $request->input('taluka_id', $profile->taluka_id),
            'city_id' => $request->input('city_id', $profile->city_id),
            'address_line' => $request->input('address_line', $profile->address_line),
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
        $profile = $user->matrimonyProfile;
        if ($profile) {
            return $profile;
        }
        $manualActivation = \App\Services\AdminSettingService::isManualProfileActivationRequired();
        $genderId = null;
        if (! empty($user->gender)) {
            $genderId = \App\Models\MasterGender::where('key', $user->gender)->where('is_active', true)->value('id');
        }
        $profile = MatrimonyProfile::create([
            'user_id' => $user->id,
            'lifecycle_state' => 'draft',
            'full_name' => $user->name ?? 'Draft',
            'gender_id' => $genderId,
            'is_suspended' => $manualActivation,
        ]);

        return $profile;
    }
}
