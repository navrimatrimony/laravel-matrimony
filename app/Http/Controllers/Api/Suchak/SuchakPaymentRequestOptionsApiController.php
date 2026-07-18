<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakServicePackage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin read adapter: picker options for Track A payment-request create.
 * Filters mirror createAndSend eligibility; create still owns final validation.
 */
class SuchakPaymentRequestOptionsApiController extends Controller
{
    public function __invoke(Request $request, int $representation): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return response()->json(['success' => false, 'message' => 'Suchak account is required.'], 403);
        }

        /** @var SuchakAccount $account */
        $account = $user->suchakAccount;

        $owned = SuchakProfileRepresentation::query()
            ->whereKey($representation)
            ->where('suchak_account_id', $account->id)
            ->exists();

        if (! $owned) {
            return response()->json(['success' => false, 'message' => 'Customer not found for this Suchak account.'], 404);
        }

        /** @var SuchakCustomerContext|null $customerContext */
        $customerContext = SuchakCustomerContext::query()
            ->where('suchak_account_id', $account->id)
            ->where('representation_id', $representation)
            ->first();

        if ($customerContext === null) {
            return response()->json([
                'success' => true,
                'message' => 'No customer context yet for payment requests.',
                'data' => [
                    'representation_id' => $representation,
                    'customer_context_id' => null,
                    'service_packages' => [],
                    'customer_agreements' => [],
                    'payment_contexts' => [],
                    'payment_identity' => $account->trackAPaymentIdentity(),
                ],
            ]);
        }

        $packages = SuchakServicePackage::query()
            ->where('suchak_account_id', $account->id)
            ->where('customer_context_id', $customerContext->id)
            ->where('package_status', SuchakServicePackage::STATUS_PUBLISHED)
            ->orderByDesc('id')
            ->get(['id', 'package_name', 'price_amount', 'currency', 'package_status'])
            ->map(static fn (SuchakServicePackage $package): array => [
                'id' => $package->id,
                'label' => $package->package_name,
                'price_amount' => $package->price_amount,
                'currency' => $package->currency,
            ])
            ->values()
            ->all();

        $packageIds = array_column($packages, 'id');

        $agreements = $packageIds === []
            ? []
            : SuchakCustomerAgreement::query()
                ->where('suchak_account_id', $account->id)
                ->where('customer_context_id', $customerContext->id)
                ->whereIn('service_package_id', $packageIds)
                ->whereIn('terms_status', [
                    SuchakCustomerAgreement::TERMS_NOT_REQUIRED,
                    SuchakCustomerAgreement::TERMS_ACCEPTED,
                    SuchakCustomerAgreement::TERMS_BYPASSED,
                ])
                ->orderByDesc('id')
                ->get([
                    'id',
                    'service_package_id',
                    'agreement_title',
                    'terms_status',
                    'price_amount',
                    'currency',
                    'agreement_revision',
                ])
                ->map(static fn (SuchakCustomerAgreement $agreement): array => [
                    'id' => $agreement->id,
                    'label' => $agreement->agreement_title,
                    'service_package_id' => $agreement->service_package_id,
                    'terms_status' => $agreement->terms_status,
                    'price_amount' => $agreement->price_amount,
                    'currency' => $agreement->currency,
                    'agreement_revision' => $agreement->agreement_revision,
                ])
                ->values()
                ->all();

        $paymentContexts = SuchakPaymentContext::query()
            ->where('suchak_account_id', $account->id)
            ->where('customer_context_id', $customerContext->id)
            ->where('context_status', SuchakPaymentContext::STATUS_ACTIVE)
            ->where('payment_collector', SuchakPaymentContext::COLLECTOR_SUCHAK)
            ->where('source_owner', '!=', SuchakPaymentContext::SOURCE_PLATFORM)
            ->orderByDesc('id')
            ->get(['id', 'source_owner', 'payment_collector', 'context_status'])
            ->map(static fn (SuchakPaymentContext $context): array => [
                'id' => $context->id,
                'label' => $context->source_owner.' / '.$context->payment_collector,
                'source_owner' => $context->source_owner,
                'payment_collector' => $context->payment_collector,
            ])
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Track A payment request options loaded.',
            'data' => [
                'representation_id' => $representation,
                'customer_context_id' => $customerContext->id,
                'track' => 'A',
                'service_packages' => $packages,
                'customer_agreements' => $agreements,
                'payment_contexts' => $paymentContexts,
                'payment_identity' => $account->trackAPaymentIdentity(),
            ],
        ]);
    }
}
