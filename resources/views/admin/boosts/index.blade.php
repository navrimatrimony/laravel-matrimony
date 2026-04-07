@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('admin_monetization.boosts_title') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('admin_monetization.boosts_intro') }}</p>
    </div>

    @if (session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ session('error') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <ul class="list-disc list-inside">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 mb-8 max-w-xl">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">{{ __('admin_monetization.boost_start_heading') }}</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">{{ __('admin_monetization.boost_start_help') }}</p>
        <form method="POST" action="{{ route('admin.boosts.start') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('admin_monetization.boost_user_id') }}</label>
                <input type="number" name="user_id" value="{{ old('user_id') }}" required min="1" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm w-40" />
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('admin_monetization.boost_hours') }}</label>
                <input type="number" name="duration_hours" value="{{ old('duration_hours', 24) }}" required min="1" max="8760" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm w-32" />
            </div>
            <button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg">
                {{ __('admin_monetization.boost_submit') }}
            </button>
        </form>
    </div>

    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-3">{{ __('admin_monetization.boost_active_heading') }}</h2>
    <div class="overflow-x-auto mb-10">
        <table class="min-w-full text-sm text-left">
            <thead class="text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-600">
                <tr>
                    <th class="py-3 pr-4">{{ __('admin_monetization.col_user') }}</th>
                    <th class="py-3 pr-4">{{ __('admin_monetization.col_starts') }}</th>
                    <th class="py-3 pr-4">{{ __('admin_monetization.col_ends') }}</th>
                    <th class="py-3 pr-4">{{ __('admin_monetization.col_source') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($activeBoosts as $b)
                    <tr>
                        <td class="py-3 pr-4">
                            #{{ $b->user_id }}
                            @if ($b->user)
                                <span class="text-gray-600 dark:text-gray-300"> — {{ $b->user->name }}</span>
                            @endif
                        </td>
                        <td class="py-3 pr-4 text-gray-600 dark:text-gray-300">{{ $b->starts_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                        <td class="py-3 pr-4 font-medium text-emerald-600 dark:text-emerald-400">{{ $b->ends_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                        <td class="py-3 pr-4 text-gray-500">{{ $b->source ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="py-8 text-center text-gray-500">{{ __('admin_monetization.boosts_none_active') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mb-8">{{ $activeBoosts->links() }}</div>

    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-3">{{ __('admin_monetization.boost_recent_heading') }}</h2>
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm text-left">
            <thead class="text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-600">
                <tr>
                    <th class="py-3 pr-4">ID</th>
                    <th class="py-3 pr-4">{{ __('admin_monetization.col_user') }}</th>
                    <th class="py-3 pr-4">{{ __('admin_monetization.col_ends') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($recentBoosts as $b)
                    <tr>
                        <td class="py-3 pr-4 font-mono text-xs">{{ $b->id }}</td>
                        <td class="py-3 pr-4">#{{ $b->user_id }} @if ($b->user) — {{ $b->user->name }} @endif</td>
                        <td class="py-3 pr-4">{{ $b->ends_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
