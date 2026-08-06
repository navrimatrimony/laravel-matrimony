@php
    use App\Models\PlanQuotaPolicy;

    $p = $quotaPoliciesForm[$featureKey] ?? [];
    $refresh = PlanQuotaPolicy::normalizeRefreshType((string) ($p['refresh_type'] ?? PlanQuotaPolicy::REFRESH_MONTHLY_30D_IST));
    $normNonNegIntStr = static function ($v): string {
        if ($v === null || $v === '') {
            return '';
        }
        if (! is_numeric($v)) {
            return '';
        }

        return (string) max(0, (int) round((float) $v));
    };
    $normPackIntStr = static function ($v): string {
        if ($v === null || $v === '') {
            return '';
        }
        if (! is_numeric($v)) {
            return '';
        }
        $n = (int) round((float) $v);

        return $n >= 1 ? (string) $n : '';
    };
    $limitVal = $p['limit_value'];
    $cap = $p['daily_sub_cap'];
    $purchasable = ($p['overuse_mode'] ?? PlanQuotaPolicy::OVERUSE_BLOCK) === PlanQuotaPolicy::OVERUSE_PACK;
    $packRupees = isset($p['pack_price_paise']) && $p['pack_price_paise'] !== null
        ? (string) max(0, (int) round(((int) $p['pack_price_paise']) / 100))
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
        // Legacy Total / Plan duration lang keys unused in select; kept in lang files for harmless lookups.
    ];
    $p1Sum = [
        'sep' => __('subscriptions.chat_quota_phase1_sum_sep'),
        'quotaOn' => __('subscriptions.chat_quota_phase1_sum_quota_on'),
        'quotaOff' => __('subscriptions.chat_quota_phase1_sum_quota_off'),
        'refresh' => __('subscriptions.chat_quota_phase1_sum_refresh'),
        'limit' => __('subscriptions.chat_quota_phase1_sum_limit'),
        'limitUnlimited' => __('subscriptions.chat_quota_phase1_sum_limit_unlimited'),
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
        'limitVal' => $normNonNegIntStr($limitVal),
        'dailyCapVal' => $normNonNegIntStr($cap),
        'packPrice' => (string) $packRupees,
        'packMsgs' => $normPackIntStr($packCount),
        'packDays' => $normPackIntStr($packDays),
        'refreshLabels' => $refreshLabels,
        'sum' => $p1Sum,
    ];
    /** Keep in sync with {@see \App\Models\Plan::PRICING_CATALOG_UI_HIDDEN_KEYS} (public pricing projection). */
    $pricingCatalogUiHiddenKeys = [
        'photo_blur_limit',
        'chat_images',
        'chat_image_messages',
        'whatsapp_button',
        'biodata_export_limit',
        'biodata_premium_templates',
    ];
    $intFieldClass = 'w-full max-w-[5.5rem] rounded-md border-slate-300 dark:border-slate-600 dark:bg-slate-900 dark:text-white text-sm font-semibold tabular-nums py-2 px-2.5';
