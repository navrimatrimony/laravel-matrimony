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
use App\Modules\Suchak\Support\SuchakDefaultPlans;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
            'plan_key' => ['nullable', 'string', Rule::in([SuchakDefaultPlans::KEY_BASIC, SuchakDefaultPlans::KEY_PREMIUM])],
            'package_name' => ['nullable', 'string', 'max:160'],
            'price_amount' => ['nullable', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'size:3'],
            'agreement_title' => ['nullable', 'string', 'max:160'],
            'customer_accepted_terms' => ['nullable', 'boolean'],
            // Custom-plan builder (no plan_key): free-form services plus an
            // optional "fold in all Basic services" toggle.
            'services' => ['nullable', 'array'],
            'services.*' => ['string', 'max:160'],
            'include_basic' => ['nullable', 'boolean'],
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

                // Resolve the SELECTED plan up front so the existing-package
                // lookup is scoped to it. A preset's package_name is its fixed
                // plan name; a custom plan's is the submitted name. This makes
                // selecting Basic always yield the Basic package — a different
                // plan's package is never silently reused for this customer.
                $plan = SuchakDefaultPlans::find($validated['plan_key'] ?? null);
                $selectedPackageName = $plan !== null
                    ? (string) $plan['name']
                    : trim((string) ($validated['package_name'] ?? 'Matchmaking service'));
                if ($selectedPackageName === '') {
                    $selectedPackageName = 'Matchmaking service';
                }

                $package = SuchakServicePackage::query()
                    ->where('suchak_account_id', $account->id)
                    ->where('customer_context_id', $customerContext->id)
                    ->where('package_status', SuchakServicePackage::STATUS_PUBLISHED)
                    ->where('package_name', $selectedPackageName)
                    ->orderByDesc('id')
                    ->first();

                $createdPackage = false;
                if ($package === null) {
                    if ($plan !== null) {
                        // Ready-made platform default plan: fixed name / price / services,
                        // published immediately so the Suchak can collect without any
                        // per-package admin review. Auto-publish is scoped to these
                        // pre-vetted presets only.
                        $payload = SuchakDefaultPlans::catalogPayload($plan);
                        $package = $packageCatalogService->createCustomPackage(
                            $account,
                            $user,
                            [
                                'package_name' => $selectedPackageName,
                                'package_name_mr' => $plan['name_mr'] ?? null,
                                'package_description' => $plan['description'] ?? '',
                                'package_description_mr' => $plan['description_mr'] ?? null,
                                'price_amount' => (string) $plan['price_amount'],
                                'currency' => strtoupper((string) $plan['currency']),
                            ],
                            $payload['stages'],
                            $payload['deliverables'],
                            $customerContext,
                            $request->ip(),
                            $request->userAgent(),
                            true,
                        );
                    } else {
                        // Custom plan builder: one stage holding the composed
                        // deliverables — the Basic services folded in (optional)
                        // plus each free-form service the Suchak typed. Published
                        // immediately, same as the presets, so it can collect
                        // without hitting the per-package admin-review block.
                        $stageKey = 'custom_plan';
                        $includeBasic = (bool) ($validated['include_basic'] ?? false);
                        $services = array_values(array_filter(
                            array_map(
                                static fn ($service): string => trim((string) $service),
                                $validated['services'] ?? [],
                            ),
                            static fn (string $service): bool => $service !== '',
                        ));

                        $deliverables = $includeBasic
                            ? SuchakDefaultPlans::deliverablesForStage(SuchakDefaultPlans::KEY_BASIC, $stageKey)
                            : [];

                        $sort = (count($deliverables) + 1) * 10;
                        foreach ($services as $service) {
                            $deliverables[] = [
                                'stage_key' => $stageKey,
                                'deliverable_key' => $stageKey.'_'.$sort,
                                'deliverable_name' => $service,
                                'sort_order' => $sort,
                            ];
                            $sort += 10;
                        }

                        if ($deliverables === []) {
                            throw new InvalidArgumentException(
                                'Add at least one service, or include the Basic services, before preparing the custom plan.'
                            );
                        }

                        $package = $packageCatalogService->createCustomPackage(
                            $account,
                            $user,
                            [
                                'package_name' => $selectedPackageName,
                                'package_description' => 'Customer service package prepared from Suchak mobile for Track A collection.',
                                'price_amount' => (string) ($validated['price_amount'] ?? '5000'),
                                'currency' => strtoupper((string) ($validated['currency'] ?? 'INR')),
                            ],
                            [
                                [
                                    'stage_key' => $stageKey,
                                    'stage_name' => $selectedPackageName,
                                    'stage_description' => 'Custom service scope prepared from Suchak mobile.',
                                    'sort_order' => 10,
                                    'expected_days' => 30,
                                ],
                            ],
                            $deliverables,
                            $customerContext,
                            $request->ip(),
                            $request->userAgent(),
                            true,
                        );
                    }
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

                // Per-request choice from the payment screen: has the customer
                // accepted the service terms? Default true (the Suchak confirms
                // acceptance and the request goes straight out). When false, the
                // request records that terms are not required for this one.
                $customerAccepted = (bool) ($validated['customer_accepted_terms'] ?? true);

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
                                'terms_policy_mode' => $customerAccepted
                                    ? SuchakCustomerAgreement::POLICY_STRICT
                                    : SuchakCustomerAgreement::POLICY_OPTIONAL,
                            ],
                            $request->ip(),
                            $request->userAgent(),
                        );
                    }

                    if (! $pending->isTermsSatisfied()) {
                        // The owning Suchak records the customer's acceptance —
                        // no admin bypass needed (acceptTerms permits the Suchak
                        // owner, unlike the strict-only bypass path).
                        $pending = $agreementService->acceptTerms(
                            $pending,
                            $user,
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
}
