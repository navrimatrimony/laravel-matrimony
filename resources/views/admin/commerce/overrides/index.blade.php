@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6 max-w-lg">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('admin_commerce.overrides_title') }}</h1>
    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">{{ __('admin_commerce.overrides_intro') }}</p>

    @if (session('error'))
        <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
            {{ session('error') }}
        </div>
    @endif

    <form method="POST" action="{{ route('admin.commerce.overrides.lookup') }}" class="mt-6 space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin_commerce.overrides_email') }}</label>
            <input type="email" name="email" value="{{ old('email') }}"
                class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm" />
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400 text-center">— or —</p>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin_commerce.overrides_user_id') }}</label>
            <input type="number" name="user_id" value="{{ old('user_id') }}" min="1"
                class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm" />
        </div>
        <button type="submit" class="w-full sm:w-auto px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg">
            {{ __('admin_commerce.overrides_lookup') }}
        </button>
    </form>
</div>
@endsection
