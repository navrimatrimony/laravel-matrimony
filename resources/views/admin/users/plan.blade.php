@extends('layouts.admin')

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('user_plan.admin_title') }}</h1>
            <p class="text-sm text-gray-600 dark:text-gray-300 mt-1">#{{ $user->id }} — {{ $user->email }}</p>
        </div>
        <a href="{{ route('admin.commerce.overrides.show', $user) }}" class="text-sm font-medium text-indigo-600 dark:text-indigo-400">{{ __('admin_commerce.override_back') }}</a>
    </div>

    <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 shadow-sm p-5 sm:p-6">
        @include('partials.quota-plan-summary-panel', ['quotaSummary' => $quotaSummary])
    </div>
</div>
@endsection
