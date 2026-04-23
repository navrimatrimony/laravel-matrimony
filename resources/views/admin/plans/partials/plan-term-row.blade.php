@php
    $forTemplate = $forTemplate ?? false;
    $bk = (string) ($row['billing_key'] ?? \App\Models\PlanTerm::BILLING_MONTHLY);
    $priceOld = old('term_rows.'.$i.'.price');
    $priceShow = $priceOld !== null && $priceOld !== '' ? $priceOld : ($row['price'] ?? 0);
    $discOld = old('term_rows.'.$i.'.discount_percent');
    $discShow = $discOld !== null && $discOld !== '' ? $discOld : ($row['discount_percent'] ?? '');
    $visOld = old('term_rows.'.$i.'.is_visible');
    $visChecked = $visOld !== null
        ? filter_var($visOld, FILTER_VALIDATE_BOOLEAN) || (string) $visOld === '1'
        : (bool) ($row['is_visible'] ?? false);
    $defaultRadioChecked = $forTemplate
        ? false
        : (string) old('default_billing_key', $defaultBillingKeyInitial ?? $bk) === (string) $bk;
@endphp
<div data-plan-term-row class="rounded-md border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 p-3 grid grid-cols-1 sm:grid-cols-12 gap-3 items-end">
    <div class="sm:col-span-3">
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
    <div class="sm:col-span-2">
        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('subscriptions.admin_plan_catalog_price_label') }}</label>
        <input type="number" name="term_rows[{{ $i }}][price]" min="0" step="0.01" required
            value="{{ $priceShow }}"
            class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
    </div>
    <div class="sm:col-span-2">
        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('subscriptions.admin_plan_discount_percent_label') }}</label>
        <input type="number" name="term_rows[{{ $i }}][discount_percent]" min="0" max="100" step="1" placeholder="—"
            value="{{ $discShow }}"
            class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
    </div>
    <div class="sm:col-span-2 flex items-end pb-0.5">
        <label class="inline-flex items-center gap-2 cursor-pointer text-xs text-gray-700 dark:text-gray-300">
            <input type="radio" name="default_billing_key" class="js-plan-default-radio rounded-full border-gray-300 text-indigo-600 focus:ring-indigo-500" value="{{ $bk }}" @checked($defaultRadioChecked) />
            <span>{{ __('subscriptions.admin_billing_default_catalog_tab') }}</span>
        </label>
    </div>
    <div class="sm:col-span-2 flex items-center gap-2">
        <input type="hidden" name="term_rows[{{ $i }}][is_visible]" value="0" />
        <label class="inline-flex items-center gap-2 cursor-pointer text-sm text-gray-700 dark:text-gray-300">
            <input type="checkbox" name="term_rows[{{ $i }}][is_visible]" value="1" class="rounded border-gray-300" @checked($visChecked) />
            <span>{{ __('subscriptions.admin_billing_show_public') }}</span>
        </label>
    </div>
    <div class="sm:col-span-1 flex items-end justify-end pb-1">
        <button type="button" data-plan-term-row-remove class="text-xs font-semibold text-red-600 hover:underline">{{ __('subscriptions.admin_remove_billing_period') }}</button>
    </div>
</div>
