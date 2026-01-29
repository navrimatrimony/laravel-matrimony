@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
    <div class="p-6">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Field Registry</h1>
        <p class="text-gray-500 dark:text-gray-400 text-sm mb-6">CORE fields metadata. Read-only.</p>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50">
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">field_key</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">field_type</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">data_type</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">is_mandatory</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">is_searchable</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">category</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">display_label</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">display_order</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">is_archived</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($fields as $field)
                        <tr class="border-b border-gray-100 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700/30">
                            <td class="py-3 px-4 text-gray-800 dark:text-gray-200">{{ $field->field_key }}</td>
                            <td class="py-3 px-4 text-gray-700 dark:text-gray-300">{{ $field->field_type }}</td>
                            <td class="py-3 px-4 text-gray-700 dark:text-gray-300">{{ $field->data_type }}</td>
                            <td class="py-3 px-4 text-gray-700 dark:text-gray-300">{{ $field->is_mandatory ? 'Yes' : 'No' }}</td>
                            <td class="py-3 px-4 text-gray-700 dark:text-gray-300">{{ $field->is_searchable ? 'Yes' : 'No' }}</td>
                            <td class="py-3 px-4 text-gray-700 dark:text-gray-300">{{ $field->category }}</td>
                            <td class="py-3 px-4 text-gray-700 dark:text-gray-300">{{ $field->display_label }}</td>
                            <td class="py-3 px-4 text-gray-700 dark:text-gray-300">{{ $field->display_order }}</td>
                            <td class="py-3 px-4 text-gray-700 dark:text-gray-300">{{ $field->is_archived ? 'Yes' : 'No' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="py-8 px-4 text-center text-gray-500">No CORE fields in registry.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
