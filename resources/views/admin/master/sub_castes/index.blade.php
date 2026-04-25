@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
    <div class="p-6">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Sub-castes</h1>
                <p class="text-gray-500 dark:text-gray-400 text-sm">Master data. Edit, merge, or soft disable.</p>
            </div>
        </div>
        <form method="GET" action="{{ route('admin.master.sub-castes.index') }}" class="mb-4 flex flex-wrap gap-3 items-center">
            <label class="text-sm text-gray-700 dark:text-gray-300">Caste:</label>
            <select name="caste_id" class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-200 px-3 py-2 text-sm">
                <option value="">All</option>
                @foreach ($castes as $c)
                    <option value="{{ $c->id }}" {{ request('caste_id') == $c->id ? 'selected' : '' }}>{{ $c->religion?->label ?? '' }} — {{ $c->label }}</option>
                @endforeach
            </select>
            <label class="text-sm text-gray-700 dark:text-gray-300">Status:</label>
            <select name="status" class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-200 px-3 py-2 text-sm">
                <option value="">All</option>
                <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                <option value="approved" {{ request('status') === 'approved' ? 'selected' : '' }}>Approved</option>
            </select>
            <button type="submit" class="px-3 py-2 bg-gray-200 dark:bg-gray-600 text-gray-800 dark:text-gray-200 rounded-lg text-sm">Filter</button>
        </form>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-gray-50 dark:bg-gray-700/50">
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">ID</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Caste</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Label (legacy)</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">English</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Marathi</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Status / Active</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $item)
                        <tr x-data="{ showEditModal: false }" class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                            <td class="py-3 px-4 text-gray-800 dark:text-gray-200">{{ $item->id }}</td>
                            <td class="py-3 px-4 text-gray-700 dark:text-gray-300">{{ $item->caste?->label ?? '—' }} ({{ $item->caste?->religion?->label ?? '' }})</td>
                            <td class="py-3 px-4 text-gray-700 dark:text-gray-300">{{ $item->label }}</td>
                            <td class="py-3 px-4 text-gray-700 dark:text-gray-300">{{ $item->label_en ?? $item->label }}</td>
                            <td class="py-3 px-4 text-gray-700 dark:text-gray-300">{{ $item->label_mr ?? '—' }}</td>
                            <td class="py-3 px-4 text-gray-700 dark:text-gray-300">{{ $item->status ?? '—' }} / {{ $item->is_active ? 'Active' : 'Disabled' }}</td>
                            <td class="py-3 px-4 flex flex-wrap gap-2">
                                <button type="button" @click="showEditModal = true" class="text-sm text-indigo-600 hover:text-indigo-800">Edit</button>
                                @if ($item->is_active)
                                    <form method="POST" action="{{ route('admin.master.sub-castes.disable', $item) }}" class="inline">@csrf<button type="submit" class="text-sm text-amber-600">Disable</button></form>
                                @else
                                    <form method="POST" action="{{ route('admin.master.sub-castes.enable', $item) }}" class="inline">@csrf<button type="submit" class="text-sm text-emerald-600">Enable</button></form>
                                @endif

                                <div
                                    x-show="showEditModal"
                                    x-cloak
                                    class="fixed inset-0 z-50 flex items-center justify-center bg-black/45 px-4"
                                    @click.self="showEditModal = false"
                                    @keydown.escape.window="showEditModal = false"
                                >
                                    <div class="w-full max-w-xl rounded-lg bg-white p-4 shadow-xl dark:bg-gray-800">
                                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Edit sub-caste</h3>
                                        <form method="POST" action="{{ route('admin.master.sub-castes.update', $item) }}" class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                                            @csrf
                                            @method('PUT')
                                            <select name="caste_id" required class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                                @foreach ($castes as $c)
                                                    <option value="{{ $c->id }}" {{ (int) $item->caste_id === (int) $c->id ? 'selected' : '' }}>
                                                        {{ $c->religion?->label ?? '' }} — {{ $c->label }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            <input type="text" name="label" value="{{ $item->label }}" required class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                            <div class="md:col-span-2 flex items-center justify-end gap-2">
                                                <button type="button" @click="showEditModal = false" class="rounded-md border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Cancel</button>
                                                <button type="submit" class="rounded-md bg-indigo-700 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-600">Save</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 px-4 text-center text-gray-500">No sub-castes found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($items->hasPages())
            <div class="mt-4 px-4">{{ $items->links() }}</div>
        @endif
    </div>
</div>
@endsection
