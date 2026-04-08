@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6 max-w-4xl">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6">
        {{ $isEdit ? __('subscriptions.edit_plan') : __('subscriptions.create_plan') }}
    </h1>

    @if ($errors->any())
        <ul class="text-red-600 text-sm mb-4 space-y-1">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    @endif

    @php
        $structuredKVHiddenKeys = [
            \App\Support\PlanFeatureKeys::REFERRAL_BONUS_DAYS,
            \App\Support\PlanFeatureKeys::PRIORITY_LISTING,
            \App\Support\PlanFeatureKeys::PROFILE_BOOST_PER_WEEK,
        ];
        $featureRowsForForm = [];
        $shouldFilterStructuredFromKv = function (array $row) use ($structuredKVHiddenKeys): bool {
            return in_array($row['key'] ?? '', $structuredKVHiddenKeys, true);
        };
        if (is_array(old('features'))) {
            foreach (old('features') as $r) {
                if (! is_array($r)) {
                    continue;
                }
                $row = [
                    'key' => (string) ($r['key'] ?? ''),
                    'value' => (string) ($r['value'] ?? ''),
                ];
                if ($shouldFilterStructuredFromKv($row)) {
                    continue;
                }
                $featureRowsForForm[] = $row;
            }
        }
        if ($featureRowsForForm === [] && $isEdit) {
            $plan->loadMissing('features');
            foreach ($plan->features->sortBy('key')->values() as $f) {
                $row = ['key' => $f->key, 'value' => (string) $f->value];
                if ($shouldFilterStructuredFromKv($row)) {
                    continue;
                }
                $featureRowsForForm[] = $row;
            }
        }
        if ($featureRowsForForm === [] && ! $isEdit) {
            foreach ($defaultFeatures as $row) {
                $r = [
                    'key' => (string) ($row['key'] ?? ''),
                    'value' => (string) ($row['value'] ?? ''),
                ];
                if ($shouldFilterStructuredFromKv($r)) {
                    continue;
                }
                $featureRowsForForm[] = $r;
            }
        }
        if ($featureRowsForForm === []) {
            $featureRowsForForm[] = ['key' => '', 'value' => ''];
        }
        $monetizationDefaultsFromTemplate = [];
        foreach ($defaultFeatures as $row) {
            $k = (string) ($row['key'] ?? '');
            if ($k !== '') {
                $monetizationDefaultsFromTemplate[$k] = (string) ($row['value'] ?? '');
            }
        }
    @endphp

    <form method="POST" action="{{ $isEdit ? route('admin.plans.update', $plan) : route('admin.plans.store') }}" class="space-y-6">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name</label>
                <input type="text" name="name" value="{{ old('name', $plan->name) }}" required maxlength="120"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Slug</label>
                <input type="text" name="slug" value="{{ old('slug', $plan->slug) }}" required maxlength="64" pattern="[a-z0-9]+(?:-[a-z0-9]+)*"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Price (₹)</label>
                <input type="number" name="price" value="{{ old('price', $plan->price ?? '') }}" required min="0" step="0.01"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Discount %</label>
                <input type="number" name="discount_percent" value="{{ old('discount_percent', $plan->discount_percent ?? '') }}" min="0" max="100" step="1"
                    placeholder="None"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Duration (days, 0 = no expiry)</label>
                <input type="number" name="duration_days" value="{{ old('duration_days', $plan->duration_days) }}" required min="0"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Sort order</label>
                <input type="number" name="sort_order" value="{{ old('sort_order', $plan->sort_order) }}" min="0"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm" />
            </div>
        </div>

        <div class="flex flex-wrap gap-6">
            <label class="inline-flex items-center gap-2 cursor-pointer">
                <input type="hidden" name="is_active" value="0" />
                <input type="checkbox" name="is_active" value="1" class="rounded border-gray-300" @checked((string) old('is_active', $plan->is_active ? '1' : '0') === '1') />
                <span class="text-sm text-gray-700 dark:text-gray-300">Active (visible for new subscriptions)</span>
            </label>
            <label class="inline-flex items-center gap-2 cursor-pointer">
                <input type="hidden" name="highlight" value="0" />
                <input type="checkbox" name="highlight" value="1" class="rounded border-gray-300" @checked((string) old('highlight', $plan->highlight ? '1' : '0') === '1') />
                <span class="text-sm text-gray-700 dark:text-gray-300">{{ __('subscriptions.highlight_plan') }}</span>
            </label>
        </div>

        @if ($isEdit && strtolower((string) $plan->slug) !== 'free')
            <div class="rounded-lg border border-indigo-200 dark:border-indigo-900 bg-indigo-50/50 dark:bg-indigo-950/20 p-4 space-y-4">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ __('subscriptions.admin_billing_periods_title') }}</h2>
                <p class="text-xs text-gray-600 dark:text-gray-400">{{ __('subscriptions.admin_billing_periods_intro') }}</p>
                <div class="space-y-4">
                    @foreach (\App\Models\PlanTerm::billingKeys() as $billingKey)
                        @php
                            $termRow = $plan->terms->firstWhere('billing_key', $billingKey);
                        @endphp
                        <div class="rounded-md border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 p-3 grid grid-cols-1 sm:grid-cols-12 gap-3 items-end">
                            <div class="sm:col-span-3">
                                <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('subscriptions.billing_'.$billingKey) }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-500">{{ __('subscriptions.duration_days', ['count' => \App\Models\PlanTerm::durationDaysFor($billingKey)]) }}</span>
                            </div>
                            <div class="sm:col-span-3">
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">Price (₹)</label>
                                <input type="number" name="terms[{{ $billingKey }}][price]" min="0" step="0.01" required
                                    value="{{ old('terms.'.$billingKey.'.price', $termRow?->price ?? '0') }}"
                                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                            </div>
                            <div class="sm:col-span-3">
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">Discount %</label>
                                <input type="number" name="terms[{{ $billingKey }}][discount_percent]" min="0" max="100" step="1"
                                    value="{{ old('terms.'.$billingKey.'.discount_percent', $termRow?->discount_percent ?? '') }}"
                                    placeholder="—"
                                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                            </div>
                            <div class="sm:col-span-3 flex items-center">
                                <input type="hidden" name="terms[{{ $billingKey }}][is_visible]" value="0" />
                                <label class="inline-flex items-center gap-2 cursor-pointer text-sm text-gray-700 dark:text-gray-300">
                                    <input type="checkbox" name="terms[{{ $billingKey }}][is_visible]" value="1" class="rounded border-gray-300"
                                        @checked((string) old('terms.'.$billingKey.'.is_visible', $termRow?->is_visible ? '1' : '0') === '1') />
                                    <span>{{ __('subscriptions.admin_billing_show_public') }}</span>
                                </label>
                            </div>
                        </div>
                    @endforeach
                </div>

                @php
                    $advancedDurations = [
                        'monthly' => 30,
                        'quarterly' => 90,
                        'half_yearly' => 180,
                        'yearly' => 365,
                    ];
                @endphp
                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100 mt-6 pt-4 border-t border-indigo-100 dark:border-indigo-900">Pricing (Advanced)</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">Same duration keys as billing above; when price or discount is filled here, it overrides that row on save.</p>
                <div class="overflow-x-auto mt-2">
                    <table class="w-full text-sm border border-gray-200 dark:border-gray-600 rounded-md">
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr>
                                <th class="text-left p-2 font-medium text-gray-700 dark:text-gray-300">Duration</th>
                                <th class="text-left p-2 font-medium text-gray-700 dark:text-gray-300">Days</th>
                                <th class="text-left p-2 font-medium text-gray-700 dark:text-gray-300">Price (₹)</th>
                                <th class="text-left p-2 font-medium text-gray-700 dark:text-gray-300">Discount %</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($advancedDurations as $type => $days)
                                @php
                                    $ppRow = $plan->planPrices->firstWhere('duration_type', $type);
                                    $termForType = $plan->terms->firstWhere('billing_key', $type);
                                    $advPrice = old('prices.'.$type.'.price', $ppRow?->price ?? $termForType?->price ?? '');
                                    $advDisc = old('prices.'.$type.'.discount', $ppRow?->discount_percent ?? $termForType?->discount_percent ?? '');
                                @endphp
                                <tr class="border-t border-gray-200 dark:border-gray-600">
                                    <td class="p-2 text-gray-800 dark:text-gray-200">{{ ucfirst(str_replace('_', ' ', $type)) }}</td>
                                    <td class="p-2 text-gray-600 dark:text-gray-400">{{ $days }}</td>
                                    <td class="p-2">
                                        <input type="number" name="prices[{{ $type }}][price]" min="0" step="0.01" value="{{ $advPrice }}"
                                            class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                                    </td>
                                    <td class="p-2">
                                        <input type="number" name="prices[{{ $type }}][discount]" min="0" max="100" step="1" value="{{ $advDisc }}"
                                            placeholder="—"
                                            class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @elseif (! $isEdit)
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('subscriptions.admin_billing_after_create') }}</p>
        @endif

        <div>
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-1">{{ __('admin_commerce.features_manage_title') }}</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('subscriptions.feature_key_hint') }}</p>
            <div id="plan-feature-rows" class="space-y-2" data-next-index="{{ count($featureRowsForForm) }}">
                @foreach ($featureRowsForForm as $idx => $row)
                    <div class="plan-feature-row flex flex-wrap gap-2 items-center rounded-md border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 p-2" data-row>
                        <input type="text" name="features[{{ $idx }}][key]" value="{{ $row['key'] }}" placeholder="key"
                            class="flex-1 min-w-[12rem] rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                        <input type="text" name="features[{{ $idx }}][value]" value="{{ $row['value'] }}" placeholder="value"
                            class="flex-1 min-w-[8rem] rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                        <button type="button" class="remove-plan-feature-row text-sm text-red-600 dark:text-red-400 hover:underline shrink-0">
                            {{ __('admin_commerce.features_delete_row') }}
                        </button>
                    </div>
                @endforeach
            </div>
            <button type="button" id="add-plan-feature-row" class="mt-3 text-sm text-indigo-600 dark:text-indigo-400 hover:underline">
                {{ __('admin_commerce.features_add_button') }}
            </button>
        </div>

        <div class="rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50/80 dark:bg-gray-900/30 p-4 space-y-4">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Advanced Monetization Settings</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400">These fields update the same plan feature keys as the key/value list above (keys listed there are managed here).</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1" for="referral_bonus_days">Referral bonus days</label>
                    <input id="referral_bonus_days" type="number" name="referral_bonus_days" min="0" step="1"
                        value="{{ old('referral_bonus_days', $isEdit ? ($plan->getFeatureValue(\App\Support\PlanFeatureKeys::REFERRAL_BONUS_DAYS) ?? '') : ($monetizationDefaultsFromTemplate[\App\Support\PlanFeatureKeys::REFERRAL_BONUS_DAYS] ?? '')) }}"
                        class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1" for="profile_boost_per_week">Profile boost per week</label>
                    <input id="profile_boost_per_week" type="number" name="profile_boost_per_week" min="0" step="1"
                        value="{{ old('profile_boost_per_week', $isEdit ? ($plan->getFeatureValue(\App\Support\PlanFeatureKeys::PROFILE_BOOST_PER_WEEK) ?? '') : ($monetizationDefaultsFromTemplate[\App\Support\PlanFeatureKeys::PROFILE_BOOST_PER_WEEK] ?? '')) }}"
                        class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1" for="who_viewed_me_days">Who viewed me (days)</label>
                    <input id="who_viewed_me_days" type="number" name="who_viewed_me_days" min="0" step="1"
                        value="{{ old('who_viewed_me_days', $isEdit ? ($plan->getFeatureValue(\App\Support\PlanFeatureKeys::WHO_VIEWED_ME_DAYS) ?? '') : ($monetizationDefaultsFromTemplate[\App\Support\PlanFeatureKeys::WHO_VIEWED_ME_DAYS] ?? '')) }}"
                        class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm" />
                </div>
                <div class="flex items-end">
                    <label class="inline-flex items-center gap-2 cursor-pointer pb-1">
                        <input type="hidden" name="priority_listing" value="0" />
                        <input type="checkbox" name="priority_listing" value="1" class="rounded border-gray-300"
                            @checked(
                                old('priority_listing') !== null
                                    ? old('priority_listing') == '1'
                                    : (bool) filter_var(
                                        (string) ($isEdit
                                            ? ($plan->getFeatureValue(\App\Support\PlanFeatureKeys::PRIORITY_LISTING) ?? '0')
                                            : ($monetizationDefaultsFromTemplate[\App\Support\PlanFeatureKeys::PRIORITY_LISTING] ?? '0')),
                                        FILTER_VALIDATE_BOOLEAN
                                    )
                            ) />
                        <span class="text-sm text-gray-700 dark:text-gray-300">Priority listing</span>
                    </label>
                </div>
            </div>
        </div>

        @php
            $structuredEngineFeatures = [
                \App\Support\PlanFeatureKeys::CHAT_SEND_LIMIT,
                \App\Support\PlanFeatureKeys::CONTACT_VIEW_LIMIT,
                \App\Support\PlanFeatureKeys::INTEREST_SEND_LIMIT,
                \App\Services\SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT,
            ];
        @endphp
        <div class="rounded-lg border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 p-4 space-y-4">
            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Feature Engine (Advanced)</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400">Structured config stored in <code class="text-xs">plan_feature_configs</code> (parallel to key/value plan_features). Request key <code class="text-xs">feature_configs</code> — does not replace the legacy features[] array.</p>
            @foreach ($structuredEngineFeatures as $feature)
                @php
                    $fc = ($isEdit && $plan->relationLoaded('featureConfigs')) ? $plan->featureConfigs->firstWhere('feature_key', $feature) : null;
                    $extraRupees = old("feature_configs.$feature.extra_cost", $fc && $fc->extra_cost_per_action !== null ? number_format($fc->extra_cost_per_action / 100, 2, '.', '') : '');
                @endphp
                <div class="border border-gray-200 dark:border-gray-600 p-3 rounded-md space-y-2">
                    <strong class="text-sm text-gray-800 dark:text-gray-100">{{ $feature }}</strong>
                    <div class="flex flex-wrap gap-4 items-center text-sm">
                        <input type="hidden" name="feature_configs[{{ $feature }}][enabled]" value="0" />
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" name="feature_configs[{{ $feature }}][enabled]" value="1" class="rounded border-gray-300"
                                @checked((string) old("feature_configs.$feature.enabled", $fc?->is_enabled !== false ? '1' : '0') === '1') />
                            Enabled
                        </label>
                        <input type="hidden" name="feature_configs[{{ $feature }}][unlimited]" value="0" />
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" name="feature_configs[{{ $feature }}][unlimited]" value="1" class="rounded border-gray-300"
                                @checked((string) old("feature_configs.$feature.unlimited", $fc?->is_unlimited ? '1' : '0') === '1') />
                            Unlimited
                        </label>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2 text-sm">
                        <input type="number" name="feature_configs[{{ $feature }}][limit]" placeholder="Limit"
                            value="{{ old("feature_configs.$feature.limit", $fc?->limit_total) }}"
                            class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                        <select name="feature_configs[{{ $feature }}][period]" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                            @php $per = old("feature_configs.$feature.period", $fc?->period ?? 'daily'); @endphp
                            <option value="daily" @selected($per === 'daily')>Daily</option>
                            <option value="monthly" @selected($per === 'monthly')>Monthly</option>
                        </select>
                        <input type="number" name="feature_configs[{{ $feature }}][daily_cap]" placeholder="Daily cap"
                            value="{{ old("feature_configs.$feature.daily_cap", $fc?->daily_cap) }}"
                            class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                        <input type="number" name="feature_configs[{{ $feature }}][soft_limit]" placeholder="Soft %"
                            value="{{ old("feature_configs.$feature.soft_limit", $fc?->soft_limit_percent) }}"
                            min="0" max="100"
                            class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                        <input type="number" name="feature_configs[{{ $feature }}][expiry]" placeholder="Expiry days"
                            value="{{ old("feature_configs.$feature.expiry", $fc?->expiry_days) }}"
                            class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                        <input type="text" inputmode="decimal" name="feature_configs[{{ $feature }}][extra_cost]" placeholder="Extra ₹ (stored as paise)"
                            value="{{ $extraRupees }}"
                            class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                    </div>
                </div>
            @endforeach
        </div>

        <div class="flex gap-3 pt-2 border-t border-gray-200 dark:border-gray-600">
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg">
                {{ __('admin_commerce.plan_save_changes') }}
            </button>
            <a href="{{ route('admin.plans.index') }}" class="px-4 py-2 text-gray-600 dark:text-gray-400 text-sm">Cancel</a>
        </div>
    </form>
