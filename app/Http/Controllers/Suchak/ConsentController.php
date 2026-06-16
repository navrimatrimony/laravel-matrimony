<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakConsent;
use App\Models\SuchakProfileRepresentation;
use App\Modules\Suchak\Services\SuchakConsentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class ConsentController extends Controller
{
    private const CONSENT_GIVER_RELATIONS = [
        'candidate_self',
        'father',
        'mother',
        'brother',
        'sister',
        'guardian',
        'other_family',
    ];

    public function request(
        Request $request,
        SuchakProfileRepresentation $representation,
        SuchakConsentService $consentService,
    ): RedirectResponse|JsonResponse {
        try {
            $validated = $this->requestPayload($request);

            return $this->dispatchConsentRequest($request, $representation, $consentService, $validated, false);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage())->withInput();
        }
    }

    public function renew(
        Request $request,
        SuchakProfileRepresentation $representation,
        SuchakConsentService $consentService,
    ): RedirectResponse|JsonResponse {
        try {
            $validated = $this->requestPayload($request);
            $validated['_renewal'] = true;
            $validated['_preserve_representation_status'] = true;

            return $this->dispatchConsentRequest($request, $representation, $consentService, $validated, true);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage())->withInput();
        }
    }

    public function resend(
        Request $request,
        SuchakConsent $consent,
        SuchakConsentService $consentService,
    ): RedirectResponse|JsonResponse {
        try {
            $result = $consentService->resendConsent(
                $consent,
                $request->user(),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return $this->backWithSecureLinkResult($result, 'Consent link regenerated.', $request);
    }

    public function cancelPending(
        Request $request,
        SuchakConsent $consent,
        SuchakConsentService $consentService,
    ): RedirectResponse {
        try {
            $consentService->cancelPendingConsent(
                $consent,
                $request->user(),
                'Suchak cancelled pending consent request to create a new one.',
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Pending consent request cancelled. You can create a new request now.');
    }

    public function sendOtp(
        Request $request,
        SuchakConsent $consent,
        SuchakConsentService $consentService,
    ): RedirectResponse {
        return back()->with('error', 'Code-based Suchak customer consent is disabled. Use a secure consent link.');
    }

    public function verifyOtp(
        Request $request,
        SuchakConsent $consent,
        SuchakConsentService $consentService,
    ): RedirectResponse {
        return back()->with('error', 'Code-based Suchak customer consent is disabled. Use a secure consent link.');
    }

    public function acceptManual(
        Request $request,
        SuchakConsent $consent,
        SuchakConsentService $consentService,
    ): RedirectResponse {
        $validated = $request->validate(array_merge($this->acceptanceRules(), [
            'evidence_note' => ['nullable', 'string', 'max:1000'],
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
        $method = $this->methodFromRequest($request);
        $needsSignedProof = $method === SuchakConsent::METHOD_OFFLINE_SIGNED_PROOF;
        $proofRules = $needsSignedProof
            ? ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120']
            : ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'];
        $declarationRules = $needsSignedProof
            ? ['required', 'accepted']
            : ['nullable'];

        $validated = $request->validate([
            'consent_type' => ['nullable', 'string', Rule::in(SuchakConsent::TYPES)],
            'consent_method' => ['nullable', 'string', Rule::in(SuchakConsent::METHODS)],
            'consent_channel' => ['nullable', 'string', Rule::in(SuchakConsent::CHANNELS)],
            'consent_given_by_name' => ['required', 'string', 'max:255'],
            'consent_giver_relation' => ['required', 'string', Rule::in(self::CONSENT_GIVER_RELATIONS)],
            'relationship_to_candidate' => ['nullable', 'string', 'max:255'],
            'intended_mobile' => ['required', 'string', 'max:20'],
            'consent_mobile_number' => ['nullable', 'string', 'max:20'],
            'evidence_note' => ['nullable', 'string', 'max:1000'],
            'proof_document' => $proofRules,
            'declaration' => $declarationRules,
        ]);

        if (($validated['proof_document'] ?? null) instanceof UploadedFile) {
            $validated['proof_document'] = $request->file('proof_document');
        }

        $validated['consent_type'] = $validated['consent_type'] ?? SuchakConsent::TYPE_ONE_YEAR;
        $validated['consent_method'] = $method;
        $validated['consent_channel'] = $method;
        $validated['relationship_to_candidate'] = $validated['consent_giver_relation'];
        $validated['consent_mobile_number'] = $validated['consent_mobile_number'] ?? $validated['intended_mobile'];

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function acceptanceRules(): array
    {
        return [
            'consent_given_by_name' => ['nullable', 'string', 'max:255'],
            'consent_giver_relation' => ['nullable', 'string', Rule::in(self::CONSENT_GIVER_RELATIONS)],
            'relationship_to_candidate' => ['nullable', 'string', 'max:255'],
            'intended_mobile' => ['nullable', 'string', 'max:20'],
            'consent_mobile_number' => ['nullable', 'string', 'max:20'],
        ];
    }

    private function methodFromRequest(Request $request): string
    {
        $method = (string) $request->input('consent_method', $request->input('consent_channel', ''));

        return match ($method) {
            SuchakConsent::CHANNEL_OFFLINE_PROOF, SuchakConsent::CHANNEL_OFFLINE_SIGNED_PROOF => SuchakConsent::METHOD_OFFLINE_SIGNED_PROOF,
            SuchakConsent::CHANNEL_ADMIN_ASSISTED, SuchakConsent::CHANNEL_PLATFORM_ASSISTED_LINK => SuchakConsent::METHOD_PLATFORM_ASSISTED_LINK,
            SuchakConsent::CHANNEL_SMS_OTP, SuchakConsent::CHANNEL_VOICE_OTP => throw new InvalidArgumentException('Code-based Suchak customer consent is disabled. Use a secure consent link.'),
            SuchakConsent::CHANNEL_WHATSAPP_DEEP_LINK, SuchakConsent::CHANNEL_SUCHAK_RELAYED_LINK => SuchakConsent::METHOD_SUCHAK_RELAYED_LINK,
            default => $method,
        };
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function dispatchConsentRequest(
        Request $request,
        SuchakProfileRepresentation $representation,
        SuchakConsentService $consentService,
        array $validated,
        bool $renewal,
    ): RedirectResponse|JsonResponse {
        $method = (string) $validated['consent_method'];

        if ($method === SuchakConsent::METHOD_OFFLINE_SIGNED_PROOF) {
            $consent = $consentService->createOfflineProofConsent(
                $representation,
                $request->user(),
                $validated,
                $request->ip(),
                $request->userAgent(),
            );

            return back()
                ->with('success', 'Signed proof uploaded and consent accepted.')
                ->with('suchak_consent_notice_id', $consent->id);
        }

        $result = $method === SuchakConsent::METHOD_PLATFORM_ASSISTED_LINK
            ? $consentService->createPlatformAssistedLinkConsent(
                $representation,
                $request->user(),
                $validated,
                $request->ip(),
                $request->userAgent(),
            )
            : $consentService->createSuchakRelayedLinkConsent(
                $representation,
                $request->user(),
                $validated,
                $request->ip(),
                $request->userAgent(),
            );

        return $this->backWithSecureLinkResult(
            $result,
            $renewal ? 'Consent renewal link created.' : 'Consent link created.',
            $request,
        );
    }

    /**
     * @param  array{consent: SuchakConsent, raw_token: string, consent_url: string, message: string}  $result
     */
    private function backWithSecureLinkResult(array $result, string $success, ?Request $request = null): RedirectResponse|JsonResponse
    {
        $whatsappUrl = $this->whatsappShareUrl($result['consent'], $result['message']);

        if ($request?->expectsJson()) {
            session()->flash('success', $success);
            session()->flash('suchak_consent_notice_id', $result['consent']->id);
            session()->flash('suchak_consent_url', $result['consent_url']);
            session()->flash('suchak_consent_forward_message', $result['message']);
            session()->flash('suchak_consent_whatsapp_url', $whatsappUrl);

            return response()->json([
                'message' => $success,
                'redirect_url' => $request->headers->get('referer') ?: route('suchak.dashboard', [
                    'dashboard_tab' => 'profiles',
                    'manage_representation' => $result['consent']->representation_id,
                ]),
                'consent_url' => $result['consent_url'],
                'forward_message' => $result['message'],
                'whatsapp_url' => $whatsappUrl,
            ]);
        }

        return back()
            ->with('success', $success)
            ->with('suchak_consent_notice_id', $result['consent']->id)
            ->with('suchak_consent_url', $result['consent_url'])
            ->with('suchak_consent_forward_message', $result['message'])
            ->with('suchak_consent_whatsapp_url', $whatsappUrl);
    }

    private function whatsappShareUrl(SuchakConsent $consent, string $message): string
    {
        $digits = preg_replace('/\D+/', '', (string) ($consent->intended_mobile ?: $consent->consent_mobile_number));

        if (strlen($digits) === 10) {
            $digits = '91'.$digits;
        }

        $query = http_build_query(['text' => $message], '', '&', PHP_QUERY_RFC3986);

        return $digits !== ''
            ? "https://wa.me/{$digits}?{$query}"
            : "https://wa.me/?{$query}";
    }
}
