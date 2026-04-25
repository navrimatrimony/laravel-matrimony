{{--
    $quotaSummary from {@see \App\Services\QuotaEngineService::getUserQuotaSummary} — display only, no quota math.
--}}
@php
    /** @var array<string, mixed>|null $quotaSummary */
@endphp
@if ($quotaSummary === null)
    <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
        {{ __('user_plan.no_profile_for_quota') }}
    </div>
@else
    <div class="space-y-8">
        @if (! empty($quotaSummary['bypass']))
            <div class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-100">
                {{ __('user_plan.bypass_note') }}
            </div>
        @endif

        <section>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-3">{{ __('user_plan.plan_section') }}</h2>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('user_plan.plan_name') }}</dt>
                    <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $quotaSummary['plan_name'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('user_plan.started_at') }}</dt>
                    <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $quotaSummary['subscription_started_at_display'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('user_plan.expires_at') }}</dt>
                    <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $quotaSummary['expires_at_display'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('user_plan.grace_ends_at') }}</dt>
                    <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $quotaSummary['grace_ends_at_display'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('user_plan.plan_grace_period_days') }}</dt>
                    <dd class="font-medium text-gray-900 dark:text-gray-100">{{ (int) ($quotaSummary['plan_grace_period_days'] ?? 0) }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('user_plan.plan_carry_window_days') }}</dt>
                    <dd class="font-medium text-gray-900 dark:text-gray-100">
                        @if (($quotaSummary['plan_carry_window_days'] ?? null) === null)
                            {{ __('user_plan.plan_carry_window_not_set') }}
                        @else
                            {{ (int) $quotaSummary['plan_carry_window_days'] }}
                        @endif
                    </dd>
                </div>
                @if (! empty($quotaSummary['subscription_row_status']))
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('user_plan.subscription_row_status') }}</dt>
                        <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $quotaSummary['subscription_row_status'] }}</dd>
                    </div>
                @endif
            </dl>
        </section>

        <section>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">{{ __('user_plan.state_section') }}</h2>
            <p class="text-sm text-gray-800 dark:text-gray-200">{{ $quotaSummary['subscription_state_label'] ?? '' }}</p>
        </section>

        @if (! empty($quotaSummary['carry_forward_items']))
            <section>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">{{ __('user_plan.carry_section') }}</h2>
                <ul class="list-disc list-inside text-sm text-gray-800 dark:text-gray-200 space-y-1">
                    @foreach ($quotaSummary['carry_forward_items'] as $cf)
                        <li>{{ __('user_plan.carry_line', ['feature' => $cf['label'], 'carry' => $cf['carry']]) }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-3">{{ __('user_plan.usage_section') }}</h2>
            @if (empty($quotaSummary['rows']))
                <p class="text-sm text-gray-600 dark:text-gray-400">—</p>
            @else
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/60 text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-4 py-2">{{ __('user_plan.table_feature') }}</th>
                                <th class="px-4 py-2">{{ __('user_plan.table_total') }}</th>
                                <th class="px-4 py-2">{{ __('user_plan.table_used') }}</th>
                                <th class="px-4 py-2">{{ __('user_plan.table_remaining') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($quotaSummary['rows'] as $row)
                                <tr>
                                    <td class="px-4 py-2 font-mono text-xs text-gray-700 dark:text-gray-300">{{ $row['feature_key'] ?? '' }}</td>
                                    <td class="px-4 py-2 tabular-nums">{{ $row['total_allocated'] ?? '—' }}</td>
                                    <td class="px-4 py-2 tabular-nums">{{ $row['used'] ?? '' }}</td>
                                    <td class="px-4 py-2 tabular-nums">{{ $row['remaining'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endif
