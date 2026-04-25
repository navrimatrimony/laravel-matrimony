@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6 sm:p-8 max-w-5xl">
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">
            {{ $isEdit ? __('subscriptions.edit_plan') : __('subscriptions.create_plan') }}
        </h1>
        <div class="flex flex-wrap items-center gap-2 shrink-0">
            <a href="{{ route('admin.plans.index') }}" class="text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100">
                {{ __('subscriptions.admin_plans_back_to_list') }}
            </a>
            @if ($isEdit)
                <a href="{{ route('admin.plans.create') }}" class="inline-flex items-center px-3 py-1.5 rounded-lg text-sm font-medium bg-indigo-600 hover:bg-indigo-700 text-white">
                    {{ __('subscriptions.create_plan') }}
                </a>
            @endif
        </div>
    </div>

    @if ($errors->any())
        <ul class="text-red-600 text-sm mb-4 space-y-1">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    @endif

    @php
        $presetKeys = \App\Models\PlanTerm::presetBillingKeys();
        $hasValidationErrors = session()->has('errors');
        // Create retry: flash old input. Edit + validation failure: reload term_rows / default tab from old().
        $allowOldInput = $hasValidationErrors && ! $isEdit;
        $defaultBillingKeyInitial = $defaultBillingKeyInitial ?? \App\Models\PlanTerm::BILLING_MONTHLY;
        if ($hasValidationErrors) {
            if (is_array(old('term_rows'))) {
                $termRowsInitial = array_values(old('term_rows'));
            } else {
                $termRowsInitial = $termRowsInitial ?? [];
            }
            $defaultBillingKeyInitial = old('default_billing_key', $defaultBillingKeyInitial);
        } elseif ($allowOldInput) {
            $termRowsInitial = old('term_rows', $termRowsInitial ?? []);
            $defaultBillingKeyInitial = old('default_billing_key', $defaultBillingKeyInitial);
        } else {
            $termRowsInitial = $termRowsInitial ?? [];
        }
        $isFreePlanEdit = $isEdit && \App\Models\Plan::isFreeCatalogSlug((string) ($plan->slug ?? ''));
        $planNameInput = $planNameInput ?? (string) ($plan->name ?? '');
        $appliesToGenderValue = $allowOldInput
            ? old('applies_to_gender', $plan->applies_to_gender ?? 'all')
            : ($plan->applies_to_gender ?? 'all');
        $appliesToGenderValue = strtolower(trim((string) $appliesToGenderValue));
        if (! in_array($appliesToGenderValue, ['male', 'female', 'all'], true)) {
            $appliesToGenderValue = 'all';
        }
        // Defensive fallback: many legacy slugs encode audience directly (e.g. silver_female, gold-male).
        if ($appliesToGenderValue === 'all') {
            $slugLower = strtolower((string) ($plan->slug ?? ''));
            if (preg_match('/(^|[_-])female([_-]|$)/', $slugLower) === 1) {
                $appliesToGenderValue = 'female';
            } elseif (preg_match('/(^|[_-])male([_-]|$)/', $slugLower) === 1) {
                $appliesToGenderValue = 'male';
            }
        }
        $sortOrderValue = $allowOldInput
            ? old('sort_order', $plan->sort_order)
            : $plan->sort_order;
        $marketingBadgeValue = $allowOldInput
            ? old('marketing_badge', $plan->marketing_badge)
            : $plan->marketing_badge;
        $durationDaysValue = $allowOldInput
            ? old('duration_days', $plan->duration_days)
            : $plan->duration_days;
        $listPriceValue = $allowOldInput
            ? old('list_price_rupees', $plan->list_price_rupees !== null ? (int) $plan->list_price_rupees : '')
            : ($plan->list_price_rupees !== null ? (int) $plan->list_price_rupees : '');
        $gstInclusiveValue = $allowOldInput
            ? old('gst_inclusive', ($plan->gst_inclusive ?? true) ? '1' : '0')
            : (($plan->gst_inclusive ?? true) ? '1' : '0');
        $isActiveValue = $allowOldInput
            ? old('is_active', $plan->is_active ? '1' : '0')
            : ($plan->is_active ? '1' : '0');
    @endphp

    <form method="POST" action="{{ $isEdit ? route('admin.plans.update', $plan) : route('admin.plans.store') }}" class="space-y-6">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif
        @if ($isFreePlanEdit)
            <input type="hidden" name="slug" value="{{ $plan->slug }}" />
        @endif

        @if (! $isFreePlanEdit)
            <div class="space-y-6">
        @endif

        @php
            use App\Support\PlanFeatureKeys;
            $initiateOld = $allowOldInput ? old('chat_initiate_new_chats_only') : null;
            $chatQuotaForm = $quotaPoliciesForm[PlanFeatureKeys::CHAT_SEND_LIMIT] ?? [];
            $chatPm = $chatQuotaForm['policy_meta'] ?? [];
            $initiateFromQuota = array_key_exists('chat_initiate_new_chats_only', $chatPm)
                && (filter_var($chatPm['chat_initiate_new_chats_only'], FILTER_VALIDATE_BOOLEAN)
                    || (string) $chatPm['chat_initiate_new_chats_only'] === '1'
                    || $chatPm['chat_initiate_new_chats_only'] === 1);
            $initiateChecked = $initiateOld !== null
                ? (string) $initiateOld === '1'
                : $initiateFromQuota;
            $adminMarketingBadgeKeys = $adminMarketingBadgeKeys ?? \App\Http\Controllers\Admin\PlanController::ADMIN_MARKETING_BADGE_KEYS;
        @endphp

        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900/40 shadow-sm">
            <div class="px-5 py-4 sm:px-6 border-b border-gray-100 dark:border-gray-700 bg-gradient-to-r from-indigo-50/95 via-white to-slate-50/80 dark:from-indigo-950/35 dark:via-gray-900 dark:to-gray-900">
                <h2 class="text-lg font-semibold tracking-tight text-gray-900 dark:text-gray-50">{{ __('subscriptions.admin_plan_details_card_title') }}</h2>
                <p class="mt-1.5 text-sm text-gray-600 dark:text-gray-400 leading-relaxed max-w-3xl">{{ __('subscriptions.admin_plan_details_card_intro') }}</p>
            </div>

            <div class="p-5 sm:p-6">
                @if ($isEdit)
                    <div class="mb-4 rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50/70 dark:bg-slate-900/50 px-3 py-2 text-xs text-slate-700 dark:text-slate-300">
                        <span class="font-semibold">{{ __('subscriptions.admin_plan_catalog_url_key') }}:</span>
                        <span class="font-mono select-all">{{ (string) ($plan->slug ?? '') }}</span>
                    </div>
                @endif
                <div class="grid grid-cols-1 md:grid-cols-3 gap-x-5 gap-y-6 md:items-end w-full">
                    {{-- Row 1: name | gender | sort --}}
                    <div class="min-w-0">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2" for="plan-admin-name">{{ __('subscriptions.admin_plan_name_label') }}</label>
                        <input id="plan-admin-name" type="text" name="name" value="{{ $planNameInput }}" required maxlength="120" autocomplete="off"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white shadow-sm text-base py-2.5 px-3.5 font-medium placeholder:text-gray-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 dark:focus:ring-indigo-400/20" />
                    </div>
                    <div class="min-w-0">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2" for="plan-admin-gender">{{ __('subscriptions.admin_plan_applies_to_gender') }}</label>
                        <select id="plan-admin-gender" name="applies_to_gender"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white shadow-sm py-2.5 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20">
                            @foreach (['all' => __('subscriptions.admin_plan_gender_all'), 'male' => __('subscriptions.admin_plan_gender_male'), 'female' => __('subscriptions.admin_plan_gender_female')] as $gk => $glab)
                                <option value="{{ $gk }}" @selected($appliesToGenderValue === $gk)>{{ $glab }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="min-w-0">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2" for="plan-admin-sort">{{ __('subscriptions.admin_plan_sort_order') }}</label>
                        <input id="plan-admin-sort" type="number" name="sort_order" value="{{ $sortOrderValue }}" min="0"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white shadow-sm py-2.5 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20" />
                    </div>

                    @if ($isFreePlanEdit)
                        {{-- Row 2: highlight | duration (days) | spacer --}}
                        <div class="min-w-0">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2" for="plan-admin-marketing-badge-free">{{ __('subscriptions.admin_plan_marketing_badge') }}</label>
                            <select id="plan-admin-marketing-badge-free" name="marketing_badge" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white shadow-sm py-2.5 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20">
                                <option value="" @selected($marketingBadgeValue === null || $marketingBadgeValue === '')>{{ __('subscriptions.admin_plan_marketing_opt_none') }}</option>
                                @foreach ($adminMarketingBadgeKeys as $mbKey)
                                    <option value="{{ $mbKey }}" @selected($marketingBadgeValue === $mbKey)>{{ __('subscriptions.admin_plan_marketing_opt_'.$mbKey) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="min-w-0">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2" for="plan-admin-duration-days-free">{{ __('subscriptions.admin_plan_duration_free_label') }}</label>
                            <input id="plan-admin-duration-days-free" type="number" name="duration_days" value="{{ $durationDaysValue }}" required min="0"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white shadow-sm py-2.5" />
                        </div>
                        <div class="hidden md:block min-h-[2.75rem]" aria-hidden="true"></div>

                        @include('admin.plans.partials.plan-grace-carry-selects', ['showPlanDiscount' => false])
                    @else
                        {{-- Row 2: Highlight | Grace period | Carry window (single line, same 3-col grid as row 1). --}}
                        <div class="min-w-0">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2" for="plan-admin-marketing-badge">{{ __('subscriptions.admin_plan_marketing_badge') }}</label>
                            <select id="plan-admin-marketing-badge" name="marketing_badge" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white shadow-sm py-2.5 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20">
                                <option value="" @selected($marketingBadgeValue === null || $marketingBadgeValue === '')>{{ __('subscriptions.admin_plan_marketing_opt_none') }}</option>
                                @foreach ($adminMarketingBadgeKeys as $mbKey)
                                    <option value="{{ $mbKey }}" @selected($marketingBadgeValue === $mbKey)>{{ __('subscriptions.admin_plan_marketing_opt_'.$mbKey) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <input type="hidden" name="list_price_rupees" value="{{ $listPriceValue }}">

                        @include('admin.plans.partials.plan-grace-carry-selects', ['showPlanDiscount' => false, 'withTrailingSpacer' => false])
                    @endif

                    <div class="col-span-full border-t border-gray-200 dark:border-gray-700 my-1"></div>

                    {{-- Row 4: checkboxes on one aligned row/grid --}}
                    <div class="col-span-full grid grid-cols-1 md:grid-cols-3 gap-x-5 gap-y-3 items-center pt-1">
                        @if ($isFreePlanEdit)
                            <div class="hidden md:block" aria-hidden="true"></div>
                        @else
                            <div class="min-w-0">
                                <input type="hidden" name="gst_inclusive" value="0" />
                                <label class="inline-flex items-center gap-2.5 cursor-pointer text-sm text-gray-800 dark:text-gray-100">
                                    <input type="checkbox" name="gst_inclusive" value="1" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" @checked((string) $gstInclusiveValue === '1') />
                                    <span>{{ __('subscriptions.admin_plan_gst_inclusive') }}</span>
                                </label>
                            </div>
                        @endif
                        <div class="min-w-0">
                            <input type="hidden" name="chat_initiate_new_chats_only" value="0" />
                            <label class="inline-flex items-center gap-2.5 cursor-pointer text-sm text-gray-800 dark:text-gray-100">
                                <input type="checkbox" name="chat_initiate_new_chats_only" value="1" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" @checked((int) $initiateChecked === 1) />
                                <span>{{ __('subscriptions.admin_plan_chat_initiate_only') }}</span>
                            </label>
                        </div>
                        <div class="min-w-0">
                            <input type="hidden" name="is_active" value="0" />
                            <label class="inline-flex items-center gap-2.5 cursor-pointer text-sm text-gray-800 dark:text-gray-100">
                                <input type="checkbox" name="is_active" value="1" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" @checked((string) $isActiveValue === '1') />
                                <span>{{ __('subscriptions.admin_plan_active_label') }}</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @unless ($isFreePlanEdit)
            <div id="plan-admin-billing-panel" class="relative z-[45] rounded-lg border border-indigo-200 dark:border-indigo-900 bg-indigo-50/50 dark:bg-indigo-950/20 p-4 space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ __('subscriptions.admin_billing_rows_title') }}</h2>
                        <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">{{ __('subscriptions.admin_billing_rows_intro_dynamic') }}</p>
                        <p class="mt-2 text-[11px] text-gray-500 dark:text-gray-400">{{ __('subscriptions.admin_billing_static_hint') }}</p>
                    </div>
                    <button type="button" id="plan-term-row-add" class="relative z-[50] shrink-0 rounded-lg border border-indigo-300 bg-white px-3 py-1.5 text-sm font-semibold text-indigo-700 shadow-sm hover:bg-indigo-50 dark:border-indigo-700 dark:bg-indigo-950 dark:text-indigo-200">
                        {{ __('subscriptions.admin_add_billing_period') }}
                    </button>
                </div>

                <div id="plan-term-rows-body" class="space-y-3">
                    @foreach ($termRowsInitial as $i => $row)
                        @include('admin.plans.partials.plan-term-row', [
                            'i' => $i,
                            'row' => $row,
                            'presetKeys' => $presetKeys,
                            'defaultBillingKeyInitial' => $defaultBillingKeyInitial,
                            'forTemplate' => false,
                        ])
                    @endforeach
                </div>

                <template id="admin-plan-term-row-template">
                    @include('admin.plans.partials.plan-term-row', [
                        'i' => 999,
                        'row' => ['billing_key' => \App\Models\PlanTerm::BILLING_MONTHLY, 'price' => 0, 'discount_percent' => null, 'is_visible' => true],
                        'presetKeys' => $presetKeys,
                        'defaultBillingKeyInitial' => null,
                        'forTemplate' => true,
                    ])
                </template>
            </div>
        @endunless

        <div class="rounded-lg border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 p-4 space-y-6">
            <div>
                <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ __('subscriptions.plan_quota_policies_title') }}</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    {{ __('subscriptions.plan_quota_policies_intro') }}
                </p>
            </div>

            @php
                $simpleBooleanToggleKeys = \App\Support\PlanQuotaPolicyKeys::adminSimpleBooleanToggleKeys();
            @endphp
            @foreach ($quotaPolicyKeys as $featureKey)
                @if (in_array($featureKey, $simpleBooleanToggleKeys, true))
                    @continue
                @endif
                @include('admin.plans.partials.quota-policy-quota-card', ['featureKey' => $featureKey, 'quotaPoliciesForm' => $quotaPoliciesForm])
            @endforeach

            @include('admin.plans.partials.quota-policy-boolean-pair-row', [
                'quotaPoliciesForm' => $quotaPoliciesForm,
                'hiddenOnlySimpleToggleKeys' => ! $isEdit ? [PlanFeatureKeys::PROFILE_WHATSAPP_DIRECT] : [],
            ])
        </div>

        @if ($isEdit)
            <div class="rounded-lg border border-indigo-200 dark:border-indigo-900 bg-indigo-50/40 dark:bg-indigo-950/20 p-4">
                <h2 class="text-base font-semibold text-gray-800 dark:text-gray-100">Referral rewards for this plan</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    Plan referral rewards (days + feature bonuses) are now managed from the dedicated Referral module.
                </p>
                <a href="{{ route('admin.referrals.index', ['tab' => 'reward-plans', 'plan_slug' => (string) ($plan->slug ?? '')]) }}"
                   class="mt-3 inline-flex items-center px-3 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium">
                    Open Referral Reward Plans
                </a>
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-3 pt-2 border-t border-gray-200 dark:border-gray-600">
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg">
                {{ __('admin_commerce.plan_save_changes') }}
            </button>
            <a href="{{ route('admin.plans.index') }}" class="px-4 py-2 text-gray-600 dark:text-gray-400 text-sm">Cancel</a>
            @if ($isEdit)
                <a href="{{ route('admin.plans.create') }}" class="inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium border border-indigo-600 text-indigo-700 hover:bg-indigo-50 dark:border-indigo-500 dark:text-indigo-300 dark:hover:bg-indigo-950/40">
                    {{ __('subscriptions.create_plan') }}
                </a>
            @endif
        </div>

        @if (! $isFreePlanEdit)
            </div>
        @endif
    </form>
</div>
@endsection
