@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('admin_monetization.wallets_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('admin_monetization.wallets_intro') }}</p>
        </div>
    </div>

    @if (session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
            {{ session('error') }}
        </div>
    @endif

    <form method="GET" action="{{ route('admin.wallets.index') }}" class="mb-6 flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('admin_monetization.wallet_search') }}</label>
            <input type="text" name="q" value="{{ $q }}" placeholder="{{ __('admin_monetization.wallet_search_placeholder') }}"
                class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm text-sm w-64" />
        </div>
        <button type="submit" class="inline-flex items-center px-3 py-2 bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-gray-100 text-sm font-medium rounded-lg">{{ __('admin_monetization.search') }}</button>
    </form>

    <div class="overflow-x-auto mb-10">
        <table class="min-w-full text-sm text-left">
            <thead class="text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-600">
                <tr>
                    <th class="py-3 pr-4">{{ __('admin_monetization.col_user') }}</th>
                    <th class="py-3 pr-4">{{ __('admin_monetization.col_balance') }}</th>
                    <th class="py-3 pr-4">{{ __('admin_monetization.col_balance_paise') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($wallets as $w)
                    <tr>
                        <td class="py-3 pr-4">
                            <span class="font-medium text-gray-900 dark:text-gray-100">#{{ $w->user_id }}</span>
                            @if ($w->user)
                                <span class="text-gray-600 dark:text-gray-300"> — {{ $w->user->name }}</span>
                                @if ($w->user->mobile)
                                    <span class="text-gray-500 text-xs font-mono">{{ $w->user->mobile }}</span>
                                @endif
                            @endif
                        </td>
                        <td class="py-3 pr-4 font-semibold text-emerald-600 dark:text-emerald-400">₹{{ number_format($w->balance_paise / 100, 2, '.', '') }}</td>
                        <td class="py-3 pr-4 text-gray-500 font-mono text-xs">{{ $w->balance_paise }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="py-8 text-center text-gray-500">{{ __('admin_monetization.wallets_empty') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mb-6">{{ $wallets->links() }}</div>

    <div class="border-t border-gray-200 dark:border-gray-700 pt-6 max-w-lg">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">{{ __('admin_monetization.wallet_credit_heading') }}</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">{{ __('admin_monetization.wallet_credit_help') }}</p>
        <form method="POST" action="{{ route('admin.wallets.credit') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin_monetization.credit_user_id') }}</label>
                <input type="number" name="user_id" value="{{ old('user_id') }}" required min="1"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin_monetization.credit_amount_rupees') }}</label>
                <input type="number" name="amount_rupees" value="{{ old('amount_rupees') }}" required min="0.01" step="0.01"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin_monetization.credit_note') }}</label>
                <input type="text" name="note" value="{{ old('note') }}" maxlength="255"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm" />
            </div>
            @if ($errors->any())
                <ul class="text-red-600 text-sm space-y-1">
                    @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
                </ul>
            @endif
            <button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg">
                {{ __('admin_monetization.credit_submit') }}
            </button>
        </form>
    </div>
</div>
@endsection
