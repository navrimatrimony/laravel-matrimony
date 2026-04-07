@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('admin_monetization.referrals_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('admin_monetization.referrals_intro') }}</p>
        </div>
    </div>

    <form method="GET" action="{{ route('admin.referrals.index') }}" class="mb-6 flex flex-wrap items-center gap-3">
        <label class="text-sm text-gray-600 dark:text-gray-400">{{ __('admin_monetization.referral_filter') }}</label>
        <select name="reward" onchange="this.form.submit()" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm text-sm">
            <option value="" @selected($rewardFilter === null || $rewardFilter === '')>{{ __('admin_monetization.referral_filter_all') }}</option>
            <option value="1" @selected($rewardFilter === '1')>{{ __('admin_monetization.referral_filter_applied') }}</option>
            <option value="0" @selected($rewardFilter === '0')>{{ __('admin_monetization.referral_filter_pending') }}</option>
        </select>
    </form>

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
</div>
@endsection
