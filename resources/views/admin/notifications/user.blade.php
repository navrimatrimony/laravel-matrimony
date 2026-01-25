@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Notifications for User #{{ $targetUser->id }}</h1>
    <p class="text-gray-500 dark:text-gray-400 text-sm mb-4">{{ $targetUser->name }} ({{ $targetUser->email }}) — debug only, read-only.</p>
    <p class="mb-6">
        <a href="{{ route('admin.notifications.index') }}" class="text-indigo-600 dark:text-indigo-400 hover:underline text-sm font-medium">← Change user</a>
    </p>
    @forelse ($notifications as $n)
        <div class="border rounded-lg p-4 mb-3 {{ $n->read_at ? 'bg-gray-50 dark:bg-gray-700/30 border-gray-200 dark:border-gray-600' : 'bg-white dark:bg-gray-700/50 border-gray-200 dark:border-gray-600' }}">
            <p class="font-medium text-gray-800 dark:text-gray-100">{{ $n->data['message'] ?? 'Notification' }}</p>
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
        <p class="text-gray-500 dark:text-gray-400 py-4">No notifications.</p>
    @endforelse
    <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-600">{{ $notifications->links() }}</div>
</div>
@endsection
