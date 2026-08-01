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
use App\Support\MoneyFormat;
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
            // Named for what it actually is. It was `customer_accepted_terms`,
            // which claimed the customer had acted when nobody had asked them —
            // and a field whose name asserts a fact it cannot know is the same
            // dishonesty this endpoint was changed to remove. The Suchak declares
            // an agreement reached offline; the customer's own acceptance has
            // exactly one route, the tokenised link. Safe to rename: the shipped
            // app has never sent this key.
            'offline_agreement_recorded' => ['nullable', 'boolean'],
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

                // TERMS_ACCEPTED is the customer's act and nothing else. It is
                // reachable only through the public tokenised link
                // (SuchakAgreementService::recordPublicAcceptance), which leaves
                // accepted_by_user_id NULL because possession of the link is what
                // was proven. No branch of this endpoint may produce it: a fee
                // obligation must never be frozen on one party's word.
                //
                // What a Suchak MAY record here is an offline agreement — real and
                // common in this business, where families agree in person or on
                // paper and are not reachable digitally. That lands in the EXISTING
                // bypass state, which already stores who declared it, why, and an
                // invoice note saying the terms were waived rather than accepted.
                //
                // Default FALSE, never true. The silent `?? true` this replaces is
                // the actual bug: absence of a claim is not a claim, and a request
                // that says nothing about the customer must freeze nothing.
                $offlineAgreement = (bool) ($validated['offline_agreement_recorded'] ?? false);

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
                                // RECOMMENDED, pinned: terms ARE required (the row
                                // opens PENDING, so the customer still has something
                                // to accept), and the owning Suchak may waive them
                                // with a recorded reason. That is exactly this
                                // decision, and it is an existing mode — no third
                                // status and no widening of the strict-only admin
                                // bypass gate. OPTIONAL is deliberately gone: it
                                // opens the row already TERMS_NOT_REQUIRED, which is
                                // the same manufactured freeze under another name.
                                'terms_policy_mode' => SuchakCustomerAgreement::POLICY_RECOMMENDED,
                            ],
                            $request->ip(),
                            $request->userAgent(),
                        );
                    } elseif (! $agreementService->isPackageSnapshotCurrent($pending)) {
                        // A pending revision whose snapshot has drifted blocks BOTH
                        // remaining paths — issueAcceptanceLink and bypassTerms each
                        // assert snapshot currency. Supersede it with a fresh
                        // revision built from the current package (the same two
                        // service calls acceptOrReviseTerms makes internally) so the
                        // device-reproduced "Suchak package changed." failure cannot
                        // come back now that acceptance no longer happens here.
                        $pending = $agreementService->createRevisionForPackageChange(
                            $pending,
                            $user,
                            [
                                'terms_policy_mode' => $pending->terms_policy_mode,
                                'agreement_title' => $pending->agreement_title,
                                'agreement_body' => $pending->agreement_body,
                                'revision_reason' => 'Suchak package changed after pending agreement; superseded before payment request.',
                            ],
                            $request->ip(),
                            $request->userAgent(),
                        );
                    }

                    if ($offlineAgreement && $pending->terms_status === SuchakCustomerAgreement::TERMS_PENDING) {
                        $pending = $agreementService->bypassTerms(
                            $pending,
                            $user,
                            $this->offlineAgreementReason($pending),
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
     * What the bypass row will say happened.
     *
     * Written as a declaration BY the Suchak, never as the customer's act —
     * that distinction is the entire reason this goes to bypass instead of
     * acceptance, and the reason column is what a human reads months later when
     * asking why a fee was owed on an agreement the customer never opened.
     *
     * Marathi, because the Suchak and the customer are the two people this
     * sentence is ever shown to. The figure comes from MoneyFormat — the one
     * money formatter — so the record names the amount that was declared agreed,
     * in Latin digits by construction.
     */
    private function offlineAgreementReason(SuchakCustomerAgreement $agreement): string
    {
        $price = MoneyFormat::amount($agreement->price_amount, (string) ($agreement->currency ?: 'INR'));

        return 'सूचकाने नोंदवले: ग्राहकाने हा करार प्रत्यक्ष भेटीत / ऑफलाइन मान्य केला आहे'
            .($price === null ? '' : ' (सेवा शुल्क: '.$price.')')
            .'. ग्राहकाने online acceptance link वापरलेली नाही.';
    }
}
