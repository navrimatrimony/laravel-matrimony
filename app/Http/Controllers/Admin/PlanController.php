<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\PlanPrice;
use App\Models\PlanQuotaPolicy;
use App\Models\PlanTerm;
use App\Models\Subscription;
use App\Services\PlanQuotaPolicyMirror;
use App\Support\PlanFeatureKeys;
use App\Support\PlanQuotaPolicyKeys;
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

        return view('admin.plans.form', [
            'plan' => $plan,
            'isEdit' => false,
            'quotaPolicyKeys' => PlanQuotaPolicyKeys::ordered(),
            'quotaPoliciesForm' => $this->quotaPoliciesFormState($plan, false),
        ]);
    }

    public function store(Request $request)
    {
        $this->validateQuotaPoliciesRequest($request);
        $data = $this->validatedPlanData($request);

        $plan = Plan::query()->create($data);
        PlanQuotaPolicy::ensureAllForPlan($plan);
        $this->syncQuotaPoliciesFromRequest($plan, $request);
        $this->syncFeatures($plan, $this->buildFullPlanFeatureRowsFromQuota($plan, $request));

        if (strtolower((string) $plan->slug) !== 'free') {
            PlanTerm::syncDefaultsForPlan($plan);
        }

        return redirect()
            ->route('admin.plans.edit', $plan)
            ->with('success', __('subscriptions.plan_saved'));
    }

    public function edit(Plan $plan)
    {
        PlanQuotaPolicy::ensureAllForPlan($plan);
        $plan->load(['features', 'terms', 'planPrices', 'quotaPolicies']);

        return view('admin.plans.form', [
            'plan' => $plan,
            'isEdit' => true,
            'quotaPolicyKeys' => PlanQuotaPolicyKeys::ordered(),
            'quotaPoliciesForm' => $this->quotaPoliciesFormState($plan, true),
        ]);
    }

    public function update(Request $request, Plan $plan)
    {
        $this->validateQuotaPoliciesRequest($request);
        $data = $this->validatedPlanData($request, $plan->id);

        if (strtolower((string) $data['slug']) !== 'free') {
            $request->validate($this->termValidationRules());
        }

        $plan->update($data);

        if (strtolower((string) $plan->slug) !== 'free') {
            $this->syncPlanTerms($plan, $request);
        } else {
            PlanTerm::query()->where('plan_id', $plan->id)->delete();
        }

        PlanQuotaPolicy::ensureAllForPlan($plan);
        $this->syncQuotaPoliciesFromRequest($plan, $request);
        $this->syncFeatures($plan, $this->buildFullPlanFeatureRowsFromQuota($plan, $request));

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

    private function validateQuotaPoliciesRequest(Request $request): void
    {
        $qp = $request->input('quota_policies');
        if (is_array($qp)) {
            foreach ($qp as $fk => $payload) {
                if (! is_array($payload) || ! array_key_exists('refresh_type', $payload)) {
                    continue;
                }
                $qp[$fk]['refresh_type'] = PlanQuotaPolicy::normalizeRefreshType((string) $payload['refresh_type']);
            }
            $request->merge(['quota_policies' => $qp]);
        }

        $rules = [
            'quota_policies' => ['required', 'array'],
        ];
        foreach (PlanQuotaPolicyKeys::ordered() as $fk) {
            $rules["quota_policies.$fk"] = ['required', 'array'];
            $rules["quota_policies.$fk.is_enabled"] = ['nullable'];
            $rules["quota_policies.$fk.refresh_type"] = ['required', 'string', Rule::in(PlanQuotaPolicy::refreshTypes())];
            $rules["quota_policies.$fk.limit_value"] = ['nullable', 'integer', 'min:0'];
            $rules["quota_policies.$fk.daily_sub_cap"] = ['nullable', 'integer', 'min:0'];
            $rules["quota_policies.$fk.per_day_usage_limit_enabled"] = ['sometimes', 'boolean'];
            $rules["quota_policies.$fk.grace_percent_of_plan"] = ['required', 'integer', 'min:0', 'max:100'];
            $rules["quota_policies.$fk.purchasable_if_exhausted"] = ['sometimes', 'boolean'];
            $rules["quota_policies.$fk.pack_price_rupees"] = ['nullable', 'numeric', 'min:0'];
            $rules["quota_policies.$fk.pack_message_count"] = ['nullable', 'integer', 'min:1'];
            $rules["quota_policies.$fk.pack_validity_days"] = ['nullable', 'integer', 'min:1'];
        }
        $request->validate($rules);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function quotaPoliciesFormState(Plan $plan, bool $isEdit): array
    {
        if ($isEdit) {
            $plan->loadMissing('quotaPolicies', 'features');
        }
        $states = [];
        foreach (PlanQuotaPolicyKeys::ordered() as $featureKey) {
            if ($isEdit && $plan->exists) {
                $row = $plan->quotaPolicies->firstWhere('feature_key', $featureKey);
                $base = $row ? $row->toArray() : PlanQuotaPolicy::defaultsFromPlanFeatures($plan, $featureKey);
            } else {
                $base = PlanQuotaPolicy::defaultsForNewPlan($featureKey);
            }
            $old = old('quota_policies.'.$featureKey);
            if (is_array($old)) {
                $base = $this->mergeQuotaPolicyOldIntoBase($base, $old);
            }
            $base['refresh_type'] = PlanQuotaPolicy::normalizeRefreshType((string) ($base['refresh_type'] ?? PlanQuotaPolicy::REFRESH_MONTHLY_30D_IST));
            $states[$featureKey] = $base;
        }

        return $states;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $old
     * @return array<string, mixed>
     */
    private function mergeQuotaPolicyOldIntoBase(array $base, array $old): array
    {
        if (isset($old['meta']) && is_array($old['meta'])) {
            $pm = is_array($base['policy_meta'] ?? null) ? $base['policy_meta'] : [];
            $base['policy_meta'] = array_replace_recursive($pm, $old['meta']);
        }
        unset($old['meta']);

        if (array_key_exists('pack_price_rupees', $old)) {
            $pr = $old['pack_price_rupees'];
            if ($pr !== '' && $pr !== null) {
                $base['pack_price_paise'] = (int) max(0, round(((float) $pr) * 100));
            }
            unset($old['pack_price_rupees']);
        }

        if (array_key_exists('purchasable_if_exhausted', $old)) {
            $p = filter_var($old['purchasable_if_exhausted'], FILTER_VALIDATE_BOOLEAN)
                || (string) $old['purchasable_if_exhausted'] === '1';
            $base['overuse_mode'] = $p ? PlanQuotaPolicy::OVERUSE_PACK : PlanQuotaPolicy::OVERUSE_BLOCK;
            unset($old['purchasable_if_exhausted']);
        }

        foreach (['is_enabled', 'per_day_usage_limit_enabled'] as $boolKey) {
            if (! array_key_exists($boolKey, $old)) {
                continue;
            }
            $base[$boolKey] = filter_var($old[$boolKey], FILTER_VALIDATE_BOOLEAN)
                || (string) $old[$boolKey] === '1';
            unset($old[$boolKey]);
        }

        return array_merge($base, $old);
    }

    private function syncQuotaPoliciesFromRequest(Plan $plan, Request $request): void
    {
        foreach (PlanQuotaPolicyKeys::ordered() as $featureKey) {
            $prefix = 'quota_policies.'.$featureKey;
            if (! is_array($request->input($prefix))) {
                continue;
            }
            $refresh = (string) $request->input("$prefix.refresh_type");
            $limitRaw = $request->input("$prefix.limit_value");
            $limitValue = null;
            if ($refresh !== PlanQuotaPolicy::REFRESH_UNLIMITED) {
                $limitValue = ($limitRaw === '' || $limitRaw === null) ? 0 : max(0, (int) $limitRaw);
            }

            $perDayEnabled = $request->boolean("$prefix.per_day_usage_limit_enabled");
            $capRaw = $request->input("$prefix.daily_sub_cap");
            $dailySubCap = null;
            if ($perDayEnabled) {
                $dailySubCap = ($capRaw === '' || $capRaw === null) ? null : max(0, (int) $capRaw);
            }

            $packRupees = $request->input("$prefix.pack_price_rupees");
            $packPaise = ($packRupees === '' || $packRupees === null)
                ? null
                : (int) max(0, round(((float) $packRupees) * 100));

            $packCountRaw = $request->input("$prefix.pack_message_count");
            $packCount = ($packCountRaw === '' || $packCountRaw === null) ? null : max(1, (int) $packCountRaw);

            $packDaysRaw = $request->input("$prefix.pack_validity_days");
            $packDays = ($packDaysRaw === '' || $packDaysRaw === null) ? null : max(1, (int) $packDaysRaw);

            $purchasableIfExhausted = $request->boolean("$prefix.purchasable_if_exhausted");
            $overuse = $purchasableIfExhausted
                ? PlanQuotaPolicy::OVERUSE_PACK
                : PlanQuotaPolicy::OVERUSE_BLOCK;
            if (! $purchasableIfExhausted) {
                $packPaise = null;
                $packCount = null;
                $packDays = null;
            }

            $meta = null;
            if ($featureKey === PlanFeatureKeys::INTEREST_VIEW_LIMIT) {
                $plan->loadMissing('features');
                $raw = (string) ($plan->getFeatureValue(PlanFeatureKeys::INTEREST_VIEW_RESET_PERIOD) ?? 'monthly');
                $period = in_array($raw, ['weekly', 'monthly', 'quarterly'], true) ? $raw : 'monthly';
                $meta = ['interest_view_reset_period' => $period];
            }

            PlanQuotaPolicy::query()->updateOrCreate(
                ['plan_id' => $plan->id, 'feature_key' => $featureKey],
                [
                    'is_enabled' => $request->boolean("$prefix.is_enabled"),
                    'refresh_type' => $refresh,
                    'limit_value' => $limitValue,
                    'daily_sub_cap' => $dailySubCap,
                    'per_day_usage_limit_enabled' => $perDayEnabled,
                    'grace_percent_of_plan' => max(0, min(100, (int) $request->input("$prefix.grace_percent_of_plan", 0))),
                    'overuse_mode' => $overuse,
                    'pack_price_paise' => $packPaise,
                    'pack_message_count' => $packCount,
                    'pack_validity_days' => $packDays,
                    'policy_meta' => $meta,
                ]
            );
        }
    }

    /**
     * @return list<array{key: string, value: string}>
     */
    private function buildFullPlanFeatureRowsFromQuota(Plan $plan, Request $request): array
    {
        $quotaRows = PlanQuotaPolicyMirror::planFeatureRowsFromQuotaRequest($request);
        $byKey = [];
        foreach ($quotaRows as $row) {
            $byKey[$row['key']] = $row['value'];
        }
        $plan->forgetCachedPlanFeatures();
        $plan->load('features');
        $out = [];
        foreach (array_keys((array) config('plan_features', [])) as $key) {
            if (array_key_exists($key, $byKey)) {
                $out[] = ['key' => $key, 'value' => $byKey[$key]];
            } else {
                $existing = $plan->getFeatureValue($key);
                $fallback = $key === PlanFeatureKeys::INTEREST_VIEW_RESET_PERIOD ? 'monthly' : '0';
                $out[] = [
                    'key' => $key,
                    'value' => ($existing !== null && $existing !== '') ? $existing : $fallback,
                ];
            }
        }

        return $this->normalizeFeatureRows($out);
    }

    /**
     * @param  array<int, array{key?: string, value?: string}>  $rows
     * @return array<int, array{key: string, value: string}>
     */
    private function normalizeFeatureRows(array $rows): array
    {
        $byKey = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $byKey[$key] = [
                'key' => $key,
                'value' => (string) ($row['value'] ?? ''),
            ];
        }

        return array_values($byKey);
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
}
