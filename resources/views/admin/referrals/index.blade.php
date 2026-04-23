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
            'audit' => 'Audit',
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

                <div class="md:col-span-2">
                    <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg">Save engine settings</button>
                </div>
            </form>
        </div>

        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 text-sm text-gray-600 dark:text-gray-300">
            Plan-wise days and feature bonuses are managed in the <strong>Reward Plans</strong> tab.
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

        <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-6">
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                <p class="text-xs text-gray-500 dark:text-gray-400">Total referrals</p>
                <p class="text-xl font-semibold text-gray-800 dark:text-gray-100">{{ $reportSummary['total'] }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                <p class="text-xs text-gray-500 dark:text-gray-400">Rewarded</p>
                <p class="text-xl font-semibold text-emerald-700 dark:text-emerald-300">{{ $reportSummary['rewarded'] }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                <p class="text-xs text-gray-500 dark:text-gray-400">Pending</p>
                <p class="text-xl font-semibold text-amber-700 dark:text-amber-300">{{ $reportSummary['pending'] }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                <p class="text-xs text-gray-500 dark:text-gray-400">Conversion rate</p>
                <p class="text-xl font-semibold text-indigo-700 dark:text-indigo-300">{{ number_format((float) $reportSummary['conversion_rate'], 2) }}%</p>
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
                        <th class="py-3 pr-4">{{ __('admin_monetization.col_reward_applied') }}</th>
                        <th class="py-3 pr-4">{{ __('admin_monetization.col_created') }}</th>
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
                                @if ($r->reward_applied)
                                    <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">{{ __('admin_monetization.yes') }}</span>
                                @else
                                    <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-semibold bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100">{{ __('admin_monetization.no') }}</span>
                                @endif
                            </td>
                            <td class="py-3 pr-4 text-gray-500">{{ $r->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-8 text-center text-gray-500">{{ __('admin_monetization.referrals_empty') }}</td>
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
