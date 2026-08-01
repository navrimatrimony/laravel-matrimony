<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPlan;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAgreementService;
use App\Modules\Suchak\Services\SuchakCustomerLifecycleService;
use App\Modules\Suchak\Services\SuchakCustomerPlanService;
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
        SuchakCustomerPlanService $customerPlanService,
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
            // The four per-meeting / post-marriage fees, honoured on BOTH branches.
            // Same vocabulary as SuchakCustomerPlanApiController::store() — the
            // endpoint that writes these very figures onto a reusable plan — so
            // one fee is validated one way wherever it is submitted.
            //
            // A fee posted with the send WINS over the plan's default. The
            // reusable plan is the default; this send is the decision. Presets
            // used to ignore these keys, which is how a Suchak could quote
            // "प्रत्यक्ष भेटीचे शुल्क ₹999" in WhatsApp on the Basic card while
            // the acceptance page the family then froze said nothing had been
            // agreed for meetings — the message and the frozen terms disagreeing
            // is the one failure this endpoint exists to prevent.
            //
            // Key ABSENT means "nothing decided here, use the plan". Key PRESENT
            // AND NULL means "decided: nothing is charged", which is what the app
            // already sends for an opted-out fee and what the message already
            // reflects by omitting the line. Both readings live in
            // submittedPlanTerms(), which also carries `price_amount` above under
            // the same rule: the send's figure beats the plan's default.
            'per_meeting_fee_amount' => ['nullable', 'numeric', 'min:0'],
            // No relation to the offline fee, in either direction: an online
            // session is priced on its own merits (D2).
            'per_meeting_online_fee_amount' => ['nullable', 'numeric', 'min:0'],
            'post_marriage_fee_mode' => ['nullable', Rule::in(SuchakCustomerPlan::POST_MARRIAGE_FEE_MODES)],
            'post_marriage_fee_amount' => ['nullable', 'numeric', 'min:0'],
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
                $customerPlanService,
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

                // THE one money decision for this send — registration price plus
                // the four per-meeting / post-marriage fees — resolved once and
                // used by every branch below: create, re-quote and refuse alike.
                // Two layers, one rule, all five figures:
                //
                //   default  ← what the plan is configured to charge
                //   decision ← what THIS send actually quoted the family
                //
                // The two existing resolvers cooperate to produce it instead of a
                // third appearing: presetPlanTerms() lays down the default and
                // submittedPlanTerms() overlays the decision.
                //
                // The preset base underneath both layers is the code-defined plan
                // (SuchakDefaultPlans). It is the last resort, not the authority:
                // it stands only where the Suchak configured no override and this
                // send quoted nothing — which is precisely the case where it is
                // also the figure the app displayed. A preset send that pinned
                // this base regardless is how an acceptance page came to read
                // "नोंदणी शुल्क ₹2,000" while the message said otherwise.
                $fees = $plan === null
                    ? array_merge([
                        'price_amount' => '5000',
                        'currency' => strtoupper((string) ($validated['currency'] ?? 'INR')),
                    ], $this->submittedPlanTerms($validated))
                    : array_merge(
                        [
                            'price_amount' => (string) $plan['price_amount'],
                            'currency' => strtoupper((string) $plan['currency']),
                        ],
                        $this->presetPlanTerms($customerPlanService, $account, (string) $plan['key']),
                        $this->submittedPlanTerms($validated),
                    );

                $package = SuchakServicePackage::query()
                    ->where('suchak_account_id', $account->id)
                    ->where('customer_context_id', $customerContext->id)
                    ->where('package_status', SuchakServicePackage::STATUS_PUBLISHED)
                    ->where('package_name', $selectedPackageName)
                    ->orderByDesc('id')
                    ->first();

                // Re-sending the same plan to the same customer reuses the package
                // that is already published for them. Reusing it VERBATIM is the
                // second way the message and the freeze can disagree: the WhatsApp
                // text is composed from what the Suchak just typed, so an edited
                // fee is quoted to the family while the package still carries the
                // old figure the acceptance page reads.
                //
                // What may be done about it depends entirely on whether anyone has
                // accepted yet, so ask that question before writing anything.
                if ($package !== null) {
                    $drift = $packageCatalogService->planTermsDrift($package, $fees);

                    if ($drift !== []) {
                        if ($this->hasSatisfiedAgreement($package)) {
                            // Accepted (or bypassed / not-required) terms are the
                            // whole point of the freeze, and the public acceptance
                            // page reads these fees LIVE off the package
                            // (PublicAgreementController::termsFor) — editing them
                            // would rewrite what a customer already agreed to,
                            // retroactively, with no record on their side. Refuse
                            // instead, and say which figure moved. Nothing is
                            // written; the accepted agreement stays exactly as
                            // accepted.
                            throw new InvalidArgumentException(
                                $this->acceptedTermsChangeRefusal($package, $drift)
                            );
                        }

                        // Nobody has accepted yet, so the quote may still move.
                        // Write it, and let the machinery already wired below do
                        // the rest: the pending revision's snapshot digest covers
                        // these four columns (SuchakAgreementService::
                        // agreementSnapshotHash), so it now reads as stale and is
                        // superseded by a fresh revision built from this package.
                        $package = $packageCatalogService->applyPlanTerms(
                            $package,
                            $user,
                            $drift,
                            $request->ip(),
                            $request->userAgent(),
                        );
                    }
                }

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
                            array_merge([
                                'package_name' => $selectedPackageName,
                                'package_name_mr' => $plan['name_mr'] ?? null,
                                'package_description' => $plan['description'] ?? '',
                                'package_description_mr' => $plan['description_mr'] ?? null,
                                // Price and currency ride in $fees with the four
                                // fees: one resolution, one place, five figures.
                            ], $fees),
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
                            array_merge([
                                'package_name' => $selectedPackageName,
                                'package_description' => 'Customer service package prepared from Suchak mobile for Track A collection.',
                            ], $fees),
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
     * What a PRESET send DEFAULTS its money terms to: the Suchak's own configured
     * plan, and nothing else.
     *
     * The default, not the decision. Whatever this send actually posted is laid
     * over the top by {@see submittedPlanTerms}, because the plan is the figure
     * the Suchak configured once and the request is the figure he just quoted
     * this family in WhatsApp — and the frozen terms have to be the second one.
     *
     * Bound to SuchakCustomerPlanService::resolveCarousel() — the exact resolver
     * SuchakPaymentRequestOptionsApiController::carouselPlansPayload() reads, so
     * the figure frozen onto the package is by construction the figure the app
     * displayed when the Suchak chose the plan. Reading the plan row directly
     * here would be a second copy of that preset/override mapping, which the
     * no-duplicate rule forbids; and picking `resolveForManagement()` instead
     * would let a HIDDEN plan freeze a fee the carousel never showed anyone.
     *
     * All four FEES stay null when the Suchak configured no plan for this preset —
     * "ठरलेले नाही" on the acceptance page is the truthful answer, and a default
     * amount invented here would be a charge nobody agreed to.
     *
     * The registration PRICE behaves the other way round and has to: a package
     * without a price is not a truthful "nothing agreed", it is a broken quote.
     * So price and currency are returned only when the carousel actually supplied
     * them, leaving the caller's code-preset base standing when it did not. The
     * carousel entry has already layered the Suchak's override over that base
     * (SuchakCustomerPlanService::presetEntry), so what comes back here is by
     * construction the figure the app printed on the card.
     *
     * @return array<string, mixed>
     */
    private function presetPlanTerms(
        SuchakCustomerPlanService $customerPlanService,
        SuchakAccount $account,
        string $presetKey,
    ): array {
        $entry = null;
        foreach ($customerPlanService->resolveCarousel($account) as $candidate) {
            if (($candidate['is_preset'] ?? false) && ($candidate['preset_key'] ?? null) === $presetKey) {
                $entry = $candidate;
                break;
            }
        }

        $terms = [
            'per_meeting_fee_amount' => $entry['per_meeting_fee_amount'] ?? null,
            'per_meeting_online_fee_amount' => $entry['per_meeting_online_fee_amount'] ?? null,
            'post_marriage_fee_mode' => $entry['post_marriage_fee_mode'] ?? null,
            'post_marriage_fee_amount' => $entry['post_marriage_fee_amount'] ?? null,
        ];

        foreach (['price_amount', 'currency'] as $key) {
            if (($entry[$key] ?? null) !== null) {
                $terms[$key] = $entry[$key];
            }
        }

        return $terms;
    }

    /**
     * The money terms THIS SEND decided, exactly as submitted — the layer that
     * wins, on both branches.
     *
     * A custom plan is not addressable through `plan_key` (that field admits
     * only the two code presets), so a Suchak sending one of their own reusable
     * plans arrives here with its figures on the request — the same figures the
     * carousel handed the app in the first place. A preset send may now carry
     * them too, and when it does they beat the plan's defaults.
     *
     * Absent keys stay absent, and that absence is load-bearing in two places:
     * on a preset it is what lets the plan's default stand, and on a custom send
     * it is what leaves the figure to the catalog service instead of forcing a
     * null. A key posted AS null is the opposite — an explicit "nothing is
     * charged", which is exactly what the app sends for a fee the Suchak opted
     * out of and left out of the WhatsApp message. Laravel's validated() keeps
     * that distinction intact, so array_key_exists is the honest test.
     *
     * `price_amount` is the one figure that reads its absence differently, and
     * has to: a fee can honestly be "nothing is charged", but a package whose
     * registration price is nothing is not a cheaper quote, it is a broken one —
     * which is why validation already refuses anything below 0.01. So a real
     * figure overrides the plan's, and a missing or null one leaves the plan's
     * standing rather than erasing it.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function submittedPlanTerms(array $validated): array
    {
        $terms = [];
        foreach ([
            'per_meeting_fee_amount',
            'per_meeting_online_fee_amount',
            'post_marriage_fee_mode',
            'post_marriage_fee_amount',
        ] as $key) {
            if (array_key_exists($key, $validated)) {
                $terms[$key] = $validated[$key];
            }
        }

        if (($validated['price_amount'] ?? null) !== null) {
            $terms['price_amount'] = $validated['price_amount'];
        }

        return $terms;
    }

    /**
     * Whether any revision of this package's agreement is already in force.
     *
     * The same three statuses the endpoint's own agreement lookup treats as
     * settled, and the same set SuchakCustomerAgreement::isTermsSatisfied()
     * defines — asked as a query because the point is whether ANY revision on
     * this package has reached that state, not what the newest row happens to
     * say. Bypassed and not-required count: in both, someone has been told these
     * are the terms, so the figures behind them are no longer ours to move.
     */
    private function hasSatisfiedAgreement(SuchakServicePackage $package): bool
    {
        return SuchakCustomerAgreement::query()
            ->where('service_package_id', $package->id)
            ->whereIn('terms_status', [
                SuchakCustomerAgreement::TERMS_NOT_REQUIRED,
                SuchakCustomerAgreement::TERMS_ACCEPTED,
                SuchakCustomerAgreement::TERMS_BYPASSED,
            ])
            ->exists();
    }

    /**
     * Why a re-send carrying different fees was refused, in the words the Suchak
     * needs to act on it.
     *
     * Names the figure that moved and both of its values, because "आधीच
     * स्वीकारले आहे" alone leaves the Suchak staring at a message he can see
     * quotes ₹999 with no idea what the customer actually holds. Amounts through
     * MoneyFormat — the one money formatter — so they read in Latin digits with
     * Indian grouping, identical to the acceptance page they refer to.
     *
     * The post-marriage MODE is named without values: it is a category, not a
     * figure, and the page words each category in its own sentence rather than a
     * rupee amount.
     *
     * @param  array<string, ?string>  $drift  columns that would change, from
     *                                         SuchakPackageCatalogService::planTermsDrift
     */
    private function acceptedTermsChangeRefusal(SuchakServicePackage $package, array $drift): string
    {
        $currency = (string) ($package->currency ?: 'INR');
        $notQuoted = 'ठरलेले नाही';
        // Same wording as the acceptance page's own fee table
        // (resources/views/suchak/agreements/public.blade.php), so the Suchak
        // reads back the exact row the customer is looking at.
        $labels = [
            'price_amount' => 'नोंदणी शुल्क',
            'per_meeting_fee_amount' => 'प्रत्यक्ष भेटीचे शुल्क',
            'per_meeting_online_fee_amount' => 'ऑनलाइन भेटीचे शुल्क',
            'post_marriage_fee_amount' => 'विवाह ठरल्यानंतरचे शुल्क',
        ];

        $changes = [];
        foreach ($labels as $column => $label) {
            if (! array_key_exists($column, $drift)) {
                continue;
            }

            $was = MoneyFormat::amount($package->getAttribute($column), $currency) ?? $notQuoted;
            $now = MoneyFormat::amount($drift[$column], $currency) ?? $notQuoted;
            $changes[] = $label.': '.$was.' → '.$now;
        }

        if (array_key_exists('post_marriage_fee_mode', $drift)) {
            $changes[] = 'विवाह ठरल्यानंतरच्या शुल्काचा प्रकार';
        }

        return 'या ग्राहकाने या योजनेच्या अटी आधीच स्वीकारल्या आहेत, त्यामुळे शुल्क बदलून तीच योजना पुन्हा पाठवता येणार नाही ('
            .implode('; ', $changes)
            .'). स्वीकारलेला करार जसाच्या तसा राहतो. नवीन शुल्क लागू करायचे असेल तर वेगळ्या नावाची योजना तयार करून पाठवा.';
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
