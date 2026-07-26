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
use App\Modules\Suchak\Services\SuchakCustomerPlanService;
use App\Modules\Suchak\Support\SuchakDefaultPlans;
use App\Support\LocalizedText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin read adapter: picker options for Track A payment-request create.
 * Filters mirror createAndSend eligibility; create still owns final validation.
 */
class SuchakPaymentRequestOptionsApiController extends Controller
{
    public function __construct(private readonly SuchakCustomerPlanService $customerPlans)
    {
    }

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
                    'default_plans' => $this->carouselPlansPayload($account),
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
            ->with(['deliverables:id,service_package_id,deliverable_name,deliverable_name_mr,deliverable_description,deliverable_description_mr,sort_order'])
            ->orderByDesc('id')
            ->get(['id', 'package_name', 'package_name_mr', 'package_description', 'package_description_mr', 'price_amount', 'currency', 'package_status'])
            ->map(static fn (SuchakServicePackage $package): array => [
                'id' => $package->id,
                'label' => LocalizedText::pick($package->package_name_mr, $package->package_name),
                'description' => LocalizedText::pick($package->package_description_mr, $package->package_description),
                'price_amount' => $package->price_amount,
                'currency' => $package->currency,
                'deliverables' => $package->deliverables
                    ->map(static fn ($deliverable): array => [
                        'name' => LocalizedText::pick($deliverable->deliverable_name_mr, $deliverable->deliverable_name),
                        'description' => LocalizedText::pick($deliverable->deliverable_description_mr, $deliverable->deliverable_description),
                    ])
                    ->values()
                    ->all(),
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
                'default_plans' => $this->carouselPlansPayload($account),
                'service_packages' => $packages,
                'customer_agreements' => $agreements,
                'payment_contexts' => $paymentContexts,
                'payment_identity' => $account->trackAPaymentIdentity(),
            ],
        ]);
    }

    /**
     * The plans shown in the app's payment carousel, localized to the request
     * locale: the two code presets (with any per-Suchak price / name / hidden
     * override applied) PLUS this Suchak's visible custom plans, all ordered.
     * Shown even before any package exists so the picker can offer plans up
     * front. See {@see SuchakCustomerPlanService::resolveCarousel()}.
     *
     * Every item — preset AND custom — is mapped into the SAME shape the app
     * already consumes (plan_key, name, description, price_amount, currency,
     * deliverables[{name, description}]). A custom plan carries a stable
     * `custom_{id}` key so the app can select it; its services become the
     * deliverables. Presets keep their code key ('basic' / 'premium').
     *
     * ADDITIVE since 2026-07-26 — the plan's own SAVED TERMS travel with it:
     * `duration`, `per_meeting_fee_amount`, `post_marriage_fee_mode` and
     * `post_marriage_fee_amount`. A Suchak fixes these ONCE while creating the
     * plan; without them on the wire the send screen fell back to hardcoded
     * client defaults (1 year / unchecked / ₹999 / "as wished") and the Suchak
     * had to retype them on every request. Null means "this plan did not fix
     * one" — the app then keeps its own default, and an absent/zero meeting fee
     * or a `none` post-marriage mode still means the fee is NOT opted in (the
     * fee blocks stay opt-in, exactly as the plan editor writes them back).
     * No key is renamed or retyped, so shipped app builds are unaffected.
     *
     * @return array<int, array<string, mixed>>
     */
    private function carouselPlansPayload(SuchakAccount $account): array
    {
        return array_map(static function (array $entry): array {
            $isPreset = (bool) ($entry['is_preset'] ?? false);
            $planKey = $isPreset
                ? (string) $entry['preset_key']
                : 'custom_'.$entry['id'];

            // Plan-level description: presets keep their code-defined blurb;
            // custom plans carry none, so the card simply omits the line.
            $description = null;
            if ($isPreset) {
                $preset = SuchakDefaultPlans::find($entry['preset_key']);
                if ($preset !== null) {
                    $description = LocalizedText::pick(
                        $preset['description_mr'] ?? null,
                        $preset['description'] ?? null,
                    );
                }
            }

            $deliverables = array_map(static fn (array $service): array => [
                'name' => LocalizedText::pick($service['name_mr'] ?? null, $service['name'] ?? null),
                'description' => null,
            ], $entry['services'] ?? []);

            return [
                'plan_key' => $planKey,
                'name' => LocalizedText::pick($entry['name_mr'] ?? null, $entry['name'] ?? null),
                'description' => $description,
                'price_amount' => $entry['price_amount'],
                'currency' => $entry['currency'],
                // The plan's saved terms — already resolved in the carousel
                // entry (custom row values, or a preset's override row). The
                // send screen seeds its duration chip and its two opt-in fee
                // rows from these instead of from hardcoded defaults.
                'duration' => $entry['duration'] ?? null,
                'per_meeting_fee_amount' => $entry['per_meeting_fee_amount'] ?? null,
                'post_marriage_fee_mode' => $entry['post_marriage_fee_mode'] ?? null,
                'post_marriage_fee_amount' => $entry['post_marriage_fee_amount'] ?? null,
                'deliverables' => $deliverables,
            ];
        }, $this->customerPlans->resolveCarousel($account));
    }
}
