@extends('layouts.admin')

@section('content')
<div class="space-y-6">
    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Suchak Admin Dashboard</h1>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Review account verification status and recent Suchak activity.</p>
            </div>
            <a href="{{ route('admin.suchak.accounts.index') }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                Review accounts
            </a>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Pending</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['pending'] }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Verified</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['verified'] }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Suspended</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['suspended'] }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Archived</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['archived'] }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Public active</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['public_active'] }}</div>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Recent Accounts</h2>
            </div>
            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($recentAccounts as $account)
                    <a href="{{ route('admin.suchak.accounts.show', $account) }}" class="block px-5 py-4 hover:bg-gray-50 dark:hover:bg-gray-900">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="font-medium text-gray-900 dark:text-gray-100">{{ $account->suchak_name }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $account->user?->email }}</div>
                            </div>
                            <div class="text-right text-xs text-gray-500 dark:text-gray-400">
                                <div>{{ ucfirst($account->verification_status) }}</div>
                                <div>{{ $account->created_at?->format('Y-m-d H:i') }}</div>
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="px-5 py-6 text-sm text-gray-500 dark:text-gray-400">No Suchak accounts yet.</div>
                @endforelse
            </div>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Recent Activity</h2>
            </div>
            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($recentActivity as $activity)
                    <div class="px-5 py-4 text-sm">
                        <div class="font-medium text-gray-900 dark:text-gray-100">{{ $activity->action_type }}</div>
                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ $activity->suchakAccount?->suchak_name ?: 'Unknown Suchak' }} · {{ $activity->occurred_at?->format('Y-m-d H:i') }}
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-6 text-sm text-gray-500 dark:text-gray-400">No Suchak activity yet.</div>
                @endforelse
            </div>
        </section>
    </div>
</div>
@endsection
