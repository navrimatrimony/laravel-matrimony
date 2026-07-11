<?php

namespace App\Http\Controllers;

use App\Services\Intake\BulkIntakePublicRegistrationService;
use App\Services\Intake\BulkIntakeRegistrationAccountSetupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BulkIntakeRegistrationAccountController extends Controller
{
    public function email(
        string $token,
        BulkIntakePublicRegistrationService $registrationService,
        BulkIntakeRegistrationAccountSetupService $accountSetup,
    ): View|RedirectResponse {
        $item = $this->resolveItem($token, $registrationService);
        if ($redirect = $this->preferencesRedirect($item, $registrationService, $token)) {
            return $redirect;
        }

        if ($accountSetup->isEmailStepComplete($item)) {
            return redirect()->route($registrationService->nextStepRouteName($item), ['token' => $token]);
        }

        app()->setLocale('mr');

        return view('bulk-intake.email', $accountSetup->emailStepPayload($item, $token));
    }

    public function verifyGoogleEmail(
        string $token,
        Request $request,
        BulkIntakePublicRegistrationService $registrationService,
        BulkIntakeRegistrationAccountSetupService $accountSetup,
    ): RedirectResponse {
        $item = $this->resolveItem($token, $registrationService);
        if ($redirect = $this->preferencesRedirect($item, $registrationService, $token)) {
            return $redirect;
        }

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'id_token' => ['required', 'string', 'max:8192'],
        ]);

        $accountSetup->verifyGoogleEmail(
            $item,
            (string) $validated['email'],
            (string) $validated['id_token'],
            $request,
        );

        return redirect()
            ->route('bulk-intake.register.password', ['token' => $token])
            ->with('success', 'Google ईमेल यशस्वीरित्या पुष्टी झाला.');
    }

    public function sendEmailOtp(
        string $token,
        Request $request,
        BulkIntakePublicRegistrationService $registrationService,
        BulkIntakeRegistrationAccountSetupService $accountSetup,
    ): RedirectResponse {
        $item = $this->resolveItem($token, $registrationService);
        if ($redirect = $this->preferencesRedirect($item, $registrationService, $token)) {
            return $redirect;
        }

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $accountSetup->sendEmailOtp($item, (string) $validated['email'], $token, $request);

        return redirect()
            ->route('bulk-intake.register.email', ['token' => $token])
            ->with('success', 'OTP तुमच्या ईमेलवर पाठवला आहे. कृपया खाली टाका.');
    }

    public function verifyEmailOtp(
        string $token,
        Request $request,
        BulkIntakePublicRegistrationService $registrationService,
        BulkIntakeRegistrationAccountSetupService $accountSetup,
    ): RedirectResponse {
        $item = $this->resolveItem($token, $registrationService);
        if ($redirect = $this->preferencesRedirect($item, $registrationService, $token)) {
            return $redirect;
        }

        $validated = $request->validate([
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        $accountSetup->verifyEmailOtp($item, $token, (string) $validated['otp'], $request);

        return redirect()
            ->route('bulk-intake.register.password', ['token' => $token])
            ->with('success', 'ईमेल यशस्वीरित्या पुष्टी झाला.');
    }

    public function skipEmail(
        string $token,
        BulkIntakePublicRegistrationService $registrationService,
        BulkIntakeRegistrationAccountSetupService $accountSetup,
    ): RedirectResponse {
        $item = $this->resolveItem($token, $registrationService);
        if ($redirect = $this->preferencesRedirect($item, $registrationService, $token)) {
            return $redirect;
        }

        $accountSetup->skipEmailStep($item);

        return redirect()
            ->route('bulk-intake.register.password', ['token' => $token]);
    }

    public function password(
        string $token,
        BulkIntakePublicRegistrationService $registrationService,
        BulkIntakeRegistrationAccountSetupService $accountSetup,
    ): View|RedirectResponse {
        $item = $this->resolveItem($token, $registrationService);
        if ($redirect = $this->preferencesRedirect($item, $registrationService, $token)) {
            return $redirect;
        }

        if (! $accountSetup->isEmailStepComplete($item)) {
            return redirect()->route('bulk-intake.register.email', ['token' => $token]);
        }

        if ($accountSetup->isPasswordStepComplete($item)) {
            return redirect()->route($registrationService->nextStepRouteName($item), ['token' => $token]);
        }

        app()->setLocale('mr');

        return view('bulk-intake.password', $accountSetup->passwordStepPayload($item, $token));
    }

    public function storePassword(
        string $token,
        Request $request,
        BulkIntakePublicRegistrationService $registrationService,
        BulkIntakeRegistrationAccountSetupService $accountSetup,
    ): RedirectResponse {
        $item = $this->resolveItem($token, $registrationService);
        if ($redirect = $this->preferencesRedirect($item, $registrationService, $token)) {
            return $redirect;
        }

        if (! $accountSetup->isEmailStepComplete($item)) {
            return redirect()->route('bulk-intake.register.email', ['token' => $token]);
        }

        $accountSetup->setPassword(
            $item,
            (string) $request->input('password', ''),
            (string) $request->input('password_confirmation', ''),
        );

        return redirect()
            ->route('bulk-intake.register.done', ['token' => $token])
            ->with('success', 'पासवर्ड जतन झाला. नोंदणी पूर्ण झाली!');
    }

    public function skipPassword(
        string $token,
        BulkIntakePublicRegistrationService $registrationService,
        BulkIntakeRegistrationAccountSetupService $accountSetup,
    ): RedirectResponse {
        $item = $this->resolveItem($token, $registrationService);
        if ($redirect = $this->preferencesRedirect($item, $registrationService, $token)) {
            return $redirect;
        }

        if (! $accountSetup->isEmailStepComplete($item)) {
            return redirect()->route('bulk-intake.register.email', ['token' => $token]);
        }

        $accountSetup->skipPasswordStep($item);

        return redirect()
            ->route('bulk-intake.register.done', ['token' => $token])
            ->with('success', 'जोडीदार प्राधान्ये जतन झाली. नोंदणी पूर्ण झाली!');
    }

    private function resolveItem(string $token, BulkIntakePublicRegistrationService $registrationService)
    {
        $item = $registrationService->itemForToken($token);
        abort_unless($item !== null, 404);

        $gate = $registrationService->postFormAccessGate($item);
        abort_unless($gate['allowed'], 403, $registrationService->accessDeniedMessage((string) $gate['reason']));

        return $item;
    }

    private function preferencesRedirect($item, BulkIntakePublicRegistrationService $registrationService, string $token): ?RedirectResponse
    {
        if ($registrationService->isPreferencesComplete($item)) {
            return null;
        }

        return redirect()->route('bulk-intake.register.preferences', ['token' => $token]);
    }
}
