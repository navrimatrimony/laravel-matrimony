<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\PlanPrice;
use App\Models\PlanTerm;
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
        $defaultFeatures = $this->defaultFeatureTemplate();
        $merged = [];
        $byKey = $plan->features->keyBy('key');
        foreach ($defaultFeatures as $row) {
            $k = $row['key'];
            $merged[] = [
                'key' => $k,
                'value' => $byKey->has($k) ? (string) $byKey->get($k)->value : '',
            ];
        }
        foreach ($plan->features as $f) {
            if (! collect($merged)->contains(fn ($r) => $r['key'] === $f->key)) {
                $merged[] = ['key' => $f->key, 'value' => (string) $f->value];
            }
        }

        return view('admin.plans.form', [
            'plan' => $plan,
            'defaultFeatures' => $merged,
            'isEdit' => true,
        ]);
    }

    public function update(Request $request, Plan $plan)
    {
        $data = $this->validatedPlanData($request, $plan->id);
        $features = $this->normalizeFeatureRows($request->input('features', []));

        if (strtolower((string) $data['slug']) !== 'free') {
            $request->validate($this->termValidationRules());
        }

        $plan->update($data);
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
            'features.*.key' => ['nullable', 'string', 'max:64'],
            'features.*.value' => ['nullable', 'string', 'max:255'],
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
        PlanFeature::query()->where('plan_id', $plan->id)->delete();
        foreach ($rows as $row) {
            PlanFeature::query()->create([
                'plan_id' => $plan->id,
                'key' => $row['key'],
                'value' => $row['value'],
            ]);
        }
    }

    /**
     * @return list<array{key: string, value: string}>
     */
    private function defaultFeatureTemplate(): array
    {
        $rows = [
            ['key' => SubscriptionService::FEATURE_DAILY_CHAT_SEND_LIMIT, 'value' => ''],
            ['key' => SubscriptionService::FEATURE_MONTHLY_INTEREST_SEND_LIMIT, 'value' => ''],
            ['key' => SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT, 'value' => ''],
            ['key' => SubscriptionService::FEATURE_CONTACT_NUMBER_ACCESS, 'value' => ''],
            ['key' => SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES, 'value' => ''],
        ];

        foreach (PlanFeatureKeys::all() as $key) {
            $rows[] = ['key' => $key, 'value' => ''];
        }

        return $rows;
    }
}
