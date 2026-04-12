@php
    use App\Models\PlanQuotaPolicy;

    $p = $quotaPoliciesForm[$featureKey] ?? [];
    $refresh = PlanQuotaPolicy::normalizeRefreshType((string) ($p['refresh_type'] ?? PlanQuotaPolicy::REFRESH_MONTHLY_30D_IST));
    $limitVal = $p['limit_value'];
    $cap = $p['daily_sub_cap'];
    $grace = (int) ($p['grace_percent_of_plan'] ?? 10);
    $purchasable = ($p['overuse_mode'] ?? PlanQuotaPolicy::OVERUSE_BLOCK) === PlanQuotaPolicy::OVERUSE_PACK;
    $packRupees = isset($p['pack_price_paise']) && $p['pack_price_paise'] !== null
        ? number_format((int) $p['pack_price_paise'] / 100, 2, '.', '')
        : '';
    $packCount = $p['pack_message_count'];
    $packDays = $p['pack_validity_days'];
    $phaseEnabled = filter_var($p['is_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $perDay = filter_var($p['per_day_usage_limit_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $refreshLabels = [
        PlanQuotaPolicy::REFRESH_UNLIMITED => __('subscriptions.chat_quota_phase1_refresh_unlimited'),
        PlanQuotaPolicy::REFRESH_DAILY => __('subscriptions.chat_quota_phase1_refresh_daily'),
        PlanQuotaPolicy::REFRESH_WEEKLY => __('subscriptions.chat_quota_phase1_refresh_weekly'),
        PlanQuotaPolicy::REFRESH_MONTHLY_30D_IST => __('subscriptions.chat_quota_phase1_refresh_monthly'),
        PlanQuotaPolicy::REFRESH_LIFETIME => __('subscriptions.chat_quota_phase1_refresh_lifetime'),
    ];
    $p1Sum = [
        'sep' => __('subscriptions.chat_quota_phase1_sum_sep'),
        'quotaOn' => __('subscriptions.chat_quota_phase1_sum_quota_on'),
        'quotaOff' => __('subscriptions.chat_quota_phase1_sum_quota_off'),
        'refresh' => __('subscriptions.chat_quota_phase1_sum_refresh'),
        'limit' => __('subscriptions.chat_quota_phase1_sum_limit'),
        'limitUnlimited' => __('subscriptions.chat_quota_phase1_sum_limit_unlimited'),
        'grace' => __('subscriptions.chat_quota_phase1_sum_grace'),
        'perDay' => __('subscriptions.chat_quota_phase1_sum_per_day'),
        'dash' => __('subscriptions.chat_quota_phase1_sum_dash'),
        'topup' => __('subscriptions.chat_quota_phase1_sum_topup'),
        'topupDetail' => __('subscriptions.chat_quota_phase1_sum_topup_detail'),
        'topupEnter' => __('subscriptions.chat_quota_phase1_sum_topup_enter'),
    ];
    $alpineInitial = [
        'phaseEnabled' => $phaseEnabled,
        'purchasableIfExhausted' => $purchasable,
        'perDayLimit' => $perDay,
        'refreshType' => $refresh,
        'refreshUnlimited' => PlanQuotaPolicy::REFRESH_UNLIMITED,
        'limitVal' => $limitVal !== null && $limitVal !== '' ? (string) $limitVal : '',
        'graceVal' => (string) $grace,
        'dailyCapVal' => $cap !== null && $cap !== '' ? (string) $cap : '',
        'packPrice' => (string) $packRupees,
        'packMsgs' => $packCount !== null && $packCount !== '' ? (string) $packCount : '',
        'packDays' => $packDays !== null && $packDays !== '' ? (string) $packDays : '',
        'refreshLabels' => $refreshLabels,
        'sum' => $p1Sum,
    ];
@endphp
<div class="rounded-lg border border-amber-300 dark:border-amber-800 bg-amber-50/80 dark:bg-amber-950/25 p-3 space-y-2"
    x-data='window.planQuotaPolicyCard(@json($alpineInitial, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE))'>
    <div>
        <strong class="text-sm text-gray-800 dark:text-gray-100">{{ \App\Support\PlanFeatureLabel::label($featureKey) }}</strong>
        <span class="ml-2 text-xs font-mono text-gray-500 dark:text-gray-400">{{ $featureKey }}</span>
        <p class="text-[11px] leading-snug text-amber-900/85 dark:text-amber-200/85 mt-1 font-mono tabular-nums" x-text="phase1SummaryLine()"></p>
    </div>
    <div class="flex flex-wrap items-center gap-x-5 gap-y-1.5 text-sm">
        <input type="hidden" name="quota_policies[{{ $featureKey }}][is_enabled]" :value="phaseEnabled ? 1 : 0" />
        <label class="inline-flex items-center gap-2 text-gray-800 dark:text-gray-100">
            <input type="checkbox" class="rounded border-gray-300" x-model="phaseEnabled" />
            {{ __('subscriptions.chat_quota_phase1_enabled') }}
        </label>
        <input type="hidden" name="quota_policies[{{ $featureKey }}][per_day_usage_limit_enabled]" :value="perDayLimit ? 1 : 0" />
        <label class="inline-flex items-center gap-2 text-gray-800 dark:text-gray-100">
            <input type="checkbox" class="rounded border-gray-300" x-model="perDayLimit" />
            {{ __('subscriptions.chat_quota_phase1_per_day_limit') }}
        </label>
        <input type="hidden" name="quota_policies[{{ $featureKey }}][purchasable_if_exhausted]" :value="purchasableIfExhausted ? 1 : 0" />
        <label class="inline-flex items-center gap-2 text-gray-800 dark:text-gray-100">
            <input type="checkbox" class="rounded border-gray-300" x-model="purchasableIfExhausted" />
            {{ __('subscriptions.chat_quota_phase1_purchasable_if_exhausted') }}
        </label>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-x-2 gap-y-2 text-sm items-end" :class="perDayLimit ? 'md:grid-cols-4' : 'md:grid-cols-3'">
        <div class="min-w-0 md:col-span-1 sm:col-span-1 col-span-2">
            <label class="block text-[10px] font-medium uppercase tracking-wide text-gray-600 dark:text-gray-400 mb-0.5 truncate" title="{{ __('subscriptions.chat_quota_phase1_refresh') }}">{{ __('subscriptions.chat_quota_phase1_col_refresh') }}</label>
            <select name="quota_policies[{{ $featureKey }}][refresh_type]" x-model="refreshType" class="w-full max-w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm py-1.5" title="{{ __('subscriptions.chat_quota_phase1_refresh') }}">
                <option value="{{ PlanQuotaPolicy::REFRESH_MONTHLY_30D_IST }}">{{ __('subscriptions.chat_quota_phase1_refresh_monthly') }}</option>
                <option value="{{ PlanQuotaPolicy::REFRESH_UNLIMITED }}">{{ __('subscriptions.chat_quota_phase1_refresh_unlimited') }}</option>
                <option value="{{ PlanQuotaPolicy::REFRESH_DAILY }}">{{ __('subscriptions.chat_quota_phase1_refresh_daily') }}</option>
                <option value="{{ PlanQuotaPolicy::REFRESH_WEEKLY }}">{{ __('subscriptions.chat_quota_phase1_refresh_weekly') }}</option>
                <option value="{{ PlanQuotaPolicy::REFRESH_LIFETIME }}">{{ __('subscriptions.chat_quota_phase1_refresh_lifetime') }}</option>
            </select>
        </div>
        <div class="min-w-0">
            <label class="block text-[10px] font-medium uppercase tracking-wide text-gray-600 dark:text-gray-400 mb-0.5 truncate" title="{{ __('subscriptions.chat_quota_phase1_limit') }}">{{ __('subscriptions.chat_quota_phase1_col_limit') }}</label>
            <input type="number" name="quota_policies[{{ $featureKey }}][limit_value]" min="0" step="1"
                x-model="limitVal"
                placeholder="0"
                title="{{ __('subscriptions.chat_quota_phase1_limit') }}"
                class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm py-1.5" />
        </div>
        <div class="min-w-0" x-show="perDayLimit"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100">
            <label class="block text-[10px] font-medium uppercase tracking-wide text-gray-600 dark:text-gray-400 mb-0.5 truncate" title="{{ __('subscriptions.chat_quota_phase1_daily_sub_cap') }}">{{ __('subscriptions.chat_quota_phase1_col_subcap') }}</label>
            <input type="number" name="quota_policies[{{ $featureKey }}][daily_sub_cap]" min="0" step="1"
                x-model="dailyCapVal"
                placeholder="—"
                title="{{ __('subscriptions.chat_quota_phase1_daily_sub_cap') }}"
                :disabled="! perDayLimit"
                class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm py-1.5 disabled:opacity-50" />
        </div>
        <div class="min-w-0">
            <label class="block text-[10px] font-medium uppercase tracking-wide text-gray-600 dark:text-gray-400 mb-0.5 truncate" title="{{ __('subscriptions.chat_quota_phase1_grace_percent') }}">{{ __('subscriptions.chat_quota_phase1_col_grace') }}</label>
            <input type="number" name="quota_policies[{{ $featureKey }}][grace_percent_of_plan]" min="0" max="100" step="1" required
                x-model="graceVal"
                title="{{ __('subscriptions.chat_quota_phase1_grace_percent') }}"
                class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm py-1.5" />
        </div>
    </div>
    <div x-show="purchasableIfExhausted" class="border-t border-amber-200/80 dark:border-amber-800/60 pt-2 mt-1">
        <p class="text-[10px] font-semibold uppercase tracking-wide text-amber-900/90 dark:text-amber-200/90 mb-1.5">{{ __('subscriptions.chat_quota_phase1_pack_heading') }}</p>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 text-sm">
            <div class="min-w-0">
                <label class="block text-[10px] font-medium text-gray-600 dark:text-gray-400 mb-0.5 truncate" title="{{ __('subscriptions.chat_quota_phase1_pack_price') }}">{{ __('subscriptions.chat_quota_phase1_pack_price_short') }}</label>
                <input type="text" inputmode="decimal" name="quota_policies[{{ $featureKey }}][pack_price_rupees]" placeholder="50"
                    x-model="packPrice"
                    title="{{ __('subscriptions.chat_quota_phase1_pack_price') }}"
                    :disabled="! purchasableIfExhausted"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm py-1.5 disabled:opacity-50" />
            </div>
            <div class="min-w-0">
                <label class="block text-[10px] font-medium text-gray-600 dark:text-gray-400 mb-0.5 truncate" title="{{ __('subscriptions.chat_quota_phase1_pack_messages') }}">{{ __('subscriptions.chat_quota_phase1_pack_msgs_short') }}</label>
                <input type="number" name="quota_policies[{{ $featureKey }}][pack_message_count]" min="1" step="1"
                    x-model="packMsgs"
                    placeholder="30"
                    title="{{ __('subscriptions.chat_quota_phase1_pack_messages') }}"
                    :disabled="! purchasableIfExhausted"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm py-1.5 disabled:opacity-50" />
            </div>
            <div class="min-w-0 col-span-2 sm:col-span-1">
                <label class="block text-[10px] font-medium text-gray-600 dark:text-gray-400 mb-0.5 truncate" title="{{ __('subscriptions.chat_quota_phase1_pack_validity') }}">{{ __('subscriptions.chat_quota_phase1_pack_days_short') }}</label>
                <input type="number" name="quota_policies[{{ $featureKey }}][pack_validity_days]" min="1" step="1"
                    x-model="packDays"
                    placeholder="7"
                    title="{{ __('subscriptions.chat_quota_phase1_pack_validity') }}"
                    :disabled="! purchasableIfExhausted"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm py-1.5 disabled:opacity-50" />
            </div>
        </div>
    </div>
</div>
