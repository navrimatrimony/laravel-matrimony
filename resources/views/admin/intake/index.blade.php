@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6">Biodata Intakes</h1>

    @if ($intakes->isEmpty())
        <p class="text-gray-600 dark:text-gray-400">No biodata intakes found.</p>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">ID</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Uploaded By</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Linked Profile</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Intake Status</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Intake Locked</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Approved By User</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Created At</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($intakes as $intake)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <td class="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{{ $intake->id }}</td>
                            <td class="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">
                                <span>{{ $intake->uploadedByUser?->name ?? '—' }}</span>
                                @if ($intake->uploadedByUser?->email)
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $intake->uploadedByUser->email }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">{{ $intake->profile?->full_name ?? '—' }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">{{ $intake->intake_status ?? '—' }}</td>
                            <td class="px-4 py-2 text-sm">
                                @if ($intake->intake_locked)
                                    <span class="px-2 py-0.5 rounded text-xs font-medium bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-200">Yes</span>
                                @else
                                    <span class="px-2 py-0.5 rounded text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">No</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-sm">
                                @if ($intake->approved_by_user)
                                    <span class="px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-200">Yes</span>
                                @else
                                    <span class="px-2 py-0.5 rounded text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">No</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">{{ $intake->created_at?->format('d-m-Y H:i') ?? '—' }}</td>
                            <td class="px-4 py-2">
                                <a href="{{ route('admin.biodata-intakes.show', $intake) }}" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 font-medium text-sm">
                                    View
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-6">{{ $intakes->links() }}</div>
    @endif
</div>
@endsection
