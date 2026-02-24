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
                    <tr class="border-b border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50">
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">ID</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Caste</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Label</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Status / Active</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $item)
                        <tr class="border-b border-gray-100 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700/30">
                            <td class="py-3 px-4 text-gray-800 dark:text-gray-200">{{ $item->id }}</td>
                            <td class="py-3 px-4 text-gray-700 dark:text-gray-300">{{ $item->caste?->label ?? '—' }} ({{ $item->caste?->religion?->label ?? '' }})</td>
                            <td class="py-3 px-4 text-gray-700 dark:text-gray-300">{{ $item->label }}</td>
                            <td class="py-3 px-4 text-gray-700 dark:text-gray-300">{{ $item->status ?? '—' }} / {{ $item->is_active ? 'Active' : 'Disabled' }}</td>
                            <td class="py-3 px-4 flex flex-wrap gap-2">
                                <a href="{{ route('admin.master.sub-castes.edit', $item) }}" class="text-sm text-indigo-600 hover:text-indigo-800">Edit</a>
                                @if ($item->is_active)
                                    <form method="POST" action="{{ route('admin.master.sub-castes.disable', $item) }}" class="inline">@csrf<button type="submit" class="text-sm text-amber-600">Disable</button></form>
                                @else
                                    <form method="POST" action="{{ route('admin.master.sub-castes.enable', $item) }}" class="inline">@csrf<button type="submit" class="text-sm text-emerald-600">Enable</button></form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-8 px-4 text-center text-gray-500">No sub-castes found.</td>
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
