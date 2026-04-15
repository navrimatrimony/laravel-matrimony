@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('subscriptions.admin_plans_title') }}</h1>
            <p class="text-gray-500 dark:text-gray-400 text-sm mt-1">{{ __('subscriptions.admin_plans_intro') }}</p>
        </div>
        <a href="{{ route('admin.plans.create') }}" class="inline-flex items-center px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg">
            {{ __('subscriptions.create_plan') }}
        </a>
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

    <div class="overflow-x-auto">
        <table class="min-w-full text-sm text-left">
            <thead class="text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-600">
                <tr>
                    <th class="py-3 pr-4">Name</th>
                    <th class="py-3 pr-4">Slug</th>
                    <th class="py-3 pr-4">Price</th>
                    <th class="py-3 pr-4">Final</th>
                    <th class="py-3 pr-4">Days</th>
                    <th class="py-3 pr-4">Active</th>
                    <th class="py-3 pr-4">Highlight</th>
                    <th class="py-3 pr-4"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($plans as $plan)
                    @php
                        $displayName = preg_replace('/\s*\((male|female)\)\s*$/i', '', (string) $plan->name) ?? (string) $plan->name;
                    @endphp
                    <tr>
                        <td class="py-3 pr-4 font-medium text-gray-900 dark:text-gray-100">
                            {{ $displayName }}
                            @if ($plan->highlight)
                                <span class="ml-2 text-xs font-semibold text-amber-600 dark:text-amber-400">★</span>
                            @endif
                        </td>
                        <td class="py-3 pr-4 text-gray-600 dark:text-gray-300">{{ $plan->slug }}</td>
                        <td class="py-3 pr-4">₹{{ number_format((float) $plan->price, 2) }}</td>
                        <td class="py-3 pr-4 font-semibold text-emerald-600 dark:text-emerald-400">₹{{ number_format($plan->final_price, 2) }}</td>
                        <td class="py-3 pr-4">{{ $plan->duration_days === 0 ? '∞' : $plan->duration_days }}</td>
                        <td class="py-3 pr-4">
                            <form method="POST" action="{{ route('admin.plans.toggle', $plan) }}" class="inline">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="field" value="is_active" />
                                <input type="hidden" name="value" value="{{ $plan->is_active ? '0' : '1' }}" />
                                <button type="submit" class="text-xs font-semibold rounded-md px-2 py-1 {{ $plan->is_active ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' : 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200' }}">
                                    {{ $plan->is_active ? 'On' : 'Off' }}
                                </button>
                            </form>
                        </td>
                        <td class="py-3 pr-4">
                            <form method="POST" action="{{ route('admin.plans.toggle', $plan) }}" class="inline">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="field" value="highlight" />
                                <input type="hidden" name="value" value="{{ $plan->highlight ? '0' : '1' }}" />
                                <button type="submit" class="text-xs font-semibold rounded-md px-2 py-1 {{ $plan->highlight ? 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100' : 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200' }}">
                                    {{ $plan->highlight ? '★' : '—' }}
                                </button>
                            </form>
                        </td>
                        <td class="py-3 pr-4 whitespace-nowrap">
                            <a href="{{ route('admin.plans.edit', $plan) }}" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 mr-3">Edit</a>
                            @if (! \App\Models\Plan::isFreeCatalogSlug((string) $plan->slug))
                                <form method="POST" action="{{ route('admin.plans.destroy', $plan) }}" class="inline" onsubmit="return confirm(@json(__('admin_commerce.plan_confirm_delete')));">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-600 hover:text-red-800 dark:text-red-400 text-xs font-semibold">{{ __('admin_commerce.plan_delete') }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-8 flex flex-wrap items-center justify-between gap-4 pt-6 border-t border-gray-200 dark:border-gray-600">
        <p class="text-sm text-gray-600 dark:text-gray-400 max-w-xl">
            {{ __('subscriptions.admin_plans_index_footer_hint') }}
        </p>
        <a href="{{ route('admin.plans.create') }}" class="inline-flex items-center px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg shrink-0">
            {{ __('subscriptions.create_plan') }}
        </a>
    </div>
</div>
@endsection
