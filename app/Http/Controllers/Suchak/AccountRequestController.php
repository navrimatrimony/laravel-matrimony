<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakVerificationRecord;
use App\Modules\Suchak\Services\SuchakPolicyService;
use App\Modules\Suchak\Services\SuchakRegistrationService;
use App\Services\Location\CurrentLocationResolverService;
use App\Support\Suchak\SuchakOnboardingPresenter;
use App\Support\MobileNumber;
use App\Support\Validation\AddressHierarchyRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class AccountRequestController extends Controller
{
    private const PUBLIC_LOCATION_RESOLVE_CACHE_USER_ID = 0;

    public function home(Request $request, SuchakPolicyService $policyService): View
    {
        return view('suchak.home', [
            'suchakAccount' => $request->user()?->suchakAccount,
            'businessTypes' => $this->businessTypes(),
            'showHeroRegistrationForm' => $policyService->heroRegistrationFormEnabled(),
            'suchakHeroImagePath' => $policyService->heroImagePath(),
            'suchakHomepageCopy' => $policyService->homepageCopy(),
            'suchakHomepageStyle' => $policyService->homepageStyle(),
        ]);
    }

    public function registrationInfo(Request $request): View|RedirectResponse
    {
        $account = $request->user()?->suchakAccount;
        if ($account) {
            return redirect()->route('suchak.register.status');
        }

        return view('suchak.register', [
            'authenticatedUser' => $request->user(),
            'businessTypes' => $this->businessTypes(),
        ]);
    }

    public function storeRegistration(Request $request, SuchakRegistrationService $registrationService): RedirectResponse
    {
        if ($request->user()?->suchakAccount) {
            return redirect()->route('suchak.dashboard');
        }

        if ($request->user()) {
            return redirect()
                ->route('suchak.register.info')
                ->with('error', __('suchak.register.separate_account_body'));
        }

        $validated = $request->validate([
            'suchak_name' => ['required', 'string', 'max:255'],
            'office_name' => [
                Rule::requiredIf(fn (): bool => in_array((string) $request->input('business_type'), [
                    SuchakAccount::BUSINESS_TYPE_BUREAU,
                    SuchakAccount::BUSINESS_TYPE_ORGANIZATION,
                ], true)),
                'nullable',
                'string',
                'max:255',
            ],
            'business_type' => ['required', 'string', Rule::in([
                SuchakAccount::BUSINESS_TYPE_INDIVIDUAL,
                SuchakAccount::BUSINESS_TYPE_BUREAU,
                SuchakAccount::BUSINESS_TYPE_ORGANIZATION,
            ])],
            'whatsapp_number' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'address_line' => ['required', 'string', 'max:1000'],
            'location_id' => ['nullable', 'integer', AddressHierarchyRules::existsLocationLeafId()],
            'location_input' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        if (empty($validated['location_id']) && filled($validated['location_input'] ?? null)) {
            return back()
                ->withInput()
                ->withErrors(['location_id' => __('suchak.register.select_office_location')]);
        }

        $whatsapp = MobileNumber::normalize((string) $validated['whatsapp_number']);
        if ($whatsapp === null) {
            return back()
                ->withInput()
                ->withErrors(['whatsapp_number' => __('otp.enter_valid_10_digit_mobile')]);
        }

        Validator::make(
            ['whatsapp_number' => $whatsapp],
            ['whatsapp_number' => ['required', Rule::unique('users', 'mobile')]],
            ['whatsapp_number.unique' => __('auth.mobile_duplicate_register')]
        )->validate();

        $validated['whatsapp_number'] = $whatsapp;
        $validated['mobile_number'] = $whatsapp;
        $validated['email'] = empty($validated['email']) ? null : Str::lower((string) $validated['email']);

        $result = $registrationService->register(
            $validated,
            $request->ip(),
            $request->userAgent()
        );

        Auth::login($result['user']);

        $this->flashOtpIfVisible($request, $result['otp']);

        return redirect()
            ->route('suchak.register.verify')
            ->with('status', $this->otpDeliveryMessage($result['delivery']));
    }

    public function resolveRegistrationLocation(
        Request $request,
        CurrentLocationResolverService $currentLocationResolverService,
    ): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $result = $currentLocationResolverService->resolve(
            self::PUBLIC_LOCATION_RESOLVE_CACHE_USER_ID,
            (float) $validated['lat'],
            (float) $validated['lon'],
        );

        $status = ($result['success'] ?? false) ? 200 : 422;
        if (($result['status'] ?? '') === 'busy') {
            $status = 429;
        }

        return response()->json($result, $status);
    }

    public function verify(Request $request, SuchakOnboardingPresenter $onboardingPresenter): View|RedirectResponse
    {
        $user = $request->user();
        $account = $user?->suchakAccount;

        if (! $user || ! $account) {
            return redirect()->route('suchak.home');
        }

        if ($user->mobile_verified_at) {
            return redirect()
                ->route('suchak.register.status')
                ->with('status', __('suchak.register.mobile_already_verified'));
        }

        $account->load([
            'verificationRecords' => fn ($query) => $query->latest('id'),
        ]);

        return view('suchak.register-verify', [
            'suchakAccount' => $account,
            'otpDisplay' => $request->session()->pull('suchak_registration_otp_display'),
            'onboarding' => $onboardingPresenter->forAccount($account, $account->verificationRecords),
        ]);
    }

    public function resendRegistrationOtp(Request $request, SuchakRegistrationService $registrationService): RedirectResponse
    {
        $user = $request->user();

        if (! $user?->suchakAccount) {
            return redirect()->route('suchak.home');
        }

        if ($user->mobile_verified_at) {
            return redirect()->route('suchak.dashboard');
        }

        $result = $registrationService->resendOtp($user);
        $this->flashOtpIfVisible($request, $result['otp']);

        return redirect()
            ->route('suchak.register.verify')
            ->with('status', $this->otpDeliveryMessage($result['delivery']));
    }

    public function verifyRegistrationOtp(
        Request $request,
        SuchakRegistrationService $registrationService,
    ): RedirectResponse
    {
        $validated = $request->validate([
            'otp' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ]);

        $user = $request->user();
        if (! $user?->suchakAccount) {
            return redirect()->route('suchak.home');
        }

        $registrationService->verifyOtp($user, (string) $validated['otp']);

        return redirect()
            ->route('suchak.register.status')
            ->with('success', __('suchak.register.otp_verified_waiting_approval'));
    }

    public function storeRegistrationDocuments(
        Request $request,
        SuchakRegistrationService $registrationService,
    ): RedirectResponse {
        $user = $request->user();
        $account = $user?->suchakAccount;

        if (! $user || ! $account) {
            return redirect()->route('suchak.home');
        }

        $validated = $request->validate([
            'verification_type' => ['required', 'string', Rule::in($this->allowedVerificationTypes())],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $registrationService->uploadVerificationDocument(
            $account,
            $validated['document'],
            (string) $validated['verification_type'],
            (int) $user->id,
            $request->ip(),
            $request->userAgent(),
        );

        return back()->with('success', __('suchak.status.document_upload_success'));
    }

    public function photo(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $account = $user?->suchakAccount;

        if (! $user || ! $account) {
            return redirect()->route('suchak.home');
        }

        if (! $user->mobile_verified_at) {
            return redirect()->route('suchak.register.status');
        }

        return view('matrimony.profile.upload-photo', [
            'profile' => null,
            'galleryPhotos' => collect(),
            'photoApprovalRequired' => true,
            'photoMaxPerProfile' => 1,
            'currentPhotoCount' => 0,
            'photoSlotsRemaining' => 1,
            'photoLimitReached' => false,
            'fromOnboarding' => false,
            'onboardingPhotoRequired' => false,
            'primaryPhotoProcessing' => false,
            'primaryOnlyOnCoreColumn' => false,
            'photoTargetQuery' => [],
            'suchakAccountPhotoUpload' => true,
            'photoUploadAction' => route('suchak.register.photo.store'),
            'photoUploadBackUrl' => route('suchak.register.status'),
            'photoUploadTitle' => __('suchak.status.photo_page_title'),
            'photoUploadSubtitle' => __('suchak.status.photo_page_intro'),
            'photoUploadSubmitLabel' => __('suchak.status.upload_photo_for_review'),
            'photoUploadUploadingLabel' => __('suchak.status.uploading_photo'),
            'photoUploadSelectError' => __('suchak.status.photo_select_error'),
            'photoUploadDefaultError' => __('suchak.status.photo_upload_failed'),
        ]);
    }

    public function storeRegistrationPhoto(
        Request $request,
        SuchakRegistrationService $registrationService,
    ): RedirectResponse|JsonResponse {
        $user = $request->user();
        $account = $user?->suchakAccount;

        if (! $user || ! $account) {
            return redirect()->route('suchak.home');
        }

        if (! $user->mobile_verified_at) {
            return redirect()->route('suchak.register.status');
        }

        $validated = $request->validate([
            'profile_photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $registrationService->uploadVerificationDocument(
            $account,
            $validated['profile_photo'],
            SuchakVerificationRecord::TYPE_PROFILE_PHOTO,
            (int) $user->id,
            $request->ip(),
            $request->userAgent(),
        );

        $request->session()->flash('success', __('suchak.status.photo_upload_success'));

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'redirect' => route('suchak.register.status'),
            ]);
        }

        return redirect()->route('suchak.register.status');
    }

    public function status(Request $request, SuchakOnboardingPresenter $onboardingPresenter): View|RedirectResponse
    {
        $account = $request->user()?->suchakAccount;

        if (! $account) {
            return redirect()->route('suchak.home');
        }

        $account->load([
            'verificationRecords' => fn ($query) => $query->latest('id'),
        ]);

        return view('suchak.register-status', [
            'suchakAccount' => $account,
            'verificationRecords' => $account->verificationRecords,
            'onboarding' => $onboardingPresenter->forAccount($account, $account->verificationRecords),
        ]);
    }

    public function create(): RedirectResponse
    {
        return $this->redirectToSeparateRegistration();
    }

    public function store(): RedirectResponse
    {
        return $this->redirectToSeparateRegistration();
    }

    private function redirectToSeparateRegistration(): RedirectResponse
    {
        return redirect()
            ->route('suchak.register.info')
            ->with('info', __('suchak.register.separate_account_note'));
    }

    /**
     * @return array<string, string>
     */
    private function businessTypes(): array
    {
        return [
            SuchakAccount::BUSINESS_TYPE_INDIVIDUAL => __('suchak.business_types.individual'),
            SuchakAccount::BUSINESS_TYPE_BUREAU => __('suchak.business_types.bureau'),
            SuchakAccount::BUSINESS_TYPE_ORGANIZATION => __('suchak.business_types.organization'),
        ];
    }

    /**
     * @return list<string>
     */
    private function allowedVerificationTypes(): array
    {
        return [
            SuchakVerificationRecord::TYPE_IDENTITY,
            SuchakVerificationRecord::TYPE_OFFICE,
            SuchakVerificationRecord::TYPE_BUSINESS,
        ];
    }

    private function flashOtpIfVisible(Request $request, ?string $otp): void
    {
        if ($otp !== null) {
            $request->session()->put('suchak_registration_otp_display', $otp);
        }
    }

    private function otpDeliveryMessage(string $delivery): string
    {
        return match ($delivery) {
            'dev_show' => __('suchak.register.otp_delivery_dev_show'),
            'whatsapp' => __('suchak.register.otp_delivery_whatsapp'),
            'disabled' => __('suchak.register.otp_delivery_disabled'),
            default => __('suchak.register.otp_delivery_default'),
        };
    }
}
