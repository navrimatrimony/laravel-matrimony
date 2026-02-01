@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
    <div class="p-6">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">EXTENDED Fields</h1>
                <p class="text-gray-500 dark:text-gray-400 text-sm">EXTENDED field definitions. Admin can create new fields.</p>
            </div>
            <div class="flex gap-3">
                <a href="{{ route('admin.field-registry.extended.create') }}" style="background-color: #4f46e5; color: white; padding: 10px 20px; border-radius: 6px; font-weight: 600; font-size: 14px; border: none; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">Create EXTENDED Field</a>
                <a href="{{ route('admin.field-registry.index') }}" class="text-sm text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300">← View CORE fields</a>
            </div>
        </div>

        @if (session('success'))
            <div class="mb-4 px-4 py-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800">{{ session('success') }}</div>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50">
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">field_key</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">field_type</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">data_type</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">display_label</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">category</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">display_order</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">is_archived</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Day 8</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($fields as $field)
                        <tr class="border-b border-gray-100 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700/30">
                            <td class="py-3 px-4 text-gray-800 dark:text-gray-200 font-mono text-sm">{{ $field->field_key }}</td>
                            <td class="py-3 px-4 text-gray-700 dark:text-gray-300">{{ $field->field_type }}</td>
                            <td class="py-3 px-4 text-gray-700 dark:text-gray-300">{{ $field->data_type }}</td>
                            <td class="py-3 px-4 text-gray-700 dark:text-gray-300">{{ $field->display_label }}</td>
                            <td class="py-3 px-4 text-gray-700 dark:text-gray-300">{{ $field->category }}</td>
                            <td class="py-3 px-4 text-gray-700 dark:text-gray-300">{{ $field->display_order }}</td>
                            <td class="py-3 px-4 text-gray-700 dark:text-gray-300">{{ $field->is_archived ? 'Yes' : 'No' }}</td>
                            <td class="py-3 px-4">
                                @if ($field->is_archived)
                                    <form method="POST" action="{{ route('admin.field-registry.unarchive', $field) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-xs px-2 py-1 rounded bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-200 dark:hover:bg-emerald-800/50">Unarchive</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.field-registry.archive', $field) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-xs px-2 py-1 rounded bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 hover:bg-amber-200 dark:hover:bg-amber-800/50">Archive</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-8 px-4 text-center text-gray-500">No EXTENDED fields defined yet. <a href="{{ route('admin.field-registry.extended.create') }}" class="text-indigo-600 hover:underline">Create one</a></td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
