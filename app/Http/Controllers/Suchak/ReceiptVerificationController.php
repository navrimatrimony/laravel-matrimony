<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Modules\Suchak\Services\SuchakCustomerPaymentService;
use Illuminate\View\View;
use InvalidArgumentException;

class ReceiptVerificationController extends Controller
{
    public function show(
        string $code,
        SuchakCustomerPaymentService $customerPaymentService,
    ): View {
        try {
            $receipt = $customerPaymentService->receiptByVerificationCode($code);
        } catch (InvalidArgumentException $exception) {
            abort(404, $exception->getMessage());
        }

        return view('suchak.receipts.verify', [
            'receipt' => $receipt,
            'payment' => $receipt->customerPayment,
        ]);
    }
}
