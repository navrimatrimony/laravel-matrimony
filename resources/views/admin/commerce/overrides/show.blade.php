@extends('layouts.admin')

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">User #{{ $user->id }}</h1>
            <p class="text-sm text-gray-600 dark:text-gray-300 mt-1">{{ $user->email }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('admin.users.plan', $user) }}" class="text-sm font-medium text-indigo-600 dark:text-indigo-400">{{ __('user_plan.page_title') }}</a>
            <a href="{{ route('admin.commerce.overrides.index') }}" class="text-sm font-medium text-indigo-600 dark:text-indigo-400">{{ __('admin_commerce.override_back') }}</a>
        </div>
    </div>

    @if (session('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200">
            {{ session('success') }}
        </div>
    @endif

    <div class="rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/30 px-4 py-3 text-sm text-amber-950 dark:text-amber-100">
        {{ __('admin_commerce.override_note') }}
    </div>

    <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 shadow-sm p-5">
        <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-3">{{ __('admin_commerce.override_subscriptions') }}</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-xs uppercase text-gray-500 border-b border-gray-200 dark:border-gray-600">
                    <tr>
                        <th class="text-left py-2 pr-4">ID</th>
                        <th class="text-left py-2 pr-4">Plan</th>
                        <th class="text-left py-2 pr-4">Status</th>
                        <th class="text-left py-2 pr-4">Ends</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($subscriptions as $sub)
                        <tr>
                            <td class="py-2 pr-4 font-mono">{{ $sub->id }}</td>
                            <td class="py-2 pr-4">{{ $sub->plan?->name ?? '—' }}</td>
                            <td class="py-2 pr-4">{{ $sub->status }}</td>
                            <td class="py-2 pr-4">{{ $sub->ends_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($subscriptions->isNotEmpty())
            <form method="POST" action="{{ route('admin.commerce.overrides.extend', $user) }}" class="mt-4 flex flex-wrap items-end gap-3">
                @csrf
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('admin_commerce.override_extend_title') }}</label>
                    <select name="subscription_id" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                        @foreach ($subscriptions as $sub)
                            <option value="{{ $sub->id }}">#{{ $sub->id }} — {{ $sub->plan?->name }} ({{ $sub->status }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('admin_commerce.override_extend_days') }}</label>
                    <input type="number" name="extend_days" value="30" min="1" max="3650" required
                        class="w-24 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                </div>
                <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    {{ __('admin_commerce.override_extend_submit') }}
                </button>
            </form>
        @endif
    </div>

    <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 shadow-sm p-5">
        <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-3">{{ __('admin_commerce.override_entitlements') }}</h2>
        <div class="overflow-x-auto mb-4">
            <table class="min-w-full text-sm">
                <thead class="text-xs uppercase text-gray-500 border-b border-gray-200 dark:border-gray-600">
                    <tr>
                        <th class="text-left py-2 pr-4">Key</th>
                        <th class="text-left py-2 pr-4">Valid until</th>
                        <th class="text-left py-2 pr-4">Revoked</th>
                        <th class="text-left py-2 pr-4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($entitlements as $ent)
                        <tr>
                            <td class="py-2 pr-4 font-mono">{{ $ent->entitlement_key }}</td>
                            <td class="py-2 pr-4">{{ $ent->valid_until?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td class="py-2 pr-4">{{ $ent->revoked_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td class="py-2 pr-4">
                                @if ($ent->revoked_at === null)
                                    <form method="POST" action="{{ route('admin.commerce.overrides.revoke', $user) }}" class="inline">
                                        @csrf
                                        <input type="hidden" name="entitlement_key" value="{{ $ent->entitlement_key }}" />
                                        <button type="submit" class="text-xs font-semibold text-red-600 dark:text-red-400">{{ __('admin_commerce.override_revoke_submit') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-2">{{ __('admin_commerce.override_grant_title') }}</h3>
        <form method="POST" action="{{ route('admin.commerce.overrides.grant', $user) }}" class="flex flex-col sm:flex-row flex-wrap gap-3 items-end">
            @csrf
            <div class="flex-1 min-w-[12rem]">
                <label class="block text-xs text-gray-600 dark:text-gray-300 mb-1">{{ __('admin_commerce.override_grant_key') }}</label>
                <select name="entitlement_key" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm font-mono">
                    @foreach ($grantableKeys as $k)
                        <option value="{{ $k }}">{{ $k }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex-1 min-w-[10rem]">
                <label class="block text-xs text-gray-600 dark:text-gray-300 mb-1">{{ __('admin_commerce.override_grant_until') }}</label>
                <input type="datetime-local" name="valid_until" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
            </div>
            <button type="submit" class="rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                {{ __('admin_commerce.override_grant_submit') }}
            </button>
        </form>
    </div>
</div>
@endsection
