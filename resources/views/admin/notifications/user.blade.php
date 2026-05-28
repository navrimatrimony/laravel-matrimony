@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">
        {{ __('admin_notifications.debug_user_title', ['id' => $targetUser->id]) }}
    </h1>
    <p class="text-gray-500 dark:text-gray-400 text-sm mb-4">
        {{ __('admin_notifications.debug_user_meta', ['name' => $targetUser->name, 'email' => $targetUser->email ?? '—']) }}
    </p>
    <p class="mb-6 flex flex-wrap gap-4 text-sm">
        <a href="{{ route('admin.notifications.index') }}" class="text-indigo-600 dark:text-indigo-400 hover:underline font-medium">
            {{ __('admin_notifications.debug_change_user') }}
        </a>
        <a href="{{ route('admin.app-settings.index', ['tab' => 'notifications']) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline font-medium">
            {{ __('admin_notifications.nav_platform') }}
        </a>
    </p>
    @forelse ($notifications as $n)
        <div class="border rounded-lg p-4 mb-3 {{ $n->read_at ? 'bg-gray-50 dark:bg-gray-700/30 border-gray-200 dark:border-gray-600' : 'bg-white dark:bg-gray-700/50 border-gray-200 dark:border-gray-600' }}">
            <p class="font-medium text-gray-800 dark:text-gray-100">{{ $n->data['message_mr'] ?? ($n->data['message'] ?? __('admin_notifications.debug_unknown_message')) }}</p>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                {{ $n->created_at->format('M j, Y g:i A') }}
                @if ($n->read_at)
                    · Read at {{ optional($n->read_at)->format('M j, Y g:i A') }}
                @else
                    · Unread
                @endif
            </p>
        </div>
    @empty
        <p class="text-gray-500 dark:text-gray-400 py-4">{{ __('admin_notifications.debug_empty') }}</p>
    @endforelse
    <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-600">{{ $notifications->links() }}</div>
</div>
@endsection
