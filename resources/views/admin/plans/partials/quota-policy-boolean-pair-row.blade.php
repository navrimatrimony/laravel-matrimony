@php
    use App\Models\PlanQuotaPolicy;
    use App\Support\PlanFeatureLabel;
    use App\Support\PlanQuotaPolicyKeys;
@endphp
<div class="rounded-lg border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800/80 p-4">
    <div class="flex flex-col sm:flex-row sm:flex-nowrap sm:items-center gap-6 sm:gap-10">
        @foreach (PlanQuotaPolicyKeys::adminSimpleBooleanToggleKeys() as $featureKey)
            @php
                $p = $quotaPoliciesForm[$featureKey] ?? [];
                $enabled = filter_var($p['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            @endphp
            <div class="min-w-0 sm:flex-1">
                <input type="hidden" name="quota_policies[{{ $featureKey }}][refresh_type]" value="{{ PlanQuotaPolicy::REFRESH_MONTHLY_30D_IST }}" />
                <input type="hidden" name="quota_policies[{{ $featureKey }}][limit_value]" value="0" />
                <input type="hidden" name="quota_policies[{{ $featureKey }}][per_day_usage_limit_enabled]" value="0" />
                <input type="hidden" name="quota_policies[{{ $featureKey }}][purchasable_if_exhausted]" value="0" />
                <input type="hidden" name="quota_policies[{{ $featureKey }}][is_enabled]" value="0" />
                <label class="inline-flex items-start gap-2.5 cursor-pointer text-sm text-gray-800 dark:text-gray-100">
                    <input type="checkbox" name="quota_policies[{{ $featureKey }}][is_enabled]" value="1" class="mt-0.5 shrink-0 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" @checked($enabled) />
                    <span class="font-medium leading-snug">{{ PlanFeatureLabel::label($featureKey) }}</span>
                </label>
            </div>
        @endforeach
    </div>
</div>
