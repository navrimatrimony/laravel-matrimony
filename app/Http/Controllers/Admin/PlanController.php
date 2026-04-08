<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\PlanFeatureConfig;
use App\Models\PlanPrice;
use App\Models\PlanTerm;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use App\Support\PlanFeatureKeys;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    public function index()
    {
        $plans = Plan::query()
            ->with('features')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return view('admin.plans.index', compact('plans'));
    }

    public function create()
    {
        $plan = new Plan([
            'is_active' => true,
            'duration_days' => 30,
            'sort_order' => 0,
            'highlight' => false,
        ]);

        $defaultFeatures = $this->defaultFeatureTemplate();

        return view('admin.plans.form', [
            'plan' => $plan,
            'defaultFeatures' => $defaultFeatures,
            'isEdit' => false,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validatedPlanData($request);
        $features = $this->normalizeFeatureRows($request->input('features', []));

        $plan = Plan::query()->create($data);
        $features = $this->mergeMonetizationFeaturesIntoRows($features, $request);
        $this->syncFeatures($plan, $features);
        $this->syncFeatureConfigsFromRequest($plan, $request);
        if (strtolower((string) $plan->slug) !== 'free') {
            PlanTerm::syncDefaultsForPlan($plan);
        }

        return redirect()
            ->route('admin.plans.edit', $plan)
            ->with('success', __('subscriptions.plan_saved'));
    }

    public function edit(Plan $plan)
    {
        $plan->load(['features', 'terms', 'planPrices', 'featureConfigs']);

        return view('admin.plans.form', [
            'plan' => $plan,
            'defaultFeatures' => $this->defaultFeatureTemplate(),
            'isEdit' => true,
        ]);
    }

    public function update(Request $request, Plan $plan)
    {
        $data = $this->validatedPlanData($request, $plan->id);

        if (strtolower((string) $data['slug']) !== 'free') {
            $request->validate($this->termValidationRules());
        }

        $plan->update($data);

        $features = $this->normalizeFeatureRows($request->input('features', []));
        $features = $this->mergeMonetizationFeaturesIntoRows($features, $request);
        $this->syncFeatures($plan, $features);

        if (strtolower((string) $plan->slug) !== 'free') {
            $this->syncPlanTerms($plan, $request);
            $this->syncAdvancedDurationPricesFromRequest($plan, $request);
        } else {
            PlanTerm::query()->where('plan_id', $plan->id)->delete();
        }

        $this->syncFeatureConfigsFromRequest($plan, $request);

        return redirect()
            ->route('admin.plans.edit', $plan)
            ->with('success', __('subscriptions.plan_saved'));
    }

    public function destroy(Plan $plan)
    {
        if (strtolower((string) $plan->slug) === 'free') {
            return redirect()
                ->route('admin.plans.index')
                ->with('error', __('admin_commerce.plan_delete_free_forbidden'));
        }

        if (Subscription::query()->where('plan_id', $plan->id)->exists()) {
            return redirect()
                ->route('admin.plans.index')
                ->with('error', __('admin_commerce.plan_delete_has_subscriptions'));
        }

        $plan->delete();

        return redirect()
            ->route('admin.plans.index')
            ->with('success', __('admin_commerce.plan_deleted'));
    }

    public function toggle(Request $request, Plan $plan)
    {
        $request->validate([
            'field' => ['required', 'string', Rule::in(['is_active', 'highlight'])],
            'value' => ['required', 'boolean'],
        ]);

        $field = (string) $request->input('field');
        $plan->update([$field => $request->boolean('value')]);

        return redirect()
            ->route('admin.plans.index')
            ->with('success', __('admin_commerce.plan_toggle_saved'));
    }

    /**
     * @return array<string, mixed>
     */
    private function termValidationRules(): array
    {
        $rules = [];
        foreach (PlanTerm::billingKeys() as $key) {
            $rules["terms.$key.price"] = ['required', 'numeric', 'min:0'];
            $rules["terms.$key.discount_percent"] = ['nullable', 'numeric', 'min:0', 'max:100'];
            $rules["terms.$key.is_visible"] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    private function syncPlanTerms(Plan $plan, Request $request): void
    {
        foreach (PlanTerm::billingKeys() as $key) {
            $price = (float) $request->input("terms.$key.price", 0);
            $rawD = $request->input("terms.$key.discount_percent");
            $disc = ($rawD === '' || $rawD === null)
                ? null
                : max(0, min(100, (int) round((float) $rawD)));
            $visible = $request->boolean("terms.$key.is_visible");

            PlanTerm::query()->updateOrCreate(
                ['plan_id' => $plan->id, 'billing_key' => $key],
                [
                    'duration_days' => PlanTerm::durationDaysFor($key),
                    'price' => $price,
                    'discount_percent' => $disc,
                    'is_visible' => $visible,
                    'sort_order' => PlanTerm::defaultSortOrder($key),
                ]
            );
        }

        PlanPrice::syncFromPlanTerms($plan->fresh('terms'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPlanData(Request $request, ?int $ignoreId = null): array
    {
        $rawDiscount = $request->input('discount_percent');
        $request->merge([
            'discount_percent' => ($rawDiscount === '' || $rawDiscount === null)
                ? null
                : max(0, min(100, (int) round((float) $rawDiscount))),
        ]);

        $slugRule = Rule::unique('plans', 'slug');
        if ($ignoreId !== null) {
            $slugRule = $slugRule->ignore($ignoreId);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slugRule],
            'price' => ['required', 'numeric', 'min:0'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'duration_days' => ['required', 'integer', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
            'highlight' => ['sometimes', 'boolean'],
            'referral_bonus_days' => ['nullable', 'integer', 'min:0'],
            'profile_boost_per_week' => ['nullable', 'integer', 'min:0'],
            'who_viewed_me_days' => ['nullable', 'integer', 'min:0'],
            'priority_listing' => ['sometimes', 'boolean'],
            'features' => ['nullable', 'array'],
            'features.*.key' => ['nullable', 'string', 'max:120'],
            'features.*.value' => ['nullable', 'string', 'max:65535'],
            'prices' => ['nullable', 'array'],
            'feature_configs' => ['nullable', 'array'],
        ]);

        return [
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'price' => $validated['price'],
            'discount_percent' => $validated['discount_percent'] ?? null,
            'duration_days' => (int) $validated['duration_days'],
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_active' => $request->boolean('is_active'),
            'highlight' => $request->boolean('highlight'),
        ];
    }

    /**
     * @param  array<int, array{key?: string, value?: string}>  $rows
     * @return array<int, array{key: string, value: string}>
     */
    private function normalizeFeatureRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $out[] = [
                'key' => $key,
                'value' => (string) ($row['value'] ?? ''),
            ];
        }

        $seen = [];
        $unique = [];
        foreach ($out as $r) {
            if (isset($seen[$r['key']])) {
                continue;
            }
            $seen[$r['key']] = true;
            $unique[] = $r;
        }

        return $unique;
    }

    /**
     * Ensures structured monetization fields are persisted as plan_features rows
     * (same keys as the key/value UI) so syncFeatures does not delete them when hidden from the list.
     *
     * @param  array<int, array{key: string, value: string}>  $rows
     * @return array<int, array{key: string, value: string}>
     */
    private function mergeMonetizationFeaturesIntoRows(array $rows, Request $request): array
    {
        $montKeys = [
            PlanFeatureKeys::REFERRAL_BONUS_DAYS,
            PlanFeatureKeys::PRIORITY_LISTING,
            PlanFeatureKeys::PROFILE_BOOST_PER_WEEK,
            PlanFeatureKeys::WHO_VIEWED_ME_DAYS,
        ];
        $filtered = [];
        foreach ($rows as $row) {
            if (in_array($row['key'], $montKeys, true)) {
                continue;
            }
            $filtered[] = $row;
        }

        $ref = $request->input('referral_bonus_days');
        $boost = $request->input('profile_boost_per_week');
        $who = $request->input('who_viewed_me_days');

        $extra = [
            [
                'key' => PlanFeatureKeys::REFERRAL_BONUS_DAYS,
                'value' => ($ref === '' || $ref === null) ? '0' : (string) max(0, (int) $ref),
            ],
            [
                'key' => PlanFeatureKeys::PRIORITY_LISTING,
                'value' => $request->boolean('priority_listing') ? '1' : '0',
            ],
            [
                'key' => PlanFeatureKeys::PROFILE_BOOST_PER_WEEK,
                'value' => ($boost === '' || $boost === null) ? '0' : (string) max(0, (int) $boost),
            ],
            [
                'key' => PlanFeatureKeys::WHO_VIEWED_ME_DAYS,
                'value' => ($who === '' || $who === null) ? '0' : (string) max(0, (int) $who),
            ],
        ];

        return array_merge($filtered, $extra);
    }

    /**
     * "Pricing (Advanced)" table: updates {@see PlanTerm} for non-empty rows, then mirrors {@see PlanPrice}.
     * Runs after {@see syncPlanTerms()} so filled advanced cells override duplicate billing fields for the same duration keys.
     */
    private function syncAdvancedDurationPricesFromRequest(Plan $plan, Request $request): void
    {
        if (strtolower((string) $plan->slug) === 'free') {
            return;
        }
        $payload = $request->input('prices');
        if (! is_array($payload)) {
            return;
        }
        $touched = false;
        foreach (PlanTerm::billingKeys() as $type) {
            if (! isset($payload[$type]) || ! is_array($payload[$type])) {
                continue;
            }
            $data = $payload[$type];
            $priceRaw = $data['price'] ?? null;
            $discRaw = $data['discount'] ?? null;
            if (($priceRaw === '' || $priceRaw === null) && ($discRaw === '' || $discRaw === null)) {
                continue;
            }
            $touched = true;
            $base = (float) ($priceRaw ?? 0);
            $disc = ($discRaw === '' || $discRaw === null)
                ? null
                : max(0, min(100, (int) round((float) $discRaw)));
            $days = PlanTerm::durationDaysFor($type);

            PlanTerm::query()->updateOrCreate(
                ['plan_id' => $plan->id, 'billing_key' => $type],
                [
                    'duration_days' => $days,
                    'price' => $base,
                    'discount_percent' => $disc,
                    'is_visible' => true,
                    'sort_order' => PlanTerm::defaultSortOrder($type),
                ]
            );
        }
        if ($touched) {
            PlanPrice::syncFromPlanTerms($plan->fresh('terms'));
        }
    }

    /**
     * Structured feature engine (parallel to plan_features key/value); does not replace {@see FeatureUsageService} reads.
     */
    private function syncFeatureConfigsFromRequest(Plan $plan, Request $request): void
    {
        $payload = $request->input('feature_configs');
        if (! is_array($payload)) {
            return;
        }
        $allowed = [
            PlanFeatureKeys::CHAT_SEND_LIMIT,
            PlanFeatureKeys::CONTACT_VIEW_LIMIT,
            PlanFeatureKeys::INTEREST_SEND_LIMIT,
            SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT,
        ];
        foreach ($allowed as $key) {
            if (! isset($payload[$key]) || ! is_array($payload[$key])) {
                continue;
            }
            $data = $payload[$key];
            $extra = $data['extra_cost'] ?? null;
            $extraPaise = ($extra === '' || $extra === null)
                ? null
                : (int) max(0, round(((float) $extra) * 100));

            $lim = $data['limit'] ?? null;
            $limitTotal = ($lim === '' || $lim === null) ? null : (int) $lim;

            $cap = $data['daily_cap'] ?? null;
            $dailyCap = ($cap === '' || $cap === null) ? null : (int) $cap;

            $soft = $data['soft_limit'] ?? null;
            $softPct = ($soft === '' || $soft === null) ? null : (int) max(0, min(100, (int) $soft));

            $ex = $data['expiry'] ?? null;
            $expiryDays = ($ex === '' || $ex === null) ? null : (int) $ex;

            $period = $data['period'] ?? null;
            $period = in_array($period, ['daily', 'monthly'], true) ? $period : null;

            PlanFeatureConfig::query()->updateOrCreate(
                ['plan_id' => $plan->id, 'feature_key' => $key],
                [
                    'is_enabled' => $request->boolean("feature_configs.$key.enabled"),
                    'is_unlimited' => $request->boolean("feature_configs.$key.unlimited"),
                    'limit_total' => $limitTotal,
                    'period' => $period,
                    'daily_cap' => $dailyCap,
                    'soft_limit_percent' => $softPct,
                    'expiry_days' => $expiryDays,
                    'extra_cost_per_action' => $extraPaise,
                ]
            );
        }
    }

    /**
     * @param  array<int, array{key: string, value: string}>  $rows
     */
    private function syncFeatures(Plan $plan, array $rows): void
    {
        $keys = [];
        foreach ($rows as $row) {
            $keys[] = $row['key'];
            PlanFeature::query()->updateOrCreate(
                [
                    'plan_id' => $plan->id,
                    'key' => $row['key'],
                ],
                [
                    'value' => $row['value'],
                ]
            );
        }
        $keys = array_values(array_unique($keys));
        if ($keys === []) {
            PlanFeature::query()->where('plan_id', $plan->id)->delete();

            return;
        }
        PlanFeature::query()->where('plan_id', $plan->id)->whereNotIn('key', $keys)->delete();
    }

    /**
     * @return list<array{key: string, value: string}>
     */
    private function defaultFeatureTemplate(): array
    {
        $ordered = [
            PlanFeatureKeys::CHAT_SEND_LIMIT,
            PlanFeatureKeys::INTEREST_SEND_LIMIT,
            SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT,
            PlanFeatureKeys::CONTACT_VIEW_LIMIT,
            SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES,
        ];
        $rest = array_values(array_diff(PlanFeatureKeys::all(), $ordered));
        $rows = [];
        foreach (array_merge($ordered, $rest) as $key) {
            $rows[] = ['key' => $key, 'value' => ''];
        }

        return $rows;
    }
}
