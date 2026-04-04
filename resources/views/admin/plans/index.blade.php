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

    <div class="overflow-x-auto">
        <table class="min-w-full text-sm text-left">
            <thead class="text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-600">
                <tr>
                    <th class="py-3 pr-4">Name</th>
                    <th class="py-3 pr-4">Slug</th>
                    <th class="py-3 pr-4">Price</th>
                    <th class="py-3 pr-4">Discount</th>
                    <th class="py-3 pr-4">Final</th>
                    <th class="py-3 pr-4">Days</th>
                    <th class="py-3 pr-4">Active</th>
                    <th class="py-3 pr-4"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($plans as $plan)
                    <tr>
                        <td class="py-3 pr-4 font-medium text-gray-900 dark:text-gray-100">
                            {{ $plan->name }}
                            @if ($plan->highlight)
                                <span class="ml-2 text-xs font-semibold text-amber-600 dark:text-amber-400">★</span>
                            @endif
                        </td>
                        <td class="py-3 pr-4 text-gray-600 dark:text-gray-300">{{ $plan->slug }}</td>
                        <td class="py-3 pr-4">₹{{ number_format((float) $plan->price, 2) }}</td>
                        <td class="py-3 pr-4">{{ $plan->discount_percent !== null ? $plan->discount_percent.'%' : '—' }}</td>
                        <td class="py-3 pr-4 font-semibold text-emerald-600 dark:text-emerald-400">₹{{ number_format($plan->final_price, 2) }}</td>
                        <td class="py-3 pr-4">{{ $plan->duration_days === 0 ? '∞' : $plan->duration_days }}</td>
                        <td class="py-3 pr-4">{{ $plan->is_active ? 'Yes' : 'No' }}</td>
                        <td class="py-3 pr-4">
                            <a href="{{ route('admin.plans.edit', $plan) }}" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">Edit</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
