<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakConsent;
use App\Models\SuchakProfileRepresentation;
use App\Modules\Suchak\Services\SuchakConsentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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
            $result = $consentService->requestConsent(
                $representation,
                $request->user(),
                $validated,
                $request->ip(),
                $request->userAgent(),
            );

            if (($validated['consent_channel'] ?? null) === SuchakConsent::CHANNEL_OFFLINE_PROOF) {
                $consentService->acceptManualProof(
                    $result['consent'],
                    $request->user(),
                    $validated,
                    $request->ip(),
                    $request->userAgent(),
                );

                return back()->with('success', 'Signed proof uploaded and consent accepted.');
            }

            if ($this->shouldIssuePlatformOtp((string) ($validated['consent_channel'] ?? ''))) {
                return $this->backWithPlatformOtpResult(
                    $consentService->issuePlatformOtp(
                        $result['consent'],
                        $request->user(),
                        $request->ip(),
                        $request->userAgent(),
                    ),
                    'Consent request recorded. Platform OTP sent to customer.',
                );
            }
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
        try {
            return $this->backWithPlatformOtpResult(
                $consentService->issuePlatformOtp(
                    $consent,
                    $request->user(),
                    $request->ip(),
                    $request->userAgent(),
                ),
                'Platform OTP sent to customer.',
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }
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
            'proof_document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
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

        return back()->with('success', 'Signed proof uploaded and consent accepted.');
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
        $channel = (string) $request->input('consent_channel');
        $needsCustomerMobile = in_array($channel, [
            SuchakConsent::CHANNEL_WHATSAPP_DEEP_LINK,
            SuchakConsent::CHANNEL_SMS_OTP,
            SuchakConsent::CHANNEL_VOICE_OTP,
            SuchakConsent::CHANNEL_ADMIN_ASSISTED,
        ], true);
        $needsSignedProof = $channel === SuchakConsent::CHANNEL_OFFLINE_PROOF;

        $validated = $request->validate([
            'consent_type' => ['required', 'string', Rule::in(SuchakConsent::TYPES)],
            'consent_channel' => ['required', 'string', Rule::in(SuchakConsent::CHANNELS)],
            'consent_given_by_name' => ['nullable', 'string', 'max:255'],
            'relationship_to_candidate' => ['nullable', 'string', 'max:255'],
            'consent_mobile_number' => [Rule::requiredIf($needsCustomerMobile || $needsSignedProof), 'nullable', 'string', 'max:20'],
            'evidence_note' => [Rule::requiredIf($needsSignedProof), 'nullable', 'string', 'min:10', 'max:1000'],
            'proof_document' => [Rule::requiredIf($needsSignedProof), 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
        ]);

        if (($validated['proof_document'] ?? null) instanceof UploadedFile) {
            $validated['proof_document'] = $request->file('proof_document');
        }

        return $validated;
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

    private function shouldIssuePlatformOtp(string $channel): bool
    {
        return in_array($channel, [
            SuchakConsent::CHANNEL_WHATSAPP_DEEP_LINK,
            SuchakConsent::CHANNEL_SMS_OTP,
            SuchakConsent::CHANNEL_VOICE_OTP,
            SuchakConsent::CHANNEL_ADMIN_ASSISTED,
        ], true);
    }

    /**
     * @param  array{consent: SuchakConsent, raw_otp: string|null, delivery: string, suchak_message: string}  $result
     */
    private function backWithPlatformOtpResult(array $result, string $success): RedirectResponse
    {
        $redirect = back()
            ->with('success', $success)
            ->with('suchak_consent_notice_id', $result['consent']->id)
            ->with('suchak_consent_forward_message', $result['suchak_message']);

        if ($result['raw_otp'] !== null) {
            $redirect->with('suchak_consent_otp_display', $result['raw_otp']);
        }

        return $redirect;
    }
}
