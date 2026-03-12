@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
    <div class="p-6">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Edit translation</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">Key is read-only. Only the display values (EN / MR) can be edited.</p>

        <form method="POST" action="{{ route('admin.translations.update') }}" class="max-w-2xl space-y-4">
            @csrf
            @method('PUT')
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Key <span class="text-gray-400">(read-only)</span></label>
                <input type="text" value="{{ $key }}" readonly class="w-full rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 px-3 py-2 font-mono text-sm">
                <input type="hidden" name="key" value="{{ $key }}">
            </div>
            <div>
                <label for="value_en" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">English (display value)</label>
                <input type="text" name="value_en" id="value_en" value="{{ old('value_en', $value_en) }}" maxlength="1000" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-200 px-3 py-2 focus:ring-2 focus:ring-indigo-500" placeholder="English text">
                @error('value_en')
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="value_mr" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Marathi (display value)</label>
                <input type="text" name="value_mr" id="value_mr" value="{{ old('value_mr', $value_mr) }}" maxlength="1000" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-200 px-3 py-2 focus:ring-2 focus:ring-indigo-500" placeholder="मराठी मूल्य">
                @error('value_mr')
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>
            <div class="flex gap-3">
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700">Save</button>
                <a href="{{ route('admin.translations.index') }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 text-sm hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
