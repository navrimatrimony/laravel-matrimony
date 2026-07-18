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
use App\Modules\Suchak\Services\SuchakAgreementService;
use App\Modules\Suchak\Services\SuchakCustomerLifecycleService;
use App\Modules\Suchak\Services\SuchakPackageCatalogService;
use App\Modules\Suchak\Services\SuchakPaymentCollectorResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Thin journey adapter: prepare Track A package + agreement + payment context
 * using existing catalog/agreement/resolver/lifecycle services only.
 */
class SuchakPaymentSetupApiController extends Controller
{
    public function __invoke(
        Request $request,
        int $representation,
        SuchakCustomerLifecycleService $lifecycleService,
        SuchakPackageCatalogService $packageCatalogService,
        SuchakAgreementService $agreementService,
        SuchakPaymentCollectorResolver $paymentCollectorResolver,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return response()->json(['success' => false, 'message' => 'Suchak account is required.'], 403);
        }

        /** @var SuchakAccount $account */
        $account = $user->suchakAccount;

        /** @var SuchakProfileRepresentation|null $rep */
        $rep = SuchakProfileRepresentation::query()
            ->whereKey($representation)
            ->where('suchak_account_id', $account->id)
            ->with('matrimonyProfile')
            ->first();

        if ($rep === null) {
            return response()->json(['success' => false, 'message' => 'Customer not found for this Suchak account.'], 404);
        }

        if ($rep->matrimonyProfile === null) {
            return response()->json([
                'success' => false,
                'message' => 'Customer profile is required before preparing Track A collection.',
            ], 422);
        }

