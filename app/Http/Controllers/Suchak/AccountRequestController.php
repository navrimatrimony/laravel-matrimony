<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Modules\Suchak\Services\SuchakRegistrationService;
use App\Support\MobileNumber;
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
    public function home(Request $request): View
    {
        return view('suchak.home', [
            'suchakAccount' => $request->user()?->suchakAccount,
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
            'businessTypes' => [
                SuchakAccount::BUSINESS_TYPE_INDIVIDUAL => 'Individual Suchak',
                SuchakAccount::BUSINESS_TYPE_BUREAU => 'Marriage bureau',
                SuchakAccount::BUSINESS_TYPE_ORGANIZATION => 'Organization',
            ],
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
                ->with('error', 'Existing regular member accounts cannot be converted to Suchak. Please log out and create a separate Suchak account.');
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
            'mobile_number' => ['required', 'string', 'max:32'],
            'whatsapp_number' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'address_line' => ['required', 'string', 'max:1000'],
            'identity_document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'office_document' => [
                Rule::requiredIf(fn (): bool => in_array((string) $request->input('business_type'), [
                    SuchakAccount::BUSINESS_TYPE_BUREAU,
                    SuchakAccount::BUSINESS_TYPE_ORGANIZATION,
                ], true)),
                'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:5120',
            ],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $mobile = MobileNumber::normalize((string) $validated['mobile_number']);
        if ($mobile === null) {
            return back()
                ->withInput()
                ->withErrors(['mobile_number' => __('otp.enter_valid_10_digit_mobile')]);
        }

        Validator::make(
            ['mobile_number' => $mobile],
            ['mobile_number' => ['required', Rule::unique('users', 'mobile')]],
            ['mobile_number.unique' => __('auth.mobile_duplicate_register')]
        )->validate();

        $validated['mobile_number'] = $mobile;
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

    public function verify(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $account = $user?->suchakAccount;

        if (! $user || ! $account) {
            return redirect()->route('suchak.home');
        }

        if ($user->mobile_verified_at) {
            return redirect()
                ->route('suchak.register.status')
                ->with('status', 'Mobile number already verified. Your Suchak request is waiting for admin review.');
        }

        return view('suchak.register-verify', [
            'suchakAccount' => $account,
            'otpDisplay' => $request->session()->pull('suchak_registration_otp_display'),
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

    public function verifyRegistrationOtp(Request $request, SuchakRegistrationService $registrationService): RedirectResponse
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
            ->with('success', 'Mobile OTP verified. Your Suchak account is now waiting for admin approval.');
    }

    public function status(Request $request): View|RedirectResponse
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
            ->with('info', 'Suchak registration is separate from regular user accounts.');
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
            'dev_show' => 'OTP generated. Enter the test OTP shown on this page.',
            'whatsapp' => 'OTP sent on WhatsApp. Enter it to continue.',
            'disabled' => 'OTP delivery is disabled in admin settings. Please contact admin to verify this Suchak request.',
            default => 'OTP generated. Enter it to continue.',
        };
    }
}
