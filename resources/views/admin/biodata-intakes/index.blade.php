@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Biodata Intakes</h1>
        <span class="text-sm text-gray-500 dark:text-gray-400">Read-only list</span>
    </div>

    @if ($intakes->isEmpty())
        <p class="text-gray-600 dark:text-gray-400">No biodata intakes yet.</p>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">ID</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Status</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">File</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">OCR Mode</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Uploaded By</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Profile</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Created</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($intakes as $intake)
                        <tr>
                            <td class="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{{ $intake->id }}</td>
                            <td class="px-4 py-2 text-sm">
                                <span class="px-2 py-0.5 rounded text-xs font-medium
                                    @if (($intake->intake_status ?? 'DRAFT') === 'DRAFT') bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300
                                    @elseif (($intake->intake_status ?? 'DRAFT') === 'ATTACHED') bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300
                                    @else bg-gray-200 text-gray-700 dark:bg-gray-600 dark:text-gray-300
                                    @endif">{{ $intake->intake_status ?? 'DRAFT' }}</span>
                            </td>
                            <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">
                                {{ $intake->original_filename ?? ($intake->file_path ? basename($intake->file_path) : '—') }}
                            </td>
                            <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">{{ $intake->ocr_mode ?? '—' }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">
                                @if ($intake->uploadedByUser)
                                    {{ $intake->uploadedByUser->name ?? $intake->uploadedByUser->email }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">
                                @if ($intake->profile)
                                    <a href="{{ route('admin.profiles.show', $intake->profile->id) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ $intake->profile->full_name }} (#{{ $intake->profile->id }})</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">{{ $intake->created_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td class="px-4 py-2 text-sm">
                                <a href="{{ route('admin.biodata-intakes.show', $intake) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">View</a>
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