        $validated = $request->validate([
            'package_name' => ['nullable', 'string', 'max:160'],
            'price_amount' => ['nullable', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'size:3'],
            'agreement_title' => ['nullable', 'string', 'max:160'],
            'bypass_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $result = DB::transaction(function () use (
                $account,
                $user,
                $rep,
                $validated,
                $lifecycleService,
                $packageCatalogService,
                $agreementService,
                $paymentCollectorResolver,
                $request,
            ): array {
                $customerContext = SuchakCustomerContext::query()
                    ->where('suchak_account_id', $account->id)
                    ->where('representation_id', $rep->id)
                    ->first();

                if ($customerContext === null) {
                    $customerContext = $lifecycleService->createForRepresentation(
                        $account,
                        $user,
                        $rep,
                        [
                            'source_owner' => SuchakCustomerContext::SOURCE_OWNER_SUCHAK,
                            'payer_name' => $rep->matrimonyProfile?->full_name,
                        ],
                        $request->ip(),
                        $request->userAgent(),
                    );
                }

                if ($customerContext->source_owner === SuchakPaymentContext::SOURCE_PLATFORM) {
                    throw new InvalidArgumentException(
                        'Platform-owned customers cannot use direct Suchak Track A collection. Use platform billing rules.'
                    );
                }

                $package = SuchakServicePackage::query()
                    ->where('suchak_account_id', $account->id)
                    ->where('customer_context_id', $customerContext->id)
                    ->where('package_status', SuchakServicePackage::STATUS_PUBLISHED)
                    ->orderByDesc('id')
                    ->first();

                $createdPackage = false;
                if ($package === null) {
                    $package = $packageCatalogService->createCustomPackage(
                        $account,
                        $user,
                        [
                            'package_name' => $validated['package_name'] ?? 'Matchmaking service',
                            'package_description' => 'Customer service package prepared from Suchak mobile for Track A collection.',
                            'price_amount' => (string) ($validated['price_amount'] ?? '5000'),
                            'currency' => strtoupper((string) ($validated['currency'] ?? 'INR')),
                        ],
                        $this->defaultStages(),
                        $this->defaultDeliverables(),
                        $customerContext,
                        $request->ip(),
                        $request->userAgent(),
                    );
                    $createdPackage = true;

                    if ($package->package_status !== SuchakServicePackage::STATUS_PUBLISHED) {
                        throw new InvalidArgumentException(
                            'Package requires admin publish approval before payment requests. Complete publish on web/admin, then retry.'
                        );
                    }
                }

                $agreement = SuchakCustomerAgreement::query()
                    ->where('suchak_account_id', $account->id)
                    ->where('customer_context_id', $customerContext->id)
                    ->where('service_package_id', $package->id)
                    ->whereIn('terms_status', [
                        SuchakCustomerAgreement::TERMS_NOT_REQUIRED,
                        SuchakCustomerAgreement::TERMS_ACCEPTED,
                        SuchakCustomerAgreement::TERMS_BYPASSED,
                    ])
                    ->orderByDesc('id')
                    ->first();

                $createdAgreement = false;
                if ($agreement === null) {
                    $pending = SuchakCustomerAgreement::query()
                        ->where('service_package_id', $package->id)
                        ->orderByDesc('id')
                        ->first();

                    if ($pending === null) {
                        $pending = $agreementService->createAgreementForPackage(
                            $package,
                            $user,
                            [
                                'agreement_title' => $validated['agreement_title'] ?? 'Service agreement',
                                'agreement_body' => 'Customer confirms package scope before payment request.',
                            ],
                            $request->ip(),
                            $request->userAgent(),
                        );
                    }

                    if (! $pending->isTermsSatisfied()) {
                        $pending = $agreementService->bypassTerms(
                            $pending,
                            $user,
                            $validated['bypass_reason'] ?? 'Suchak prepared Track A collection agreement on mobile for direct customer payment.',
                            $request->ip(),
                            $request->userAgent(),
                        );
                    }

                    $agreement = $pending;
                    $createdAgreement = true;
                }

                $paymentContext = SuchakPaymentContext::query()
                    ->where('suchak_account_id', $account->id)
                    ->where('customer_context_id', $customerContext->id)
                    ->where('context_status', SuchakPaymentContext::STATUS_ACTIVE)
                    ->where('payment_collector', SuchakPaymentContext::COLLECTOR_SUCHAK)
                    ->where('source_owner', '!=', SuchakPaymentContext::SOURCE_PLATFORM)
                    ->orderByDesc('id')
                    ->first();

                $createdContext = false;
                if ($paymentContext === null) {
                    $paymentContext = $paymentCollectorResolver->resolveForManualLedger(
                        $account,
                        $user,
                        $rep->matrimonyProfile,
                        [
                            'customer_context_id' => $customerContext->id,
                            'source_owner' => $customerContext->source_owner ?: SuchakPaymentContext::SOURCE_SUCHAK,
                            'payment_collector' => SuchakPaymentContext::COLLECTOR_SUCHAK,
                            'resolution_note' => 'Track A payment context prepared from Suchak mobile.',
                        ],
                        null,
                        null,
                        $request->ip(),
                        $request->userAgent(),
                    );
                    $createdContext = true;
                }

                return [
                    'customer_context_id' => $customerContext->id,
                    'service_package_id' => $package->id,
                    'customer_agreement_id' => $agreement->id,
                    'payment_context_id' => $paymentContext->id,
                    'created' => [
                        'package' => $createdPackage,
                        'agreement' => $createdAgreement,
                        'payment_context' => $createdContext,
                    ],
                    'package_status' => $package->package_status,
                    'terms_status' => $agreement->terms_status,
                ];
            });
        } catch (InvalidArgumentException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Track A collection prerequisites prepared.',
            'data' => array_merge($result, [
                'representation_id' => $rep->id,
                'track' => 'A',
                'payment_identity' => $account->fresh()->trackAPaymentIdentity(),
            ]),
        ], 201);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function defaultStages(): array
    {
        return [
            [
                'stage_key' => 'intake_and_shortlist',
                'stage_name' => 'Intake and shortlist',
                'stage_description' => 'Collect requirements and prepare shortlist.',
                'sort_order' => 10,
                'expected_days' => 7,
            ],
            [
                'stage_key' => 'family_coordination',
                'stage_name' => 'Family coordination',
                'stage_description' => 'Coordinate discussion and next steps.',
                'sort_order' => 20,
                'expected_days' => 14,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function defaultDeliverables(): array
    {
        return [
            [
                'stage_key' => 'intake_and_shortlist',
                'deliverable_key' => 'shortlist_pack',
                'deliverable_name' => 'Shortlist pack',
                'deliverable_description' => 'Prepared candidate shortlist for family review.',
                'sort_order' => 10,
            ],
            [
                'stage_key' => 'family_coordination',
                'deliverable_key' => 'coordination_update',
                'deliverable_name' => 'Coordination update',
                'deliverable_description' => 'Status update after family coordination.',
                'sort_order' => 20,
            ],
        ];
    }
}
