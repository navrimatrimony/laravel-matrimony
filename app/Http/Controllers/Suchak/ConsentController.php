<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakConsent;
use App\Models\SuchakProfileRepresentation;
use App\Modules\Suchak\Services\SuchakConsentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class ConsentController extends Controller
{
    public function request(
        Request $request,
        SuchakProfileRepresentation $representation,
        SuchakConsentService $consentService,
    ): RedirectResponse {
        $validated = $this->requestPayload($request);

        try {
            $consentService->requestConsent(
                $representation,
                $request->user(),
                $validated,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage())->withInput();
        }

        return back()->with('success', 'Consent request recorded.');
    }

    public function renew(
        Request $request,
        SuchakProfileRepresentation $representation,
        SuchakConsentService $consentService,
    ): RedirectResponse {
        $validated = $this->requestPayload($request);

        try {
            $consentService->renewConsent(
                $representation,
                $request->user(),
                $validated,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage())->withInput();
        }

        return back()->with('success', 'Consent renewal request recorded.');
    }

    public function resend(
        Request $request,
        SuchakConsent $consent,
        SuchakConsentService $consentService,
    ): RedirectResponse {
        try {
            $consentService->resendConsent(
                $consent,
                $request->user(),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Consent request resent.');
    }

    public function sendOtp(
        Request $request,
        SuchakConsent $consent,
        SuchakConsentService $consentService,
    ): RedirectResponse {
        $validated = $request->validate([
            'otp' => ['required', 'digits:6'],
        ]);

        try {
            $consentService->recordOtpSent(
                $consent,
                $validated['otp'],
                $request->user(),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Consent OTP hash recorded.');
    }

    public function verifyOtp(
        Request $request,
        SuchakConsent $consent,
        SuchakConsentService $consentService,
    ): RedirectResponse {
        $validated = $request->validate(array_merge([
            'otp' => ['required', 'digits:6'],
        ], $this->acceptanceRules()));

        try {
            $consentService->verifyOtpAndAccept($consent, $validated['otp'], $validated);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage())->withInput();
        }

        return back()->with('success', 'Consent OTP verified.');
    }

    public function acceptManual(
        Request $request,
        SuchakConsent $consent,
        SuchakConsentService $consentService,
    ): RedirectResponse {
        $validated = $request->validate(array_merge($this->acceptanceRules(), [
            'evidence_note' => ['required', 'string', 'min:10', 'max:1000'],
        ]));

        try {
            $consentService->acceptManualProof(
                $consent,
                $request->user(),
                $validated,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage())->withInput();
        }

        return back()->with('success', 'Manual consent proof accepted.');
    }

    public function revoke(
        Request $request,
        SuchakConsent $consent,
        SuchakConsentService $consentService,
    ): RedirectResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        try {
            $consentService->revoke($consent, $request->user(), $validated['reason']);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage())->withInput();
        }

        return back()->with('success', 'Consent revoked.');
    }

    /**
     * @return array<string, mixed>
     */
    private function requestPayload(Request $request): array
    {
        return $request->validate([
            'consent_type' => ['required', 'string', Rule::in(SuchakConsent::TYPES)],
            'consent_channel' => ['required', 'string', Rule::in(SuchakConsent::CHANNELS)],
            'consent_given_by_name' => ['nullable', 'string', 'max:255'],
            'relationship_to_candidate' => ['nullable', 'string', 'max:255'],
            'consent_mobile_number' => ['nullable', 'string', 'max:20'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function acceptanceRules(): array
    {
        return [
            'consent_given_by_name' => ['nullable', 'string', 'max:255'],
            'relationship_to_candidate' => ['nullable', 'string', 'max:255'],
            'consent_mobile_number' => ['nullable', 'string', 'max:20'],
        ];
    }
}
