<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminSetting;
use App\Models\Plan;
use App\Models\PlanTerm;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ActivePlanResolver;
use App\Services\FeatureUsageService;
use App\Services\RevenueOrchestratorService;
use App\Support\PlanFeatureKeys;
use App\Support\PlanFeatureLabel;
use App\Support\PlanQuotaCatalogFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class MobilePlanApiController extends Controller
{
    public const CHECKOUT_BRIDGE_CACHE_PREFIX = 'mobile_plan_checkout_bridge:';

    private const CHECKOUT_BRIDGE_TTL_MINUTES = 10;

    public function __construct(
        private readonly ActivePlanResolver $activePlanResolver,
        private readonly FeatureUsageService $featureUsage,
    ) {}

    public function current(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        $subscription = $this->activePlanResolver->getActiveSubscription($user);
        $subscription?->loadMissing(['plan.features', 'plan.terms', 'plan.quotaPolicies', 'planTerm']);

        $effectivePlan = $this->activePlanResolver->get($user);
        $effectivePlan->loadMissing(['features', 'terms', 'quotaPolicies']);

        $contactState = $this->featureUsage->getFeatureState($user, PlanFeatureKeys::CONTACT_VIEW_LIMIT);
        $contactUsage = $this->featureUsage->getContactViewUsageSnapshot($user);
        $currentPlanSlug = (string) ($effectivePlan->slug ?? '');
        $hasPaidSubscription = $subscription instanceof Subscription
            && $subscription->plan instanceof Plan
            && ! Plan::isFreeCatalogSlug((string) $subscription->plan->slug);

        return response()->json([
            'success' => true,
            'message' => 'Current plan loaded.',
            'current_plan' => $this->planPayload($effectivePlan, $user),
            'active_subscription' => $this->subscriptionPayload($subscription),
            'contact_view' => [
                'state' => $this->featureStatePayload($contactState),
                'usage' => $contactUsage,
            ],
            'upgrade_recommended' => ! $hasPaidSubscription
                || Plan::isFreeCatalogSlug($currentPlanSlug)
                || ($contactState['allowed'] ?? false) !== true,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        $plans = Plan::query()
            ->where('is_active', true)
            ->where('is_visible', true)
            ->with(['features', 'terms', 'quotaPolicies'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (Plan $plan): bool => ! Plan::isFreeCatalogSlug((string) $plan->slug))
            ->values();

        $enforceGenderSpecificPlans = AdminSetting::getBool('plans_enforce_gender_specific_visibility', true);
        $user->loadMissing('matrimonyProfile.gender');
        if ($enforceGenderSpecificPlans) {
            $plans = $plans
                ->filter(fn (Plan $plan): bool => Plan::profileGenderAllowsPlan($user, $plan))
                ->values();
        }

        return response()->json([
            'success' => true,
            'message' => $plans->isEmpty()
                ? 'No upgrade plans are available for this profile right now.'
                : 'Plans loaded.',
            'plans' => $plans
                ->map(fn (Plan $plan): array => $this->planPayload($plan, $user))
                ->values()
                ->all(),
            'catalog' => [
                'currency' => 'INR',
                'enforce_gender_specific_plans' => $enforceGenderSpecificPlans,
            ],
        ]);
    }

    public function checkout(Request $request, Plan $plan, RevenueOrchestratorService $revenue): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        $data = $request->validate([
            'plan_term_id' => ['nullable', 'integer'],
        ]);
        $planTermId = array_key_exists('plan_term_id', $data) && $data['plan_term_id'] !== null
            ? (int) $data['plan_term_id']
            : null;

        $plan->loadMissing(['features', 'terms', 'quotaPolicies']);
        if (! $this->isMobileBuyablePlan($user, $plan)) {
            return $this->error('This plan is not available for checkout.', 422);
        }

        try {
            $prepared = $revenue->prepareCheckout($user, $plan, $planTermId, null);
        } catch (HttpException $exception) {
            return $this->error($exception->getMessage(), $this->httpStatus($exception));
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(__('subscriptions.subscribe_failed'), 422);
        }

        $resolved = is_array($prepared['resolved'] ?? null) ? $prepared['resolved'] : [];
        $resolvedPlanTermId = isset($resolved['plan_term_id']) ? (int) $resolved['plan_term_id'] : null;
        $nonce = Str::random(48);

        Cache::put(
            self::CHECKOUT_BRIDGE_CACHE_PREFIX.$nonce,
            [
                'user_id' => (int) $user->id,
                'plan_id' => (int) $plan->id,
                'plan_term_id' => $resolvedPlanTermId,
                'created_at' => now()->toIso8601String(),
            ],
            now()->addMinutes(self::CHECKOUT_BRIDGE_TTL_MINUTES),
        );

        $checkoutUrl = URL::temporarySignedRoute(
            'mobile.plans.checkout.bridge',
            now()->addMinutes(self::CHECKOUT_BRIDGE_TTL_MINUTES),
            ['nonce' => $nonce],
        );

        Log::info('mobile_plan_checkout_link_created', [
            'user_id' => (int) $user->id,
            'plan_id' => (int) $plan->id,
            'plan_term_id' => $resolvedPlanTermId,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Checkout link created. Complete payment in the browser.',
            'checkout_url' => $checkoutUrl,
            'checkout' => [
                'method' => 'GET',
                'opens_external_browser' => true,
                'expires_in_seconds' => self::CHECKOUT_BRIDGE_TTL_MINUTES * 60,
                'plan' => $this->planPayload($plan, $user),
                'plan_term_id' => $resolvedPlanTermId,
                'amount' => [
                    'currency' => 'INR',
                    'base_amount' => isset($resolved['base_amount']) ? round((float) $resolved['base_amount'], 2) : null,
                    'final_amount' => isset($resolved['final_amount']) ? round((float) $resolved['final_amount'], 2) : null,
                ],
                'duration_days' => isset($resolved['duration_days']) ? (int) $resolved['duration_days'] : null,
            ],
        ]);
    }

    private function planPayload(Plan $plan, User $user): array
    {
        $plan->loadMissing(['features', 'terms', 'quotaPolicies']);

        $visibleTerms = $this->visibleTerms($plan);
        $defaultTerm = $this->defaultTerm($plan, $visibleTerms);
        $basePrice = $defaultTerm instanceof PlanTerm ? (float) $defaultTerm->price : (float) $plan->price;
        $finalPrice = $defaultTerm instanceof PlanTerm ? (float) $defaultTerm->final_price : (float) $plan->final_price;
        $durationDays = $defaultTerm instanceof PlanTerm ? (int) $defaultTerm->duration_days : (int) $plan->duration_days;

        return [
            'id' => $plan->exists ? (int) $plan->id : null,
            'slug' => (string) ($plan->slug ?? ''),
            'name' => (string) ($plan->name ?? ''),
            'name_mr' => $plan->name_mr,
            'display_name' => $this->displayName($plan),
            'description' => $plan->description,
            'currency' => 'INR',
            'price' => round($basePrice, 2),
            'final_price' => round($finalPrice, 2),
            'discount_percent' => $defaultTerm instanceof PlanTerm
                ? (int) ($defaultTerm->discount_percent ?? 0)
                : (int) ($plan->discount_percent ?? 0),
            'duration_days' => $durationDays,
            'duration_label' => $this->durationLabel($durationDays),
            'highlight' => (bool) $plan->highlight,
            'marketing_badge' => $plan->marketing_badge,
            'applies_to_gender' => $plan->applies_to_gender ?: 'all',
            'is_current' => $this->isCurrentPlanForUser($user, $plan),
            'default_plan_term_id' => $defaultTerm instanceof PlanTerm ? (int) $defaultTerm->id : null,
            'terms' => $visibleTerms
                ->map(fn (PlanTerm $term): array => $this->termPayload($term))
                ->values()
                ->all(),
            'features' => $this->catalogFeatureLines($plan, $defaultTerm),
        ];
    }

    private function subscriptionPayload(?Subscription $subscription): ?array
    {
        if (! $subscription instanceof Subscription) {
            return null;
        }

        $subscription->loadMissing(['plan', 'planTerm']);
        $snapshot = $subscription->checkoutSnapshot();
        $planName = trim((string) ($snapshot['plan_name'] ?? ''));

        return [
            'id' => (int) $subscription->id,
            'status' => (string) $subscription->status,
            'starts_at' => $this->dateValue($subscription->starts_at),
            'ends_at' => $this->dateValue($subscription->ends_at),
            'plan_id' => $subscription->plan_id !== null ? (int) $subscription->plan_id : null,
            'plan_term_id' => $subscription->plan_term_id !== null ? (int) $subscription->plan_term_id : null,
            'plan_name' => $planName !== '' ? $planName : (string) ($subscription->plan?->name ?? ''),
            'plan_slug' => (string) ($subscription->plan?->slug ?? ''),
            'billing_key' => $subscription->planTerm?->billing_key,
            'is_paid' => $subscription->plan instanceof Plan
                && ! Plan::isFreeCatalogSlug((string) $subscription->plan->slug),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, PlanTerm>
     */
    private function visibleTerms(Plan $plan): \Illuminate\Support\Collection
    {
        if (! $plan->exists) {
            return collect();
        }

        $terms = $plan->relationLoaded('terms') ? $plan->terms : $plan->terms()->get();

        return $terms
            ->filter(fn (PlanTerm $term): bool => (bool) $term->is_visible)
            ->sortBy([
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->values();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PlanTerm>  $visibleTerms
     */
    private function defaultTerm(Plan $plan, \Illuminate\Support\Collection $visibleTerms): ?PlanTerm
    {
        if ($visibleTerms->isEmpty()) {
            return null;
        }

        $defaultBillingKey = trim((string) ($plan->default_billing_key ?? ''));
        if ($defaultBillingKey !== '') {
            $matching = $visibleTerms->first(
                fn (PlanTerm $term): bool => (string) $term->billing_key === $defaultBillingKey
            );
            if ($matching instanceof PlanTerm) {
                return $matching;
            }
        }

        return $visibleTerms->first();
    }

    private function termPayload(PlanTerm $term): array
    {
        return [
            'id' => (int) $term->id,
            'billing_key' => (string) $term->billing_key,
            'label' => __(PlanTerm::billingLabelKey((string) $term->billing_key)),
            'duration_days' => (int) $term->duration_days,
            'duration_label' => $this->durationLabel((int) $term->duration_days),
            'price' => round((float) $term->price, 2),
            'final_price' => round((float) $term->final_price, 2),
            'discount_percent' => (int) ($term->discount_percent ?? 0),
        ];
    }

    /**
     * @return list<string>
     */
    private function catalogFeatureLines(Plan $plan, ?PlanTerm $defaultTerm): array
    {
        $durationMultiplier = $this->durationMultiplier($plan, $defaultTerm);
        $billingDurationType = $defaultTerm instanceof PlanTerm ? (string) $defaultTerm->billing_key : null;

        return $plan->catalogFeatureRowsForPricing()
            ->map(function (object $feature) use ($durationMultiplier, $billingDurationType): string {
                if (property_exists($feature, 'catalog_quota_payload') && is_array($feature->catalog_quota_payload)) {
                    return PlanQuotaCatalogFormatter::catalogLineFromPayload(
                        (string) $feature->key,
                        $feature->catalog_quota_payload,
                        $durationMultiplier,
                        $billingDurationType,
                    );
                }

                return PlanFeatureLabel::catalogLabelForPricing((string) $feature->key, null)
                    .' — '
                    .PlanFeatureLabel::catalogFormatValue(
                        (string) $feature->key,
                        (string) ($feature->value ?? ''),
                        $durationMultiplier,
                        $billingDurationType,
                    );
            })
            ->filter(fn (string $line): bool => trim($line) !== '')
            ->values()
            ->all();
    }

    private function durationMultiplier(Plan $plan, ?PlanTerm $defaultTerm): float
    {
        if (! $defaultTerm instanceof PlanTerm) {
            return 1.0;
        }

        $visibleTerms = $this->visibleTerms($plan);
        $positiveDurations = $visibleTerms
            ->map(fn (PlanTerm $term): int => (int) $term->duration_days)
            ->filter(fn (int $days): bool => $days > 0)
            ->values();
        $baselineDays = $positiveDurations->isNotEmpty() ? (int) $positiveDurations->min() : 0;
        $selectedDays = (int) $defaultTerm->duration_days;

        if ($baselineDays <= 0 || $selectedDays <= 0) {
            return 1.0;
        }

        return max(0.0, $selectedDays / $baselineDays);
    }

    private function featureStatePayload(array $state): array
    {
        return [
            'allowed' => (bool) ($state['allowed'] ?? false),
            'limit' => $state['limit'] ?? null,
            'used' => isset($state['used']) ? (int) $state['used'] : 0,
            'remaining' => $state['remaining'] ?? null,
            'unlimited' => (bool) ($state['unlimited'] ?? false),
            'reset_at' => $this->dateValue($state['reset_at'] ?? null),
            'reason' => $state['reason'] ?? null,
        ];
    }

    private function displayName(Plan $plan): string
    {
        $nameMr = trim((string) ($plan->name_mr ?? ''));
        if ($nameMr !== '') {
            return $nameMr;
        }

        return (string) ($plan->name ?? '');
    }

    private function durationLabel(int $days): string
    {
        if ($days <= 0) {
            return 'Lifetime';
        }
        if ($days % 365 === 0) {
            $years = (int) ($days / 365);

            return $years === 1 ? '1 year' : $years.' years';
        }
        if ($days % 30 === 0) {
            $months = (int) ($days / 30);

            return $months === 1 ? '1 month' : $months.' months';
        }

        return $days.' days';
    }

    private function isCurrentPlanForUser(User $user, Plan $plan): bool
    {
        $subscription = $this->activePlanResolver->getActiveSubscription($user);

        return $subscription instanceof Subscription
            && (int) $subscription->plan_id === (int) $plan->id;
    }

    private function isMobileBuyablePlan(User $user, Plan $plan): bool
    {
        return (bool) $plan->is_active
            && (bool) $plan->is_visible
            && ! Plan::isFreeCatalogSlug((string) $plan->slug)
            && Plan::profileGenderAllowsPlan($user, $plan);
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_object($value) && method_exists($value, 'toIso8601String')) {
            return $value->toIso8601String();
        }

        return (string) $value;
    }

    private function httpStatus(HttpException $exception): int
    {
        $status = $exception->getStatusCode();

        return $status >= 400 && $status < 600 ? $status : 422;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
