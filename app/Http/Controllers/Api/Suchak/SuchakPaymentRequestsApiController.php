<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPaymentRequest;
use App\Models\SuchakPlanInvoice;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakBillingCatalogService;
use App\Modules\Suchak\Services\SuchakCustomerPaymentService;
use App\Modules\Suchak\Services\SuchakPaymentRequestService;
use App\Modules\Suchak\Services\SuchakPaymentStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Thin money adapters: Track A payment request create + mark-paid over existing services.
 * Payment identity (UPI/QR) is account-scoped Track A data (PO D1).
 */
class SuchakPaymentRequestsApiController extends Controller
{
    public function store(
        Request $request,
        SuchakPaymentRequestService $paymentRequestService,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return response()->json(['success' => false, 'message' => 'Suchak account is required.'], 403);
        }

        $validated = $request->validate([
            'service_package_id' => ['required', 'integer', 'exists:suchak_service_packages,id'],
            'customer_agreement_id' => ['required', 'integer', 'exists:suchak_customer_agreements,id'],
            'payment_context_id' => ['required', 'integer', 'exists:suchak_payment_contexts,id'],
            'request_title' => ['nullable', 'string', 'max:160'],
            'request_title_mr' => ['nullable', 'string', 'max:160'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $package = SuchakServicePackage::query()->findOrFail((int) $validated['service_package_id']);
        $agreement = SuchakCustomerAgreement::query()->findOrFail((int) $validated['customer_agreement_id']);
        $paymentContext = SuchakPaymentContext::query()->findOrFail((int) $validated['payment_context_id']);

        if ((int) $package->suchak_account_id !== (int) $user->suchakAccount->id) {
            return response()->json(['success' => false, 'message' => 'Package does not belong to this Suchak account.'], 403);
        }

        try {
            $result = $paymentRequestService->createAndSend(
                $package,
                $agreement,
                $paymentContext,
                $user,
                $validated,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        /** @var SuchakPaymentRequest $paymentRequest */
        $paymentRequest = $result['payment_request'];

        /** @var SuchakAccount $account */
        $account = $user->suchakAccount;

        return response()->json([
            'success' => true,
            'message' => 'Payment request created.',
            'data' => [
                'payment_request_id' => $paymentRequest->id,
                'public_url' => $result['public_url'] ?? null,
                'portal_url' => $result['portal_url'] ?? null,
                'payment_status' => $paymentRequest->payment_status,
                'track' => 'A',
                'payment_identity' => $account->trackAPaymentIdentity(),
            ],
        ], 201);
    }

    public function markPaid(
        Request $request,
        SuchakPaymentRequest $paymentRequest,
        SuchakCustomerPaymentService $customerPaymentService,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return response()->json(['success' => false, 'message' => 'Suchak account is required.'], 403);
        }

        if ((int) $paymentRequest->suchak_account_id !== (int) $user->suchakAccount->id) {
            return response()->json(['success' => false, 'message' => 'Payment request not found for this account.'], 404);
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_mode' => ['required', 'string'],
            'paid_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'reference' => ['nullable', 'string', 'max:191'],
        ]);

        try {
            $result = $customerPaymentService->recordManualPayment(
                $paymentRequest,
                $user,
                $validated,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Customer payment recorded.',
            'data' => [
                'customer_payment_id' => $result['customer_payment']->id ?? null,
                'invoice_number' => $result['invoice']->document_number ?? null,
                'receipt_number' => $result['receipt']->document_number ?? null,
                'receipt_verification_url' => $result['receipt_verification_url'] ?? null,
            ],
        ]);
    }
}
