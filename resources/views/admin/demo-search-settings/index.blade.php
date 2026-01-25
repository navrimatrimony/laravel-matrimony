@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Demo Search Visibility</h1>
    <p class="text-gray-500 dark:text-gray-400 text-sm mb-6">Show or hide all demo profiles from search. Profile view, interests, and completeness rules unchanged.</p>
    @if ($errors->any())
        <ul class="text-red-600 text-sm mb-4 space-y-1">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    @endif
    <div class="mb-6 p-4 bg-sky-50 dark:bg-sky-900/20 border border-sky-200 dark:border-sky-800 rounded-lg text-sky-800 dark:text-sky-200 text-sm">
        <p class="font-semibold mb-1">Show demo profiles in search</p>
        <p>When OFF, all demo profiles are excluded from search results.</p>
    </div>
    <form method="POST" action="{{ route('admin.demo-search-settings.update') }}" class="space-y-4">
        @csrf
        <div>
            <label class="flex items-center gap-2">
                <input type="checkbox" name="demo_profiles_visible_in_search" value="1" {{ $demoProfilesVisibleInSearch ? 'checked' : '' }} class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Show demo profiles in search</span>
            </label>
        </div>
        <button type="submit" style="background-color: #4f46e5; color: white; padding: 10px 20px; border-radius: 6px; font-weight: 600; font-size: 14px; border: none; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">Save</button>
    </form>
</div>
@endsection
