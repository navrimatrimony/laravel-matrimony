<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\PlanQuotaPolicy;
use App\Models\PlanTerm;
use App\Models\Subscription;
use App\Services\PlanSubscriptionTerms;
use App\Support\PlanFeatureKeys;
use App\Support\PlanQuotaPolicyKeys;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    /** Plan-wide duration preset: product periods only (1 / 3 / 6 / 12 months). */
    public const ADMIN_PLAN_DURATION_PRESET_KEYS = [
        PlanTerm::BILLING_MONTHLY,
        PlanTerm::BILLING_QUARTERLY,
        PlanTerm::BILLING_HALF_YEARLY,
        PlanTerm::BILLING_YEARLY,
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
            'defaultBillingKeyInitial' => $this->durationPresetForAdminForm($plan),
            'termRowsInitial' => $this->termRowsForAdminBillingForm($plan, false),
            'adminMarketingBadgeKeys' => self::ADMIN_MARKETING_BADGE_KEYS,
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

            $plan = DB::transaction(function () use ($data, $request) {
                $plan = Plan::query()->create($data);
                PlanQuotaPolicy::ensureAllKeysForPlan($plan);
                $this->syncQuotaPoliciesFromRequest($plan, $request);
                $this->syncFeatures($plan, $this->buildFullPlanFeatureRowsFromQuota($plan, $request));
                $plan->forgetCachedPlanFeatures();

                return $plan;
            });

            return redirect()
                ->route('admin.plans.edit', $plan)
                ->with('success', __('subscriptions.plan_saved'));
        }

        $this->mergeDurationPresetFromDefaultBillingKey($request);
        $this->mergePaidPlanPricingFromTermRowsForRequest($request, null);
        $this->validateTermRowsRequest($request);
        $rows = $this->normalizedTermRowsFromRequest($request);
        if ($rows === []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'term_rows' => [__('subscriptions.admin_term_rows_required')],
            ]);
        }

        $data = $this->validatedPlanData($request);

        $plan = DB::transaction(function () use ($data, $request, $rows) {
            $plan = Plan::query()->create($data);
            PlanQuotaPolicy::ensureAllKeysForPlan($plan);
            $this->syncQuotaPoliciesFromRequest($plan, $request);
            $this->syncFeatures($plan, $this->buildFullPlanFeatureRowsFromQuota($plan, $request));
            $plan->forgetCachedPlanFeatures();

            PlanTerm::syncAdminTermRows($plan, $rows);
            $this->persistPlanDefaultBillingKeyFromRequest($plan->fresh('terms'), $request);

            return $plan;
        });

        return redirect()
            ->route('admin.plans.edit', $plan)
            ->with('success', __('subscriptions.plan_saved'));
    }

    /**
     * Plan {@code duration_days} logic still reads {@code duration_preset}; mirror {@code default_billing_key}.
     */
    private function mergeDurationPresetFromDefaultBillingKey(Request $request): void
    {
        $dbk = trim((string) $request->input('default_billing_key', ''));
        if ($dbk !== '') {
            $request->merge(['duration_preset' => $dbk]);
        }
    }

    /**
     * Paid-plan form posts MRP + selling per {@see PlanTerm} row; validation still expects plan-level
     * {@code price} / {@code selling_price}. Mirror the selected catalog tab row before validation.
     */
    private function mergePaidPlanPricingFromTermRowsForRequest(Request $request, ?Plan $plan): void
    {
        $slug = $plan !== null
            ? (string) $plan->slug
            : strtolower(trim((string) $request->input('slug', '')));
        if (Plan::isFreeCatalogSlug($slug)) {
            return;
        }

        $rows = $this->normalizedTermRowsFromRequest($request);
        if ($rows === []) {
            return;
        }

        $byKey = [];
        foreach ($rows as $row) {
            $key = (string) ($row['billing_key'] ?? '');
            if ($key !== '') {
                $byKey[$key] = $row;
            }
        }

        $selectedKey = (string) $this->resolvedRequestedBillingKey($request);
        $selectedRow = $selectedKey !== '' ? ($byKey[$selectedKey] ?? null) : null;
        if (! is_array($selectedRow)) {
            $selectedRow = $rows[0];
        }

        $price = \App\Support\PlanPricing::normalizeMoney($selectedRow['price'] ?? 0);
        $selling = \App\Support\PlanPricing::normalizeMoney($selectedRow['selling_price'] ?? $price);

        $request->merge([
            'price' => $price,
            'selling_price' => $selling,
            'discount_percent' => \App\Support\PlanPricing::deprecatedDiscountColumnValue($price, $selling),
        ]);
    }

    public function edit(Plan $plan)
    {
        PlanQuotaPolicy::ensureAllKeysForPlan($plan);
        $plan->load(['features', 'terms', 'quotaPolicies']);

        return view('admin.plans.form', [
            'plan' => $plan,
            'isEdit' => true,
            'quotaPolicyKeys' => PlanQuotaPolicyKeys::ordered(),
            'quotaPoliciesForm' => $this->quotaPoliciesFormState($plan, true),
            'defaultBillingKeyInitial' => $this->durationPresetForAdminForm($plan),
            'termRowsInitial' => $this->termRowsForAdminBillingForm($plan, true),
            'adminMarketingBadgeKeys' => self::ADMIN_MARKETING_BADGE_KEYS,
            'planNameInput' => $this->planNameInputFromSession($plan),
            'initialPlanNameSha10' => substr(hash('sha256', $this->planNameInputFromSession($plan)), 0, 10),
        ]);
    }

    public function update(Request $request, Plan $plan)
    {
        $this->validateQuotaPoliciesRequest($request);
        $rows = [];
        $shouldSyncTermRows = false;
        if (! Plan::isFreeCatalogSlug((string) $plan->slug)) {
            $this->mergeDurationPresetFromDefaultBillingKey($request);
            $this->mergePaidPlanPricingFromTermRowsForRequest($request, $plan);
            $this->validateTermRowsRequest($request);
            $rows = $this->normalizedTermRowsFromRequest($request);
            $shouldSyncTermRows = ! $this->matchesPersistedTermRows($plan, $rows);
        }
        $data = $this->validatedPlanData($request, $plan->id, $plan);

        DB::transaction(function () use ($request, $plan, $data, $rows, $shouldSyncTermRows) {
            $plan->update($data);

            if (! Plan::isFreeCatalogSlug((string) ($data['slug'] ?? ''))) {
                if ($shouldSyncTermRows) {
                    PlanTerm::syncAdminTermRows($plan, $rows);
                }
                $this->persistPlanDefaultBillingKeyFromRequest($plan->fresh('terms'), $request);
            } else {
                PlanTerm::query()->where('plan_id', $plan->id)->delete();
            }

            PlanQuotaPolicy::ensureAllKeysForPlan($plan);
            $this->syncQuotaPoliciesFromRequest($plan, $request);
            $this->syncFeatures($plan, $this->buildFullPlanFeatureRowsFromQuota($plan, $request));
            $plan->forgetCachedPlanFeatures();
        });

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
            'term_rows' => ['required', 'array', 'min:1'],
            'term_rows.*.billing_key' => ['required', 'string', Rule::in(PlanTerm::productBillingKeys())],
            'term_rows.*.price' => ['required', 'numeric', 'integer', 'gt:0'],
            'term_rows.*.selling_price' => ['required', 'numeric', 'integer', 'min:0'],
            'term_rows.*.quota_bonus_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'term_rows.*.is_visible' => ['nullable'],
            'default_billing_key' => ['required', 'string', Rule::in(PlanTerm::productBillingKeys())],
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

        $errors = [];
        foreach ((array) $request->input('term_rows', []) as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $mrp = \App\Support\PlanPricing::normalizeMoney($row['price'] ?? 0);
            $selling = \App\Support\PlanPricing::normalizeMoney($row['selling_price'] ?? 0);
            if ($selling > $mrp) {
                $errors["term_rows.$i.selling_price"] = [__('subscriptions.admin_selling_must_not_exceed_mrp')];
            }
        }
        if ($errors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }

        $defaultKey = trim((string) $request->input('default_billing_key', ''));
        $targetRows = collect((array) $request->input('term_rows', []))
            ->filter(fn ($row) => is_array($row) && (string) ($row['billing_key'] ?? '') === $defaultKey);
        if ($targetRows->isEmpty()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'default_billing_key' => [__('subscriptions.admin_default_billing_must_match_row')],
            ]);
        }

        $selectedRow = $targetRows->first();
        $selectedVisible = filter_var($selectedRow['is_visible'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || (string) ($selectedRow['is_visible'] ?? '') === '1';
        if (! $selectedVisible) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'default_billing_key' => [__('subscriptions.admin_default_billing_must_be_visible')],
            ]);
        }
    }

    /**
     * @return list<array{billing_key: string, price: float, selling_price: float, quota_bonus_percent: int, is_visible: bool}>
     */
    private function normalizedTermRowsFromRequest(Request $request): array
    {
        $out = [];
        foreach ((array) $request->input('term_rows', []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = (string) ($row['billing_key'] ?? '');
            if ($key === '' || ! in_array($key, PlanTerm::productBillingKeys(), true)) {
                continue;
            }
            $price = \App\Support\PlanPricing::normalizeMoney($row['price'] ?? 0);
            $selling = array_key_exists('selling_price', $row)
                ? \App\Support\PlanPricing::normalizeMoney($row['selling_price'])
                : $price;
            $rawQuotaBonus = $row['quota_bonus_percent'] ?? null;
            $quotaBonus = ($rawQuotaBonus === '' || $rawQuotaBonus === null)
                ? PlanTerm::defaultQuotaBonusPercentFor($key)
                : max(0, min(100, (int) round((float) $rawQuotaBonus)));
            $visible = filter_var($row['is_visible'] ?? true, FILTER_VALIDATE_BOOLEAN)
                || (string) ($row['is_visible'] ?? '') === '1';

            $out[] = [
                'billing_key' => $key,
                'price' => $price,
                'selling_price' => $selling,
                'quota_bonus_percent' => $quotaBonus,
                'is_visible' => $visible,
            ];
        }

        return $out;
    }

    /**
     * True when a sync would be a no-op: each submitted row already matches DB,
     * and every DB term omitted from the form is already hidden (Phase 1 upsert).
     * Hidden legacy keys (five_yearly / lifetime) must not force a false mismatch.
     */
    private function matchesPersistedTermRows(Plan $plan, array $rows): bool
    {
        $plan->loadMissing('terms');
        $requested = collect($rows)
            ->mapWithKeys(fn (array $row) => [
                (string) ($row['billing_key'] ?? '') => [
                    'price' => round((float) ($row['price'] ?? 0), 2),
                    'selling_price' => round((float) ($row['selling_price'] ?? $row['price'] ?? 0), 2),
                    'quota_bonus_percent' => (int) ($row['quota_bonus_percent'] ?? PlanTerm::defaultQuotaBonusPercentFor((string) ($row['billing_key'] ?? ''))),
                    'is_visible' => (bool) ($row['is_visible'] ?? false),
                ],
            ])
            ->filter(fn ($_, $key) => $key !== '')
            ->all();

        foreach ($requested as $key => $attrs) {
            $term = $plan->terms->firstWhere('billing_key', $key);
            if ($term === null) {
                return false;
            }
            $persisted = [
                'price' => round((float) $term->price, 2),
                'selling_price' => round((float) $term->final_price, 2),
                'quota_bonus_percent' => (int) ($term->quota_bonus_percent ?? 0),
                'is_visible' => (bool) $term->is_visible,
            ];
            if ($persisted !== $attrs) {
                return false;
            }
        }

        foreach ($plan->terms as $term) {
            $key = (string) $term->billing_key;
            if (! isset($requested[$key]) && (bool) $term->is_visible) {
                return false;
            }
        }

        return true;
    }

    /**
     * Persist catalog default tab from the validated request only (already matched to a visible row).
     */
    private function persistPlanDefaultBillingKeyFromRequest(Plan $plan, Request $request): void
    {
        $plan->loadMissing('terms');
        $key = trim((string) $request->input('default_billing_key', ''));
        $keys = $plan->terms->pluck('billing_key')->map(fn ($k) => (string) $k)->all();
        if ($key !== '' && in_array($key, $keys, true)) {
            $plan->update(['default_billing_key' => $key]);

            return;
        }

        $plan->update(['default_billing_key' => null]);
    }

    /**
     * Default catalog billing: prefers {@code default_billing_key}, then {@code duration_preset}
     * (both set from the billing-rows UI). Must match a persisted term row after save.
     */
    private function resolvedRequestedBillingKey(Request $request): ?string
    {
        $explicit = trim((string) $request->input('default_billing_key', ''));
        if ($explicit !== '') {
            return in_array($explicit, PlanTerm::productBillingKeys(), true) ? $explicit : null;
        }

        $durationPreset = trim((string) $request->input('duration_preset', ''));
        if ($durationPreset !== '' && in_array($durationPreset, PlanTerm::productBillingKeys(), true)) {
            return $durationPreset;
        }

        return null;
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

        if ($request->input('selling_price') === '' || $request->input('selling_price') === null) {
            if ($request->filled('price')) {
                $request->merge(['selling_price' => $request->input('price')]);
            }
        }

        $isFreeSystemPlan = ($planContext !== null && Plan::isFreeCatalogSlug((string) $planContext->slug))
            || Plan::isFreeCatalogSlug((string) $request->input('slug', ''));

        if ($isFreeSystemPlan && ! $request->filled('price')) {
            $request->merge(['price' => $planContext !== null ? (float) ($planContext->price ?? 0) : 0.0]);
        }
        if ($isFreeSystemPlan && ! $request->filled('selling_price')) {
            $request->merge([
                'selling_price' => $planContext !== null
                    ? (float) ($planContext->selling_price ?? $planContext->price ?? 0)
                    : (float) $request->input('price', 0),
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'duration_days' => [$isFreeSystemPlan ? 'required' : 'nullable', 'integer', 'min:0'],
            'grace_period_days' => ['required', 'integer', Rule::in(self::ADMIN_GRACE_PERIOD_DAY_OPTIONS)],
            'leftover_quota_carry_window_days' => ['nullable', 'integer', Rule::in(self::ADMIN_LEFTOVER_CARRY_DAY_OPTIONS)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
            'default_billing_key' => ['nullable', 'string', 'max:64'],
            'applies_to_gender' => ['required', 'string', Rule::in(['male', 'female', 'all'])],
            'marketing_badge' => ['nullable', 'string', 'max:80', Rule::in(array_merge([''], self::ADMIN_MARKETING_BADGE_KEYS))],
            'gst_inclusive' => ['sometimes'],
            'chat_initiate_new_chats_only' => ['sometimes', 'boolean'],
        ]);

        $canonicalPaidPricing = null;
        if (! $isFreeSystemPlan) {
            $canonicalPaidPricing = $this->canonicalPaidPricingFromRequest($request, $planContext);
            if ($canonicalPaidPricing !== null
                && $canonicalPaidPricing['selling_price'] > $canonicalPaidPricing['price']) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'selling_price' => [__('subscriptions.admin_selling_must_not_exceed_mrp')],
                ]);
            }
        }

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
            if (in_array($preset, PlanTerm::productBillingKeys(), true)) {
                $durationDays = PlanTerm::durationDaysFor($preset);
            }
        }

        $durationQuantity = null;
        $durationUnit = null;

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
            'price' => $canonicalPaidPricing['price'] ?? $validated['price'],
            'selling_price' => $canonicalPaidPricing['selling_price']
                ?? (isset($validated['selling_price'])
                    ? (float) $validated['selling_price']
                    : (float) ($validated['price'] ?? 0)),
            'discount_percent' => $canonicalPaidPricing['discount_percent'] ?? ($validated['discount_percent'] ?? null),
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
     * Paid-plan monetary SSOT comes from selected duration billing row in term_rows.
     *
     * @return array{price:float, selling_price:float, discount_percent:int|null}|null
     */
    private function canonicalPaidPricingFromRequest(Request $request, ?Plan $planContext): ?array
    {
        $rows = $this->normalizedTermRowsFromRequest($request);
        if ($rows === []) {
            return null;
        }

        $byKey = [];
        foreach ($rows as $row) {
            $key = (string) ($row['billing_key'] ?? '');
            if ($key !== '') {
                $byKey[$key] = $row;
            }
        }

        $selectedKey = (string) $this->resolvedRequestedBillingKey($request);
        $selectedRow = $selectedKey !== '' ? ($byKey[$selectedKey] ?? null) : null;
        if (! is_array($selectedRow)) {
            $selectedRow = $rows[0];
        }

        $price = \App\Support\PlanPricing::normalizeMoney($selectedRow['price'] ?? 0);
        $selling = \App\Support\PlanPricing::normalizeMoney($selectedRow['selling_price'] ?? $price);

        return [
            'price' => $price,
            'selling_price' => $selling,
            'discount_percent' => \App\Support\PlanPricing::deprecatedDiscountColumnValue($price, $selling),
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
        $this->normalizeQuotaPoliciesNumericInputsForValidation($request);

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
            $rules["quota_policies.$fk.limit_value"] = ['nullable', 'numeric', 'min:0'];
            $rules["quota_policies.$fk.daily_sub_cap"] = ['nullable', 'numeric', 'min:0'];
            $rules["quota_policies.$fk.per_day_usage_limit_enabled"] = ['sometimes', 'boolean'];
            $rules["quota_policies.$fk.purchasable_if_exhausted"] = ['sometimes', 'boolean'];
            $rules["quota_policies.$fk.pack_price_rupees"] = ['nullable', 'numeric', 'min:0'];
            $rules["quota_policies.$fk.pack_message_count"] = ['nullable', 'numeric', 'min:1'];
            $rules["quota_policies.$fk.pack_validity_days"] = ['nullable', 'numeric', 'min:1'];
        }
        $request->validate($rules);
    }

    /**
     * Coerce quota policy numeric fields before validation so admin never fails on harmless formatting ("010", whitespace).
     *
     * @see syncQuotaPoliciesFromRequest() which casts to int again when persisting.
     */
    private function normalizeQuotaPoliciesNumericInputsForValidation(Request $request): void
    {
        $qp = $request->input('quota_policies');
        if (! is_array($qp)) {
            return;
        }

        foreach ($qp as $featureKey => $payload) {
            if (! is_array($payload)) {
                continue;
            }
            $fk = (string) $featureKey;
            foreach (['limit_value', 'daily_sub_cap'] as $field) {
                if (! array_key_exists($field, $payload)) {
                    continue;
                }
                $qp[$featureKey][$field] = $this->normalizedQuotaNumericInputString(
                    $payload[$field],
                    $field,
                    $fk,
                    allowZero: true,
                    minWhenPresent: 0,
                );
            }
            foreach (['pack_message_count', 'pack_validity_days'] as $field) {
                if (! array_key_exists($field, $payload)) {
                    continue;
                }
                $qp[$featureKey][$field] = $this->normalizedQuotaNumericInputString(
                    $payload[$field],
                    $field,
                    $fk,
                    allowZero: false,
                    minWhenPresent: 1,
                );
            }
        }

        $request->merge(['quota_policies' => $qp]);
    }

    /**
     * @return string Normalized for HTML form: '' = absent / use defaults downstream; digits only when numeric.
     */
    private function normalizedQuotaNumericInputString(
        mixed $raw,
        string $field,
        string $featureKey,
        bool $allowZero,
        int $minWhenPresent,
    ): string {
        if ($raw === null || $raw === '') {
            return '';
        }
        $original = $raw;
        $s = trim(preg_replace('/\s+/', '', (string) $raw));
        if ($s === '') {
            return '';
        }
        if (! is_numeric($s)) {
            Log::warning('Invalid quota limit normalized', [
                'feature_key' => $featureKey,
                'field' => $field,
                'raw' => $original,
                'reason' => 'non_numeric',
            ]);

            return '';
        }
        if (is_string($original) && preg_match('/^0+\d+$/', $original)) {
            Log::warning('Invalid quota limit normalized', [
                'feature_key' => $featureKey,
                'field' => $field,
                'raw' => $original,
                'reason' => 'leading_zeros',
            ]);
        }
        $n = (int) round((float) $s);
        if ($n < 0) {
            Log::warning('Invalid quota limit normalized', [
                'feature_key' => $featureKey,
                'field' => $field,
                'raw' => $original,
                'reason' => 'negative',
            ]);

            return $allowZero && $minWhenPresent === 0 ? '0' : '';
        }
        if ($n === 0 && ! $allowZero) {
            return '';
        }
        if ($n > 0 && $n < $minWhenPresent) {
            Log::warning('Invalid quota limit normalized', [
                'feature_key' => $featureKey,
                'field' => $field,
                'raw' => $original,
                'reason' => 'below_minimum',
                'min' => $minWhenPresent,
            ]);

            return '';
        }

        return (string) $n;
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
                $base = $row ? $row->toArray() : PlanQuotaPolicy::defaultsForNewPlan($featureKey);
            } else {
                $base = PlanQuotaPolicy::defaultsForNewPlan($featureKey);
            }
            $old = old('quota_policies.'.$featureKey);
            if (is_array($old)) {
                $base = $this->mergeQuotaPolicyOldIntoBase($base, $old);
            } elseif (! $isEdit && array_key_exists('refresh_type', $base)) {
                // On create-plan screen, start quota refresh with lifetime by default.
                $base['refresh_type'] = PlanQuotaPolicy::REFRESH_LIFETIME;
            }
            $base['refresh_type'] = PlanQuotaPolicy::normalizeRefreshType((string) ($base['refresh_type'] ?? PlanQuotaPolicy::REFRESH_LIFETIME));
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
        $plan->loadMissing('quotaPolicies');
        foreach (PlanQuotaPolicyKeys::ordered() as $featureKey) {
            $prefix = 'quota_policies.'.$featureKey;
            if (! is_array($request->input($prefix))) {
                continue;
            }
            $refresh = PlanQuotaPolicy::normalizeRefreshType((string) $request->input("$prefix.refresh_type"));
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
            if ($featureKey === PlanFeatureKeys::CHAT_SEND_LIMIT) {
                $existingChat = $plan->quotaPolicies->firstWhere('feature_key', $featureKey);
                $pm = is_array($existingChat?->policy_meta) ? $existingChat->policy_meta : [];
                $pm['chat_initiate_new_chats_only'] = $request->boolean('chat_initiate_new_chats_only');
                $meta = $pm;
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
        $plan->forgetCachedPlanFeatures();
        $plan->load('features');
        $quotaWritten = array_flip(PlanQuotaPolicyKeys::planFeatureKeysWrittenByPolicies());
        $out = [];
        foreach (array_keys((array) config('plan_features', [])) as $key) {
            if (isset($quotaWritten[$key])) {
                continue;
            }
            $existing = $plan->getFeatureValue($key);
            if ($existing !== null && $existing !== '') {
                $out[] = ['key' => $key, 'value' => (string) $existing];
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
        // Admin duration must reflect the plan period users actually buy in catalog.
        // Prefer persisted billing intent over legacy duration_days fallback.
        $plan->loadMissing('terms');

        $productKeys = PlanTerm::productBillingKeys();
        $defaultBilling = strtolower(trim((string) ($plan->default_billing_key ?? '')));
        if ($defaultBilling !== '' && in_array($defaultBilling, $productKeys, true)) {
            return $defaultBilling;
        }

        $visibleTerm = $plan->terms
            ->where('is_visible', true)
            ->filter(fn (PlanTerm $t) => in_array((string) $t->billing_key, $productKeys, true))
            ->sortBy('sort_order')
            ->first();
        if ($visibleTerm) {
            return (string) $visibleTerm->billing_key;
        }

        $firstTerm = $plan->terms
            ->filter(fn (PlanTerm $t) => in_array((string) $t->billing_key, $productKeys, true))
            ->sortBy('sort_order')
            ->first();
        if ($firstTerm) {
            return (string) $firstTerm->billing_key;
        }

        return PlanTerm::BILLING_MONTHLY;
    }

    /**
     * Initial default billing key for the admin billing-rows UI (subset of billing keys).
     */
    private function durationPresetForAdminForm(Plan $plan): string
    {
        $resolved = $this->resolveDurationPresetFromPlan($plan);

        return in_array($resolved, self::ADMIN_PLAN_DURATION_PRESET_KEYS, true)
            ? $resolved
            : PlanTerm::BILLING_MONTHLY;
    }

    /**
     * Billing rows for admin form: edit loads only persisted {@see Plan::$terms}; create uses one starter row.
     *
     * @return list<array{billing_key: string, price: float, selling_price: float, quota_bonus_percent: int, is_visible: bool}>
     */
    private function termRowsForAdminBillingForm(Plan $plan, bool $isEdit): array
    {
        if (Plan::isFreeCatalogSlug((string) $plan->slug)) {
            return [];
        }

        if ($isEdit && $plan->exists) {
            $plan->loadMissing('terms');
            $productKeys = array_flip(PlanTerm::productBillingKeys());

            // Only visible product periods (1/3/6/12). Hidden legacy five_yearly / lifetime
            // must not reappear in the form and get re-posted on save.
            $rows = $plan->terms
                ->filter(fn (PlanTerm $t) => (bool) $t->is_visible && isset($productKeys[(string) $t->billing_key]))
                ->sortBy('sort_order')
                ->values()
                ->map(fn (PlanTerm $t) => [
                    'billing_key' => $t->billing_key,
                    'price' => (float) $t->price,
                    'selling_price' => (float) $t->final_price,
                    'quota_bonus_percent' => (int) ($t->quota_bonus_percent ?? 0),
                    'is_visible' => true,
                ])->all();

            if ($rows !== []) {
                return $rows;
            }
        }

        $p = (float) ($plan->price ?? 0);
        $s = (float) ($plan->selling_price ?? $plan->final_price ?? $p);

        return [[
            'billing_key' => PlanTerm::BILLING_MONTHLY,
            'price' => $p,
            'selling_price' => $s,
            'quota_bonus_percent' => PlanTerm::defaultQuotaBonusPercentFor(PlanTerm::BILLING_MONTHLY),
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
