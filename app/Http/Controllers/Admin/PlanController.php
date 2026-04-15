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
use App\Services\PlanSubscriptionTerms;
use App\Support\PlanFeatureKeys;
use App\Support\PlanQuotaPolicyKeys;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    /** Stored in {@see Plan::$marketing_badge}; labels from {@code subscriptions.admin_plan_marketing_opt_*}. */
    public const ADMIN_MARKETING_BADGE_KEYS = [
        'best_seller',
        'popular',
        'new',
        'limited_offer',
        'recommended',
    ];

    /** Plan-wide duration preset (subset of billing keys; excludes custom-only paths). */
    public const ADMIN_PLAN_DURATION_PRESET_KEYS = [
        PlanTerm::BILLING_MONTHLY,
        PlanTerm::BILLING_QUARTERLY,
        PlanTerm::BILLING_HALF_YEARLY,
        PlanTerm::BILLING_YEARLY,
        PlanTerm::BILLING_FIVE_YEARLY,
        PlanTerm::BILLING_LIFETIME,
    ];

    /** Admin plan form: grace period (days); 0 = none (no extra days after paid window). */
    public const ADMIN_GRACE_PERIOD_DAY_OPTIONS = [0, 3, 5, 7, 14, 30, 90];

    /** Admin plan form: leftover quota carry window (days) dropdown values. */
    public const ADMIN_LEFTOVER_CARRY_DAY_OPTIONS = [7, 30, 90, 180, 365];

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
            'grace_period_days' => 3,
            'leftover_quota_carry_window_days' => null,
            'sort_order' => 0,
            'highlight' => false,
            'applies_to_gender' => 'all',
            'gst_inclusive' => true,
        ]);

        return view('admin.plans.form', [
            'plan' => $plan,
            'isEdit' => false,
            'quotaPolicyKeys' => PlanQuotaPolicyKeys::ordered(),
            'quotaPoliciesForm' => $this->quotaPoliciesFormState($plan, false),
            'durationPresetInitial' => $this->durationPresetForAdminForm($plan),
            'termRowsInitial' => $this->initialTermRowsForForm($plan, false),
            'adminMarketingBadgeKeys' => self::ADMIN_MARKETING_BADGE_KEYS,
            'adminPlanDurationPresetKeys' => self::ADMIN_PLAN_DURATION_PRESET_KEYS,
            'planNameInput' => $this->planNameInputFromSession($plan),
            'initialPlanNameSha10' => substr(hash('sha256', $this->planNameInputFromSession($plan)), 0, 10),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->validateQuotaPoliciesRequest($request);

        $slug = strtolower((string) $request->input('slug', ''));

        if (Plan::isFreeCatalogSlug($slug)) {
            $request->merge([
                'price' => $request->input('price', 0),
                'duration_days' => $request->input('duration_days', 0),
            ]);
            $data = $this->validatedPlanData($request);

            $plan = Plan::query()->create($data);
            PlanQuotaPolicy::ensureAllForPlan($plan);
            $this->syncQuotaPoliciesFromRequest($plan, $request);
            $this->syncFeatures($plan, $this->buildFullPlanFeatureRowsFromQuota($plan, $request));
            $plan->forgetCachedPlanFeatures();

            return redirect()
                ->route('admin.plans.edit', $plan)
                ->with('success', __('subscriptions.plan_saved'));
        }

        $this->syncPlanDiscountFromFormIntoFirstTermRow($request);
        $this->validateTermRowsRequest($request);
        $rows = $this->normalizedTermRowsFromRequest($request);
        $primary = $rows[0] ?? null;
        if ($primary === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'term_rows' => [__('subscriptions.admin_term_rows_required')],
            ]);
        }
        $reqDisc = $request->input('discount_percent');
        $mergedDisc = ($reqDisc !== '' && $reqDisc !== null)
            ? max(0, min(100, (int) round((float) $reqDisc)))
            : $primary['discount_percent'];
        $request->merge([
            'price' => $primary['price'],
            'discount_percent' => $mergedDisc,
        ]);

        $data = $this->validatedPlanData($request);

        $plan = Plan::query()->create($data);
        PlanQuotaPolicy::ensureAllForPlan($plan);
        $this->syncQuotaPoliciesFromRequest($plan, $request);
        $this->syncFeatures($plan, $this->buildFullPlanFeatureRowsFromQuota($plan, $request));
        $plan->forgetCachedPlanFeatures();

        PlanTerm::syncAdminTermRows($plan, $rows);
        $this->syncPlanDefaultBillingKey($plan->fresh('terms'), $request->input('default_billing_key'));

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
            'durationPresetInitial' => $this->durationPresetForAdminForm($plan),
            'termRowsInitial' => $this->initialTermRowsForForm($plan, true),
            'adminMarketingBadgeKeys' => self::ADMIN_MARKETING_BADGE_KEYS,
            'adminPlanDurationPresetKeys' => self::ADMIN_PLAN_DURATION_PRESET_KEYS,
            'planNameInput' => $this->planNameInputFromSession($plan),
            'initialPlanNameSha10' => substr(hash('sha256', $this->planNameInputFromSession($plan)), 0, 10),
        ]);
    }

    public function update(Request $request, Plan $plan)
    {
        $this->validateQuotaPoliciesRequest($request);
        if (! Plan::isFreeCatalogSlug((string) $plan->slug)) {
            $this->mergeTermRowsFallbackForUpdate($request, $plan);
            $this->syncPlanDiscountFromFormIntoFirstTermRow($request);
            $this->validateTermRowsRequest($request);
        }
        $data = $this->validatedPlanData($request, $plan->id, $plan);

        $plan->update($data);

        if (! Plan::isFreeCatalogSlug((string) ($data['slug'] ?? ''))) {
            $rows = $this->normalizedTermRowsFromRequest($request);
            PlanTerm::syncAdminTermRows($plan, $rows);
            $this->syncPlanDefaultBillingKey($plan->fresh('terms'), $request->input('default_billing_key'));
        } else {
            PlanTerm::query()->where('plan_id', $plan->id)->delete();
            PlanPrice::query()->where('plan_id', $plan->id)->delete();
        }

        PlanQuotaPolicy::ensureAllForPlan($plan);
        $this->syncQuotaPoliciesFromRequest($plan, $request);
        $this->syncFeatures($plan, $this->buildFullPlanFeatureRowsFromQuota($plan, $request));
        $plan->forgetCachedPlanFeatures();

        return redirect()
            ->route('admin.plans.edit', $plan)
            ->with('success', __('subscriptions.plan_saved'));
    }

    public function destroy(Plan $plan)
    {
        if (Plan::isFreeCatalogSlug((string) $plan->slug)) {
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
        $value = $request->boolean('value');

        if ($field === 'highlight') {
            if ($value) {
                $badge = $plan->marketing_badge ?: 'best_seller';
                $plan->update([
                    'highlight' => true,
                    'marketing_badge' => $badge,
                ]);
            } else {
                $plan->update([
                    'highlight' => false,
                    'marketing_badge' => null,
                ]);
            }
        } else {
            $plan->update([$field => $value]);
        }

        return redirect()
            ->route('admin.plans.index')
            ->with('success', __('admin_commerce.plan_toggle_saved'));
    }

    private function validateTermRowsRequest(Request $request): void
    {
        $request->validate([
            'duration_preset' => ['required', 'string', Rule::in(PlanTerm::presetBillingKeys())],
            'term_rows' => ['required', 'array', 'min:1'],
            'term_rows.*.billing_key' => ['required', 'string', Rule::in(PlanTerm::presetBillingKeys())],
            'term_rows.*.price' => ['required', 'numeric', 'min:0'],
            'term_rows.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'term_rows.*.is_visible' => ['nullable'],
            'default_billing_key' => ['nullable', 'string', Rule::in(PlanTerm::presetBillingKeys())],
        ]);

        $keys = collect($request->input('term_rows', []))
            ->pluck('billing_key')
            ->map(fn ($k) => (string) $k)
            ->filter();
        if ($keys->count() !== $keys->unique()->count()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'term_rows' => [__('subscriptions.admin_term_rows_duplicate')],
            ]);
        }
    }

    /**
     * @return list<array{billing_key: string, price: float, discount_percent: int|null, is_visible: bool}>
     */
    private function normalizedTermRowsFromRequest(Request $request): array
    {
        $out = [];
        foreach ((array) $request->input('term_rows', []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = (string) ($row['billing_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $rawD = $row['discount_percent'] ?? null;
            $disc = ($rawD === '' || $rawD === null)
                ? null
                : max(0, min(100, (int) round((float) $rawD)));
            $visible = filter_var($row['is_visible'] ?? true, FILTER_VALIDATE_BOOLEAN)
                || (string) ($row['is_visible'] ?? '') === '1';

            $out[] = [
                'billing_key' => $key,
                'price' => (float) ($row['price'] ?? 0),
                'discount_percent' => $disc,
                'is_visible' => $visible,
            ];
        }

        return $out;
    }

    private function syncPlanDefaultBillingKey(Plan $plan, mixed $requested): void
    {
        $plan->loadMissing('terms');
        $keys = $plan->terms->pluck('billing_key')->all();
        if ($keys === []) {
            $plan->update(['default_billing_key' => null]);

            return;
        }

        $req = is_string($requested) ? trim($requested) : '';
        if ($req !== '' && in_array($req, $keys, true)) {
            $plan->update(['default_billing_key' => $req]);

            return;
        }

        $visible = $plan->terms->where('is_visible', true)->sortBy('sort_order')->first();
        $fallback = $visible?->billing_key ?? $plan->terms->sortBy('sort_order')->first()?->billing_key;
        $plan->update(['default_billing_key' => $fallback]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPlanData(Request $request, ?int $ignoreId = null, ?Plan $planContext = null): array
    {
        if (! $request->has('applies_to_gender')) {
            $request->merge(['applies_to_gender' => 'all']);
        }
        if (! $request->has('gst_inclusive')) {
            $request->merge(['gst_inclusive' => '1']);
        }
        $this->normalizeMarketingBadgeRequest($request);
        if ($request->input('leftover_quota_carry_window_days') === '' || $request->input('leftover_quota_carry_window_days') === null) {
            $request->merge(['leftover_quota_carry_window_days' => null]);
        }

        $rawDiscount = $request->input('discount_percent');
        $request->merge([
            'discount_percent' => ($rawDiscount === '' || $rawDiscount === null)
                ? null
                : max(0, min(100, (int) round((float) $rawDiscount))),
        ]);

        $isFreeSystemPlan = ($planContext !== null && Plan::isFreeCatalogSlug((string) $planContext->slug))
            || Plan::isFreeCatalogSlug((string) $request->input('slug', ''));

        if ($planContext !== null && ! $isFreeSystemPlan) {
            $lp = $request->input('list_price_rupees');
            if ($lp !== null && $lp !== '') {
                $request->merge(['price' => (float) $lp]);
            } else {
                $request->merge(['price' => (float) ($planContext->price ?? 0)]);
            }
        } elseif ($isFreeSystemPlan && ! $request->filled('price')) {
            $request->merge(['price' => $planContext !== null ? (float) ($planContext->price ?? 0) : 0.0]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'price' => ['required', 'numeric', 'min:0'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'duration_days' => [$isFreeSystemPlan ? 'required' : 'nullable', 'integer', 'min:0'],
            'grace_period_days' => ['required', 'integer', Rule::in(self::ADMIN_GRACE_PERIOD_DAY_OPTIONS)],
            'leftover_quota_carry_window_days' => ['nullable', 'integer', Rule::in(self::ADMIN_LEFTOVER_CARRY_DAY_OPTIONS)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
            'default_billing_key' => ['nullable', 'string', 'max:64'],
            'applies_to_gender' => ['required', 'string', Rule::in(['male', 'female', 'all'])],
            'marketing_badge' => ['nullable', 'string', 'max:80', Rule::in(array_merge([''], self::ADMIN_MARKETING_BADGE_KEYS))],
            'list_price_rupees' => ['nullable', 'integer', 'min:0'],
            'gst_inclusive' => ['sometimes'],
            'chat_initiate_new_chats_only' => ['sometimes', 'boolean'],
        ]);

        $leftoverRaw = $request->input('leftover_quota_carry_window_days');
        $leftover = ($leftoverRaw === '' || $leftoverRaw === null)
            ? null
            : max(0, (int) $leftoverRaw);

        if ($isFreeSystemPlan) {
            $incoming = strtolower(trim((string) $request->input('slug', '')));
            if ($incoming !== '' && Plan::isFreeCatalogSlug($incoming)) {
                $slug = $incoming;
            } elseif ($planContext !== null) {
                $slug = strtolower((string) $planContext->slug);
            } else {
                $slug = 'free';
            }
        } elseif ($planContext !== null) {
            // Keep existing catalog URL key stable while editing paid plans.
            $slug = strtolower((string) $planContext->slug);
        } else {
            $slug = $this->resolveAutomaticPlanSlug(
                (string) $validated['name'],
                (string) ($validated['applies_to_gender'] ?? 'all'),
                $ignoreId
            );
        }

        $durationDays = (int) ($validated['duration_days'] ?? 0);
        if (! $isFreeSystemPlan) {
            $preset = (string) $request->input('duration_preset', '');
            if (in_array($preset, PlanTerm::presetBillingKeys(), true)) {
                $durationDays = PlanTerm::durationDaysFor($preset);
            }
        }

        $durationQuantity = null;
        $durationUnit = null;

        $listPrice = $validated['list_price_rupees'] ?? null;
        if ($listPrice === '' || $listPrice === null) {
            $listPriceRupees = null;
        } else {
            $listPriceRupees = max(0, (int) $listPrice);
        }

        $defaultBilling = $isFreeSystemPlan ? null : ($validated['default_billing_key'] ?? null);
        if (is_string($defaultBilling) && $defaultBilling === '') {
            $defaultBilling = null;
        }

        $marketingBadge = isset($validated['marketing_badge']) && $validated['marketing_badge'] !== ''
            ? (string) $validated['marketing_badge']
            : null;

        return [
            'name' => $validated['name'],
            'slug' => $slug,
            'price' => $validated['price'],
            'discount_percent' => $validated['discount_percent'] ?? null,
            'list_price_rupees' => $listPriceRupees,
            'gst_inclusive' => $request->boolean('gst_inclusive'),
            'duration_days' => $durationDays,
            'duration_quantity' => $durationQuantity !== null ? (int) $durationQuantity : null,
            'duration_unit' => $durationUnit,
            'default_billing_key' => $defaultBilling,
            'grace_period_days' => max(0, (int) $validated['grace_period_days']),
            'leftover_quota_carry_window_days' => $leftover,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_active' => $request->boolean('is_active'),
            'highlight' => $marketingBadge !== null,
            'applies_to_gender' => (string) $validated['applies_to_gender'],
            'marketing_badge' => $marketingBadge,
        ];
    }

    /**
     * Public URL key: slugified plan name + audience suffix ({@code -male}, {@code -female}, {@code -all}).
     *
     * Uniqueness: {@see ensureUniquePlanSlug()} appends {@code -2}, {@code -3}, … on collisions.
     * For names that do not ASCII-slug (many scripts), the base segment uses a short hash of the
     * full name so different titles rarely collide before the numeric suffix pass.
     */
    private function resolveAutomaticPlanSlug(string $name, string $appliesToGender, ?int $ignorePlanId): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'p-'.substr(hash('sha256', $name), 0, 10);
        }
        $g = strtolower(trim($appliesToGender));
        $suffix = match ($g) {
            'male' => '-male',
            'female' => '-female',
            default => '-all',
        };
        $maxBase = max(1, 64 - strlen($suffix));
        $basePart = Str::substr($base, 0, $maxBase);
        $basePart = rtrim((string) $basePart, '-');
        if ($basePart === '') {
            $basePart = 'plan';
        }
        $candidate = $basePart.$suffix;

        return $this->ensureUniquePlanSlug($candidate, $ignorePlanId);
    }

    private function ensureUniquePlanSlug(string $slug, ?int $ignorePlanId): string
    {
        $slug = strtolower(trim($slug));
        if ($slug === '') {
            $slug = 'plan';
        }
        if (strlen($slug) > 64) {
            $slug = Str::substr($slug, 0, 64);
        }
        $original = $slug;
        $i = 2;
        while (Plan::query()
            ->when($ignorePlanId !== null, fn ($q) => $q->where('id', '!=', $ignorePlanId))
            ->where('slug', $slug)
            ->exists()
        ) {
            $suf = '-'.$i;
            $slug = rtrim(Str::substr($original, 0, max(1, 64 - strlen($suf))), '-').$suf;
            if (strlen($slug) > 64) {
                $slug = Str::substr($slug, 0, 64);
            }
            $i++;
        }

        return $slug;
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
                    'overuse_mode' => $overuse,
                    'pack_price_paise' => $packPaise,
                    'pack_message_count' => $packCount,
                    'pack_validity_days' => $packDays,
                    'policy_meta' => $meta,
                ]
            );
        }

        PlanSubscriptionTerms::syncDerivedGracePercentToAllQuotaPolicies($plan);
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

        $rows = $this->normalizeFeatureRows($out);
        $initVal = $request->boolean('chat_initiate_new_chats_only') ? '1' : '0';
        $replaced = false;
        foreach ($rows as &$row) {
            if (($row['key'] ?? '') === PlanFeatureKeys::CHAT_INITIATE_NEW_CHATS_ONLY) {
                $row['value'] = $initVal;
                $replaced = true;
                break;
            }
        }
        unset($row);
        if (! $replaced) {
            $rows[] = ['key' => PlanFeatureKeys::CHAT_INITIATE_NEW_CHATS_ONLY, 'value' => $initVal];
        }

        return $this->normalizeFeatureRows($rows);
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

    /**
     * When the admin sets plan-level “Discount %”, mirror it into the first billing row before term validation/sync.
     */
    private function syncPlanDiscountFromFormIntoFirstTermRow(Request $request): void
    {
        $tr = $request->input('term_rows');
        if (! is_array($tr) || ! isset($tr[0]) || ! is_array($tr[0])) {
            return;
        }
        $d = $request->input('discount_percent');
        if ($d === null || $d === '') {
            return;
        }
        $tr[0]['discount_percent'] = $d;
        $request->merge(['term_rows' => $tr]);
    }

    /**
     * Edit fallback: if JS does not submit term_rows (or admin only updates name), reuse current rows.
     */
    private function mergeTermRowsFallbackForUpdate(Request $request, Plan $plan): void
    {
        if (is_array($request->input('term_rows')) && count((array) $request->input('term_rows')) > 0) {
            return;
        }

        $rows = $this->initialTermRowsForForm($plan, true);
        if ($rows !== []) {
            $request->merge(['term_rows' => $rows]);
        }

        if (! $request->filled('duration_preset')) {
            $request->merge(['duration_preset' => $this->durationPresetForAdminForm($plan)]);
        }
    }

    private function normalizeMarketingBadgeRequest(Request $request): void
    {
        $raw = $request->input('marketing_badge');
        if (! is_string($raw) || $raw === '') {
            return;
        }
        if (in_array($raw, self::ADMIN_MARKETING_BADGE_KEYS, true)) {
            return;
        }
        $legacy = [
            'Best Seller' => 'best_seller',
            'Popular' => 'popular',
            'New' => 'new',
            'Limited offer' => 'limited_offer',
            'Limited Offer' => 'limited_offer',
            'Recommended' => 'recommended',
        ];
        if (isset($legacy[$raw])) {
            $request->merge(['marketing_badge' => $legacy[$raw]]);
        }
    }

    private function resolveDurationPresetFromPlan(Plan $plan): string
    {
        $days = (int) ($plan->duration_days ?? 0);
        foreach (PlanTerm::presetBillingKeys() as $k) {
            if (PlanTerm::durationDaysFor($k) === $days) {
                return $k;
            }
        }

        return PlanTerm::BILLING_MONTHLY;
    }

    /**
     * Initial value for the admin “Duration” dropdown (subset of billing keys).
     */
    private function durationPresetForAdminForm(Plan $plan): string
    {
        $resolved = $this->resolveDurationPresetFromPlan($plan);

        return in_array($resolved, self::ADMIN_PLAN_DURATION_PRESET_KEYS, true)
            ? $resolved
            : PlanTerm::BILLING_MONTHLY;
    }

    /**
     * @return list<array{billing_key: string, price: float, discount_percent: int|null, is_visible: bool}>
     */
    private function initialTermRowsForForm(Plan $plan, bool $isEdit): array
    {
        if (Plan::isFreeCatalogSlug((string) $plan->slug)) {
            return [];
        }

        if ($isEdit && $plan->exists) {
            $plan->loadMissing('terms');
            if ($plan->terms->isNotEmpty()) {
                return $plan->terms->sortBy('sort_order')->values()->map(fn (PlanTerm $t) => [
                    'billing_key' => $t->billing_key,
                    'price' => (float) $t->price,
                    'discount_percent' => $t->discount_percent,
                    'is_visible' => (bool) $t->is_visible,
                ])->all();
            }
        }

        $p = (float) ($plan->price ?? 0);
        $d = $plan->discount_percent;

        return [[
            'billing_key' => PlanTerm::BILLING_MONTHLY,
            'price' => $p,
            'discount_percent' => $d !== null ? (int) $d : null,
            'is_visible' => true,
        ]];
    }

    /**
     * Plan name for the admin form + Alpine: use flashed old input only after a validation redirect,
     * otherwise the persisted name. (Stale {@code _old_input} with an empty {@code name} would otherwise
     * hide the real title on edit — {@see old()} returns that empty string instead of the default.)
     */
    private function planNameInputFromSession(?Plan $plan = null): string
    {
        if (session()->has('errors')) {
            $old = session()->get('_old_input', []);
            if (is_array($old) && array_key_exists('name', $old)) {
                $oldName = (string) $old['name'];
                if (trim($oldName) !== '') {
                    return $oldName;
                }
            }
        }

        $fromDb = $this->displayPlanNameWithoutGenderSuffix((string) ($plan?->name ?? ''));
        if (trim($fromDb) !== '') {
            return $fromDb;
        }

        return $this->inferPlanNameFromSlug((string) ($plan?->slug ?? ''));
    }

    private function displayPlanNameWithoutGenderSuffix(string $name): string
    {
        return preg_replace('/\s*\((male|female)\)\s*$/i', '', $name) ?? $name;
    }

    private function inferPlanNameFromSlug(string $slug): string
    {
        $raw = strtolower(trim($slug));
        if ($raw === '') {
            return '';
        }

        $base = preg_replace('/(?:[_-])(male|female|all)$/i', '', $raw) ?? $raw;
        $base = str_replace(['_', '-'], ' ', $base);
        $base = preg_replace('/\s+/', ' ', $base) ?? $base;
        $base = trim($base);
        if ($base === '') {
            return '';
        }

        return Str::of($base)->title()->toString();
    }
}
