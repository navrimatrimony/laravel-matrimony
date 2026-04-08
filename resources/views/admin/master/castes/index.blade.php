@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg min-w-0">
    <div class="p-4 sm:p-6 min-w-0">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Castes</h1>
                <p class="text-gray-500 dark:text-gray-400 text-sm">Master data — unique label per religion. Soft disable only.</p>
            </div>
            <a href="{{ route('admin.master.castes.create') }}" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700">Add Caste</a>
        </div>
        <form method="GET" action="{{ route('admin.master.castes.index') }}" class="mb-4 flex flex-wrap gap-3 items-center">
            <label class="text-sm text-gray-700 dark:text-gray-300">Religion:</label>
            <select name="religion_id" class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-200 px-3 py-2 text-sm">
                <option value="">All</option>
                @foreach ($religions as $r)
                    <option value="{{ $r->id }}" {{ request('religion_id') == $r->id ? 'selected' : '' }}>{{ $r->label }}</option>
                @endforeach
            </select>
            <button type="submit" class="px-3 py-2 bg-gray-200 dark:bg-gray-600 text-gray-800 dark:text-gray-200 rounded-lg text-sm hover:bg-gray-300 dark:hover:bg-gray-500">Filter</button>
        </form>
        {{-- table-fixed + wrapping: full grid visible without horizontal scroll; avoid display:flex on <td> --}}
        <div class="w-full min-w-0">
            <table class="w-full table-fixed border-collapse text-xs sm:text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50">
                        <th scope="col" class="text-left py-2 px-2 w-10 font-semibold text-gray-700 dark:text-gray-300">ID</th>
                        <th scope="col" class="text-left py-2 px-2 w-[11%] min-w-0 font-semibold text-gray-700 dark:text-gray-300">Religion</th>
                        <th scope="col" class="text-left py-2 px-2 min-w-0 font-semibold text-gray-700 dark:text-gray-300">Key</th>
                        <th scope="col" class="text-left py-2 px-2 min-w-0 font-semibold text-gray-700 dark:text-gray-300">Label (legacy)</th>
                        <th scope="col" class="text-left py-2 px-2 min-w-0 font-semibold text-gray-700 dark:text-gray-300">English</th>
                        <th scope="col" class="text-left py-2 px-2 min-w-0 font-semibold text-gray-700 dark:text-gray-300">Marathi</th>
                        <th scope="col" class="text-left py-2 px-2 w-16 font-semibold text-gray-700 dark:text-gray-300">Status</th>
                        <th scope="col" class="text-left py-2 px-2 w-[5.5rem] sm:w-24 font-semibold text-gray-700 dark:text-gray-300">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $item)
                        <tr class="border-b border-gray-100 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700/30">
                            <td class="py-2 px-2 align-top text-gray-800 dark:text-gray-200 tabular-nums">{{ $item->id }}</td>
                            <td class="py-2 px-2 align-top min-w-0 break-words text-gray-700 dark:text-gray-300">{{ $item->religion?->label ?? '—' }}</td>
                            <td class="py-2 px-2 align-top min-w-0 break-all text-gray-700 dark:text-gray-300" title="{{ $item->key }}">{{ $item->key }}</td>
                            <td class="py-2 px-2 align-top min-w-0 break-words text-gray-700 dark:text-gray-300">{{ $item->label }}</td>
                            <td class="py-2 px-2 align-top min-w-0 break-words text-gray-700 dark:text-gray-300">{{ $item->label_en ?? $item->label }}</td>
                            <td class="py-2 px-2 align-top min-w-0 break-words text-gray-700 dark:text-gray-300">{{ $item->label_mr ?? '—' }}</td>
                            <td class="py-2 px-2 align-top">
                                @if ($item->is_active)
                                    <span class="text-emerald-600 dark:text-emerald-400">Active</span>
                                @else
                                    <span class="text-gray-500 dark:text-gray-400">Disabled</span>
                                @endif
                            </td>
                            <td class="py-2 px-2 align-top min-w-0">
                                <div class="flex flex-col gap-1 items-start">
                                    <a href="{{ route('admin.master.castes.edit', $item) }}" class="text-xs sm:text-sm text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 whitespace-nowrap">Edit</a>
                                    @if ($item->is_active)
                                        <form method="POST" action="{{ route('admin.master.castes.disable', $item) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-xs sm:text-sm text-amber-600 hover:text-amber-800 dark:text-amber-400 whitespace-nowrap">Disable</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('admin.master.castes.enable', $item) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-xs sm:text-sm text-emerald-600 hover:text-emerald-800 dark:text-emerald-400 whitespace-nowrap">Enable</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-8 px-4 text-center text-gray-500">No castes found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
