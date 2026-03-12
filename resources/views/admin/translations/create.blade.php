@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
    <div class="p-6">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Add alias (new translation key)</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">Add a new key and its English and Marathi values. Key must use only letters, numbers, dots and underscores (e.g. <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">components.options.diet.jain_food</code>).</p>

        <form method="POST" action="{{ route('admin.translations.store') }}" class="max-w-2xl space-y-4">
            @csrf
            <div>
                <label for="key" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">New key</label>
                <input type="text" name="key" id="key" value="{{ old('key') }}" required maxlength="255" pattern="[a-zA-Z0-9_.]+" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-200 px-3 py-2 font-mono text-sm focus:ring-2 focus:ring-indigo-500" placeholder="e.g. components.options.diet.jain_food">
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Only a-z, 0-9, dot and underscore. Example: components.options.diet.jain_food</p>
                @error('key')
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="value_en" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">English (display value)</label>
                <input type="text" name="value_en" id="value_en" value="{{ old('value_en') }}" required maxlength="1000" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-200 px-3 py-2 focus:ring-2 focus:ring-indigo-500" placeholder="English text">
                @error('value_en')
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="value_mr" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Marathi (display value)</label>
                <input type="text" name="value_mr" id="value_mr" value="{{ old('value_mr') }}" maxlength="1000" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-200 px-3 py-2 focus:ring-2 focus:ring-indigo-500" placeholder="मराठी मूल्य">
                @error('value_mr')
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>
            <div class="flex gap-3">
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700">Add alias</button>
                <a href="{{ route('admin.translations.index') }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 text-sm hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
