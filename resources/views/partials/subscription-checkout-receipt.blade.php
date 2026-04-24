{{-- Session flash: subscription_checkout_receipt from {@see \App\Services\RevenueSummaryService::forCompletedSubscriptionPayu} --}}
@php
    /** @var array<string, mixed> $receipt */
    $receipt = session('subscription_checkout_receipt', []);
@endphp
@if (is_array($receipt) && $receipt !== [])
    <div data-flash-dismissible data-flash-auto-ms="20000" role="status" class="relative z-40 mx-auto max-w-2xl px-4 pt-4">
        <div class="flex items-start gap-3 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-950 shadow-sm dark:border-indigo-800 dark:bg-indigo-950/40 dark:text-indigo-100">
            <div class="min-w-0 flex-1 space-y-3 leading-relaxed">
                <p class="font-semibold">{{ __('revenue_summary.receipt_title') }}</p>
                <dl class="space-y-1 text-xs sm:text-sm">
                    <div class="flex justify-between gap-3">
                        <dt>{{ __('revenue_summary.row_plan') }}</dt>
                        <dd class="tabular-nums font-medium">{{ $receipt['base_plan_price_display'] ?? '' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt>{{ __('revenue_summary.row_coupon_discount') }}</dt>
                        <dd class="tabular-nums font-medium">{{ $receipt['discount_amount_display'] ?? '' }}</dd>
                    </div>
                    @if (! empty($receipt['coupon_code']))
                        <div class="flex justify-between gap-3">
                            <dt>{{ __('revenue_summary.coupon_code_label') }}</dt>
                            <dd class="font-mono font-medium">{{ $receipt['coupon_code'] }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between gap-3">
                        <dt>{{ __('revenue_summary.row_wallet') }}</dt>
                        <dd class="tabular-nums font-medium">{{ $receipt['wallet_used_display'] ?? '' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3 border-t border-indigo-200/80 pt-1 font-semibold dark:border-indigo-800/80">
                        <dt>{{ __('revenue_summary.row_final') }}</dt>
                        <dd class="tabular-nums">{{ $receipt['final_price_display'] ?? '' }}</dd>
                    </div>
                </dl>
                @if (! empty($receipt['coupon_checkout_extras']))
                    <ul class="list-disc space-y-1 pl-4 text-xs text-indigo-900/90 dark:text-indigo-100/90">
                        @foreach ($receipt['coupon_checkout_extras'] as $row)
                            <li>{{ $row['display'] ?? '' }}</li>
                        @endforeach
                    </ul>
                @endif
                @if (! empty($receipt['bonus_quota_added']))
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-800 dark:text-indigo-200">{{ __('revenue_summary.receipt_carry_title') }}</p>
                        <ul class="mt-1 list-disc space-y-0.5 pl-4 text-xs">
                            @foreach ($receipt['bonus_quota_added'] as $line)
                                <li>{{ $line['display'] ?? '' }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if (! empty($receipt['coupon_applied_meta']['display']))
                    <p class="text-xs"><span class="font-semibold">{{ __('revenue_summary.receipt_coupon_meta_title') }}:</span> {{ $receipt['coupon_applied_meta']['display'] }}</p>
                @endif
                @if (! empty($receipt['referral_purchase_ledger']['display']))
                    <p class="text-xs"><span class="font-semibold">{{ __('revenue_summary.receipt_referral_title') }}:</span> {{ $receipt['referral_purchase_ledger']['display'] }}</p>
                @endif
            </div>
            <button type="button" data-flash-close class="shrink-0 rounded-lg px-2 py-1 text-xs font-semibold text-indigo-800 hover:bg-indigo-100 dark:text-indigo-200 dark:hover:bg-indigo-900/60" aria-label="{{ __('common.dismiss') }}">×</button>
        </div>
    </div>
@endif
