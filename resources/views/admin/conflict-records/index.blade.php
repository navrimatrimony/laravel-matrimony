@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Conflict Records</h1>
        <a href="{{ route('admin.conflict-records.create') }}" class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-md text-sm">Create test record</a>
    </div>

    @if (session('success'))
        <div class="mb-4 px-4 py-2 rounded bg-emerald-50 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-200 text-sm">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-4 px-4 py-2 rounded bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200 text-sm">{{ $errors->first() }}</div>
    @endif

    @if ($records->isEmpty())
        <p class="text-gray-600 dark:text-gray-400">No conflict records.</p>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">ID</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Profile ID</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Field name</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Field type</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Source</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Resolution status</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Detected at</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($records as $record)
                        <tr>
                            <td class="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{{ $record->id }}</td>
                            <td class="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{{ $record->profile_id }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">{{ $record->field_name }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">{{ $record->field_type }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">{{ $record->source }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">{{ $record->resolution_status }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">{{ $record->detected_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td class="px-4 py-2 text-sm">
                                @if ($record->resolution_status === 'PENDING')
                                    <div class="space-y-2" x-data="{ active: null }">
                                        <div class="flex flex-wrap gap-2">
                                            <button type="button" @click="active = active === 'approve' ? null : 'approve'" class="px-2 py-1 text-xs bg-emerald-600 hover:bg-emerald-700 text-white rounded">Approve</button>
                                            <button type="button" @click="active = active === 'reject' ? null : 'reject'" class="px-2 py-1 text-xs bg-red-600 hover:bg-red-700 text-white rounded">Reject</button>
                                            <button type="button" @click="active = active === 'override' ? null : 'override'" class="px-2 py-1 text-xs bg-amber-600 hover:bg-amber-700 text-white rounded">Override</button>
                                        </div>
                                        <div x-show="active === 'approve'" x-cloak class="mb-2">
                                            <form method="POST" action="{{ route('admin.conflict-records.approve', $record) }}">
                                                @csrf
                                                <textarea name="resolution_reason" rows="2" required minlength="10" placeholder="Reason (min 10 chars)" class="w-full text-xs border rounded px-2 py-1 mb-1"></textarea>
                                                <button type="submit" class="px-2 py-1 text-xs bg-emerald-600 text-white rounded">Submit</button>
                                            </form>
                                        </div>
                                        <div x-show="active === 'reject'" x-cloak class="mb-2">
                                            <form method="POST" action="{{ route('admin.conflict-records.reject', $record) }}">
                                                @csrf
                                                <textarea name="resolution_reason" rows="2" required minlength="10" placeholder="Reason (min 10 chars)" class="w-full text-xs border rounded px-2 py-1 mb-1"></textarea>
                                                <button type="submit" class="px-2 py-1 text-xs bg-red-600 text-white rounded">Submit</button>
                                            </form>
                                        </div>
                                        <div x-show="active === 'override'" x-cloak class="mb-2">
                                            <form method="POST" action="{{ route('admin.conflict-records.override', $record) }}">
                                                @csrf
                                                <textarea name="resolution_reason" rows="2" required minlength="10" placeholder="Reason (min 10 chars)" class="w-full text-xs border rounded px-2 py-1 mb-1"></textarea>
                                                <button type="submit" class="px-2 py-1 text-xs bg-amber-600 text-white rounded">Submit</button>
                                            </form>
                                        </div>
                                    </div>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-6">{{ $records->links() }}</div>
    @endif
</div>
@endsection
