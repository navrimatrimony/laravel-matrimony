@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Bulk Create Demo Profiles (1–50)</h1>
    <p class="text-gray-500 dark:text-gray-400 text-sm mb-6">Create multiple demo profiles. All mandatory fields auto-filled per profile.</p>
    @if ($errors->any())
        <ul class="text-red-600 text-sm mb-4 space-y-1">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    @endif
    <form method="POST" action="{{ route('admin.demo-profile.bulk-store') }}" class="space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Number of profiles (1–50)</label>
            <input type="number" name="count" min="1" max="50" value="{{ old('count', '5') }}" required class="border border-gray-300 dark:border-gray-600 rounded px-3 py-2 w-24 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Gender</label>
            <select name="gender" class="border border-gray-300 dark:border-gray-600 rounded px-3 py-2 w-full max-w-xs bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                <option value="random" {{ old('gender', 'random') === 'random' ? 'selected' : '' }}>Random</option>
                <option value="male" {{ old('gender') === 'male' ? 'selected' : '' }}>Male</option>
                <option value="female" {{ old('gender') === 'female' ? 'selected' : '' }}>Female</option>
            </select>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Random = each profile gets random gender. Otherwise all use selected gender; other fields remain random per profile.</p>
        </div>
        <p class="text-sm text-gray-500 dark:text-gray-400">All other mandatory fields are auto-filled with random values per profile. No manual input.</p>
        <div class="flex gap-3">
            <button type="submit" style="background-color: #059669; color: white; padding: 10px 20px; border-radius: 6px; font-weight: 600; font-size: 14px; border: none; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">Create</button>
            <a href="{{ route('admin.demo-profile.create') }}" class="px-4 py-2 text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 text-sm">Cancel</a>
        </div>
    </form>
</div>
@endsection
