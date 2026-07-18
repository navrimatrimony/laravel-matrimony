<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakPaymentContext;
use App\Modules\Suchak\Services\SuchakPaymentRequestService;
use Illuminate\Http\Request;
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

        return view('suchak.payment-requests.show', [
            'paymentRequest' => $paymentRequest,
            'agreement' => $paymentRequest->customerAgreement,
            'package' => $paymentRequest->servicePackage,
            'showTrackAIdentity' => $showTrackAIdentity,
            'paymentIdentity' => $paymentIdentity,
        ]);
    }
}
