@extends('layouts.app')

@section('content')
<div class="py-12">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
        <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ __('user_plan.plan_history_title') }}</h1>
            <a href="{{ route('user.my-plan') }}" class="text-sm font-medium text-red-700 hover:underline dark:text-red-400">{{ __('user_plan.back_my_plan') }}</a>
        </div>

        <div class="bg-white dark:bg-gray-800 shadow-sm rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/60 text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-3">{{ __('user_plan.history_plan') }}</th>
                            <th class="px-4 py-3">{{ __('user_plan.history_start') }}</th>
                            <th class="px-4 py-3">{{ __('user_plan.history_end') }}</th>
                            <th class="px-4 py-3">{{ __('user_plan.history_status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($subscriptions as $sub)
                            <tr>
                                <td class="px-4 py-3">{{ $sub->plan?->name ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $sub->starts_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $sub->ends_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $sub->status }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">—</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
