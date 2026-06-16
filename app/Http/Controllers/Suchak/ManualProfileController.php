<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakConsent;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAccessService;
use App\Modules\Suchak\Services\SuchakConsentService;
use App\Modules\Suchak\Services\SuchakCustomerLifecycleService;
use App\Modules\Suchak\Services\SuchakRepresentationService;
use App\Services\Admin\AdminSettingService;
use App\Services\MutationService;
use App\Support\MobileNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class ManualProfileController extends Controller
{
    public function create(Request $request, SuchakAccessService $accessService): View|RedirectResponse
    {
        $account = $request->user()->suchakAccount;

        if (! $account || ! $accessService->canOwnerPrepareCustomers($account, $request->user())) {
            return redirect()
                ->route('suchak.dashboard')
                ->with('error', 'Only active Suchak accounts can create a manual candidate profile.');
        }

        return view('suchak.manual-profiles.create', [
            'suchakAccount' => $account,
            'genders' => $this->activeGenders(),
            'registeringForOptions' => $this->registeringForOptions(),
        ]);
    }

    public function store(
        Request $request,
        SuchakAccessService $accessService,
        MutationService $mutationService,
        SuchakRepresentationService $representationService,
        SuchakCustomerLifecycleService $customerLifecycleService,
        SuchakConsentService $consentService,
    ): RedirectResponse {
        $account = $request->user()->suchakAccount;

        if (! $account || ! $accessService->canOwnerPrepareCustomers($account, $request->user())) {
            return redirect()
                ->route('suchak.dashboard')
                ->with('error', 'Only active Suchak accounts can create a manual candidate profile.');
        }

        $validated = $request->validate([
            'candidate_name' => ['required', 'string', 'max:255'],
            'candidate_mobile' => ['nullable', 'string', 'max:32'],
            'candidate_email' => ['nullable', 'email', 'max:255'],
            'candidate_gender' => ['required', Rule::exists('master_genders', 'key')->where('is_active', true)],
            'registering_for' => [
                'required',
                Rule::in(array_keys($this->registeringForOptions())),
            ],
            'use_existing_profile' => ['nullable', 'boolean'],
        ]);

        $mobile = null;
        if (trim((string) ($validated['candidate_mobile'] ?? '')) !== '') {
            $mobile = MobileNumber::normalize((string) $validated['candidate_mobile']);
            if ($mobile === null) {
                return back()
                    ->withInput()
                    ->withErrors(['candidate_mobile' => __('otp.enter_valid_10_digit_mobile')]);
            }

            $existingMember = User::query()
                ->where('mobile', $mobile)
                ->with('matrimonyProfile')
                ->first();

            if ($existingMember !== null) {
                return $this->handleExistingMobileProfile(
                    $request,
                    $validated,
                    $mobile,
                    $existingMember,
                    $account,
                    $representationService,
                    $customerLifecycleService,
                    $consentService,
                );
            }
        }

        if (! empty($validated['candidate_email'])) {
            Validator::make(
                ['candidate_email' => $validated['candidate_email']],
                ['candidate_email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')]],
            )->validate();
        }

        $genderId = MasterGender::query()
            ->where('key', $validated['candidate_gender'])
            ->where('is_active', true)
            ->value('id');

        try {
            [$member, $profile, $representation] = DB::transaction(function () use (
                $validated,
                $mobile,
                $genderId,
                $request,
                $account,
                $mutationService,
                $representationService,
                $customerLifecycleService
            ): array {
                $member = User::query()->create([
                    'name' => $validated['candidate_name'],
                    'email' => ($validated['candidate_email'] ?? null) ?: null,
                    'mobile' => $mobile,
                    'password' => Hash::make(Str::random(40)),
                    'registering_for' => $validated['registering_for'],
                    'gender' => $validated['candidate_gender'],
                    'referral_code' => User::generateUniqueReferralCode(),
                ]);

                $profile = $mutationService->createDraftProfileForUser($member, [
                    'full_name' => $validated['candidate_name'],
                    'gender_id' => $genderId,
                    'is_suspended' => AdminSettingService::isManualProfileActivationRequired(),
                ]);

                $representation = $representationService->createPendingManualProfile(
                    $account,
                    $request->user(),
                    $profile,
                    $request->ip(),
                    $request->userAgent(),
                );

                $customerLifecycleService->createForRepresentation(
                    $account,
                    $request->user(),
                    $representation,
                    [
                        'source_type' => SuchakCustomerContext::SOURCE_TYPE_MANUAL,
                        'customer_lifecycle_status' => SuchakCustomerContext::STATUS_CANDIDATE_IDENTIFIED,
                        'payer_name' => $validated['candidate_name'],
                    ],
                    $request->ip(),
                    $request->userAgent(),
                );

                return [$member, $profile, $representation];
            });
        } catch (InvalidArgumentException $exception) {
            return back()
                ->withInput()
                ->withErrors(['candidate_name' => $exception->getMessage()]);
        }

        session([
            'suchak_registration_account_id' => (int) $account->id,
            'suchak_registration_profile_id' => (int) $profile->id,
            'suchak_registration_representation_id' => (int) $representation->id,
            'suchak_edit_profile_id' => (int) $profile->id,
        ]);

        return redirect()
            ->route('matrimony.profile.wizard.section', [
                'section' => 'full',
                'all' => 1,
                'profile_id' => $profile->id,
            ])
            ->with('success', "Manual profile created for {$member->name}. Complete the existing profile form.");
    }

    public function editProfile(
        Request $request,
        SuchakProfileRepresentation $representation,
        SuchakAccessService $accessService,
    ): RedirectResponse {
        $account = $request->user()->suchakAccount;

        if (! $account || ! $accessService->canOwnerPrepareCustomers($account, $request->user())) {
            return redirect()
                ->route('suchak.dashboard')
                ->with('error', 'Only active Suchak accounts can manage represented candidate profiles.');
        }

        if ((int) $representation->suchak_account_id !== (int) $account->id || $representation->matrimony_profile_id === null) {
            abort(404);
        }

        session([
            'suchak_registration_account_id' => (int) $account->id,
            'suchak_registration_profile_id' => (int) $representation->matrimony_profile_id,
            'suchak_registration_representation_id' => (int) $representation->id,
            'suchak_edit_profile_id' => (int) $representation->matrimony_profile_id,
        ]);

        return redirect()
            ->route('matrimony.profile.wizard.section', [
                'section' => 'full',
                'all' => 1,
                'profile_id' => $representation->matrimony_profile_id,
            ])
            ->with('success', 'Manage this represented profile in the centralized profile form.');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function handleExistingMobileProfile(
        Request $request,
        array $validated,
        string $mobile,
        User $existingMember,
        SuchakAccount $account,
        SuchakRepresentationService $representationService,
        SuchakCustomerLifecycleService $customerLifecycleService,
        SuchakConsentService $consentService,
    ): RedirectResponse {
        /** @var MatrimonyProfile|null $existingProfile */
        $existingProfile = $existingMember->matrimonyProfile;

        if ($existingProfile === null) {
            return back()
                ->withInput($this->manualProfileInput($validated, $mobile))
                ->withErrors([
                    'candidate_mobile' => 'This mobile number belongs to an existing account, but no candidate profile is available to link. Use another number or contact admin for duplicate review.',
                ]);
        }

        if (! $request->boolean('use_existing_profile')) {
            return back()
                ->withInput($this->manualProfileInput($validated, $mobile))
                ->with('suchak_existing_profile_match', [
                    'mobile_mask' => $this->maskMobile($mobile),
                ]);
        }

        try {
            [$representation] = DB::transaction(function () use (
                $account,
                $request,
                $existingProfile,
                $validated,
                $mobile,
                $representationService,
                $customerLifecycleService,
                $consentService
            ): array {
                $representation = $this->existingOrNewMatchedRepresentation(
                    $account,
                    $request->user(),
                    $existingProfile,
                    $representationService,
                    $request->ip(),
                    $request->userAgent(),
                );

                $consent = $this->existingOrNewConsentRequest(
                    $representation,
                    $request->user(),
                    $mobile,
                    $consentService,
                    $request->ip(),
                    $request->userAgent(),
                );

                $this->existingOrNewCustomerContext(
                    $account,
                    $request->user(),
                    $representation,
                    $consent,
                    (string) $validated['candidate_name'],
                    $customerLifecycleService,
                    $request->ip(),
                    $request->userAgent(),
                );

                return [$representation];
            });
        } catch (InvalidArgumentException $exception) {
            return back()
                ->withInput($this->manualProfileInput($validated, $mobile))
                ->withErrors(['candidate_mobile' => $exception->getMessage()]);
        }

        session([
            'suchak_registration_account_id' => (int) $account->id,
            'suchak_registration_profile_id' => (int) $existingProfile->id,
            'suchak_registration_representation_id' => (int) $representation->id,
        ]);

        return redirect()
            ->route('suchak.dashboard', ['dashboard_tab' => 'profiles'])
            ->with('success', 'Existing profile found. A Suchak representation and consent request are ready; continue from your profile list without creating a duplicate profile.');
    }

    private function existingOrNewMatchedRepresentation(
        SuchakAccount $account,
        User $actor,
        MatrimonyProfile $profile,
        SuchakRepresentationService $representationService,
        ?string $ipAddress,
        ?string $userAgent,
    ): SuchakProfileRepresentation {
        $existing = SuchakProfileRepresentation::query()
            ->where('suchak_account_id', $account->id)
            ->where('matrimony_profile_id', $profile->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $representationService->createPendingMatchedExistingProfile(
            $account,
            $actor,
            $profile,
            $ipAddress,
            $userAgent,
        );
    }

    private function existingOrNewConsentRequest(
        SuchakProfileRepresentation $representation,
        User $actor,
        string $mobile,
        SuchakConsentService $consentService,
        ?string $ipAddress,
        ?string $userAgent,
    ): SuchakConsent {
        $existing = SuchakConsent::query()
            ->where('representation_id', $representation->id)
            ->whereIn('consent_status', SuchakConsent::OPEN_STATUSES)
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $result = $consentService->createSuchakRelayedLinkConsent(
            $representation,
            $actor,
            [
                'consent_type' => SuchakConsent::TYPE_ONE_YEAR,
                'intended_mobile' => $mobile,
            ],
            $ipAddress,
            $userAgent,
        );

        return $result['consent'];
    }

    private function existingOrNewCustomerContext(
        SuchakAccount $account,
        User $actor,
        SuchakProfileRepresentation $representation,
        SuchakConsent $consent,
        string $payerName,
        SuchakCustomerLifecycleService $customerLifecycleService,
        ?string $ipAddress,
        ?string $userAgent,
    ): SuchakCustomerContext {
        $existing = SuchakCustomerContext::query()
            ->where('representation_id', $representation->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $customerLifecycleService->createForRepresentation(
            $account,
            $actor,
            $representation,
            [
                'source_type' => SuchakCustomerContext::SOURCE_TYPE_EXISTING_PROFILE_MATCH,
                'customer_lifecycle_status' => SuchakCustomerContext::STATUS_CONSENT_PENDING,
                'payer_name' => $payerName,
                'consent_id' => $consent->id,
            ],
            $ipAddress,
            $userAgent,
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function manualProfileInput(array $validated, string $mobile): array
    {
        return array_merge($validated, [
            'candidate_mobile' => $mobile,
        ]);
    }

    private function maskMobile(string $mobile): string
    {
        return str_repeat('*', max(0, strlen($mobile) - 4)).substr($mobile, -4);
    }

    private function activeGenders()
    {
        return MasterGender::query()
            ->where('is_active', true)
            ->whereIn('key', ['male', 'female'])
            ->orderByRaw("CASE WHEN `key` = 'male' THEN 1 ELSE 2 END")
            ->get(['id', 'key', 'label']);
    }

    /**
     * @return array<string, string>
     */
    private function registeringForOptions(): array
    {
        return [
            'self' => 'Candidate self',
            'parent_guardian' => 'Parent / guardian',
            'sibling' => 'Sibling',
            'relative' => 'Relative',
            'friend' => 'Friend',
            'other' => 'Other',
        ];
    }
}
