@extends('layouts.app')

@php
    use App\Support\PlanFeatureKeys;
    use App\Support\PlanFeatureLabel;

    $eff = $currentPlan ?? $effectivePlan;

    $durationTypeLabel = function (string $t): string {
        $key = 'subscriptions.duration_type_'.$t;
        $tr = __($key);

        return $tr === $key ? \Illuminate\Support\Str::headline(str_replace('_', ' ', $t)) : $tr;
    };

    $isFreeViewer = ! $eff->id || \App\Models\Plan::isFreeCatalogSlug((string) ($eff->slug ?? ''));

    /** Admin plan quota engine owns these; omit from public pricing feature lists. */
    $pricingCatalogKeysHidden = [
        PlanFeatureKeys::CHAT_CAN_READ,
        PlanFeatureKeys::PHOTO_FULL_ACCESS,
        PlanFeatureKeys::PRIORITY_LISTING,
    ];

    $pricingHighlightFeatureOrder = [
        PlanFeatureKeys::CHAT_SEND_LIMIT,
        PlanFeatureKeys::CONTACT_VIEW_LIMIT,
    ];

    $partitionPricingFeatures = function ($plan) use ($pricingHighlightFeatureOrder, $pricingCatalogKeysHidden) {
        $rows = $plan->features
            ->filter(fn ($f) => PlanFeatureLabel::shouldListKey((string) $f->key))
            ->reject(fn ($f) => in_array((string) $f->key, $pricingCatalogKeysHidden, true))
            ->keyBy(fn ($f) => (string) $f->key);
        $primary = collect();
        foreach ($pricingHighlightFeatureOrder as $key) {
            if ($rows->has($key)) {
                $primary->push($rows->get($key));
            }
            $rows->forget($key);
        }

        return [$primary, $rows->sortKeys()->values()];
    };

    $discountPercentForPrice = function (\App\Models\PlanPrice $pp): int {
        $d = (int) ($pp->discount_percent ?? 0);
        if ($d > 0) {
            return min(100, $d);
        }
        $strike = $pp->strike_list_price;
        $final = (float) $pp->final_price;
        if ($strike !== null && (float) $strike > $final + 0.004) {
            return (int) round(100 * (1 - $final / (float) $strike));
        }

        return 0;
    };

    $discountPercentForTerm = function (\App\Models\PlanTerm $t): int {
        $d = (int) ($t->discount_percent ?? 0);
        if ($d > 0) {
            return min(100, $d);
        }
        $list = (float) $t->price;
        $final = (float) $t->final_price;
        if ($list > $final + 0.004) {
            return (int) round(100 * (1 - $final / $list));
        }

        return 0;
    };

    $pricingPlans = $pricingPlans ?? collect();
@endphp

