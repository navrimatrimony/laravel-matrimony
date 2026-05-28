@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">{{ __('admin_notifications.debug_index_title') }}</h1>
    <p class="text-gray-500 dark:text-gray-400 text-sm mb-4">{{ __('admin_notifications.debug_index_intro') }}</p>
    <p class="text-sm mb-6">
        <a href="{{ route('admin.app-settings.index', ['tab' => 'notifications']) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline font-medium">
            {{ __('admin_notifications.debug_platform_link') }}
        </a>
    </p>
    @if ($errors->any())
        <ul class="text-red-600 text-sm mb-4 space-y-1">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    @endif
    <form method="GET" action="{{ route('admin.notifications.index') }}" class="flex gap-3 items-end">
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin_notifications.debug_user_id_label') }}</label>
            <input type="text" name="user_id" required placeholder="e.g. 1111111111 or 42" value="{{ old('user_id') }}" autocomplete="off" class="border border-gray-300 dark:border-gray-600 rounded px-3 py-2 w-48 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 max-w-md">{{ __('admin_notifications.debug_user_id_help') }}</p>
        </div>
        <button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-md font-semibold text-sm hover:bg-indigo-700">
            {{ __('admin_notifications.debug_view_button') }}
        </button>
    </form>
</div>
@endsection
