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

    $pricingHighlightFeatureOrder = [
        PlanFeatureKeys::CHAT_SEND_LIMIT,
        PlanFeatureKeys::CONTACT_VIEW_LIMIT,
    ];

    /**
     * @return array{0: \Illuminate\Support\Collection, 1: \Illuminate\Support\Collection, 2: \Illuminate\Support\Collection}
     *         [primary included, secondary included, excluded]
     */
    $partitionPricingFeatures = function ($plan) use ($pricingHighlightFeatureOrder) {
        $all = $plan->catalogFeatureRowsForPricing()
            ->filter(fn ($f) => PlanFeatureLabel::shouldListKey((string) $f->key));
        $excluded = $all->filter(fn ($f) => ! (bool) ($f->included ?? false))->values();
        $included = $all->filter(fn ($f) => (bool) ($f->included ?? false))
            ->keyBy(fn ($f) => (string) $f->key);
        $primary = collect();
        foreach ($pricingHighlightFeatureOrder as $key) {
            if ($included->has($key)) {
                $primary->push($included->get($key));
            }
            $included->forget($key);
        }

        return [$primary, $included->values(), $excluded];
    };

    $discountPercentForTerm = function (\App\Models\PlanTerm $t): int {
        return \App\Support\PlanPricing::displayDiscountPercent($t->price, $t->final_price);
    };

    /** Admin {@see \App\Models\Plan::$marketing_badge} keys; labels reuse admin option translations (SSOT with admin form). */
    $pricingMarketingBadgeKeys = ['best_seller', 'popular', 'new', 'limited_offer', 'recommended'];
    $pricingMarketingBadgeLabel = static function (\App\Models\Plan $plan) use ($pricingMarketingBadgeKeys): ?string {
        if (! (bool) $plan->highlight) {
            return null;
        }
        $k = strtolower(trim((string) ($plan->marketing_badge ?? '')));
        if ($k === '' || ! in_array($k, $pricingMarketingBadgeKeys, true)) {
            return null;
        }

        return __('subscriptions.admin_plan_marketing_opt_'.$k);
    };
    $pricingMarketingBadgeRibbonClass = static function (\App\Models\Plan $plan) use ($pricingMarketingBadgeKeys): string {
        $k = strtolower(trim((string) ($plan->marketing_badge ?? '')));
        if (! in_array($k, $pricingMarketingBadgeKeys, true)) {
            return 'from-slate-600 to-slate-800';
        }

        return match ($k) {
            'best_seller' => 'from-amber-500 to-orange-600',
            'popular' => 'from-violet-500 to-purple-600',
            'new' => 'from-emerald-500 to-teal-600',
            'limited_offer' => 'from-rose-500 to-orange-600',
            'recommended' => 'from-indigo-600 to-violet-600',
            default => 'from-slate-600 to-slate-800',
        };
    };

    $pricingPlans = $pricingPlans ?? collect();
    /** Desktop carousel: size cards for up to 3 equal columns (no half-peek of a 4th). */
    $pricingDesktopSlots = max(1, min(3, $pricingPlans->count()));
    $pricingDesktopGapRem = ($pricingDesktopSlots - 1) * 1.25;

    $planSummaryItems = [];
    if (! empty($catalogIncludesInactive)) {
        $planSummaryItems[] = [
            'severity' => 'warning',
            'message' => __('subscriptions.plans_inactive_catalog_note'),
        ];
    }
    if (! empty($pricingCatalogMissesActivePlan)) {
        $planSummaryItems[] = [
            'severity' => 'info',
            'message' => __('subscriptions.active_plan_not_in_catalog'),
        ];
    }
    if (! empty($pricingCatalogUsedUngenderedFallback) && empty($enforceGenderSpecificPlans)) {
        $planSummaryItems[] = [
            'severity' => 'warning',
            'message' => 'We detected a profile-plan matching issue, so all paid plans are shown to avoid a blank catalog.',
        ];
    }
    if (session('success')) {
        $planSummaryItems[] = [
            'severity' => 'info',
            'message' => (string) session('success'),
        ];
    }
    if (session('error')) {
        $planSummaryItems[] = [
            'severity' => 'danger',
            'message' => (string) session('error'),
        ];
    }
    if (! empty($referredCheckoutOffer) && is_array($referredCheckoutOffer)) {
        $pct = (int) ($referredCheckoutOffer['percent_off'] ?? 0);
        $extra = (int) ($referredCheckoutOffer['extra_days'] ?? 0);
        if ($pct > 0 || $extra > 0) {
            $planSummaryItems[] = [
                'severity' => 'info',
                'message' => $pct > 0 && $extra > 0
                    ? __('referrals.plans_invite_offer_percent_and_days', ['percent' => $pct, 'days' => $extra])
                    : ($pct > 0
                        ? __('referrals.plans_invite_offer_percent', ['percent' => $pct])
                        : __('referrals.plans_invite_offer_days', ['days' => $extra])),
            ];
        }
    }
    if (! empty($pricingCatalogInjectedActivePlan)) {
        $planSummaryItems[] = [
            'severity' => 'warning',
            'message' => __('subscriptions.active_plan_not_in_catalog'),
        ];
    }
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

    <div class="relative z-10 mx-auto max-w-7xl px-4 pt-10 sm:px-6 lg:px-8">

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

        @if (! empty($planSummaryItems))
            <div class="mx-auto mt-6 max-w-3xl">
                <x-notification-summary :items="$planSummaryItems" variant="cards" :columns="2" />
            </div>
        @endif

        @if ($pricingPlans->isEmpty())
            <div class="mx-auto mt-14 max-w-md rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm dark:border-slate-700 dark:bg-slate-800">
                @if (! empty($pricingCatalogEmptyDueToMissingGender))
                    <p class="font-semibold text-slate-900 dark:text-white">{{ __('subscriptions.pricing_no_paid_plans') }}</p>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Complete your basic profile gender to unlock matching paid plans.</p>
                    @auth
                        <a href="{{ route('matrimony.profile.wizard.section', ['section' => 'basic-info']) }}"
                           class="mt-4 inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-500">
                            Complete Basic Profile
                        </a>
                    @endauth
                @else
                    <p class="font-semibold text-slate-900 dark:text-white">{{ __('subscriptions.pricing_no_paid_plans') }}</p>
                @endif
            </div>
        @else
            <div
                class="mx-auto mt-10 max-w-7xl"
                x-data="{
                    scrollByAmount: 320,
                    canLeft: false,
                    canRight: true,
                    check() {
                        const el = this.$refs.track;
                        if (!el) return;
                        this.canLeft = el.scrollLeft > 4;
                        this.canRight = el.scrollLeft + el.clientWidth < el.scrollWidth - 4;
                    },
                    move(dir) {
                        const el = this.$refs.track;
                        if (!el) return;
                        el.scrollBy({ left: dir * this.scrollByAmount, behavior: 'smooth' });
                        setTimeout(() => this.check(), 220);
                    }
                }"
                x-init="$nextTick(() => check())"
            >
                <div class="relative">
                    <div class="pointer-events-none absolute inset-y-0 left-0 right-0 z-20 hidden items-center justify-between md:flex">
                        <button
                            type="button"
                            @click="move(-1)"
                            :disabled="!canLeft"
                            class="pointer-events-auto -ml-4 inline-flex h-11 w-11 items-center justify-center rounded-full border border-slate-300 bg-white text-slate-700 shadow-md transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700"
                            aria-label="Scroll plans left"
                        >
                            <span aria-hidden="true">‹</span>
                        </button>
                        <button
                            type="button"
                            @click="move(1)"
                            :disabled="!canRight"
                            class="pointer-events-auto -mr-4 inline-flex h-11 w-11 items-center justify-center rounded-full border border-slate-300 bg-white text-slate-700 shadow-md transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700"
                            aria-label="Scroll plans right"
                        >
                            <span aria-hidden="true">›</span>
                        </button>
                    </div>

                    <div class="mb-4 flex items-center justify-end gap-2 md:hidden">
                    <button
                        type="button"
                        @click="move(-1)"
                        :disabled="!canLeft"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-slate-300 bg-white text-slate-700 shadow-sm transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700"
                        aria-label="Scroll plans left"
                    >
                        <span aria-hidden="true">‹</span>
                    </button>
                    <button
                        type="button"
                        @click="move(1)"
                        :disabled="!canRight"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-slate-300 bg-white text-slate-700 shadow-sm transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700"
                        aria-label="Scroll plans right"
                    >
                        <span aria-hidden="true">›</span>
                    </button>
                </div>

                <div
                    class="no-scrollbar flex snap-x snap-mandatory items-stretch gap-5 overflow-x-auto overflow-y-visible px-1 pb-2 pt-4"
                    x-ref="track"
                    @scroll.debounce.100ms="check()"
                    @resize.window.debounce.150ms="check()"
                >
                <style>
                    @media (min-width: 1024px) {
                        .pricing-plan-card {
                            width: var(--pricing-desktop-w);
                            min-width: var(--pricing-desktop-w);
                        }
                    }
                </style>
                @foreach ($pricingPlans as $plan)
                    @php
                        $slug = strtolower((string) ($plan->slug ?? ''));
                        $isGold = str_starts_with($slug, 'gold');
                        $isCurrent = $plan->id && $eff->id && (int) $plan->id === (int) $eff->id;
                        $visibleTerms = $plan->terms->where('is_visible', true)->sortBy('sort_order')->values();
                        $useTerms = $visibleTerms->isNotEmpty();
                        $defaultKey = (string) ($plan->default_billing_key ?? '');
                        $defaultTermRow = $defaultKey !== ''
                            ? $visibleTerms->firstWhere('billing_key', $defaultKey)
                            : null;
                        $defaultBillingId = $useTerms
                            ? (int) (($defaultTermRow ?? $visibleTerms->first())?->id ?? 0)
                            : 0;
                        [$primaryFeatureRows, $secondaryFeatureRows, $excludedFeatureRows] = $partitionPricingFeatures($plan);
                        $marketingRibbonLabel = $pricingMarketingBadgeLabel($plan);
                        $displayPlanName = preg_replace('/\s*\((male|female)\)\s*$/i', '', (string) $plan->name) ?? (string) $plan->name;
                    @endphp
                    <article
                        class="pricing-plan-card relative flex w-[85vw] min-w-[85vw] snap-start flex-col self-stretch rounded-2xl border bg-white shadow-lg transition-shadow sm:w-[22rem] sm:min-w-[22rem] dark:bg-slate-800/95
                            {{ $isGold
                                ? 'z-10 border-amber-400/90 shadow-xl shadow-amber-500/10 ring-2 ring-amber-400/50 dark:border-amber-500/60 dark:ring-amber-500/40'
                                : 'border-slate-200 dark:border-slate-700' }}
                            {{ $isCurrent ? 'ring-2 ring-indigo-500 dark:ring-indigo-400' : '' }}"
                        style="--pricing-desktop-w: calc((100% - {{ $pricingDesktopGapRem }}rem) / {{ $pricingDesktopSlots }});"
                        @if ($useTerms)
                            x-data="{ selectedBillingId: {{ $defaultBillingId }} }"
                        @endif
                    >
                        @if ($isGold && $marketingRibbonLabel === null)
                            <div class="pointer-events-none absolute -top-3 left-1/2 z-20 max-w-[min(100%-1.5rem,18rem)] -translate-x-1/2 truncate rounded-full bg-gradient-to-r from-amber-500 to-orange-600 px-3 py-1 text-center text-[10px] font-extrabold uppercase tracking-wider text-white shadow-md sm:max-w-[min(100%-2rem,22rem)] sm:px-4 sm:text-xs" title="{{ __('subscriptions.pricing_most_popular') }}">
                                {{ __('subscriptions.pricing_most_popular') }}
                            </div>
                        @elseif ($marketingRibbonLabel !== null)
                            <div
                                class="pointer-events-none absolute -top-3 left-1/2 z-20 max-w-[min(100%-1.5rem,18rem)] -translate-x-1/2 truncate rounded-full bg-gradient-to-r {{ $pricingMarketingBadgeRibbonClass($plan) }} px-3 py-1 text-center text-[10px] font-extrabold uppercase tracking-wider text-white shadow-md sm:max-w-[min(100%-2rem,22rem)] sm:px-4 sm:text-xs"
                                title="{{ __('subscriptions.pricing_marketing_badge_hint', ['label' => $marketingRibbonLabel]) }}"
                            >{{ $marketingRibbonLabel }}</div>
                        @endif

                        @if ($isCurrent)
                            <span class="absolute right-3 top-3 z-20 rounded-full bg-indigo-600 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-white shadow sm:text-xs">
                                {{ __('subscriptions.your_plan_badge') }}
                            </span>
                        @endif

                        <div class="flex h-full min-h-0 flex-1 flex-col px-5 pb-6 pt-8 sm:px-6 sm:pb-7 sm:pt-9">
                            <h2 class="min-h-[2rem] text-xl font-bold text-slate-900 dark:text-white sm:min-h-[2.25rem] sm:text-2xl">
                                {{ $displayPlanName }}
                            </h2>

                            {{-- Equal-height billing toggle zone (spacer when fewer terms). --}}
                            <div class="mt-4 flex min-h-[2.75rem] flex-wrap content-start gap-1.5" @if ($useTerms) role="tablist" aria-label="{{ __('subscriptions.billing_period_label') }}" @endif>
                                @if ($useTerms)
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
                                @else
                                    <span class="rounded-lg border border-indigo-600 bg-indigo-600 px-2.5 py-1 text-[11px] font-semibold text-white sm:text-xs">
                                        <x-plan.duration-label :days="$plan->duration_days" class="pointer-events-none" />
                                    </span>
                                @endif
                            </div>

                            @if ($useTerms)
                                <div class="mt-4 min-h-[5.5rem]">
                                    @foreach ($visibleTerms as $t)
                                        @php
                                            $tList = (float) $t->price;
                                            $tFinal = (float) $t->final_price;
                                            $tDisc = $discountPercentForTerm($t);
                                        @endphp
                                        <div x-show="selectedBillingId === {{ (int) $t->id }}" x-cloak class="space-y-2">
                                            @if ($tDisc > 0)
                                                <span class="inline-flex w-fit rounded-md bg-rose-100 px-2 py-0.5 text-xs font-bold text-rose-800 dark:bg-rose-950/70 dark:text-rose-200">
                                                    {{ __('subscriptions.discount_badge', ['percent' => $tDisc]) }}
                                                </span>
                                            @else
                                                <span class="inline-flex h-[1.375rem] w-fit invisible select-none" aria-hidden="true">0%</span>
                                            @endif
                                            <div class="flex flex-wrap items-end gap-x-3 gap-y-1">
                                                @if ($tDisc > 0 && $tList > $tFinal + 0.004)
                                                    <span class="text-lg text-slate-400 line-through tabular-nums dark:text-slate-500">{{ \App\Support\PlanPricing::formatRupees($tList) }}</span>
                                                @endif
                                                <span class="text-3xl font-extrabold text-slate-900 tabular-nums dark:text-white">
                                                    ₹{{ \App\Support\PlanPricing::formatRupees($tFinal) }}
                                                </span>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                @php
                                    $listPrice = (float) $plan->price;
                                    $finalPrice = (float) $plan->final_price;
                                    $legacyDisc = \App\Support\PlanPricing::displayDiscountPercent($listPrice, $finalPrice);
                                @endphp
                                <div class="mt-4 min-h-[5.5rem] space-y-2">
                                    @if ($legacyDisc > 0)
                                        <span class="inline-flex w-fit rounded-md bg-rose-100 px-2 py-0.5 text-xs font-bold text-rose-800 dark:bg-rose-950/70 dark:text-rose-200">
                                            {{ __('subscriptions.discount_badge', ['percent' => $legacyDisc]) }}
                                        </span>
                                    @else
                                        <span class="inline-flex h-[1.375rem] w-fit invisible select-none" aria-hidden="true">0%</span>
                                    @endif
                                    <div class="flex flex-wrap items-end gap-x-3 gap-y-1">
                                        @if ($legacyDisc > 0 && $listPrice > $finalPrice + 0.004)
                                            <span class="text-lg text-slate-400 line-through tabular-nums dark:text-slate-500">₹{{ \App\Support\PlanPricing::formatRupees($listPrice) }}</span>
                                        @endif
                                        <span class="text-3xl font-extrabold text-slate-900 tabular-nums dark:text-white">
                                            ₹{{ \App\Support\PlanPricing::formatRupees($finalPrice) }}
                                        </span>
                                    </div>
                                </div>
                            @endif

                            <div class="mt-2 flex min-h-0 flex-1 flex-col">
                                @if ($useTerms)
                                    @foreach ($visibleTerms as $termCatalog)
                                        @include('plans.partials.pricing-plan-features', [
                                            'planId' => $plan->id,
                                            'primaryFeatureRows' => $primaryFeatureRows,
                                            'secondaryFeatureRows' => $secondaryFeatureRows,
                                            'excludedFeatureRows' => $excludedFeatureRows,
                                            'quotaBonusPercent' => (int) ($termCatalog->quota_bonus_percent ?? 0),
                                            'durationMultiplier' => \App\Models\PlanTerm::quotaDurationMultiplierFor(
                                                (string) $termCatalog->billing_key,
                                                (int) $termCatalog->duration_days
                                            ),
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
                                        'excludedFeatureRows' => $excludedFeatureRows,
                                        'quotaBonusPercent' => 0,
                                        'durationMultiplier' => 1.0,
                                        'billingDurationType' => null,
                                        'selectedBillingId' => 0,
                                        'wrapInBillingToggle' => false,
                                    ])
                                @endif
                            </div>

                            <div class="mt-auto pt-8">
                                @if (! $plan->is_active)
                                    <span class="inline-flex w-full justify-center rounded-xl bg-slate-100 px-4 py-3.5 text-sm font-medium text-slate-600 dark:bg-slate-900 dark:text-slate-400">
                                        {{ __('subscriptions.plan_not_available_signup') }}
                                    </span>
                                @elseif ($isCurrent)
                                    <span class="inline-flex w-full justify-center rounded-xl bg-slate-200 px-4 py-3.5 text-sm font-bold text-slate-700 dark:bg-slate-700 dark:text-slate-200">
                                        {{ __('subscriptions.pricing_current_plan') }}
                                    </span>
                                @elseif (auth()->check())
                                    <form method="POST" action="{{ route('plans.subscribe') }}" id="plan-subscribe-{{ (int) $plan->id }}">
                                        @csrf
                                        <input type="hidden" name="plan" value="{{ $plan->slug }}" />
                                        @if ($useTerms)
                                            <input type="hidden" name="plan_term_id" x-bind:value="selectedBillingId" />
                                        @endif
                                        <button
                                            type="submit"
                                            class="w-full rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 px-4 py-3.5 text-sm font-bold text-white shadow-lg transition hover:from-indigo-500 hover:to-violet-500 hover:shadow-xl active:scale-[0.98]"
                                        >
                                            {{ __('subscriptions.pricing_cta_upgrade') }}
                                        </button>
                                    </form>
                                @else
                                    <a
                                        href="{{ route('login') }}"
                                        class="flex w-full items-center justify-center rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 px-4 py-3.5 text-sm font-bold text-white shadow-lg transition hover:from-indigo-500 hover:to-violet-500 hover:shadow-xl active:scale-[0.98]"
                                    >
                                        {{ __('subscriptions.pricing_cta_sign_in') }}
                                    </a>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
                </div>
                </div>
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
