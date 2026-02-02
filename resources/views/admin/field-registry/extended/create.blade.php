@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Create EXTENDED Field</h1>
        <p class="text-gray-500 dark:text-gray-400 text-sm mb-4">Define a new EXTENDED field. Field key is immutable after creation.</p>
        <a href="{{ route('admin.field-registry.extended.index') }}" class="text-sm text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300">← Back to EXTENDED fields</a>
    </div>

    @if ($errors->any())
        <ul class="text-red-600 text-sm mb-4 space-y-1 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    @endif

    <div class="mb-6 p-4 bg-sky-50 dark:bg-sky-900/20 border border-sky-200 dark:border-sky-800 rounded-lg text-sky-800 dark:text-sky-200 text-sm">
        <p class="font-semibold mb-2">EXTENDED Field Rules</p>
        <ul class="list-disc pl-5 space-y-1">
            <li><strong>Field Key:</strong> Immutable after creation. Use lowercase letters, numbers, and underscores only (e.g., property_details, children_info).</li>
            <li><strong>Data Type:</strong> Determines how values are stored and validated.</li>
            <li><strong>Display Label:</strong> User-facing label (can be changed later).</li>
            <li><strong>EXTENDED fields:</strong> Not searchable, not part of completeness calculation, no app dependency.</li>
        </ul>
    </div>

    <form method="POST" action="{{ route('admin.field-registry.extended.store') }}" class="space-y-6">
        @csrf

        <div>
            <label for="field_key" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Field Key <span class="text-red-600">*</span>
            </label>
            <input type="text" 
                   id="field_key" 
                   name="field_key" 
                   value="{{ old('field_key') }}" 
                   required 
                   pattern="[a-z0-9_]+"
                   placeholder="e.g., property_details"
                   class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Lowercase letters, numbers, and underscores only. Cannot be changed after creation.</p>
        </div>

        <div>
            <label for="data_type" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Data Type <span class="text-red-600">*</span>
            </label>
            <select id="data_type" 
                    name="data_type" 
                    required 
                    class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                <option value="">Select data type</option>
                <option value="text" {{ old('data_type') === 'text' ? 'selected' : '' }}>Text</option>
                <option value="number" {{ old('data_type') === 'number' ? 'selected' : '' }}>Number</option>
                <option value="date" {{ old('data_type') === 'date' ? 'selected' : '' }}>Date</option>
                <option value="boolean" {{ old('data_type') === 'boolean' ? 'selected' : '' }}>Boolean</option>
                <option value="select" {{ old('data_type') === 'select' ? 'selected' : '' }}>Select</option>
            </select>
        </div>

        <div>
            <label for="display_label" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Display Label <span class="text-red-600">*</span>
            </label>
            <input type="text" 
                   id="display_label" 
                   name="display_label" 
                   value="{{ old('display_label') }}" 
                   required 
                   maxlength="128"
                   placeholder="e.g., Property Details"
                   class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">User-facing label. Can be changed later.</p>
        </div>

        <div>
            <label for="category" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Category
            </label>
            <input type="text" 
                   id="category" 
                   name="category" 
                   value="{{ old('category', 'basic') }}" 
                   maxlength="64"
                   placeholder="e.g., basic, family, preferences"
                   class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Optional. Defaults to 'basic'.</p>
        </div>

        <div>
            <label for="display_order" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Display Order
            </label>
            <input type="number" 
                   id="display_order" 
                   name="display_order" 
                   value="{{ old('display_order', 0) }}" 
                   min="0"
                   class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Optional. Defaults to 0. Lower numbers appear first.</p>
        </div>

        <div class="p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg text-amber-800 dark:text-amber-200 text-sm">
            <p class="font-semibold mb-2">Visibility dependency (optional)</p>
            <p class="mb-3">Show this field only when another EXTENDED field meets a condition. Display/visibility only; does not affect validation or storage.</p>
            <div class="space-y-4">
                <div>
                    <label for="parent_field_key" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Parent EXTENDED field</label>
                    <select id="parent_field_key" name="parent_field_key" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                        <option value="">— None —</option>
                        @foreach ($extendedFields ?? [] as $opt)
                            <option value="{{ $opt->field_key }}" {{ old('parent_field_key') === $opt->field_key ? 'selected' : '' }}>{{ $opt->field_key }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="dependency_type" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Condition type</label>
                    <select id="dependency_type" name="dependency_type" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                        <option value="">—</option>
                        <option value="present" {{ old('dependency_type') === 'present' ? 'selected' : '' }}>present (parent has any value)</option>
                        <option value="equals" {{ old('dependency_type') === 'equals' ? 'selected' : '' }}>equals (parent equals value below)</option>
                    </select>
                </div>
                <div>
                    <label for="dependency_value" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Condition value (for equals)</label>
                    <input type="text" id="dependency_value" name="dependency_value" value="{{ old('dependency_value') }}" maxlength="255" placeholder="Value to match" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                </div>
            </div>
        </div>

        <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
            <button type="submit" style="background-color: #4f46e5; color: white; padding: 12px 24px; border-radius: 6px; font-weight: 600; font-size: 16px; border: none; cursor: pointer; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                Create EXTENDED Field
            </button>
        </div>
    </form>
</div>
@endsection