</div>
<script>
(function () {
    var removeLabel = @json(__('admin_commerce.features_delete_row'));
    var wrap = document.getElementById('plan-feature-rows');
    var btn = document.getElementById('add-plan-feature-row');
    if (!wrap || !btn) return;

    function nextIndex() {
        var n = parseInt(wrap.getAttribute('data-next-index') || '0', 10);
        if (isNaN(n)) n = 0;
        return n;
    }

    function setNextIndex(i) {
        wrap.setAttribute('data-next-index', String(i));
    }

    function rowCount() {
        return wrap.querySelectorAll('[data-row]').length;
    }

    btn.addEventListener('click', function () {
        var i = nextIndex();
        var row = document.createElement('div');
        row.className = 'plan-feature-row flex flex-wrap gap-2 items-center rounded-md border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 p-2';
        row.setAttribute('data-row', '');
        row.innerHTML =
            '<input type="text" name="features[' + i + '][key]" value="" placeholder="key" class="flex-1 min-w-[12rem] rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />' +
            '<input type="text" name="features[' + i + '][value]" value="" placeholder="value" class="flex-1 min-w-[8rem] rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />' +
            '<button type="button" class="remove-plan-feature-row text-sm text-red-600 dark:text-red-400 hover:underline shrink-0">' + removeLabel + '</button>';
        wrap.appendChild(row);
        setNextIndex(i + 1);
    });

    wrap.addEventListener('click', function (e) {
        var t = e.target.closest('.remove-plan-feature-row');
        if (!t || !wrap.contains(t)) return;
        var row = t.closest('[data-row]');
        if (!row) return;
        if (rowCount() > 1) {
            row.remove();
            return;
        }
        row.querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
    });
})();
</script>
@endsection
