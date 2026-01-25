@extends('layouts.app')

@section('content')
<div class="max-w-3xl mx-auto py-8 px-4">
    <h1 class="text-2xl font-bold mb-4">Notifications</h1>

    @if (session('success'))
        <p class="text-green-600 mb-4">{{ session('success') }}</p>
    @endif

    @if ($unreadNotifications->isNotEmpty())
        <form method="POST" action="{{ route('notifications.mark-all-read') }}" class="mb-4">
            @csrf
            <button type="submit" style="background-color: #4f46e5; color: white; padding: 10px 20px; border-radius: 6px; font-weight: 600; font-size: 14px; border: none; cursor: pointer;">Mark all as read</button>
        </form>
    @endif

    @forelse ($notifications as $n)
        <div class="border rounded-lg p-4 mb-3 {{ $n->read_at ? 'bg-gray-50' : 'bg-white border-l-4 border-l-indigo-500' }}">
            <div class="flex justify-between items-start gap-2">
                <div>
                    <a href="{{ route('notifications.show', $n->id) }}" class="block font-medium {{ $n->read_at ? 'text-gray-700' : 'text-gray-900' }}">
                        {{ $n->data['message'] ?? 'Notification' }}
                    </a>
                    <p class="text-sm text-gray-500 mt-1">{{ $n->created_at->diffForHumans() }}</p>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <a href="{{ route('notifications.show', $n->id) }}" class="text-indigo-600 text-sm hover:underline">Open</a>
                    @if (!$n->read_at)
                        <form method="POST" action="{{ route('notifications.mark-read', $n->id) }}" class="inline">
                            @csrf
                            <button type="submit" class="text-gray-500 text-sm hover:underline">Mark read</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    @empty
        <p class="text-gray-500">No notifications.</p>
    @endforelse

    <div class="mt-4">{{ $notifications->links() }}</div>
</div>
@endsection
