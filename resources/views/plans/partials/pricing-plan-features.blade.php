@php
    use App\Support\PlanFeatureLabel;
    /** @var \Illuminate\Support\Collection $primaryFeatureRows */
    /** @var \Illuminate\Support\Collection $secondaryFeatureRows */
    $wrapInBillingToggle = $wrapInBillingToggle ?? true;
@endphp

@if ($wrapInBillingToggle)
    <div x-show="selectedBillingId === {{ (int) $selectedBillingId }}" x-cloak class="pricing-plan-features-for-billing">
@else
    <div class="pricing-plan-features-static">
@endif
    @if ($primaryFeatureRows->isEmpty() && $secondaryFeatureRows->isNotEmpty())
        <ul class="mt-6 flex-1 space-y-2 border-t border-slate-100 pt-5 text-sm dark:border-slate-700" role="list">
            @foreach ($secondaryFeatureRows as $feat)
                <li class="flex gap-2.5 text-slate-700 dark:text-slate-200" role="listitem">
                    <span class="mt-0.5 shrink-0 text-emerald-600/80 dark:text-emerald-400/90" aria-hidden="true">✓</span>
                    <span>
                        <span class="font-medium text-slate-900 dark:text-white">{{ PlanFeatureLabel::label((string) $feat->key) }}</span>
                        <span class="text-slate-600 dark:text-slate-400"> — {{ PlanFeatureLabel::catalogFormatValue((string) $feat->key, (string) $feat->value, (float) $durationMultiplier, $billingDurationType) }}</span>
                    </span>
                </li>
            @endforeach
        </ul>
    @else
        <div
            class="mt-6 flex-1 border-t border-slate-100 pt-5 dark:border-slate-700"
            x-data="{ planFeaturesExpanded: false }"
        >
            @if ($primaryFeatureRows->isNotEmpty())
                <ul class="space-y-2" role="list">
                    @foreach ($primaryFeatureRows as $feat)
                        <li
                            class="flex gap-3 rounded-xl border border-slate-100/90 bg-slate-50/70 px-3 py-2.5 dark:border-slate-700/80 dark:bg-slate-900/35"
                            role="listitem"
                        >
                            <x-plan.feature-highlight-icon :feature-key="(string) $feat->key" class="mt-0.5" />
                            <span class="min-w-0 flex-1 text-sm leading-snug">
                                <span class="font-semibold text-slate-900 dark:text-white">{{ PlanFeatureLabel::label((string) $feat->key) }}</span>
                                <span class="text-slate-600 dark:text-slate-400"> — {{ PlanFeatureLabel::catalogFormatValue((string) $feat->key, (string) $feat->value, (float) $durationMultiplier, $billingDurationType) }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($secondaryFeatureRows->isNotEmpty())
                <button
                    type="button"
                    class="mt-4 flex w-full items-center justify-center gap-1.5 rounded-lg py-2 text-sm font-semibold text-indigo-600 transition hover:text-indigo-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 dark:text-indigo-400 dark:hover:text-indigo-300 dark:focus-visible:ring-offset-slate-900"
                    @click="planFeaturesExpanded = ! planFeaturesExpanded"
                    :aria-expanded="planFeaturesExpanded ? 'true' : 'false'"
                    aria-controls="plan-{{ $planId }}-features-more-{{ (int) $selectedBillingId }}"
                >
                    <span x-show="! planFeaturesExpanded" x-cloak>{{ __('subscriptions.pricing_features_show_more') }}</span>
                    <span x-show="planFeaturesExpanded" x-cloak>{{ __('subscriptions.pricing_features_show_less') }}</span>
                    <svg class="h-4 w-4 shrink-0 transition-transform duration-200" :class="planFeaturesExpanded ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>

                <div
                    id="plan-{{ $planId }}-features-more-{{ (int) $selectedBillingId }}"
                    x-show="planFeaturesExpanded"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 -translate-y-0.5"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    x-cloak
                    class="mt-1 border-t border-slate-100/80 pt-3 dark:border-slate-700/80"
                >
                    <ul class="space-y-1.5 text-sm text-slate-600 dark:text-slate-300" role="list" aria-label="{{ __('subscriptions.pricing_features_extended_list') }}">
                        @foreach ($secondaryFeatureRows as $feat)
                            <li class="flex gap-2.5" role="listitem">
                                <span class="mt-1.5 h-1 shrink-0 w-1 rounded-full bg-emerald-500/80" aria-hidden="true"></span>
                                <span class="min-w-0">
                                    <span class="font-medium text-slate-800 dark:text-slate-100">{{ PlanFeatureLabel::label((string) $feat->key) }}</span>
                                    <span class="text-slate-500 dark:text-slate-400"> — {{ PlanFeatureLabel::catalogFormatValue((string) $feat->key, (string) $feat->value, (float) $durationMultiplier, $billingDurationType) }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif
    </div>