@endphp
<div class="rounded-xl border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800/90 shadow-sm overflow-hidden"
    x-data='window.planQuotaPolicyCard(@json($alpineInitial, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE))'>
    <div class="flex gap-0">
        <div class="w-1 shrink-0 bg-indigo-500" aria-hidden="true"></div>
        <div class="min-w-0 flex-1 px-3.5 py-3 space-y-2.5">
            <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1.5">
                <div class="min-w-0 flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                    <strong class="text-base font-semibold text-slate-900 dark:text-white leading-snug">{{ \App\Support\PlanFeatureLabel::catalogLabelForPricing($featureKey, ['refresh_type' => $refresh, 'is_enabled' => $phaseEnabled]) }}</strong>
                    <span class="text-xs font-mono text-slate-400 dark:text-slate-500">{{ $featureKey }}</span>
                    @if (in_array($featureKey, $pricingCatalogUiHiddenKeys, true))
                        <span
                            class="inline-flex shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-600 dark:bg-slate-700 dark:text-slate-300"
                            title="{{ __('subscriptions.admin_quota_hidden_from_pricing_hint') }}"
                        >{{ __('subscriptions.admin_quota_hidden_from_pricing_badge') }}</span>
                    @endif
                </div>
                <span
                    class="inline-flex shrink-0 items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold tabular-nums"
                    :class="phaseEnabled
                        ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200'
                        : 'border-slate-200 bg-slate-50 text-slate-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-400'"
                    x-text="phase1SummaryLine()"
                ></span>
            </div>

            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-slate-800 dark:text-slate-100">
                <input type="hidden" name="quota_policies[{{ $featureKey }}][is_enabled]" :value="phaseEnabled ? 1 : 0" />
                <label class="inline-flex items-center gap-2 cursor-pointer font-medium">
                    <input type="checkbox" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" x-model="phaseEnabled" />
                    {{ __('subscriptions.chat_quota_phase1_enabled') }}
                </label>
                <input type="hidden" name="quota_policies[{{ $featureKey }}][per_day_usage_limit_enabled]" :value="perDayLimit ? 1 : 0" />
                <label class="inline-flex items-center gap-2 cursor-pointer font-medium">
                    <input type="checkbox" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" x-model="perDayLimit" />
                    {{ __('subscriptions.chat_quota_phase1_per_day_limit') }}
                </label>
                <input type="hidden" name="quota_policies[{{ $featureKey }}][purchasable_if_exhausted]" :value="purchasableIfExhausted ? 1 : 0" />
                <label class="inline-flex items-center gap-2 cursor-pointer font-medium">
                    <input type="checkbox" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" x-model="purchasableIfExhausted" />
                    {{ __('subscriptions.chat_quota_phase1_purchasable_if_exhausted') }}
                </label>
            </div>

            <div class="flex flex-wrap items-end gap-x-3 gap-y-2">
                <div class="min-w-[11rem] max-w-[16rem] flex-1">
                    <label class="block text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-1" title="{{ __('subscriptions.chat_quota_phase1_refresh') }}">{{ __('subscriptions.chat_quota_phase1_col_refresh') }}</label>
                    <select name="quota_policies[{{ $featureKey }}][refresh_type]" x-model="refreshType" class="w-full rounded-md border-slate-300 dark:border-slate-600 dark:bg-slate-900 dark:text-white text-sm py-2" title="{{ __('subscriptions.chat_quota_phase1_refresh') }}">
                        <option value="{{ PlanQuotaPolicy::REFRESH_MONTHLY_30D_IST }}">{{ __('subscriptions.chat_quota_phase1_refresh_monthly') }}</option>
                        <option value="{{ PlanQuotaPolicy::REFRESH_UNLIMITED }}">{{ __('subscriptions.chat_quota_phase1_refresh_unlimited') }}</option>
                        <option value="{{ PlanQuotaPolicy::REFRESH_DAILY }}">{{ __('subscriptions.chat_quota_phase1_refresh_daily') }}</option>
                        <option value="{{ PlanQuotaPolicy::REFRESH_WEEKLY }}">{{ __('subscriptions.chat_quota_phase1_refresh_weekly') }}</option>
                        <option value="{{ PlanQuotaPolicy::REFRESH_LIFETIME }}">{{ __('subscriptions.chat_quota_phase1_refresh_lifetime') }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-1" title="{{ __('subscriptions.chat_quota_phase1_limit') }}">{{ __('subscriptions.chat_quota_phase1_col_limit') }}</label>
                    <input type="text" inputmode="numeric" pattern="[0-9]*" name="quota_policies[{{ $featureKey }}][limit_value]"
                        x-model="limitVal"
                        @input="coerceNonNegIntField('limitVal')"
                        placeholder="0"
                        autocomplete="off"
                        title="{{ __('subscriptions.chat_quota_phase1_limit') }}"
                        class="{{ $intFieldClass }}" />
                </div>
                <div x-show="perDayLimit"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100">
                    <label class="block text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-1" title="{{ __('subscriptions.chat_quota_phase1_daily_sub_cap') }}">{{ __('subscriptions.chat_quota_phase1_col_subcap') }}</label>
                    <input type="text" inputmode="numeric" pattern="[0-9]*" name="quota_policies[{{ $featureKey }}][daily_sub_cap]"
                        x-model="dailyCapVal"
                        @input="coerceNonNegIntField('dailyCapVal')"
                        placeholder="—"
                        autocomplete="off"
                        title="{{ __('subscriptions.chat_quota_phase1_daily_sub_cap') }}"
                        :disabled="! perDayLimit"
                        class="{{ $intFieldClass }} disabled:opacity-50" />
                </div>
            </div>

            <div x-show="purchasableIfExhausted" class="border-t border-slate-100 dark:border-slate-700 pt-2.5">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-2">{{ __('subscriptions.chat_quota_phase1_pack_heading') }}</p>
                <div class="flex flex-wrap items-end gap-x-3 gap-y-2">
                    <div>
                        <label class="block text-[11px] font-medium text-slate-500 dark:text-slate-400 mb-1 truncate" title="{{ __('subscriptions.chat_quota_phase1_pack_price') }}">{{ __('subscriptions.chat_quota_phase1_pack_price_short') }}</label>
                        <input type="text" inputmode="numeric" pattern="[0-9]*" name="quota_policies[{{ $featureKey }}][pack_price_rupees]" placeholder="50"
                            x-model="packPrice"
                            @input="coerceNonNegIntField('packPrice')"
                            title="{{ __('subscriptions.chat_quota_phase1_pack_price') }}"
                            autocomplete="off"
                            :disabled="! purchasableIfExhausted"
                            class="{{ $intFieldClass }} disabled:opacity-50" />
                    </div>
                    <div>
                        <label class="block text-[11px] font-medium text-slate-500 dark:text-slate-400 mb-1 truncate" title="{{ __('subscriptions.chat_quota_phase1_pack_messages') }}">{{ __('subscriptions.chat_quota_phase1_pack_msgs_short') }}</label>
                        <input type="text" inputmode="numeric" pattern="[0-9]*" name="quota_policies[{{ $featureKey }}][pack_message_count]"
                            x-model="packMsgs"
                            @input="coercePackIntField('packMsgs')"
                            placeholder="30"
                            autocomplete="off"
                            title="{{ __('subscriptions.chat_quota_phase1_pack_messages') }}"
                            :disabled="! purchasableIfExhausted"
                            class="{{ $intFieldClass }} disabled:opacity-50" />
                    </div>
                    <div>
                        <label class="block text-[11px] font-medium text-slate-500 dark:text-slate-400 mb-1 truncate" title="{{ __('subscriptions.chat_quota_phase1_pack_validity') }}">{{ __('subscriptions.chat_quota_phase1_pack_days_short') }}</label>
                        <input type="text" inputmode="numeric" pattern="[0-9]*" name="quota_policies[{{ $featureKey }}][pack_validity_days]"
                            x-model="packDays"
                            @input="coercePackIntField('packDays')"
                            placeholder="7"
                            autocomplete="off"
                            title="{{ __('subscriptions.chat_quota_phase1_pack_validity') }}"
                            :disabled="! purchasableIfExhausted"
                            class="{{ $intFieldClass }} disabled:opacity-50" />
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
