@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6">Create conflict record (testing only)</h1>

    <form method="POST" action="{{ route('admin.conflict-records.store') }}" class="max-w-xl space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Profile</label>
            <select name="profile_id" required class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                <option value="">— Select —</option>
                @foreach ($profiles as $p)
                    <option value="{{ $p->id }}" {{ old('profile_id') == $p->id ? 'selected' : '' }}>{{ $p->id }} — {{ $p->full_name ?? '—' }}</option>
                @endforeach
            </select>
            @error('profile_id')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Field name</label>
            <input type="text" name="field_name" value="{{ old('field_name') }}" required maxlength="255" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100" placeholder="e.g. full_name">
            @error('field_name')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Field type</label>
            <select name="field_type" required class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                <option value="CORE" {{ old('field_type') === 'CORE' ? 'selected' : '' }}>CORE</option>
                <option value="EXTENDED" {{ old('field_type') === 'EXTENDED' ? 'selected' : '' }}>EXTENDED</option>
            </select>
            @error('field_type')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Old value (optional)</label>
            <input type="text" name="old_value" value="{{ old('old_value') }}" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">New value (optional)</label>
            <input type="text" name="new_value" value="{{ old('new_value') }}" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Source</label>
            <select name="source" required class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                <option value="USER" {{ old('source') === 'USER' ? 'selected' : '' }}>USER</option>
                <option value="ADMIN" {{ old('source') === 'ADMIN' ? 'selected' : '' }}>ADMIN</option>
                <option value="OCR" {{ old('source') === 'OCR' ? 'selected' : '' }}>OCR</option>
                <option value="MATCHMAKER" {{ old('source') === 'MATCHMAKER' ? 'selected' : '' }}>MATCHMAKER</option>
                <option value="SYSTEM" {{ old('source') === 'SYSTEM' ? 'selected' : '' }}>SYSTEM</option>
            </select>
            @error('source')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
        </div>
        <div class="flex gap-3 pt-2">
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md font-medium">Create</button>
            <a href="{{ route('admin.conflict-records.index') }}" class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-md font-medium">Cancel</a>
        </div>
    </form>
</div>
@endsection
