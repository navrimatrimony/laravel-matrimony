@extends('layouts.app')

@section('content')
<div class="max-w-2xl mx-auto py-8 px-4">
    <div class="border rounded-lg p-6 bg-white">
        <p class="text-gray-900 font-medium">{{ $notification->data['message'] ?? 'Notification' }}</p>
        <p class="text-sm text-gray-500 mt-2">{{ $notification->created_at->format('M j, Y g:i A') }}</p>
        <p class="mt-4">
            <a href="{{ route('notifications.index') }}" class="text-indigo-600 hover:underline">← Back to notifications</a>
        </p>
    </div>
</div>
@endsection