@section('content')
<div class="relative overflow-x-hidden bg-gradient-to-b from-slate-50 via-white to-indigo-50/40 pb-20 dark:from-gray-950 dark:via-gray-900 dark:to-indigo-950/25">
    {{-- Urgency --}}
    <div class="relative z-20 border-b border-amber-300/60 bg-gradient-to-r from-amber-500 via-orange-500 to-rose-600 px-4 py-3 text-center shadow-sm">
        <p class="text-sm font-bold tracking-wide text-white sm:text-base">
            {{ __('subscriptions.pricing_urgency_limited') }}
        </p>
        @if (($maxDiscountPercent ?? 0) > 0)
            <p class="mt-0.5 text-xs font-semibold text-amber-100/95 sm:text-sm">
                {{ __('subscriptions.urgency_banner_discount', ['percent' => $maxDiscountPercent]) }}
            </p>
        @endif
    </div>

    <div
        class="relative z-10 mx-auto max-w-6xl px-4 pt-10 sm:px-6 lg:px-8"
        x-data="plansPricingCatalog({
            validateUrl: @js(route('plans.coupon.validate')),
            csrf: @js(csrf_token()),
        })"
    >
        <header class="mx-auto max-w-2xl text-center">
            <h1 class="text-3xl font-extrabold tracking-tight text-slate-900 dark:text-white sm:text-4xl">
                {{ __('subscriptions.pricing_page_title') }}
            </h1>
            <p class="mt-3 text-base text-slate-600 dark:text-slate-300 sm:text-lg">
                {{ __('subscriptions.pricing_page_subtitle') }}
            </p>
            <div class="mt-5 inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white/90 px-4 py-2 text-sm font-medium text-slate-800 shadow-sm dark:border-slate-700 dark:bg-slate-800/90 dark:text-slate-100">
                <span class="h-2 w-2 shrink-0 animate-pulse rounded-full bg-emerald-500"></span>
                <span>{{ __('subscriptions.pricing_current_plan') }}:</span>
                <span class="font-bold">{{ $eff->name }}</span>
            </div>
        </header>

        @auth
            @if ($unreadMessagesCount > 0 || $profileViewersCount > 0)
                <div class="mx-auto mt-8 flex max-w-xl flex-col gap-2 sm:flex-row sm:justify-center sm:gap-3">
                    @if ($unreadMessagesCount > 0)
                        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-center text-sm font-semibold text-rose-900 shadow-sm dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                            {{ __('subscriptions.pricing_trigger_unread') }}
                            <span class="tabular-nums text-rose-700 dark:text-rose-300">({{ $unreadMessagesCount }})</span>
                        </div>
                    @endif
                    @if ($profileViewersCount > 0)
                        <a href="{{ route('who-viewed.index') }}"
                           class="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2.5 text-center text-sm font-semibold text-indigo-900 shadow-sm transition hover:bg-indigo-100 dark:border-indigo-800 dark:bg-indigo-950/40 dark:text-indigo-100 dark:hover:bg-indigo-900/60">
                            {{ __('subscriptions.pricing_trigger_views') }}
                            <span class="tabular-nums text-indigo-700 dark:text-indigo-300">({{ $profileViewersCount }})</span>
                        </a>
                    @endif
                </div>
            @endif
        @endauth

        @if ($isFreeViewer)
            <div class="mx-auto mt-8 max-w-lg rounded-2xl border border-indigo-200/80 bg-white/95 p-4 shadow-md dark:border-indigo-900/50 dark:bg-slate-800/95 sm:p-5">
                <p class="text-center text-xs font-semibold uppercase tracking-wider text-indigo-600 dark:text-indigo-300">
                    {{ __('subscriptions.blur_teaser_title') }}
                </p>
                <div class="mt-3 flex flex-col items-center rounded-xl bg-slate-100 px-4 py-4 dark:bg-slate-900/80">
                    <span class="select-none text-lg font-medium tracking-widest text-slate-400 blur-sm dark:text-slate-500" aria-hidden="true">
                        {{ __('subscriptions.blur_phone_preview') }}
                    </span>
                    <p class="mt-2 text-center text-sm text-slate-600 dark:text-slate-400">
                        {{ __('subscriptions.blur_upgrade_cta') }}
                    </p>
                </div>
            </div>
        @endif

        @if (! empty($catalogIncludesInactive))
            <div class="mx-auto mt-6 max-w-xl rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-center text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                {{ __('subscriptions.plans_inactive_catalog_note') }}
            </div>
        @endif

        @if (session('success'))
            <div class="mx-auto mt-6 max-w-xl rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-center text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-100">
                {{ session('success') }}
            </div>
        @endif
        @if (session('error'))
            <div class="mx-auto mt-6 max-w-xl rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-center text-sm text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">
                {{ session('error') }}
            </div>
        @endif

        <div class="mx-auto mt-10 max-w-md" x-data="{ couponOpen: false }">
            <button
                type="button"
                class="flex w-full items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white/90 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-800/90 dark:text-slate-200 dark:hover:bg-slate-800"
                @click="couponOpen = !couponOpen"
                :aria-expanded="couponOpen"
            >
                <span>{{ __('subscriptions.pricing_coupon_toggle') }}</span>
                <svg class="h-4 w-4 transition" :class="couponOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div
                x-show="couponOpen"
                x-transition
                x-cloak
                class="mt-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-800/95"
            >
                <label class="block text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400" for="plan-coupon-input">{{ __('subscriptions.coupon_heading') }}</label>
                <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-stretch">
                    <input
                        id="plan-coupon-input"
                        type="text"
                        name="catalog_coupon_preview"
                        x-model="couponCode"
                        autocomplete="off"
                        class="min-w-0 flex-1 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-slate-600 dark:bg-slate-900 dark:text-white"
                        placeholder="{{ __('subscriptions.coupon_placeholder') }}"
                    />
                    <div class="flex shrink-0 gap-2">
                        <button
                            type="button"
                            class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-bold text-white shadow transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                            @click="validateCoupon()"
                            :disabled="couponLoading || !couponCode.trim()"
                        >{{ __('subscriptions.coupon_apply') }}</button>
                        <button
                            type="button"
                            class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
                            @click="clearCoupon()"
                        >{{ __('subscriptions.coupon_clear') }}</button>
                    </div>
                </div>
                <p x-show="couponMeta && couponMeta.valid" x-cloak class="mt-2 text-sm font-medium text-emerald-700 dark:text-emerald-300">{{ __('subscriptions.coupon_applied_hint') }}</p>
                <p x-show="couponError" x-cloak x-text="couponError" class="mt-2 text-sm text-red-600 dark:text-red-400"></p>
            </div>
        </div>

        @if ($pricingPlans->isEmpty())
            <div class="mx-auto mt-14 max-w-md rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm dark:border-slate-700 dark:bg-slate-800">
                <p class="font-semibold text-slate-900 dark:text-white">{{ __('subscriptions.pricing_no_paid_plans') }}</p>
            </div>
        @else
            <div class="mx-auto mt-14 grid max-w-5xl grid-cols-1 gap-6 lg:grid-cols-3 lg:items-stretch lg:gap-5">
                @foreach ($pricingPlans as $plan)
                    @php
                        $slug = strtolower((string) ($plan->slug ?? ''));
                        $isGold = $slug === 'gold';
                        $isCurrent = $plan->id && $eff->id && (int) $plan->id === (int) $eff->id;
                        $visiblePlanPrices = $plan->visiblePlanPrices->sortBy('sort_order')->values();
                        $visibleTerms = $plan->terms->where('is_visible', true)->sortBy('sort_order')->values();
                        $useEnginePrices = $visiblePlanPrices->isNotEmpty();
                        $useTerms = ! $useEnginePrices && $visibleTerms->isNotEmpty();
                        $defaultKey = (string) ($plan->default_billing_key ?? '');
                        $defaultPriceRow = $defaultKey !== ''
                            ? $visiblePlanPrices->firstWhere('duration_type', $defaultKey)
                            : null;
                        $defaultTermRow = $defaultKey !== ''
                            ? $visibleTerms->firstWhere('billing_key', $defaultKey)
                            : null;
                        $defaultBillingId = $useEnginePrices
                            ? (int) (($defaultPriceRow ?? $visiblePlanPrices->first())?->id ?? 0)
                            : ($useTerms ? (int) (($defaultTermRow ?? $visibleTerms->first())?->id ?? 0) : 0);
                        [$primaryFeatureRows, $secondaryFeatureRows] = $partitionPricingFeatures($plan);
                    @endphp
                    <article
                        class="relative flex min-w-0 flex-col rounded-2xl border bg-white shadow-lg transition-shadow dark:bg-slate-800/95
                            {{ $isGold
                                ? 'z-10 border-amber-400/90 shadow-xl shadow-amber-500/10 ring-2 ring-amber-400/50 dark:border-amber-500/60 dark:ring-amber-500/40 lg:scale-[1.03] lg:py-1'
                                : 'border-slate-200 dark:border-slate-700' }}
                            {{ $isCurrent ? 'ring-2 ring-indigo-500 dark:ring-indigo-400' : '' }}"
                        @if ($useEnginePrices || $useTerms)
                            x-data="{ selectedBillingId: {{ $defaultBillingId }} }"
                        @endif
                    >
                        @if ($isGold)
                            <div class="absolute -top-3 left-1/2 z-20 -translate-x-1/2 whitespace-nowrap rounded-full bg-gradient-to-r from-amber-500 to-orange-600 px-4 py-1 text-[11px] font-extrabold uppercase tracking-wider text-white shadow-md sm:text-xs">
                                {{ __('subscriptions.pricing_most_popular') }}
                            </div>
                        @endif

                        @if ($isCurrent)
                            <span class="absolute right-3 top-3 z-20 rounded-full bg-indigo-600 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-white shadow sm:text-xs">
                                {{ __('subscriptions.your_plan_badge') }}
                            </span>
                        @endif

                        @php
                            $displayPlanName = preg_replace('/\s*\((male|female)\)\s*$/i', '', (string) $plan->name) ?? (string) $plan->name;
                        @endphp
                        <div class="flex flex-1 flex-col px-5 pb-6 pt-8 sm:px-6 sm:pb-7 sm:pt-9 {{ $isGold ? 'lg:px-7 lg:pb-8 lg:pt-10' : '' }}">
                            <h2 class="text-xl font-bold text-slate-900 dark:text-white sm:text-2xl {{ $isGold ? 'lg:text-[1.65rem]' : '' }}">
                                {{ $displayPlanName }}
                            </h2>

                            @if ($useEnginePrices)
                                <div class="mt-4 flex flex-wrap gap-1.5" role="tablist" aria-label="{{ __('subscriptions.billing_period_label') }}">
                                    @foreach ($visiblePlanPrices as $pp)
                                        <button
                                            type="button"
                                            role="tab"
                                            class="rounded-lg border px-2.5 py-1 text-[11px] font-semibold transition focus:outline-none focus:ring-2 focus:ring-indigo-500 sm:text-xs"
                                            :class="selectedBillingId === {{ (int) $pp->id }}
                                                ? 'border-indigo-600 bg-indigo-600 text-white'
                                                : 'border-slate-200 bg-slate-50 text-slate-700 hover:border-indigo-300 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200'"
                                            :aria-selected="selectedBillingId === {{ (int) $pp->id }} ? 'true' : 'false'"
                                            @click="selectedBillingId = {{ (int) $pp->id }}"
                                        >
                                            {{ $durationTypeLabel($pp->duration_type) }}
                                        </button>
                                    @endforeach
                                </div>

                                @foreach ($visiblePlanPrices as $pp)
                                    @php
                                        $ppFinal = (float) $pp->final_price;
                                        $ppStrike = $pp->strike_list_price;
                                        $ppDisc = $discountPercentForPrice($pp);
                                    @endphp
                                    <div x-show="selectedBillingId === {{ (int) $pp->id }}" x-cloak class="mt-4 space-y-2">
                                        <p class="text-sm font-medium text-slate-500 dark:text-slate-400">
                                            <x-plan.duration-label :days="$pp->duration_days" />
                                            <span class="text-slate-400 dark:text-slate-500"> · </span>
                                            <span>{{ __('subscriptions.pricing_per_cycle', ['period' => $durationTypeLabel($pp->duration_type)]) }}</span>
                                        </p>
                                        @if ($ppDisc > 0)
                                            <span class="inline-flex w-fit rounded-md bg-rose-100 px-2 py-0.5 text-xs font-bold text-rose-800 dark:bg-rose-950/70 dark:text-rose-200">
                                                {{ __('subscriptions.discount_badge', ['percent' => $ppDisc]) }}
                                            </span>
                                        @endif
                                        <div class="flex flex-wrap items-end gap-x-3 gap-y-1">
                                            @if ($ppStrike !== null && (float) $ppStrike > $ppFinal + 0.004)
                                                <span class="text-lg text-slate-400 line-through tabular-nums dark:text-slate-500">{{ number_format((float) $ppStrike) }}</span>
                                            @endif
                                            @if ($ppFinal <= 0)
                                                <span class="text-3xl font-bold text-emerald-600 tabular-nums dark:text-emerald-400">{{ __('subscriptions.price_free_label') }}</span>
                                            @else
                                                <span class="text-3xl font-extrabold text-slate-900 tabular-nums dark:text-white">
                                                    ₹{{ number_format($ppFinal) }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            @elseif ($useTerms)
                                <div class="mt-4 flex flex-wrap gap-1.5" role="tablist" aria-label="{{ __('subscriptions.billing_period_label') }}">
                                    @foreach ($visibleTerms as $t)
                                        <button
                                            type="button"
                                            role="tab"
                                            class="rounded-lg border px-2.5 py-1 text-[11px] font-semibold transition focus:outline-none focus:ring-2 focus:ring-indigo-500 sm:text-xs"
                                            :class="selectedBillingId === {{ (int) $t->id }}
                                                ? 'border-indigo-600 bg-indigo-600 text-white'
                                                : 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200'"
                                            :aria-selected="selectedBillingId === {{ (int) $t->id }} ? 'true' : 'false'"
                                            @click="selectedBillingId = {{ (int) $t->id }}"
                                        >
                                            <x-plan.duration-label :days="$t->duration_days" class="pointer-events-none" />
                                        </button>
                                    @endforeach
                                </div>
                                @foreach ($visibleTerms as $t)
                                    @php
                                        $tList = (float) $t->price;
                                        $tFinal = (float) $t->final_price;
                                        $tDisc = $discountPercentForTerm($t);
                                    @endphp
                                    <div x-show="selectedBillingId === {{ (int) $t->id }}" x-cloak class="mt-4 space-y-2">
                                        <p class="text-sm font-medium text-slate-500 dark:text-slate-400">
                                            <x-plan.duration-label :days="$t->duration_days" />
                                        </p>
                                        @if ($tDisc > 0)
                                            <span class="inline-flex w-fit rounded-md bg-rose-100 px-2 py-0.5 text-xs font-bold text-rose-800 dark:bg-rose-950/70 dark:text-rose-200">
                                                {{ __('subscriptions.discount_badge', ['percent' => $tDisc]) }}
                                            </span>
                                        @endif
                                        <div class="flex flex-wrap items-end gap-x-3 gap-y-1">
                                            @if ($tDisc > 0 && $tList > $tFinal + 0.004)
                                                <span class="text-lg text-slate-400 line-through tabular-nums dark:text-slate-500">{{ number_format($tList) }}</span>
                                            @endif
                                            <span class="text-3xl font-extrabold text-slate-900 tabular-nums dark:text-white">
                                                ₹{{ number_format($tFinal) }}
                                            </span>
                                        </div>
                                    </div>
                                @endforeach
                            @else
                                @php
                                    $listPrice = (float) $plan->price;
                                    $finalPrice = (float) $plan->final_price;
                                    $legacyDisc = (int) ($plan->discount_percent ?? 0);
                                @endphp
                                <div class="mt-4 space-y-2">
                                    <p class="text-sm font-medium text-slate-500 dark:text-slate-400">
                                        <x-plan.duration-label :days="$plan->duration_days" />
                                    </p>
                                    @if ($legacyDisc > 0)
                                        <span class="inline-flex w-fit rounded-md bg-rose-100 px-2 py-0.5 text-xs font-bold text-rose-800 dark:bg-rose-950/70 dark:text-rose-200">
                                            {{ __('subscriptions.discount_badge', ['percent' => $legacyDisc]) }}
                                        </span>
                                    @endif
                                    <div class="flex flex-wrap items-end gap-x-3 gap-y-1">
                                        @if ($legacyDisc > 0 && $listPrice > $finalPrice + 0.004)
                                            <span class="text-lg text-slate-400 line-through tabular-nums dark:text-slate-500">₹{{ number_format($listPrice) }}</span>
                                        @endif
                                        <span class="text-3xl font-extrabold text-slate-900 tabular-nums dark:text-white">
                                            ₹{{ number_format($finalPrice) }}
                                        </span>
                                    </div>
                                </div>
                            @endif

                            @if ($useEnginePrices)
                                @php
                                    $refDaysCatalog = max(1, (int) $visiblePlanPrices->min('duration_days'));
                                @endphp
                                @foreach ($visiblePlanPrices as $ppCatalog)
                                    @include('plans.partials.pricing-plan-features', [
                                        'planId' => $plan->id,
                                        'primaryFeatureRows' => $primaryFeatureRows,
                                        'secondaryFeatureRows' => $secondaryFeatureRows,
                                        'durationMultiplier' => ((float) $ppCatalog->duration_days) / (float) $refDaysCatalog,
                                        'billingDurationType' => (string) $ppCatalog->duration_type,
                                        'selectedBillingId' => (int) $ppCatalog->id,
                                        'wrapInBillingToggle' => true,
                                    ])
                                @endforeach
                            @elseif ($useTerms)
                                @php
                                    $refDaysCatalog = max(1, (int) $visibleTerms->min('duration_days'));
                                @endphp
                                @foreach ($visibleTerms as $termCatalog)
                                    @include('plans.partials.pricing-plan-features', [
                                        'planId' => $plan->id,
                                        'primaryFeatureRows' => $primaryFeatureRows,
                                        'secondaryFeatureRows' => $secondaryFeatureRows,
                                        'durationMultiplier' => ((float) $termCatalog->duration_days) / (float) $refDaysCatalog,
                                        'billingDurationType' => (string) $termCatalog->billing_key,
                                        'selectedBillingId' => (int) $termCatalog->id,
                                        'wrapInBillingToggle' => true,
                                    ])
                                @endforeach
                            @else
                                @include('plans.partials.pricing-plan-features', [
                                    'planId' => $plan->id,
                                    'primaryFeatureRows' => $primaryFeatureRows,
                                    'secondaryFeatureRows' => $secondaryFeatureRows,
                                    'durationMultiplier' => 1.0,
                                    'billingDurationType' => null,
                                    'selectedBillingId' => 0,
                                    'wrapInBillingToggle' => false,
                                ])
                            @endif

                            <div class="mt-8">
                                @if (! $plan->is_active)
                                    <span class="inline-flex w-full justify-center rounded-xl bg-slate-100 px-4 py-3.5 text-sm font-medium text-slate-600 dark:bg-slate-900 dark:text-slate-400">
                                        {{ __('subscriptions.plan_not_available_signup') }}
                                    </span>
                                @elseif ($isCurrent)
                                    <span class="inline-flex w-full justify-center rounded-xl bg-slate-200 px-4 py-3.5 text-sm font-bold text-slate-700 dark:bg-slate-700 dark:text-slate-200">
                                        {{ __('subscriptions.pricing_current_plan') }}
                                    </span>
                                @elseif (auth()->check())
                                    <form method="POST" action="{{ route('plans.subscribe') }}">
                                        @csrf
                                        <input type="hidden" name="plan" value="{{ $plan->slug }}" />
                                        @if ($useEnginePrices)
                                            <input type="hidden" name="plan_price_id" x-bind:value="selectedBillingId" />
                                        @elseif ($useTerms)
                                            <input type="hidden" name="plan_term_id" x-bind:value="selectedBillingId" />
                                        @endif
                                        <input type="hidden" name="coupon_code" x-bind:value="$root.couponCode" />
                                        <button
                                            type="submit"
                                            class="w-full rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 px-4 py-3.5 text-sm font-bold text-white shadow-lg transition hover:from-indigo-500 hover:to-violet-500 hover:shadow-xl active:scale-[0.98] {{ $isGold ? 'py-4 text-base shadow-indigo-500/25' : '' }}"
                                        >
                                            {{ __('subscriptions.pricing_cta_upgrade') }}
                                        </button>
                                    </form>
                                @else
                                    <a
                                        href="{{ route('login') }}"
                                        class="flex w-full items-center justify-center rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 px-4 py-3.5 text-sm font-bold text-white shadow-lg transition hover:from-indigo-500 hover:to-violet-500 hover:shadow-xl active:scale-[0.98] {{ $isGold ? 'py-4 text-base' : '' }}"
                                    >
                                        {{ __('subscriptions.pricing_cta_sign_in') }}
                                    </a>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif

        <div class="mx-auto mt-16 max-w-2xl rounded-2xl border border-slate-200 bg-white/80 px-6 py-6 text-center shadow-sm dark:border-slate-700 dark:bg-slate-800/80">
            <ul class="flex flex-col gap-3 text-sm font-medium text-slate-600 dark:text-slate-300 sm:flex-row sm:justify-center sm:gap-8">
                <li class="flex items-center justify-center gap-2"><span class="text-emerald-600">✓</span> {{ __('subscriptions.trust_matches') }}</li>
                <li class="flex items-center justify-center gap-2"><span class="text-emerald-600">✓</span> {{ __('subscriptions.trust_secure') }}</li>
            </ul>
        </div>
    </div>
</div>
@endsection
