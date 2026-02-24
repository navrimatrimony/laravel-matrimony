@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
    <div class="p-6">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6">Edit Sub-caste</h1>
        <form method="POST" action="{{ route('admin.master.sub-castes.update', $subCaste) }}" class="max-w-md space-y-4 mb-8">
            @csrf
            @method('PUT')
            <div>
                <label for="caste_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Caste</label>
                <select name="caste_id" id="caste_id" required class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-3 py-2">
                    @foreach ($castes as $c)
                        <option value="{{ $c->id }}" {{ old('caste_id', $subCaste->caste_id) == $c->id ? 'selected' : '' }}>{{ $c->religion?->label ?? '' }} — {{ $c->label }}</option>
                    @endforeach
                </select>
                @error('caste_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="label" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Label</label>
                <input type="text" name="label" id="label" value="{{ old('label', $subCaste->label) }}" required class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-3 py-2">
                @error('label')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div class="flex gap-3">
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">Update</button>
                <a href="{{ route('admin.master.sub-castes.index') }}" class="px-4 py-2 border rounded-lg">Cancel</a>
            </div>
        </form>

        <hr class="border-gray-200 dark:border-gray-600 my-6">
        <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-3">Merge into another sub-caste</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">Reassign all profiles from this sub-caste to another, then disable this one.</p>
        <form method="POST" action="{{ route('admin.master.sub-castes.merge', $subCaste) }}" class="max-w-md space-y-4" onsubmit="return confirm('Merge this sub-caste into the selected one? Profiles will be reassigned and this sub-caste will be disabled.');">
            @csrf
            <div>
                <label for="merge_into_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Merge into</label>
                <select name="merge_into_id" id="merge_into_id" required class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-3 py-2">
                    <option value="">Select sub-caste</option>
                    @foreach ($mergeTargets as $target)
                        <option value="{{ $target->id }}">{{ $target->caste?->religion?->label ?? '' }} — {{ $target->caste?->label ?? '' }} — {{ $target->label }}</option>
                    @endforeach
                </select>
                @error('merge_into_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <button type="submit" class="px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700">Merge and disable</button>
        </form>
    </div>
</div>
@endsection
