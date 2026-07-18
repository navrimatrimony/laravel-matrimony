<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakConsent;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Thin mobile adapter over SuchakConsentService (mirrors web ConsentController).
 * No new consent business rules.
 */
class SuchakConsentsApiController extends Controller
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

    public function store(
        Request $request,
        int $representation,
        SuchakConsentService $consentService,
    ): JsonResponse {
        return $this->dispatch($request, $representation, $consentService, renewal: false);
    }

    public function renew(
        Request $request,
        int $representation,
        SuchakConsentService $consentService,
    ): JsonResponse {
        return $this->dispatch($request, $representation, $consentService, renewal: true);
    }

    private function dispatch(
        Request $request,
        int $representationId,
        SuchakConsentService $consentService,
        bool $renewal,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return response()->json(['success' => false, 'message' => 'Suchak account is required.'], 403);
        }

        /** @var SuchakAccount $account */
        $account = $user->suchakAccount;

        /** @var SuchakProfileRepresentation|null $representation */
        $representation = SuchakProfileRepresentation::query()
            ->whereKey($representationId)
            ->where('suchak_account_id', $account->id)
            ->first();

        if ($representation === null) {
            return response()->json(['success' => false, 'message' => 'Customer not found for this Suchak account.'], 404);
        }

        try {
            $validated = $this->requestPayload($request);
            if ($renewal) {
                $validated['_renewal'] = true;
                $validated['_preserve_representation_status'] = true;
            }

            $method = (string) $validated['consent_method'];

            if ($method === SuchakConsent::METHOD_OFFLINE_SIGNED_PROOF) {
                $consent = $consentService->createOfflineProofConsent(
                    $representation,
                    $user,
                    $validated,
                    $request->ip(),
                    $request->userAgent(),
                );

                return response()->json([
                    'success' => true,
                    'message' => 'Signed proof uploaded and consent accepted.',
                    'data' => [
                        'consent_id' => $consent->id,
                        'consent_status' => $consent->consent_status,
                        'representation_id' => $consent->representation_id,
                        'renewal' => $renewal,
                    ],
                ], 201);
            }

            $result = $method === SuchakConsent::METHOD_PLATFORM_ASSISTED_LINK
                ? $consentService->createPlatformAssistedLinkConsent(
                    $representation,
                    $user,
                    $validated,
                    $request->ip(),
                    $request->userAgent(),
                )
                : $consentService->createSuchakRelayedLinkConsent(
                    $representation,
                    $user,
                    $validated,
                    $request->ip(),
                    $request->userAgent(),
                );

            /** @var SuchakConsent $consent */
            $consent = $result['consent'];
            $message = (string) ($result['message'] ?? '');
            $whatsappUrl = $this->whatsappShareUrl($consent, $message);

            return response()->json([
                'success' => true,
                'message' => $renewal ? 'Consent renewal link created.' : 'Consent link created.',
                'data' => [
                    'consent_id' => $consent->id,
                    'consent_status' => $consent->consent_status,
                    'representation_id' => $consent->representation_id,
                    'consent_url' => $result['consent_url'] ?? null,
                    'forward_message' => $message,
                    'whatsapp_url' => $whatsappUrl,
                    'renewal' => $renewal,
                ],
            ], 201);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function resend(
        Request $request,
        int $consent,
        SuchakConsentService $consentService,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return response()->json(['success' => false, 'message' => 'Suchak account is required.'], 403);
        }

        /** @var SuchakConsent|null $model */
        $model = SuchakConsent::query()
            ->whereKey($consent)
            ->where('suchak_account_id', $user->suchakAccount->id)
            ->first();

        if ($model === null) {
            return response()->json(['success' => false, 'message' => 'Consent not found for this account.'], 404);
        }

        try {
            $result = $consentService->resendConsent(
                $model,
                $user,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        /** @var SuchakConsent $fresh */
        $fresh = $result['consent'];
        $message = (string) ($result['message'] ?? '');

        return response()->json([
            'success' => true,
            'message' => 'Consent link regenerated.',
            'data' => [
                'consent_id' => $fresh->id,
                'consent_url' => $result['consent_url'] ?? null,
                'forward_message' => $message,
                'whatsapp_url' => $this->whatsappShareUrl($fresh, $message),
            ],
        ]);
    }

    public function cancelPending(
        Request $request,
        int $consent,
        SuchakConsentService $consentService,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return response()->json(['success' => false, 'message' => 'Suchak account is required.'], 403);
        }

        /** @var SuchakConsent|null $model */
        $model = SuchakConsent::query()
            ->whereKey($consent)
            ->where('suchak_account_id', $user->suchakAccount->id)
            ->first();

        if ($model === null) {
            return response()->json(['success' => false, 'message' => 'Consent not found for this account.'], 404);
        }

        try {
            $consentService->cancelPendingConsent(
                $model,
                $user,
                'Suchak cancelled pending consent from mobile to create a new request.',
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Pending consent cancelled.',
            'data' => ['consent_id' => $model->id],
        ]);
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

    private function methodFromRequest(Request $request): string
    {
        $method = (string) $request->input(
            'consent_method',
            $request->input('consent_channel', SuchakConsent::METHOD_SUCHAK_RELAYED_LINK),
        );

        return match ($method) {
            SuchakConsent::CHANNEL_OFFLINE_PROOF, SuchakConsent::CHANNEL_OFFLINE_SIGNED_PROOF => SuchakConsent::METHOD_OFFLINE_SIGNED_PROOF,
            SuchakConsent::CHANNEL_ADMIN_ASSISTED, SuchakConsent::CHANNEL_PLATFORM_ASSISTED_LINK => SuchakConsent::METHOD_PLATFORM_ASSISTED_LINK,
            SuchakConsent::CHANNEL_SMS_OTP, SuchakConsent::CHANNEL_VOICE_OTP => throw new InvalidArgumentException('Code-based Suchak customer consent is disabled. Use a secure consent link.'),
            SuchakConsent::CHANNEL_WHATSAPP_DEEP_LINK, SuchakConsent::CHANNEL_SUCHAK_RELAYED_LINK, '' => SuchakConsent::METHOD_SUCHAK_RELAYED_LINK,
            default => $method !== '' ? $method : SuchakConsent::METHOD_SUCHAK_RELAYED_LINK,
        };
    }

    private function whatsappShareUrl(SuchakConsent $consent, string $message): string
    {
        $digits = preg_replace('/\D+/', '', (string) ($consent->intended_mobile ?: $consent->consent_mobile_number));

        if (strlen((string) $digits) === 10) {
            $digits = '91'.$digits;
        }

        $query = http_build_query(['text' => $message], '', '&', PHP_QUERY_RFC3986);

        return $digits !== ''
            ? "https://wa.me/{$digits}?{$query}"
            : "https://wa.me/?{$query}";
    }
}
