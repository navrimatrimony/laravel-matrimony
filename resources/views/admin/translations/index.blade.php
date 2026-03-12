@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
    <div class="p-6">
        <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-1">Translations (EN / MR)</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400">Edit only the display values. Key (small letter English) is read-only. DB overrides file.</p>
            </div>
            <a href="{{ route('admin.translations.create') }}" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700">+ Add alias</a>
        </div>

        <form method="GET" action="{{ route('admin.translations.index') }}" class="flex flex-wrap gap-3 mb-4">
            <select name="namespace" class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2 text-sm">
                <option value="">All namespaces</option>
                @foreach($namespaces as $ns)
                    <option value="{{ $ns }}" {{ $currentNamespace === $ns ? 'selected' : '' }}>{{ $ns }}</option>
                @endforeach
            </select>
            <input type="text" name="search" value="{{ $search }}" placeholder="Search key…" class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2 text-sm min-w-[12rem]">
            <button type="submit" class="px-4 py-2 bg-gray-200 dark:bg-gray-600 text-gray-800 dark:text-gray-200 rounded-lg text-sm font-medium hover:bg-gray-300 dark:hover:bg-gray-500">Filter</button>
        </form>

        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50">
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Key <span class="text-gray-400 font-normal">(read-only)</span></th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">English</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Marathi</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300 w-24">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($list as $row)
                        <tr class="border-b border-gray-100 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700/30">
                            <td class="py-3 px-4 text-gray-600 dark:text-gray-400 font-mono text-sm">{{ $row['key'] }}</td>
                            <td class="py-3 px-4 text-gray-800 dark:text-gray-200 max-w-xs truncate" title="{{ $row['value_en'] }}">{{ $row['value_en'] ?: '—' }}</td>
                            <td class="py-3 px-4 text-gray-800 dark:text-gray-200 max-w-xs truncate" title="{{ $row['value_mr'] }}">{{ $row['value_mr'] ?: '—' }}</td>
                            <td class="py-3 px-4">
                                <a href="{{ route('admin.translations.edit', ['key' => $row['key']]) }}" class="text-sm text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">Edit</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-8 px-4 text-center text-gray-500 dark:text-gray-400">No keys found. Use Add alias to create one.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
