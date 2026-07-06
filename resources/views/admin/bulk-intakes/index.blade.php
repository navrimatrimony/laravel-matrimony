@extends('layouts.admin')

@section('content')
@php
    $activeAdminProfileTab = 'bulk';
@endphp
<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Bulk Intakes</h1>
            <p class="mt-1 text-sm text-gray-600">Bulk intake creates biodata_intakes only. It does not create or apply profiles.</p>
        </div>
        <a href="{{ route('admin.bulk-intakes.create') }}" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
            New bulk intake
        </a>
    </div>

    @include('admin.intake._tabs')

    <form method="GET" action="{{ route('admin.bulk-intakes.index') }}" class="flex flex-wrap items-end gap-3 rounded-lg bg-white p-4 shadow">
        <div>
            <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
            <select id="status" name="status" class="mt-1 rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="">All</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option }}" @selected($status === $option)>{{ $option }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700">Filter</button>
        @if ($status !== '')
            <a href="{{ route('admin.bulk-intakes.index') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Clear</a>
        @endif
    </form>

    <div class="rounded-lg bg-white p-6 shadow">
        @if ($batches->isEmpty())
            <p class="text-sm text-gray-600">No bulk intake batches found.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">ID</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Batch</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Status</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Items</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Intakes</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Failed</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Created</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                        @foreach ($batches as $batch)
                            <tr>
                                <td class="px-4 py-2 text-sm text-gray-900">#{{ $batch->id }}</td>
                                <td class="px-4 py-2 text-sm text-gray-900">
                                    {{ $batch->batch_name ?: 'Untitled batch' }}
                                    <span class="block text-xs text-gray-500">Actor: {{ $batch->uploaded_by_actor_type ?: '—' }}</span>
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700">{{ $batch->batch_status }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700">{{ $batch->total_items }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700">{{ $batch->total_intakes_created }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700">{{ $batch->total_failed }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700">{{ $batch->created_at?->format('d-m-Y H:i') ?? '—' }}</td>
                                <td class="px-4 py-2 text-sm">
                                    <a href="{{ route('admin.bulk-intakes.show', $batch) }}" class="font-medium text-indigo-600 hover:text-indigo-800">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-6">{{ $batches->links() }}</div>
        @endif
    </div>
</div>
@endsection
