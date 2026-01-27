@extends('layouts.admin')

@section('content')
{{-- Toggle Switch Styles --}}
<style>
.admin-toggle { position: relative; display: inline-flex; align-items: center; cursor: pointer; }
.admin-toggle input { opacity: 0; width: 0; height: 0; position: absolute; }
.admin-toggle .toggle-track { width: 52px; height: 28px; background-color: #d1d5db; border-radius: 9999px; transition: background-color 0.2s ease; position: relative; }
.admin-toggle input:checked + .toggle-track { background-color: #10b981; }
.admin-toggle .toggle-thumb { position: absolute; top: 2px; left: 2px; width: 24px; height: 24px; background-color: white; border-radius: 9999px; transition: transform 0.2s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
.admin-toggle input:checked + .toggle-track .toggle-thumb { transform: translateX(24px); }
.admin-toggle .toggle-label { margin-left: 12px; font-weight: 600; font-size: 14px; }
.admin-toggle .toggle-label.on { color: #059669; }
.admin-toggle .toggle-label.off { color: #6b7280; }
</style>

<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Demo Search Visibility</h1>
    <p class="text-gray-500 dark:text-gray-400 text-sm mb-6">Show or hide all demo profiles from search. Profile view, interests, and completeness rules unchanged.</p>
    @if ($errors->any())
        <ul class="text-red-600 text-sm mb-4 space-y-1">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    @endif
    <div class="mb-6 p-4 bg-sky-50 dark:bg-sky-900/20 border border-sky-200 dark:border-sky-800 rounded-lg text-sky-800 dark:text-sky-200 text-sm">
        <p class="font-semibold mb-1">Demo Profiles in Search Results</p>
        <p>Controls whether demo profiles appear in user search results. Direct profile views and interest actions are unaffected.</p>
    </div>
    <form method="POST" action="{{ route('admin.demo-search-settings.update') }}" class="space-y-6">
        @csrf

        {{-- Demo Search Visibility Toggle --}}
        <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-200 dark:border-gray-600">
            <label class="admin-toggle" id="demoSearchToggle">
                <input type="checkbox" name="demo_profiles_visible_in_search" value="1" {{ $demoProfilesVisibleInSearch ? 'checked' : '' }} onchange="updateDemoSearchUI()">
                <span class="toggle-track"><span class="toggle-thumb"></span></span>
                <span class="toggle-label {{ $demoProfilesVisibleInSearch ? 'on' : 'off' }}" id="demoSearchLabel">
                    {{ $demoProfilesVisibleInSearch ? 'Demo Profiles VISIBLE in Search' : 'Demo Profiles HIDDEN from Search' }}
                </span>
            </label>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">When OFF, demo profiles will not appear in search results but can still be accessed via direct links.</p>
        </div>

        <div class="pt-2">
            <button type="submit" style="background-color: #4f46e5; color: white; padding: 12px 24px; border-radius: 6px; font-weight: 600; font-size: 16px; border: none; cursor: pointer; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                Save Settings
            </button>
        </div>
    </form>
</div>

<script>
function updateDemoSearchUI() {
    const checkbox = document.querySelector('#demoSearchToggle input');
    const label = document.getElementById('demoSearchLabel');
    
    if (checkbox.checked) {
        label.textContent = 'Demo Profiles VISIBLE in Search';
        label.classList.remove('off');
        label.classList.add('on');
    } else {
        label.textContent = 'Demo Profiles HIDDEN from Search';
        label.classList.remove('on');
        label.classList.add('off');
    }
}
</script>
@endsection
