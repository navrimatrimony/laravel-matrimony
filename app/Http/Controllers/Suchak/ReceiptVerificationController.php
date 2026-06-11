<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Modules\Suchak\Services\SuchakCustomerPaymentService;
use App\Modules\Suchak\Services\SuchakQrCodeImageService;
use App\Modules\Suchak\Services\SuchakWhiteLabelSharingKitService;
use Illuminate\View\View;
use InvalidArgumentException;

class ReceiptVerificationController extends Controller
{
    public function show(
        string $code,
        SuchakCustomerPaymentService $customerPaymentService,
        SuchakQrCodeImageService $qrCodeImageService,
    ): View {
        try {
            $receipt = $customerPaymentService->receiptByVerificationCode($code);
        } catch (InvalidArgumentException $exception) {
            abort(404, $exception->getMessage());
        }

        $verificationUrl = $customerPaymentService->receiptVerificationUrl((string) $receipt->verification_code);

        return view('suchak.receipts.verify', [
            'receipt' => $receipt,
            'payment' => $receipt->customerPayment,
            'verificationUrl' => $verificationUrl,
            'verificationQrDataUri' => $qrCodeImageService->svgDataUri($verificationUrl, 220),
            'poweredByFooter' => SuchakWhiteLabelSharingKitService::POWERED_BY_FOOTER,
        ]);
    }
}
