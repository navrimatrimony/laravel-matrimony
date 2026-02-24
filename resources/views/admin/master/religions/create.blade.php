@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
    <div class="p-6">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6">Add Religion</h1>
        <form method="POST" action="{{ route('admin.master.religions.store') }}" class="max-w-md space-y-4">
            @csrf
            <div>
                <label for="label" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Label</label>
                <input type="text" name="label" id="label" value="{{ old('label') }}" required class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-3 py-2">
                @error('label')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div class="flex gap-3">
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">Save</button>
                <a href="{{ route('admin.master.religions.index') }}" class="px-4 py-2 border rounded-lg">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
