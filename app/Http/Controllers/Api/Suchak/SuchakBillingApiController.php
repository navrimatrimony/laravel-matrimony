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
 * Thin money adapters: Track B catalog/status + Track A request/mark-paid over existing services.
 * No UPI/QR schema (PO gate D1).
 */
class SuchakBillingApiController extends Controller
{
    public function plans(
        Request $request,
        SuchakBillingCatalogService $billingCatalogService,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return response()->json(['success' => false, 'message' => 'Suchak account is required.'], 403);
        }

        $catalog = $billingCatalogService->visibleCatalogForSuchak($user->suchakAccount, $user)
            ->map(static fn ($plan): array => [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'price_amount' => $plan->price_amount,
                'currency' => $plan->currency,
                'billing_period_days' => $plan->billing_period_days,
                'is_active' => (bool) $plan->is_active,
            ])
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Suchak platform plans loaded.',
            'data' => ['plans' => $catalog],
        ]);
    }

    public function status(
        Request $request,
        SuchakPaymentStatusService $paymentStatusService,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return response()->json(['success' => false, 'message' => 'Suchak account is required.'], 403);
        }

        $account = $user->suchakAccount;
        $status = $paymentStatusService->statusFor($account);
        $subscription = $status['subscription'] ?? null;
        $statusPayload = [
            'status' => $status['status'] ?? null,
            'has_active_subscription' => (bool) ($status['has_active_subscription'] ?? false),
            'subscription_id' => is_object($subscription) ? ($subscription->id ?? null) : null,
            'subscription_status' => is_object($subscription) ? ($subscription->status ?? null) : null,
        ];

        $invoices = SuchakPlanInvoice::query()
            ->whereHas('payment', static function ($query) use ($account): void {
                $query->where('suchak_account_id', $account->id);
            })
            ->latest('id')
            ->limit(20)
            ->get(['id', 'suchak_plan_payment_id', 'invoice_number', 'fy_label', 'issued_at'])
            ->map(static fn (SuchakPlanInvoice $invoice): array => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'fy_label' => $invoice->fy_label,
                'issued_at' => $invoice->issued_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Suchak platform billing status loaded.',
            'data' => [
                'status' => $statusPayload,
                'plan_invoices' => $invoices,
            ],
        ]);
    }
}

