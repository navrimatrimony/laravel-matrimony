{{-- $revenueSummary from {@see \App\Services\RevenueSummaryService::forSubscriptionResolvedCheckout} — display only. --}}
@php
    /** @var array<string, mixed> $revenueSummary */
@endphp
<div class="mx-auto mb-8 max-w-md rounded-2xl border border-slate-200 bg-white p-5 text-left text-sm text-slate-800 shadow-sm">
    <h2 class="text-base font-bold text-slate-900">{{ __('revenue_summary.payu_title') }}</h2>
    <dl class="mt-4 space-y-2">
        <div class="flex justify-between gap-3">
            <dt>{{ __('revenue_summary.row_plan') }}</dt>
            <dd class="font-semibold tabular-nums">{{ $revenueSummary['base_plan_price_display'] ?? '' }}</dd>
        </div>
        <div class="flex justify-between gap-3">
            <dt>{{ __('revenue_summary.row_coupon_discount') }}</dt>
            <dd class="font-semibold tabular-nums">{{ $revenueSummary['discount_amount_display'] ?? '' }}</dd>
        </div>
        @if (! empty($revenueSummary['coupon_code']))
            <div class="flex justify-between gap-3 text-xs text-slate-600">
                <dt>{{ __('revenue_summary.coupon_code_label') }}</dt>
                <dd class="font-mono font-semibold">{{ $revenueSummary['coupon_code'] }}</dd>
            </div>
        @endif
        <div class="flex justify-between gap-3">
            <dt>{{ __('revenue_summary.row_wallet') }}</dt>
            <dd class="font-semibold tabular-nums">{{ $revenueSummary['wallet_used_display'] ?? '' }}</dd>
        </div>
        <div class="border-t border-slate-200 pt-2 flex justify-between gap-3 text-base">
            <dt class="font-bold">{{ __('revenue_summary.row_final') }}</dt>
            <dd class="font-extrabold tabular-nums text-indigo-700">{{ $revenueSummary['final_price_display'] ?? '' }}</dd>
        </div>
    </dl>
    @if (empty($revenueSummary['subscription_checkout_uses_wallet']))
        <p class="mt-3 text-xs text-slate-500">{{ __('revenue_summary.wallet_not_used_checkout') }}</p>
    @endif
    @if (! empty($revenueSummary['coupon_checkout_extras']))
        <ul class="mt-3 list-disc space-y-1 pl-5 text-xs text-slate-600">
            @foreach ($revenueSummary['coupon_checkout_extras'] as $row)
                <li>{{ $row['display'] ?? '' }}</li>
            @endforeach
        </ul>
    @endif
</div>
