@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
    <div class="p-6">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6">Edit Caste</h1>
        <form method="POST" action="{{ route('admin.master.castes.update', $caste) }}" class="max-w-md space-y-4">
            @csrf
            @method('PUT')
            <div>
                <label for="religion_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Religion</label>
                <select name="religion_id" id="religion_id" required
                    class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-200 px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                    @foreach ($religions as $r)
                        <option value="{{ $r->id }}" {{ old('religion_id', $caste->religion_id) == $r->id ? 'selected' : '' }}>{{ $r->label }}</option>
                    @endforeach
                </select>
                @error('religion_id')
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="label" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Label</label>
                <input type="text" name="label" id="label" value="{{ old('label', $caste->label) }}" required
                    class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-200 px-3 py-2 focus:ring-2 focus:ring-indigo-500"
                    placeholder="e.g. Brahmin">
                @error('label')
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>
            <div class="flex gap-3">
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700">Update</button>
                <a href="{{ route('admin.master.castes.index') }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 text-sm hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
