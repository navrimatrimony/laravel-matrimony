@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">View-Back Settings</h1>
    <p class="text-gray-500 dark:text-gray-400 text-sm mb-6">Control demo → real view-back. Max one per demo–real pair per 24 hours. No recursion.</p>
    @if ($errors->any())
        <ul class="text-red-600 text-sm mb-4 space-y-1">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    @endif
    <div class="mb-6 p-4 bg-sky-50 dark:bg-sky-900/20 border border-sky-200 dark:border-sky-800 rounded-lg text-sky-800 dark:text-sky-200 text-sm">
        <p class="font-semibold mb-1">Demo → Real view-back</p>
        <p>When a real user views a demo profile, the system may create a view-back (demo views real).</p>
    </div>
    <form method="POST" action="{{ route('admin.view-back-settings.update') }}" class="space-y-4">
        @csrf
        <div>
            <label class="flex items-center gap-2">
                <input type="checkbox" name="view_back_enabled" value="1" {{ $viewBackEnabled ? 'checked' : '' }} class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Enable view-back</span>
            </label>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Probability (0–100)</label>
            <input type="number" name="view_back_probability" min="0" max="100" value="{{ $viewBackProbability }}" required class="border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 w-24 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
        </div>

        {{-- Delay settings --}}
        <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
            <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-2">View-Back Delay</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Random delay before view-back is created. Leave at 0 for instant behavior.</p>
            <div class="flex gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Min delay (minutes)</label>
                    <input type="number" name="view_back_delay_min" min="0" max="1440" value="{{ $viewBackDelayMin }}" required class="border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 w-24 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Max delay (minutes)</label>
                    <input type="number" name="view_back_delay_max" min="0" max="1440" value="{{ $viewBackDelayMax }}" required class="border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 w-24 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                </div>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Actual delay = random value between min and max.</p>
        </div>

        <div class="pt-6">
            <button type="submit" style="background-color: #4f46e5; color: white; padding: 12px 24px; border-radius: 6px; font-weight: 600; font-size: 16px; border: none; cursor: pointer; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                Save Settings
            </button>
        </div>
    </form>
</div>
@endsection
