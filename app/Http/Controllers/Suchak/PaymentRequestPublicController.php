<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakPaymentContext;
use App\Modules\Suchak\Services\SuchakPaymentRequestService;
use App\Modules\Suchak\Services\SuchakQrCodeImageService;
use App\Support\LocalizedText;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use InvalidArgumentException;

class PaymentRequestPublicController extends Controller
{
    public function show(
        Request $request,
        string $token,
        SuchakPaymentRequestService $paymentRequestService,
    ): View {
        try {
            $paymentRequest = $paymentRequestService->openPublicRequest(
                $token,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            abort(410, $exception->getMessage());
        }

        $collector = $paymentRequest->paymentContext?->payment_collector;
        $showTrackAIdentity = $collector === SuchakPaymentContext::COLLECTOR_SUCHAK;
        $paymentIdentity = $showTrackAIdentity
            ? ($paymentRequest->suchakAccount?->trackAPaymentIdentity() ?? [
                'upi_vpa' => null,
                'payment_qr_url' => null,
                'is_configured' => false,
            ])
            : null;

        // Candidate name is the only field the redesigned page needs that the
        // eager-loaded relations do not already carry. customerContext is loaded;
        // pull just its candidate profile so the page can show who this is for.
        $paymentRequest->loadMissing('customerContext.candidateProfile');
        $candidateName = $paymentRequest->customerContext?->candidateProfile?->full_name;

        return view('suchak.payment-requests.show', [
            'paymentRequest' => $paymentRequest,
            'agreement' => $paymentRequest->customerAgreement,
            'package' => $paymentRequest->servicePackage,
            'showTrackAIdentity' => $showTrackAIdentity,
            'paymentIdentity' => $paymentIdentity,
            'candidateName' => $candidateName,
            'token' => $token,
        ]);
    }

    /**
     * PNG of the UPI-intent QR for this request, sized for a WhatsApp link
     * preview (og:image). Read-only: it never opens/mutates the request, so a
     * crawler fetching the image cannot flip its status. Only Track A
     * (customer → Suchak UPI) requests with a configured VPA have a QR to show.
     */
    public function qr(
        Request $request,
        string $token,
        SuchakPaymentRequestService $paymentRequestService,
        SuchakQrCodeImageService $qrCodeImageService,
    ): Response {
        $paymentRequest = $paymentRequestService->findPublicRequestForAsset($token);

        if ($paymentRequest === null) {
            abort(404);
        }

        $collector = $paymentRequest->paymentContext?->payment_collector;
        if ($collector !== SuchakPaymentContext::COLLECTOR_SUCHAK) {
            abort(404);
        }

        $identity = $paymentRequest->suchakAccount?->trackAPaymentIdentity() ?? [];
        $vpa = trim((string) ($identity['upi_vpa'] ?? ''));
        if ($vpa === '') {
            abort(404);
        }

        $payee = trim((string) LocalizedText::column($paymentRequest->suchakAccount, 'suchak_name'));
        if ($payee === '') {
            $payee = 'Suchak';
        }

        // upi://pay?pa=<vpa>&pn=<payee>&am=<amount>&cu=INR — the same intent any
        // UPI app understands, so the customer can scan and pay the exact amount.
        $intent = 'upi://pay?pa='.rawurlencode($vpa).'&pn='.rawurlencode($payee);

        $amountRaw = $paymentRequest->amount_due;
        if ($amountRaw !== null && $amountRaw !== '' && (float) $amountRaw > 0) {
            $intent .= '&am='.number_format((float) $amountRaw, 2, '.', '');
        }

        $intent .= '&cu=INR';

        $png = $qrCodeImageService->pngBytes($intent, 512);

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($png),
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
