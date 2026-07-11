<?php

namespace App\Http\Controllers;

use App\Services\Intake\BulkIntakePublicRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BulkIntakePublicRegistrationController extends Controller
{
    public function show(string $token, BulkIntakePublicRegistrationService $registrationService): View|RedirectResponse
    {
        $item = $registrationService->itemForToken($token);
        abort_unless($item !== null, 404);

        $gate = $registrationService->accessGate($item);
        abort_unless($gate['allowed'], 403, $registrationService->accessDeniedMessage((string) $gate['reason']));

        if ($registrationService->isRegistrationFormComplete($item)) {
            return redirect()->route($registrationService->nextStepRouteName($item), ['token' => $token]);
        }

        $payload = $registrationService->formPayload($item);
        if ($payload['prefer_marathi_labels'] ?? false) {
            app()->setLocale('mr');
        }

        return view('bulk-intake.register', [
            'token' => $token,
            'payload' => $payload,
        ]);
    }

    public function store(string $token, Request $request, BulkIntakePublicRegistrationService $registrationService): RedirectResponse
    {
        $item = $registrationService->itemForToken($token);
        abort_unless($item !== null, 404);

        $gate = $registrationService->accessGate($item);
        abort_unless($gate['allowed'], 403);

        if ($registrationService->isRegistrationFormComplete($item)) {
            return redirect()->route($registrationService->nextStepRouteName($item), ['token' => $token]);
        }

        $registrationService->save($item, $request);

        return redirect()
            ->route('bulk-intake.register.complete', ['token' => $token])
            ->with('success', 'नोंदणी माहिती जतन झाली. कृपया फोटो अपलोड करा.');
    }

    public function complete(string $token, BulkIntakePublicRegistrationService $registrationService): View|RedirectResponse
    {
        $item = $registrationService->itemForToken($token);
        abort_unless($item !== null, 404);

        $gate = $registrationService->postFormAccessGate($item);
        abort_unless($gate['allowed'], 403, $registrationService->accessDeniedMessage((string) $gate['reason']));

        if ($registrationService->isPhotoComplete($item) && ! $registrationService->isPreferencesComplete($item)) {
            return redirect()->route('bulk-intake.register.preferences', ['token' => $token]);
        }
        if ($registrationService->isPreferencesComplete($item)) {
            return redirect()->route('bulk-intake.register.done', ['token' => $token]);
        }

        app()->setLocale('mr');
        $payload = $registrationService->completePayload($item, $token);
        $maxUploadMb = (int) \App\Models\AdminSetting::getValue('photo_max_upload_mb', '8');
        $maxUploadKb = max(1, $maxUploadMb) * 1024;

        return view('matrimony.profile.upload-photo', array_merge($payload, [
            'bulkRegistrationPhotoStep' => true,
            'photoUploadLayout' => 'layouts.bulk-register',
            'suchakAccountPhotoUpload' => true,
            'profile' => null,
            'galleryPhotos' => collect(),
            'photoApprovalRequired' => false,
            'photoMaxPerProfile' => 1,
            'currentPhotoCount' => 0,
            'photoSlotsRemaining' => 1,
            'photoLimitReached' => false,
            'fromOnboarding' => false,
            'onboardingPhotoRequired' => true,
            'primaryPhotoProcessing' => false,
            'primaryOnlyOnCoreColumn' => false,
            'photoTargetQuery' => [],
            'photoUploadAction' => route('bulk-intake.register.photo.store', ['token' => $token]),
            'photoUploadTitle' => 'प्रोफाइल फोटो',
            'photoUploadSubtitle' => 'स्पष्ट चेहरा दिसणारा फोटो अपलोड करा. हा फोटो नोंदणीसाठी वापरला जाईल.',
            'photoUploadSubmitLabel' => 'फोटो जतन करा आणि पुढे जा',
            'photoUploadUploadingLabel' => 'अपलोड होत आहे…',
            'photoUploadSelectError' => 'कृपया प्रथम फोटो निवडा.',
            'photoUploadDefaultError' => 'फोटो अपलोड अयशस्वी. कृपया पुन्हा प्रयत्न करा.',
            'photoMaxUploadKb' => $maxUploadKb,
        ]));
    }

    public function storePhoto(string $token, Request $request, BulkIntakePublicRegistrationService $registrationService): RedirectResponse|JsonResponse
    {
        $item = $registrationService->itemForToken($token);
        abort_unless($item !== null, 404);

        $maxUploadMb = (int) \App\Models\AdminSetting::getValue('photo_max_upload_mb', '8');
        $maxUploadKb = max(1, $maxUploadMb) * 1024;

        $validated = $request->validate([
            'profile_photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.$maxUploadKb],
        ]);

        $registrationService->savePhoto($item, $validated['profile_photo']);

        return $this->photoUploadRedirectResponse(
            $request,
            route('bulk-intake.register.preferences', ['token' => $token]),
            ['success' => 'फोटो जतन झाला. आता जोडीदार प्राधान्ये भरा.']
        );
    }

    public function photoCandidate(string $token, BulkIntakePublicRegistrationService $registrationService): BinaryFileResponse
    {
        $item = $registrationService->itemForToken($token);
        abort_unless($item !== null, 404);

        $gate = $registrationService->postFormAccessGate($item);
        abort_unless($gate['allowed'], 403);

        $path = $registrationService->photoCandidateAbsolutePath($item);
        abort_unless(is_string($path) && is_readable($path), 404);

        return response()->file($path, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=60',
        ]);
    }

    public function preferences(string $token, BulkIntakePublicRegistrationService $registrationService): View|RedirectResponse
    {
        $item = $registrationService->itemForToken($token);
        abort_unless($item !== null, 404);

        $gate = $registrationService->postFormAccessGate($item);
        abort_unless($gate['allowed'], 403, $registrationService->accessDeniedMessage((string) $gate['reason']));

        if (! $registrationService->isPhotoComplete($item)) {
            return redirect()->route('bulk-intake.register.complete', ['token' => $token]);
        }
        if ($registrationService->isPreferencesComplete($item)) {
            return redirect()->route('bulk-intake.register.done', ['token' => $token]);
        }

        app()->setLocale('mr');
        $payload = $registrationService->preferencesPayload($item);

        return view('bulk-intake.preferences', array_merge($payload, [
            'token' => $token,
        ]));
    }

    public function storePreferences(string $token, Request $request, BulkIntakePublicRegistrationService $registrationService): RedirectResponse
    {
        $item = $registrationService->itemForToken($token);
        abort_unless($item !== null, 404);

        $registrationService->savePreferences($item, $request);

        return redirect()
            ->route('bulk-intake.register.done', ['token' => $token])
            ->with('success', 'जोडीदार प्राधान्ये जतन झाली. नोंदणी पूर्ण झाली!');
    }

    public function done(string $token, BulkIntakePublicRegistrationService $registrationService): View|RedirectResponse
    {
        $item = $registrationService->itemForToken($token);
        abort_unless($item !== null, 404);

        $gate = $registrationService->postFormAccessGate($item);
        abort_unless($gate['allowed'], 403, $registrationService->accessDeniedMessage((string) $gate['reason']));

        if (! $registrationService->isPreferencesComplete($item)) {
            return redirect()->route($registrationService->nextStepRouteName($item), ['token' => $token]);
        }

        app()->setLocale('mr');
        $payload = $registrationService->completePayload($item, $token);

        return view('bulk-intake.done', $payload);
    }

    private function photoUploadRedirectResponse(Request $request, string $url, array $flash = []): RedirectResponse|JsonResponse
    {
        if ($request->ajax() || $request->expectsJson()) {
            foreach ($flash as $key => $value) {
                if ($value !== null && $value !== '') {
                    session()->flash($key, $value);
                }
            }

            $hasError = isset($flash['error']) && $flash['error'] !== null && $flash['error'] !== '';

            return response()->json([
                'success' => ! $hasError,
                'redirect' => $url,
            ]);
        }

        $redirect = redirect()->to($url);
        foreach ($flash as $key => $value) {
            if ($value !== null && $value !== '') {
                $redirect = $redirect->with($key, $value);
            }
        }

        return $redirect;
    }
}
