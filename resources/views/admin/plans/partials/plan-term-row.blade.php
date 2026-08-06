@php
    use App\Support\PlanPricing;

    $forTemplate = $forTemplate ?? false;
    $bk = (string) ($row['billing_key'] ?? \App\Models\PlanTerm::BILLING_MONTHLY);
    $priceOld = old('term_rows.'.$i.'.price');
    $priceShow = PlanPricing::normalizeMoney(
        $priceOld !== null && $priceOld !== '' ? $priceOld : ($row['price'] ?? 0)
    );
    $sellingOld = old('term_rows.'.$i.'.selling_price');
    $sellingShow = PlanPricing::normalizeMoney(
        $sellingOld !== null && $sellingOld !== ''
            ? $sellingOld
            : ($row['selling_price'] ?? $priceShow)
    );
    $discShow = PlanPricing::displayDiscountPercent($priceShow, $sellingShow);
    $quotaBonusOld = old('term_rows.'.$i.'.quota_bonus_percent');
    $quotaBonusShow = $quotaBonusOld !== null && $quotaBonusOld !== '' ? $quotaBonusOld : ($row['quota_bonus_percent'] ?? 0);
    $visOld = old('term_rows.'.$i.'.is_visible');
    $visChecked = $visOld !== null
        ? filter_var($visOld, FILTER_VALIDATE_BOOLEAN) || (string) $visOld === '1'
        : (bool) ($row['is_visible'] ?? false);
    $defaultRadioChecked = $forTemplate
        ? false
        : (string) old('default_billing_key', $defaultBillingKeyInitial ?? $bk) === (string) $bk;
    $moneyInputClass = 'js-plan-int-money w-full max-w-[7.5rem] rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm tabular-nums py-2';
@endphp
<div data-plan-term-row class="rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-gray-800 p-3 space-y-3">
    <div class="flex flex-wrap items-end gap-x-3 gap-y-2">
        <div class="min-w-[8.5rem]">
            <span class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('subscriptions.admin_billing_period_column') }}</span>
            <select name="term_rows[{{ $i }}][billing_key]" required
                class="js-plan-billing-key-select w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm relative z-[50]">
                @foreach ($presetKeys as $opt)
                    <option value="{{ $opt }}" @selected($bk === $opt)>{{ __('subscriptions.billing_'.$opt) }}</option>
                @endforeach
            </select>
            @if ($bk === \App\Models\PlanTerm::BILLING_LIFETIME)
                <p class="text-[10px] text-gray-500 mt-0.5">{{ __('subscriptions.admin_billing_lifetime_note') }}</p>
            @endif
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('subscriptions.admin_plan_mrp_label') }}</label>
            <input type="text" inputmode="numeric" pattern="[0-9]*" name="term_rows[{{ $i }}][price]" required
                value="{{ (int) $priceShow }}"
                autocomplete="off"
                data-plan-mrp
                class="js-plan-mrp {{ $moneyInputClass }}" />
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('subscriptions.admin_plan_selling_price_label') }}</label>
            <input type="text" inputmode="numeric" pattern="[0-9]*" name="term_rows[{{ $i }}][selling_price]" required
                value="{{ (int) $sellingShow }}"
                autocomplete="off"
                data-plan-selling
                class="js-plan-selling {{ $moneyInputClass }}" />
        </div>
        <div class="min-w-[4.5rem] pb-2">
            <span class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ __('subscriptions.admin_plan_discount_display_label') }}</span>
            <p class="js-plan-discount-display text-sm font-bold tabular-nums text-rose-600 dark:text-rose-400 leading-8"
                data-empty-label="—">
                @if ($discShow > 0)
                    {{ __('subscriptions.discount_badge', ['percent' => $discShow]) }}
                @else
                    —
                @endif
            </p>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1" title="{{ __('subscriptions.admin_plan_quota_bonus_percent_label') }}">{{ __('subscriptions.admin_plan_bonus_short_label') }}</label>
            <input type="text" inputmode="numeric" pattern="[0-9]*" name="term_rows[{{ $i }}][quota_bonus_percent]"
                value="{{ (int) $quotaBonusShow }}"
                autocomplete="off"
                class="js-plan-int-pct w-[4.25rem] rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm tabular-nums py-2" />
        </div>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 pb-1.5">
            <label class="inline-flex items-center gap-2 cursor-pointer text-xs text-gray-700 dark:text-gray-300">
                <input type="radio" name="default_billing_key" class="js-plan-default-radio rounded-full border-gray-300 text-indigo-600 focus:ring-indigo-500" value="{{ $bk }}" @checked($defaultRadioChecked) />
                <span>{{ __('subscriptions.admin_billing_default_catalog_tab') }}</span>
            </label>
            <input type="hidden" name="term_rows[{{ $i }}][is_visible]" value="0" />
            <label class="inline-flex items-center gap-2 cursor-pointer text-xs text-gray-700 dark:text-gray-300">
                <input type="checkbox" name="term_rows[{{ $i }}][is_visible]" value="1" class="rounded border-gray-300" @checked($visChecked) />
                <span>{{ __('subscriptions.admin_billing_show_public') }}</span>
            </label>
            <button type="button" data-plan-term-row-remove class="text-xs font-semibold text-red-600 hover:underline">{{ __('subscriptions.admin_remove_billing_period') }}</button>
        </div>
    </div>
</div>
