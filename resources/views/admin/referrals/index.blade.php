@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('admin_monetization.referrals_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('admin_monetization.referrals_intro') }}</p>
        </div>
    </div>

    @if (session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200">
            {{ session('error') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
            <ul class="list-disc pl-5 space-y-1">
                @foreach ($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php
        $tabs = [
            'engine' => 'Referral Engine',
            'reward-plans' => 'Reward Plans',
            'reports' => 'Reports',
            'review' => 'Review queue'.(($reviewQueueCount ?? 0) > 0 ? ' ('.$reviewQueueCount.')' : ''),
            'supreme' => __('admin_monetization.referral_tab_supreme'),
            'audit' => 'Audit',
        ];
        $fraudFlagLabels = [
            \App\Services\ReferralService::FRAUD_SAME_MOBILE => __('admin_monetization.fraud_flag_same_mobile'),
            \App\Services\ReferralService::FRAUD_CIRCULAR => __('admin_monetization.fraud_flag_circular'),
            \App\Services\ReferralService::FRAUD_LINKED_DUPLICATE_MOBILE => __('admin_monetization.fraud_flag_linked_duplicate'),
            \App\Services\ReferralService::FRAUD_RAPID_INVITES => __('admin_monetization.fraud_flag_rapid_invites'),
            \App\Services\ReferralService::FRAUD_SAME_REGISTRATION_IP => __('admin_monetization.fraud_flag_same_ip'),
        ];
        $plansBySlug = $plans->keyBy('slug');
    @endphp

    <div class="mb-6 border-b border-gray-200 dark:border-gray-700">
        <nav class="-mb-px flex flex-wrap gap-2">
            @foreach($tabs as $key => $label)
                <a href="{{ route('admin.referrals.index', ['tab' => $key]) }}"
                   class="px-4 py-2 text-sm font-medium rounded-t-md border {{ $tab === $key ? 'border-gray-300 dark:border-gray-600 border-b-white dark:border-b-gray-800 text-indigo-600 dark:text-indigo-300' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' }}">
                    {{ $label }}
                </a>
            @endforeach
        </nav>
    </div>

    @if ($tab === 'engine')
        <div class="mb-8 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-3">Referral engine controls</h2>
            <form method="POST" action="{{ route('admin.referrals.engine.save') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @csrf
                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="hidden" name="enabled" value="0">
                    <input type="checkbox" name="enabled" value="1" @checked((bool) ($engineSettings['enabled'] ?? true)) class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                    Enable referral engine
                </label>

                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="hidden" name="paid_only" value="0">
                    <input type="checkbox" name="paid_only" value="1" @checked((bool) ($engineSettings['paid_only'] ?? true)) class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                    Reward only on paid plans
                </label>

                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Minimum plan amount (INR)</label>
                    <input type="number" name="min_plan_amount" min="0" value="{{ (int) ($engineSettings['min_plan_amount'] ?? 0) }}" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                    <p class="mt-1 text-xs text-gray-500">0 = no minimum amount filter.</p>
                </div>

                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Monthly reward cap per referrer</label>
                    <input type="number" name="monthly_cap" min="0" value="{{ (int) ($engineSettings['monthly_cap'] ?? 0) }}" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                    <p class="mt-1 text-xs text-gray-500">0 = unlimited rewards per month.</p>
                </div>

                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="hidden" name="fraud_auto_hold" value="0">
                    <input type="checkbox" name="fraud_auto_hold" value="1" @checked((bool) ($engineSettings['fraud_auto_hold'] ?? true)) class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                    Hold flagged referrals for admin review
                </label>

                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Rapid invites per 24h (referrer)</label>
                    <input type="number" name="fraud_rapid_invites" min="0" value="{{ (int) ($engineSettings['fraud_rapid_invites'] ?? 5) }}" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                    <p class="mt-1 text-xs text-gray-500">0 = disable rapid-invite flag.</p>
                </div>

                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">{{ __('admin_monetization.referral_pending_expiry_days_label') }}</label>
                    <input type="number" name="pending_claim_expiry_days" min="0" value="{{ (int) ($engineSettings['pending_claim_expiry_days'] ?? 90) }}" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                    <p class="mt-1 text-xs text-gray-500">{{ __('admin_monetization.referral_pending_expiry_days_hint') }}</p>
                </div>

                <div class="md:col-span-2 border-t border-gray-200 dark:border-gray-700 pt-4 mt-2">
                    <p class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-2">{{ __('admin_monetization.referral_quality_gates_heading') }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('admin_monetization.referral_quality_gates_intro') }}</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="hidden" name="quality_require_profile_active" value="0">
                            <input type="checkbox" name="quality_require_profile_active" value="1" @checked((bool) ($engineSettings['quality_require_profile_active'] ?? false)) class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                            {{ __('admin_monetization.referral_quality_profile_active') }}
                        </label>
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="hidden" name="quality_require_mobile_verified" value="0">
                            <input type="checkbox" name="quality_require_mobile_verified" value="1" @checked((bool) ($engineSettings['quality_require_mobile_verified'] ?? false)) class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                            {{ __('admin_monetization.referral_quality_mobile_verified') }}
                        </label>
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="hidden" name="quality_require_photo_approved" value="0">
                            <input type="checkbox" name="quality_require_photo_approved" value="1" @checked((bool) ($engineSettings['quality_require_photo_approved'] ?? false)) class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                            {{ __('admin_monetization.referral_quality_photo_approved') }}
                        </label>
                        <div>
                            <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">{{ __('admin_monetization.referral_quality_cooling_days') }}</label>
                            <input type="number" name="quality_cooling_period_days" min="0" max="365" value="{{ (int) ($engineSettings['quality_cooling_period_days'] ?? 0) }}" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin_monetization.referral_quality_cooling_hint') }}</p>
                        </div>
                    </div>
                </div>

                <div class="md:col-span-2 border-t border-gray-200 dark:border-gray-700 pt-4 mt-2">
                    <p class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-2">{{ __('admin_monetization.referral_referred_checkout_heading') }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('admin_monetization.referral_referred_checkout_intro') }}</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 md:col-span-2">
                            <input type="hidden" name="referred_checkout_enabled" value="0">
                            <input type="checkbox" name="referred_checkout_enabled" value="1" @checked((bool) ($engineSettings['referred_checkout_enabled'] ?? true)) class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                            {{ __('admin_monetization.referral_referred_checkout_enabled') }}
                        </label>
                        <div>
                            <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">{{ __('admin_monetization.referral_referred_checkout_percent') }}</label>
                            <input type="number" name="referred_checkout_percent" min="0" max="100" value="{{ (int) ($engineSettings['referred_checkout_percent'] ?? 0) }}" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">{{ __('admin_monetization.referral_referred_checkout_extra_days') }}</label>
                            <input type="number" name="referred_checkout_extra_days" min="0" max="365" value="{{ (int) ($engineSettings['referred_checkout_extra_days'] ?? 0) }}" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin_monetization.referral_referred_checkout_extra_days_hint') }}</p>
                        </div>
                    </div>
                </div>

                <div class="md:col-span-2 border-t border-gray-200 dark:border-gray-700 pt-4 mt-2">
                    <p class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-2">{{ __('admin_monetization.referral_renewal_micro_heading') }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('admin_monetization.referral_renewal_micro_intro') }}</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 md:col-span-2">
                            <input type="hidden" name="renewal_micro_bonus_enabled" value="0">
                            <input type="checkbox" name="renewal_micro_bonus_enabled" value="1" @checked((bool) ($engineSettings['renewal_micro_bonus_enabled'] ?? false)) class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                            {{ __('admin_monetization.referral_renewal_micro_enabled') }}
                        </label>
                        <div>
                            <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">{{ __('admin_monetization.referral_renewal_micro_days') }}</label>
                            <input type="number" name="renewal_micro_bonus_days" min="0" max="30" value="{{ (int) ($engineSettings['renewal_micro_bonus_days'] ?? 1) }}" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin_monetization.referral_renewal_micro_days_hint') }}</p>
                        </div>
                    </div>
                </div>

                <div class="md:col-span-2">
                    <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg">Save engine settings</button>
                </div>
            </form>
        </div>

        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 text-sm text-gray-600 dark:text-gray-300">
            Plan-wise referrer rewards and optional invite-checkout overrides are managed in the <strong>Reward Plans</strong> tab.
        </div>
    @elseif ($tab === 'reward-plans')
        <div class="mb-8 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-3">Referral reward rules (plan-wise)</h2>
            <form method="POST" action="{{ route('admin.referrals.rules.save') }}" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                @csrf
                <input type="hidden" name="tab" value="reward-plans">
                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Plan</label>
                    <select name="plan_slug" required class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                        <option value="">Select plan</option>
                        @foreach($plans as $p)
                            <option value="{{ $p->slug }}" @selected(($selectedPlanSlug ?? '') === $p->slug || old('plan_slug') === $p->slug)>{{ $p->name }} ({{ $p->slug }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Days bonus</label>
                    <input type="number" name="bonus_days" min="0" value="{{ old('bonus_days', (int) ($selectedRewardRule?->bonus_days ?? 0)) }}" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Chat bonus</label>
                    <input type="number" name="chat_send_limit_bonus" min="0" value="{{ old('chat_send_limit_bonus', (int) ($selectedRewardRule?->chat_send_limit_bonus ?? 0)) }}" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Contact bonus</label>
                    <input type="number" name="contact_view_limit_bonus" min="0" value="{{ old('contact_view_limit_bonus', (int) ($selectedRewardRule?->contact_view_limit_bonus ?? 0)) }}" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Interest bonus</label>
                    <input type="number" name="interest_send_limit_bonus" min="0" value="{{ old('interest_send_limit_bonus', (int) ($selectedRewardRule?->interest_send_limit_bonus ?? 0)) }}" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Profile view bonus</label>
                    <input type="number" name="daily_profile_view_limit_bonus" min="0" value="{{ old('daily_profile_view_limit_bonus', (int) ($selectedRewardRule?->daily_profile_view_limit_bonus ?? 0)) }}" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Who viewed preview bonus</label>
                    <input type="number" name="who_viewed_me_preview_limit_bonus" min="0" value="{{ old('who_viewed_me_preview_limit_bonus', (int) ($selectedRewardRule?->who_viewed_me_preview_limit_bonus ?? 0)) }}" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                </div>
                <div class="md:col-span-4 border-t border-gray-200 dark:border-gray-700 pt-3 mt-1">
                    <p class="text-xs font-semibold text-gray-700 dark:text-gray-200 mb-2">{{ __('admin_monetization.referral_plan_checkout_override_heading') }}</p>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 md:col-span-3">
                            <input type="hidden" name="referred_checkout_excluded" value="0">
                            <input type="checkbox" name="referred_checkout_excluded" value="1" @checked((bool) old('referred_checkout_excluded', (bool) ($selectedRewardRule?->referred_checkout_excluded ?? false))) class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                            {{ __('admin_monetization.referral_plan_checkout_excluded') }}
                        </label>
                        <div>
                            <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">{{ __('admin_monetization.referral_plan_checkout_percent') }}</label>
                            <input type="number" name="referred_checkout_percent_off" min="0" max="100" placeholder="{{ __('admin_monetization.referral_plan_checkout_use_global') }}" value="{{ old('referred_checkout_percent_off', $selectedRewardRule?->referred_checkout_percent_off) }}" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">{{ __('admin_monetization.referral_plan_checkout_extra_days') }}</label>
                            <input type="number" name="referred_checkout_extra_days" min="0" max="365" placeholder="{{ __('admin_monetization.referral_plan_checkout_use_global') }}" value="{{ old('referred_checkout_extra_days', $selectedRewardRule?->referred_checkout_extra_days) }}" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                        </div>
                    </div>
                </div>
                <div class="md:col-span-4 flex items-center justify-between gap-3">
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', (bool) ($selectedRewardRule?->is_active ?? true))) class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                        Active rule
                    </label>
                    <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg">Save rule</button>
                </div>
            </form>

            <div class="overflow-x-auto mt-4">
                <table class="min-w-full text-xs text-left">
                    <thead class="uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-600">
                        <tr>
                            <th class="py-2 pr-3">Plan</th>
                            <th class="py-2 pr-3">Slug</th>
                            <th class="py-2 pr-3">Active</th>
                            <th class="py-2 pr-3">Days</th>
                            <th class="py-2 pr-3">Chat</th>
                            <th class="py-2 pr-3">Contact</th>
                            <th class="py-2 pr-3">Interest</th>
                            <th class="py-2 pr-3">Profile</th>
                            <th class="py-2 pr-3">Who viewed preview</th>
                            <th class="py-2 pr-3">{{ __('admin_monetization.referral_plan_checkout_column') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse($rules as $rule)
                            @php $plan = $plansBySlug->get($rule->plan_slug); @endphp
                            <tr>
                                <td class="py-2 pr-3">{{ $plan?->name ?? '—' }}</td>
                                <td class="py-2 pr-3 font-mono">{{ $rule->plan_slug }}</td>
                                <td class="py-2 pr-3">{{ $rule->is_active ? 'Yes' : 'No' }}</td>
                                <td class="py-2 pr-3">{{ $rule->bonus_days }}</td>
                                <td class="py-2 pr-3">{{ $rule->chat_send_limit_bonus }}</td>
                                <td class="py-2 pr-3">{{ $rule->contact_view_limit_bonus }}</td>
                                <td class="py-2 pr-3">{{ $rule->interest_send_limit_bonus }}</td>
                                <td class="py-2 pr-3">{{ $rule->daily_profile_view_limit_bonus }}</td>
                                <td class="py-2 pr-3">{{ $rule->who_viewed_me_preview_limit_bonus }}</td>
                                <td class="py-2 pr-3 text-xs">
                                    @if ($rule->referred_checkout_excluded)
                                        {{ __('admin_monetization.referral_plan_checkout_excluded_short') }}
                                    @elseif ($rule->referred_checkout_percent_off !== null || $rule->referred_checkout_extra_days !== null)
                                        {{ $rule->referred_checkout_percent_off ?? '—' }}% / +{{ $rule->referred_checkout_extra_days ?? 0 }}d
                                    @else
                                        {{ __('admin_monetization.referral_plan_checkout_use_global') }}
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="py-4 text-gray-500">No rules configured.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @elseif ($tab === 'review')
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
            {{ __('admin_monetization.referral_review_intro') }}
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm text-left">
                <thead class="text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-600">
                    <tr>
                        <th class="py-3 pr-4">ID</th>
                        <th class="py-3 pr-4">{{ __('admin_monetization.col_referrer') }}</th>
                        <th class="py-3 pr-4">{{ __('admin_monetization.col_referred') }}</th>
                        <th class="py-3 pr-4">{{ __('admin_monetization.col_fraud_flags') }}</th>
                        <th class="py-3 pr-4">{{ __('admin_monetization.col_created') }}</th>
                        <th class="py-3 pr-4">{{ __('admin_monetization.col_actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($reviewQueue as $r)
                        <tr>
                            <td class="py-3 pr-4 font-mono text-xs">{{ $r->id }}</td>
                            <td class="py-3 pr-4">
                                #{{ $r->referrer_id }} — {{ $r->referrer?->name ?? '—' }}
                                @if ($r->referrer?->referral_code)
                                    <span class="ml-1 font-mono text-xs text-gray-500">{{ $r->referrer->referral_code }}</span>
                                @endif
                            </td>
                            <td class="py-3 pr-4">#{{ $r->referred_user_id }} — {{ $r->referredUser?->name ?? '—' }}</td>
                            <td class="py-3 pr-4">
                                @php $flags = is_array($r->fraud_flags) ? $r->fraud_flags : []; @endphp
                                @if ($flags === [])
                                    <span class="text-gray-500">—</span>
                                @else
                                    <ul class="list-disc pl-4 space-y-0.5 text-xs">
                                        @foreach ($flags as $flag)
                                            <li>{{ $fraudFlagLabels[$flag] ?? $flag }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                            <td class="py-3 pr-4 text-gray-500">{{ $r->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="py-3 pr-4 align-top">
                                <form method="POST" action="{{ route('admin.referrals.review.approve', $r) }}" class="mb-2">
                                    @csrf
                                    <input type="text" name="notes" maxlength="500" placeholder="{{ __('admin_monetization.referral_approve_notes_placeholder') }}" class="mb-1 w-full min-w-[12rem] rounded border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                                    <button type="submit" class="rounded bg-emerald-600 px-2 py-1 text-xs font-semibold text-white hover:bg-emerald-700">{{ __('admin_monetization.referral_approve') }}</button>
                                </form>
                                <form method="POST" action="{{ route('admin.referrals.review.reject', $r) }}">
                                    @csrf
                                    <input type="text" name="reason" required minlength="10" maxlength="500" placeholder="{{ __('admin_monetization.referral_reject_reason_placeholder') }}" class="mb-1 w-full min-w-[12rem] rounded border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                                    <button type="submit" class="rounded bg-rose-600 px-2 py-1 text-xs font-semibold text-white hover:bg-rose-700">{{ __('admin_monetization.referral_reject') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-gray-500">{{ __('admin_monetization.referral_review_empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $reviewQueue->links() }}</div>
    @elseif ($tab === 'supreme')
        <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">{{ __('admin_monetization.referral_supreme_intro') }}</p>
        <form method="GET" action="{{ route('admin.referrals.index') }}" class="mb-6 flex flex-wrap items-end gap-3">
            <input type="hidden" name="tab" value="supreme">
            <div class="min-w-[16rem] flex-1">
                <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">{{ __('admin_monetization.referral_supreme_lookup_label') }}</label>
                <input type="text" name="referrer_lookup" value="{{ request('referrer_lookup') }}" placeholder="{{ __('admin_monetization.referral_supreme_lookup_placeholder') }}" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
            </div>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg">{{ __('admin_monetization.referral_supreme_lookup_btn') }}</button>
        </form>

        @if (request()->filled('referrer_lookup') && empty($supremePanel))
            <p class="text-sm text-amber-700 dark:text-amber-300">{{ __('admin_monetization.referral_supreme_not_found') }}</p>
        @endif

        @if (! empty($supremePanel))
            @php
                $supremeUser = $supremePanel['user'];
                $supremeSummary = $supremePanel['summary'] ?? [];
            @endphp
            <div class="mb-6 rounded-lg border border-indigo-200 dark:border-indigo-800 p-4 bg-indigo-50/50 dark:bg-indigo-950/20">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">#{{ $supremeUser->id }} — {{ $supremeUser->name }}</p>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            <span class="font-mono">{{ $supremeUser->referral_code ?? '—' }}</span>
                            · {{ $supremeUser->mobile ?? '—' }}
                        </p>
                        <div class="mt-2 flex flex-wrap gap-2 text-xs">
                            @if ($supremeUser->isReferralRewardsFrozen())
                                <span class="rounded bg-rose-100 px-2 py-0.5 font-semibold text-rose-800 dark:bg-rose-900/40 dark:text-rose-100">{{ __('admin_monetization.referral_supreme_status_frozen') }}</span>
                            @endif
                            @if ($supremeUser->isReferralCodeDisabled())
                                <span class="rounded bg-amber-100 px-2 py-0.5 font-semibold text-amber-900 dark:bg-amber-900/40 dark:text-amber-100">{{ __('admin_monetization.referral_supreme_status_code_disabled') }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-center text-xs">
                        <div class="rounded bg-white/80 dark:bg-gray-800 px-2 py-2"><span class="block font-bold text-lg">{{ (int) ($supremeSummary['invited'] ?? 0) }}</span>Invited</div>
                        <div class="rounded bg-white/80 dark:bg-gray-800 px-2 py-2"><span class="block font-bold text-lg">{{ (int) ($supremeSummary['converted'] ?? 0) }}</span>Converted</div>
                        <div class="rounded bg-white/80 dark:bg-gray-800 px-2 py-2"><span class="block font-bold text-lg">{{ (int) ($supremeSummary['rewards_earned'] ?? 0) }}</span>Rewards</div>
                        <div class="rounded bg-white/80 dark:bg-gray-800 px-2 py-2"><span class="block font-bold text-lg">{{ (int) ($supremeSummary['pending_claim'] ?? 0) }}</span>Pending</div>
                    </div>
                </div>
                <p class="mt-3 text-xs text-gray-600 dark:text-gray-400">
                    @if ($supremeUser->referral_monthly_cap_override === null)
                        {{ __('admin_monetization.referral_supreme_cap_global') }}
                    @elseif ((int) $supremeUser->referral_monthly_cap_override === 0)
                        {{ __('admin_monetization.referral_supreme_cap_unlimited') }}
                    @else
                        {{ __('admin_monetization.referral_supreme_cap_custom', ['cap' => (int) $supremeUser->referral_monthly_cap_override]) }}
                    @endif
                </p>
            </div>

            <div class="mb-8 grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-100">Reward & code controls</h3>
                    @if ($supremeUser->isReferralRewardsFrozen())
                        <form method="POST" action="{{ route('admin.referrals.supreme.unfreeze', $supremeUser) }}">@csrf
                            <input type="text" name="reason" maxlength="500" placeholder="Optional note" class="mb-2 w-full rounded border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                            <button type="submit" class="rounded bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white">Unfreeze rewards</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('admin.referrals.supreme.freeze', $supremeUser) }}">@csrf
                            <input type="text" name="reason" maxlength="500" placeholder="Optional note" class="mb-2 w-full rounded border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                            <button type="submit" class="rounded bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white">Freeze rewards</button>
                        </form>
                    @endif
                    @if ($supremeUser->isReferralCodeDisabled())
                        <form method="POST" action="{{ route('admin.referrals.supreme.enable-code', $supremeUser) }}">@csrf
                            <button type="submit" class="rounded bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white">Enable referral code</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('admin.referrals.supreme.disable-code', $supremeUser) }}">@csrf
                            <button type="submit" class="rounded bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white">Disable referral code</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('admin.referrals.supreme.regenerate-code', $supremeUser) }}" onsubmit="return confirm('Regenerate referral code? Old links will stop working.');">@csrf
                        <button type="submit" class="rounded bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white">Regenerate code</button>
                    </form>
                </div>
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-100 mb-2">Monthly cap override</h3>
                    <form method="POST" action="{{ route('admin.referrals.supreme.cap-override', $supremeUser) }}" class="space-y-2">@csrf
                        <label class="block text-xs text-gray-600 dark:text-gray-400">Custom cap (0 = unlimited, empty + global = engine default)</label>
                        <input type="number" name="monthly_cap_override" min="0" max="10000" value="{{ $supremeUser->referral_monthly_cap_override !== null ? (int) $supremeUser->referral_monthly_cap_override : '' }}" class="w-full rounded border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                        <label class="inline-flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400">
                            <input type="checkbox" name="use_global_cap" value="1" class="rounded border-gray-300"> Use global engine cap (clear override)
                        </label>
                        <input type="text" name="reason" maxlength="500" placeholder="Optional note" class="w-full rounded border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                        <button type="submit" class="rounded bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white">Save cap override</button>
                    </form>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div>
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-2">Recent referrals</h3>
                    <ul class="text-sm divide-y divide-gray-100 dark:divide-gray-700 border rounded-lg dark:border-gray-700">
                        @forelse ($supremePanel['recent_referrals'] as $rr)
                            <li class="px-3 py-2">
                                <div class="flex justify-between gap-2">
                                    <span>#{{ $rr->id }} → {{ $rr->referredUser?->name ?? '#'.$rr->referred_user_id }}</span>
                                    <span class="text-xs text-gray-500">{{ $rr->reward_status ?? '—' }}</span>
                                </div>
                                @include('admin.referrals.partials.row-supreme-actions', [
                                    'referral' => $rr,
                                    'returnTab' => 'supreme',
                                    'referrerLookup' => request('referrer_lookup'),
                                ])
                            </li>
                        @empty
                            <li class="px-3 py-4 text-gray-500 text-center">No rows</li>
                        @endforelse
                    </ul>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-2">Recent ledger</h3>
                    <ul class="text-xs divide-y divide-gray-100 dark:divide-gray-700 border rounded-lg dark:border-gray-700">
                        @forelse ($supremePanel['recent_ledgers'] as $lg)
                            <li class="px-3 py-2">{{ $lg->action_type }} — {{ Str::limit($lg->reason ?? '', 60) }}</li>
                        @empty
                            <li class="px-3 py-4 text-gray-500 text-center">No ledger rows</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        @endif
    @elseif ($tab === 'reports')
        <form method="GET" action="{{ route('admin.referrals.index') }}" class="mb-6 flex flex-wrap items-end gap-3">
            <input type="hidden" name="tab" value="reports">
            <div>
                <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">{{ __('admin_monetization.referral_filter') }}</label>
                <select name="reward" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm text-sm">
                    <option value="" @selected($rewardFilter === null || $rewardFilter === '')>{{ __('admin_monetization.referral_filter_all') }}</option>
                    <option value="1" @selected($rewardFilter === '1')>{{ __('admin_monetization.referral_filter_applied') }}</option>
                    <option value="0" @selected($rewardFilter === '0')>{{ __('admin_monetization.referral_filter_pending') }}</option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">From date</label>
                <input type="date" name="from_date" value="{{ $fromDate }}" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm text-sm">
            </div>
            <div>
                <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">To date</label>
                <input type="date" name="to_date" value="{{ $toDate }}" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm text-sm">
            </div>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg">
                Apply filters
            </button>
            <a href="{{ route('admin.referrals.export', ['tab' => 'reports', 'reward' => $rewardFilter, 'from_date' => $fromDate, 'to_date' => $toDate]) }}"
               class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-lg">
                Export CSV
            </a>
        </form>

        @php
            $funnel = $referralReports['funnel'] ?? [];
            $economics = $referralReports['economics'] ?? [];
            $funnelMax = max(1, (int) ($funnel['invited'] ?? 0));
        @endphp

        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('admin_monetization.referral_report_total') }}</p>
                <p class="text-xl font-semibold text-gray-800 dark:text-gray-100">{{ (int) ($reportSummary['total'] ?? 0) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('admin_monetization.referral_report_profile_ready') }}</p>
                <p class="text-xl font-semibold text-sky-700 dark:text-sky-300">{{ (int) ($reportSummary['profile_ready'] ?? 0) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('admin_monetization.referral_report_upgraded') }}</p>
                <p class="text-xl font-semibold text-violet-700 dark:text-violet-300">{{ (int) ($reportSummary['upgraded'] ?? 0) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('admin_monetization.referral_report_rewarded') }}</p>
                <p class="text-xl font-semibold text-emerald-700 dark:text-emerald-300">{{ (int) ($reportSummary['rewarded'] ?? 0) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('admin_monetization.referral_report_pending') }}</p>
                <p class="text-xl font-semibold text-amber-700 dark:text-amber-300">{{ (int) ($reportSummary['pending'] ?? 0) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('admin_monetization.referral_report_conversion') }}</p>
                <p class="text-xl font-semibold text-indigo-700 dark:text-indigo-300">{{ number_format((float) ($reportSummary['conversion_rate'] ?? 0), 2) }}%</p>
            </div>
        </div>

        <div class="mb-6 grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100 mb-1">{{ __('admin_monetization.referral_funnel_heading') }}</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">{{ __('admin_monetization.referral_funnel_intro') }}</p>
                <div class="space-y-3 text-sm">
                    @foreach ([
                        'invited' => __('admin_monetization.referral_funnel_invited'),
                        'profile_ready' => __('admin_monetization.referral_funnel_profile'),
                        'upgraded' => __('admin_monetization.referral_funnel_upgraded'),
                        'rewarded' => __('admin_monetization.referral_funnel_rewarded'),
                    ] as $key => $label)
                        @php
                            $count = (int) ($funnel[$key] ?? 0);
                            $pct = $key === 'invited'
                                ? ($count > 0 ? 100.0 : 0.0)
                                : (float) ($funnel['rates'][$key] ?? 0);
                            $width = max(4, (int) round(($count / $funnelMax) * 100));
                        @endphp
                        <div>
                            <div class="flex justify-between gap-2 mb-1">
                                <span class="font-medium text-gray-800 dark:text-gray-100">{{ $label }}</span>
                                <span class="text-gray-500 tabular-nums">{{ $count }} ({{ number_format($pct, 1) }}%)</span>
                            </div>
                            <div class="h-2 rounded-full bg-gray-100 dark:bg-gray-800 overflow-hidden">
                                <div class="h-full rounded-full bg-indigo-500 dark:bg-indigo-400" style="width: {{ $width }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100 mb-1">{{ __('admin_monetization.referral_economics_heading') }}</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">{{ __('admin_monetization.referral_economics_intro', ['daily' => number_format((float) ($economics['avg_daily_plan_value'] ?? 0), 2)]) }}</p>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                    <div class="rounded-md bg-emerald-50 dark:bg-emerald-950/30 px-3 py-2">
                        <dt class="text-xs text-emerald-800/80 dark:text-emerald-200/80">{{ __('admin_monetization.referral_economics_revenue') }}</dt>
                        <dd class="text-lg font-semibold text-emerald-900 dark:text-emerald-100">₹{{ number_format((float) ($economics['referred_first_paid_revenue'] ?? 0), 2) }}</dd>
                    </div>
                    <div class="rounded-md bg-amber-50 dark:bg-amber-950/30 px-3 py-2">
                        <dt class="text-xs text-amber-800/80 dark:text-amber-200/80">{{ __('admin_monetization.referral_economics_discount') }}</dt>
                        <dd class="text-lg font-semibold text-amber-900 dark:text-amber-100">₹{{ number_format((float) ($economics['invite_checkout_discount'] ?? 0), 2) }}</dd>
                    </div>
                    <div class="rounded-md bg-violet-50 dark:bg-violet-950/30 px-3 py-2">
                        <dt class="text-xs text-violet-800/80 dark:text-violet-200/80">{{ __('admin_monetization.referral_economics_referrer_cost') }}</dt>
                        <dd class="text-lg font-semibold text-violet-900 dark:text-violet-100">₹{{ number_format((float) ($economics['referrer_reward_cost_estimate'] ?? 0), 2) }}</dd>
                        <dd class="text-[11px] text-violet-700/90 dark:text-violet-200/80">{{ __('admin_monetization.referral_economics_bonus_days', ['days' => (int) ($economics['referrer_reward_bonus_days'] ?? 0)]) }}</dd>
                    </div>
                    <div class="rounded-md bg-indigo-50 dark:bg-indigo-950/30 px-3 py-2">
                        <dt class="text-xs text-indigo-800/80 dark:text-indigo-200/80">{{ __('admin_monetization.referral_economics_net') }}</dt>
                        <dd class="text-lg font-semibold text-indigo-900 dark:text-indigo-100">₹{{ number_format((float) ($economics['net_margin_estimate'] ?? 0), 2) }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        <div class="mb-6 overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100 mb-3">Top referrers</h3>
            <table class="min-w-full text-sm text-left">
                <thead class="text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-600">
                    <tr>
                        <th class="py-2 pr-3">Referrer</th>
                        <th class="py-2 pr-3">Code</th>
                        <th class="py-2 pr-3">Total referrals</th>
                        <th class="py-2 pr-3">Rewarded</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($topReferrers as $ref)
                        <tr>
                            <td class="py-2 pr-3">{{ $ref->referrer?->name ?? ('#'.$ref->referrer_id) }}</td>
                            <td class="py-2 pr-3 font-mono">{{ $ref->referrer?->referral_code ?? '—' }}</td>
                            <td class="py-2 pr-3">{{ (int) $ref->total_referrals }}</td>
                            <td class="py-2 pr-3">{{ (int) $ref->rewarded_referrals }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-4 text-gray-500">No referral data for selected filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm text-left">
                <thead class="text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-600">
                    <tr>
                        <th class="py-3 pr-4">ID</th>
                        <th class="py-3 pr-4">{{ __('admin_monetization.col_referrer') }}</th>
                        <th class="py-3 pr-4">{{ __('admin_monetization.col_referred') }}</th>
                        <th class="py-3 pr-4">{{ __('admin_monetization.col_review') }}</th>
                        <th class="py-3 pr-4">{{ __('admin_monetization.col_reward_applied') }}</th>
                        <th class="py-3 pr-4">{{ __('admin_monetization.col_created') }}</th>
                        <th class="py-3 pr-4">{{ __('admin_monetization.col_actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($referrals as $r)
                        <tr>
                            <td class="py-3 pr-4 font-mono text-xs">{{ $r->id }}</td>
                            <td class="py-3 pr-4">
                                @if ($r->referrer)
                                    #{{ $r->referrer_id }} — {{ $r->referrer->name }}
                                    @if ($r->referrer->referral_code)
                                        <span class="ml-1 text-xs font-mono text-gray-500">{{ $r->referrer->referral_code }}</span>
                                    @endif
                                @else
                                    #{{ $r->referrer_id }}
                                @endif
                            </td>
                            <td class="py-3 pr-4">
                                @if ($r->referredUser)
                                    #{{ $r->referred_user_id }} — {{ $r->referredUser->name }}
                                @else
                                    #{{ $r->referred_user_id }}
                                @endif
                            </td>
                            <td class="py-3 pr-4">
                                @if ($r->review_status === \App\Models\UserReferral::REVIEW_PENDING)
                                    <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-semibold bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100">{{ __('admin_monetization.referral_review_pending') }}</span>
                                @elseif ($r->review_status === \App\Models\UserReferral::REVIEW_REJECTED)
                                    <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-semibold bg-rose-100 text-rose-900 dark:bg-rose-900/40 dark:text-rose-100">{{ __('admin_monetization.referral_review_rejected') }}</span>
                                @else
                                    <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-semibold bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300">{{ __('admin_monetization.referral_review_approved') }}</span>
                                @endif
                            </td>
                            <td class="py-3 pr-4">
                                @if ($r->reward_applied)
                                    <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">{{ __('admin_monetization.yes') }}</span>
                                @elseif ($r->reward_status === \App\Models\UserReferral::STATUS_PENDING_CLAIM)
                                    <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-semibold bg-violet-100 text-violet-900 dark:bg-violet-900/40 dark:text-violet-100">{{ __('admin_monetization.referral_status_pending_claim') }}</span>
                                @elseif ($r->reward_status === \App\Models\UserReferral::STATUS_CAP_SKIPPED)
                                    <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-semibold bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200">{{ __('admin_monetization.referral_status_cap_skipped') }}</span>
                                @elseif ($r->reward_status === \App\Models\UserReferral::STATUS_ADMIN_CANCELLED)
                                    <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-semibold bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200">{{ __('admin_monetization.referral_status_admin_cancelled') }}</span>
                                @elseif ($r->reward_status === \App\Models\UserReferral::STATUS_PENDING_EXPIRED)
                                    <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-semibold bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200">{{ __('admin_monetization.referral_status_pending_expired') }}</span>
                                @elseif ($r->reward_status === \App\Models\UserReferral::STATUS_QUALITY_PENDING)
                                    <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-semibold bg-sky-100 text-sky-900 dark:bg-sky-900/40 dark:text-sky-100">{{ __('admin_monetization.referral_status_quality_pending') }}</span>
                                @elseif ($r->reward_status === \App\Models\UserReferral::STATUS_REWARD_REVOKED)
                                    <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-semibold bg-rose-100 text-rose-900 dark:bg-rose-900/40 dark:text-rose-100">{{ __('admin_monetization.referral_status_reward_revoked') }}</span>
                                @else
                                    <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-semibold bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100">{{ __('admin_monetization.no') }}</span>
                                @endif
                            </td>
                            <td class="py-3 pr-4 text-gray-500">{{ $r->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="py-3 pr-4 align-top min-w-[14rem]">
                                @if ($r->reward_status === \App\Models\UserReferral::STATUS_PENDING_CLAIM && ! $r->reward_applied)
                                    <form method="POST" action="{{ route('admin.referrals.force-pending', $r) }}" class="mb-1">@csrf
                                        <input type="hidden" name="return_tab" value="reports">
                                        <button type="submit" class="rounded bg-emerald-600 px-2 py-0.5 text-xs font-semibold text-white">{{ __('admin_monetization.referral_force_pending') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.referrals.cancel-pending', $r) }}">@csrf
                                        <input type="hidden" name="return_tab" value="reports">
                                        <input type="text" name="reason" required minlength="10" maxlength="500" placeholder="{{ __('admin_monetization.referral_cancel_reason_placeholder') }}" class="mb-1 w-full min-w-[10rem] rounded border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                                        <button type="submit" class="rounded bg-rose-600 px-2 py-0.5 text-xs font-semibold text-white">{{ __('admin_monetization.referral_cancel_pending') }}</button>
                                    </form>
                                @endif
                                @include('admin.referrals.partials.row-supreme-actions', ['referral' => $r, 'returnTab' => 'reports'])
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center text-gray-500">{{ __('admin_monetization.referrals_empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $referrals->links() }}</div>
    @else
        <div class="mb-6 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-3">Manual referral override</h2>
            <form method="POST" action="{{ route('admin.referrals.audit.override') }}" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                @csrf
                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Referrer user ID</label>
                    <input type="number" name="referrer_id" min="1" required class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Referred user ID (optional)</label>
                    <input type="number" name="referred_user_id" min="1" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Bonus days</label>
                    <input type="number" name="bonus_days" min="0" value="0" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Chat bonus</label>
                    <input type="number" name="chat_send_limit_bonus" min="0" value="0" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Contact bonus</label>
                    <input type="number" name="contact_view_limit_bonus" min="0" value="0" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Interest bonus</label>
                    <input type="number" name="interest_send_limit_bonus" min="0" value="0" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Profile view bonus</label>
                    <input type="number" name="daily_profile_view_limit_bonus" min="0" value="0" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Who viewed preview bonus</label>
                    <input type="number" name="who_viewed_me_preview_limit_bonus" min="0" value="0" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                </div>
                <div class="md:col-span-3">
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Reason (mandatory)</label>
                    <input type="text" name="reason" required minlength="10" maxlength="500" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" placeholder="Explain why this manual override is needed">
                </div>
                <div class="md:col-span-4">
                    <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg">Apply override</button>
                </div>
            </form>
        </div>

        <form method="GET" action="{{ route('admin.referrals.index') }}" class="mb-6 flex flex-wrap items-end gap-3">
            <input type="hidden" name="tab" value="audit">
            <div>
                <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">Action type</label>
                <select name="audit_action" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm text-sm">
                    <option value="">All actions</option>
                    @foreach($auditActionTypes as $type)
                        <option value="{{ $type }}" @selected($auditAction === $type)>{{ $type }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">Referrer ID</label>
                <input type="number" name="audit_referrer_id" min="1" value="{{ $auditReferrerId }}" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm text-sm">
            </div>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg">Apply filters</button>
            <a href="{{ route('admin.referrals.export', ['tab' => 'audit', 'export_type' => 'audit', 'audit_action' => $auditAction, 'audit_referrer_id' => $auditReferrerId]) }}"
               class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-lg">
                Export Audit CSV
            </a>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm text-left">
                <thead class="text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-600">
                    <tr>
                        <th class="py-3 pr-3">ID</th>
                        <th class="py-3 pr-3">Action</th>
                        <th class="py-3 pr-3">Referrer</th>
                        <th class="py-3 pr-3">Referred</th>
                        <th class="py-3 pr-3">Bonus days</th>
                        <th class="py-3 pr-3">Feature bonus</th>
                        <th class="py-3 pr-3">By admin</th>
                        <th class="py-3 pr-3">Reason</th>
                        <th class="py-3 pr-3">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($ledgers as $entry)
                        <tr>
                            <td class="py-3 pr-3 font-mono text-xs">{{ $entry->id }}</td>
                            <td class="py-3 pr-3"><span class="inline-flex rounded-md px-2 py-0.5 text-xs font-semibold bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200">{{ $entry->action_type }}</span></td>
                            <td class="py-3 pr-3">
                                #{{ $entry->referrer_id }}{{ $entry->referrer?->name ? ' — '.$entry->referrer->name : '' }}
                            </td>
                            <td class="py-3 pr-3">
                                @if ($entry->referred_user_id)
                                    #{{ $entry->referred_user_id }}{{ $entry->referredUser?->name ? ' — '.$entry->referredUser->name : '' }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="py-3 pr-3">{{ (int) $entry->bonus_days }}</td>
                            <td class="py-3 pr-3 font-mono text-xs">
                                @if (is_array($entry->feature_bonus) && $entry->feature_bonus !== [])
                                    {{ json_encode($entry->feature_bonus, JSON_UNESCAPED_SLASHES) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="py-3 pr-3">{{ $entry->performedByAdmin?->name ?? 'System' }}</td>
                            <td class="py-3 pr-3 text-gray-600 dark:text-gray-300">{{ $entry->reason ?: '—' }}</td>
                            <td class="py-3 pr-3 text-gray-500">{{ $entry->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-8 text-center text-gray-500">No audit entries found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $ledgers->links() }}</div>
    @endif
</div>
@endsection
