<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlanFeature;
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
        $this->syncFeatures($plan, $features);
        if (strtolower((string) $plan->slug) !== 'free') {
            PlanTerm::syncDefaultsForPlan($plan);
        }

        return redirect()
            ->route('admin.plans.edit', $plan)
            ->with('success', __('subscriptions.plan_saved'));
    }

    public function edit(Plan $plan)
    {
        $plan->load(['features', 'terms']);

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
        $this->syncFeatures($plan, $features);

        if (strtolower((string) $plan->slug) !== 'free') {
            $this->syncPlanTerms($plan, $request);
        } else {
            PlanTerm::query()->where('plan_id', $plan->id)->delete();
        }

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
            'features' => ['nullable', 'array'],
            'features.*.key' => ['nullable', 'string', 'max:120'],
            'features.*.value' => ['nullable', 'string', 'max:65535'],
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
