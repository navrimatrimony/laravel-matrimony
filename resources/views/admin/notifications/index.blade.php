@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Notification Settings (Debug)</h1>
    <p class="text-gray-500 dark:text-gray-400 text-sm mb-6">View any user’s notifications for debugging or dispute resolution. View-only; no actions.</p>
    @if ($errors->any())
        <ul class="text-red-600 text-sm mb-4 space-y-1">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    @endif
    <form method="GET" action="{{ route('admin.notifications.user.show') }}" class="flex gap-3 items-end">
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">User ID</label>
            <input type="number" name="user_id" min="1" required placeholder="e.g. 1" value="{{ old('user_id') }}" class="border border-gray-300 dark:border-gray-600 rounded px-3 py-2 w-32 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
        </div>
        <button type="submit" style="background-color: #4f46e5; color: white; padding: 10px 20px; border-radius: 6px; font-weight: 600; font-size: 14px; border: none; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">View notifications</button>
    </form>
</div>
@endsection
