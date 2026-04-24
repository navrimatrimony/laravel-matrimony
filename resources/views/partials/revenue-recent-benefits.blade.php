{{-- $recentBenefits from {@see \App\Services\RevenueSummaryService::recentBenefitsForMember} --}}
@php
    /** @var array<string, mixed> $recentBenefits */
    $purchases = $recentBenefits['subscription_purchases'] ?? [];
    $referrals = $recentBenefits['referral_rewards_as_referrer'] ?? [];
@endphp
<section class="mt-10 border-t border-gray-200 pt-8 dark:border-gray-700">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('revenue_summary.recent_benefits_title') }}</h2>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('revenue_summary.recent_subheading') }}</p>

    @if ($purchases === [] && $referrals === [])
        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('revenue_summary.recent_none') }}</p>
    @else
        @if ($purchases !== [])
            <div class="mt-4 overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-gray-900/60 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2">{{ __('revenue_summary.recent_col_when') }}</th>
                            <th class="px-3 py-2">{{ __('revenue_summary.recent_col_plan') }}</th>
                            <th class="px-3 py-2">{{ __('revenue_summary.recent_col_coupon') }}</th>
                            <th class="px-3 py-2">{{ __('revenue_summary.recent_col_discount') }}</th>
                            <th class="px-3 py-2">{{ __('revenue_summary.recent_col_carry') }}</th>
                            <th class="px-3 py-2">{{ __('revenue_summary.recent_col_coupon_extra') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($purchases as $row)
                            <tr class="align-top">
                                <td class="px-3 py-2 whitespace-nowrap text-gray-700 dark:text-gray-300">{{ $row['starts_at_display'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ $row['plan_name'] ?? '—' }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $row['coupon_code'] ?? '—' }}</td>
                                <td class="px-3 py-2 tabular-nums">{{ $row['coupon_discount_display'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-xs text-gray-700 dark:text-gray-300">
                                    @if (! empty($row['carry_quota_lines']))
                                        <ul class="list-disc list-inside space-y-0.5">
                                            @foreach ($row['carry_quota_lines'] as $line)
                                                <li>{{ $line['display'] ?? '' }}</li>
                                            @endforeach
                                        </ul>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-xs text-gray-700 dark:text-gray-300">
                                    {{ $row['coupon_applied_snippet']['display'] ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($referrals !== [])
            <h3 class="mt-8 text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('revenue_summary.recent_referral_heading') }}</h3>
            <div class="mt-3 overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-gray-900/60 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2">{{ __('revenue_summary.recent_referral_col_when') }}</th>
                            <th class="px-3 py-2">{{ __('revenue_summary.recent_referral_col_action') }}</th>
                            <th class="px-3 py-2">{{ __('revenue_summary.recent_referral_col_days') }}</th>
                            <th class="px-3 py-2">{{ __('revenue_summary.recent_referral_col_features') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($referrals as $r)
                            <tr>
                                <td class="px-3 py-2 whitespace-nowrap">{{ $r['created_at_display'] ?? '' }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $r['action_type'] ?? '' }}</td>
                                <td class="px-3 py-2 tabular-nums">{{ (int) ($r['bonus_days'] ?? 0) }}</td>
                                <td class="px-3 py-2 text-xs">
                                    @if (! empty($r['feature_bonus']) && is_array($r['feature_bonus']))
                                        @foreach ($r['feature_bonus'] as $fk => $fv)
                                            <div><span class="font-mono">{{ $fk }}</span>: {{ (int) $fv }}</div>
                                        @endforeach
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</section>
