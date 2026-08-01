<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakCustomerAgreement;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAgreementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * POST /api/v1/suchak/customer-agreements/{agreement}/acceptance-link
 *
 * The missing half of the customer acceptance flow. The public page and the
 * token that opens it already shipped; until this endpoint existed nothing in
 * the product could mint that token, so the page was unreachable.
 *
 * Thin adapter, no new rules: SuchakAgreementService::issueAcceptanceLink()
 * still owns the token, the expiry, the audit row and the message. What this
 * class adds is the mobile-side authorisation the sibling Suchak controllers
 * all use — the caller must hold a Suchak account, and the agreement must be
 * one of THAT account's rows — on top of (never instead of) the service's own
 * assertTermsActor.
 *
 * Re-issuing is deliberately the same call: a Suchak whose customer lost the
 * WhatsApp forward posts again and the previous link dies. Hence the throttle,
 * which matches the public consent/agreement decision routes.
 */
class SuchakAgreementLinkApiController extends Controller
{
    public function __invoke(
        Request $request,
        int $agreement,
        SuchakAgreementService $agreementService,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return response()->json([
                'success' => false,
                'message' => 'सूचक खाते आवश्यक आहे.',
            ], 403);
        }

        /** @var SuchakCustomerAgreement|null $model */
        $model = SuchakCustomerAgreement::query()
            ->whereKey($agreement)
            ->where('suchak_account_id', $user->suchakAccount->id)
            ->first();

        if ($model === null) {
            return response()->json([
                'success' => false,
                'message' => 'हा करार तुमच्या खात्यात सापडला नाही.',
            ], 404);
        }

        // Read the state back in Marathi BEFORE the service throws in English.
        // The service still enforces it — this only decides what the Suchak is
        // told, because "already accepted" and "superseded" need different
        // actions from him and one generic refusal would hide which.
        $refusal = $this->refusalReason($model);
        if ($refusal !== null) {
            return response()->json(['success' => false, 'message' => $refusal], 422);
        }

        try {
            $link = $agreementService->issueAcceptanceLink(
                $model,
                $user,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $this->translatedServiceRefusal($exception),
            ], 422);
        }

        /** @var SuchakCustomerAgreement $fresh */
        $fresh = $link['agreement'];

        return response()->json([
            'success' => true,
            'message' => 'करार लिंक तयार झाली आहे.',
            'data' => [
                'agreement_id' => (int) $fresh->id,
                'agreement_revision' => (int) $fresh->agreement_revision,
                'terms_status' => $fresh->terms_status,
                'acceptance_url' => $link['acceptance_url'],
                'expires_at' => $link['expires_at']->toIso8601String(),
                'forward_message' => $link['forward_message'],
            ],
        ]);
    }

    /**
     * Null means "a link can be issued". Anything else is the Marathi sentence
     * the Suchak sees, and each one names the next move.
     */
    private function refusalReason(SuchakCustomerAgreement $agreement): ?string
    {
        return match ($agreement->terms_status) {
            SuchakCustomerAgreement::TERMS_PENDING => null,
            SuchakCustomerAgreement::TERMS_ACCEPTED => 'हा करार ग्राहकाने आधीच स्वीकारला आहे.',
            SuchakCustomerAgreement::TERMS_SUPERSEDED => 'हा करार आता वापरात नाही. नवीन करार तयार करून पाठवा.',
            SuchakCustomerAgreement::TERMS_DECLINED => 'ग्राहकाने हा करार नाकारला आहे. नवीन करार तयार करून पाठवा.',
            SuchakCustomerAgreement::TERMS_EXPIRED => 'या कराराची मुदत संपली आहे. नवीन करार तयार करून पाठवा.',
            SuchakCustomerAgreement::TERMS_BYPASSED,
            SuchakCustomerAgreement::TERMS_NOT_REQUIRED => 'या करारासाठी ग्राहकाच्या स्वीकाराची गरज नाही.',
            default => 'या करारासाठी आता लिंक पाठवता येणार नाही.',
        };
    }

    /**
     * The service speaks English to the developer. Only two of its refusals can
     * reach a Suchak who passed the checks above — a stale package snapshot and
     * an actor the agreement does not belong to — and both have an action he
     * can take, so both get named.
     */
    private function translatedServiceRefusal(InvalidArgumentException $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'Suchak package changed')) {
            return 'सेवा तपशील बदलले आहेत. नवीन करार तयार करून पाठवा.';
        }

        if (str_contains($message, 'agreement terms')) {
            return 'हा करार पाठवण्याची परवानगी तुम्हाला नाही.';
        }

        return 'या करारासाठी आता लिंक पाठवता येणार नाही.';
    }
}
