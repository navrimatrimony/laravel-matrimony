@extends('layouts.admin')

@section('content')
@php
    $pendingCount = (int) ($pendingCount ?? 0);
    $approvedCount = (int) ($approvedCount ?? 0);
    $rejectedCount = (int) ($rejectedCount ?? 0);
    $fieldLabelMap = $fieldLabelMap ?? [];
@endphp
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Conflict Records</h1>
        <a href="{{ route('admin.conflict-records.create') }}" class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-md text-sm">Create test record</a>
    </div>

    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Review queue: the table below lists <strong class="text-gray-800 dark:text-gray-200">pending</strong> conflicts only. Summary counts include all statuses.</p>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
        <div class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 px-4 py-3">
            <div class="text-xs font-semibold uppercase text-amber-800 dark:text-amber-200 tracking-wide">Pending</div>
            <div class="text-2xl font-bold text-amber-900 dark:text-amber-100 mt-1">{{ $pendingCount }}</div>
        </div>
        <div class="rounded-lg border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-900/20 px-4 py-3">
            <div class="text-xs font-semibold uppercase text-emerald-800 dark:text-emerald-200 tracking-wide">Approved</div>
            <div class="text-2xl font-bold text-emerald-900 dark:text-emerald-100 mt-1">{{ $approvedCount }}</div>
        </div>
        <div class="rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 px-4 py-3">
            <div class="text-xs font-semibold uppercase text-red-800 dark:text-red-200 tracking-wide">Rejected</div>
            <div class="text-2xl font-bold text-red-900 dark:text-red-100 mt-1">{{ $rejectedCount }}</div>
        </div>
    </div>

    @if (session('success'))
        <div class="mb-4 px-4 py-2 rounded bg-emerald-50 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-200 text-sm">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-4 px-4 py-2 rounded bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200 text-sm">{{ $errors->first() }}</div>
    @endif

    @if ($records->isEmpty())
        <p class="text-gray-600 dark:text-gray-400">No pending conflict records.</p>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">ID</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Profile</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Field</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Type</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Source</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Old value</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">New value</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Detected</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($records as $record)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100 align-top">{{ $record->id }}</td>
                            <td class="px-4 py-3 text-sm align-top">
                                <a href="{{ route('admin.profiles.show', $record->profile_id) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline font-medium">{{ $record->profile_id }}</a>
                                @if($record->relationLoaded('profile') && $record->profile)
                                    <div class="text-gray-500 dark:text-gray-400 text-xs mt-0.5">({{ Str::limit($record->profile->full_name ?? '', 24) }})</div>
                                    @if(filled($record->profile->lifecycle_state ?? null))
                                        <span class="mt-1 inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wide bg-slate-200 dark:bg-slate-600 text-slate-800 dark:text-slate-100" title="Lifecycle">{{ $record->profile->lifecycle_state }}</span>
                                    @endif
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm align-top">
                                @php
                                    $fl = $fieldLabelMap[$record->field_type.'|'.$record->field_name] ?? Str::headline(str_replace('_', ' ', $record->field_name));
                                @endphp
                                <span class="font-semibold text-gray-900 dark:text-gray-100 leading-snug">{{ $fl }}</span>
                                <div class="text-[11px] text-gray-400 dark:text-gray-500 font-mono mt-0.5">{{ $record->field_name }}</div>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 align-top">{{ $record->field_type }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 align-top">{{ $record->source }}</td>
                            <td class="px-4 py-3 text-sm max-w-[180px] align-top">
                                <span class="inline-block px-2 py-1 rounded bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-200 text-xs break-words leading-relaxed">{{ Str::limit($record->old_value ?? '—', 120) }}</span>
                            </td>
                            <td class="px-4 py-3 text-sm max-w-[180px] align-top">
                                <span class="inline-block px-2 py-1 rounded bg-emerald-100 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-200 text-xs break-words leading-relaxed">{{ Str::limit($record->new_value ?? '—', 120) }}</span>
                            </td>
                            <td class="px-4 py-3 text-sm align-top">
                                <span class="px-2 py-0.5 rounded text-xs font-medium
                                    @if($record->resolution_status === 'PENDING') bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-200
                                    @elseif($record->resolution_status === 'APPROVED') bg-emerald-100 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-200
                                    @elseif($record->resolution_status === 'REJECTED') bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-200
                                    @else bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300
                                    @endif">{{ $record->resolution_status }}</span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 align-top whitespace-nowrap">{{ $record->detected_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm align-top min-w-[10rem]">
                                <a href="{{ route('admin.conflict-records.show', $record) }}" class="inline-flex items-center px-2.5 py-1 rounded-md bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold">Review</a>
                                @if ($record->resolution_status === 'PENDING')
                                    <div class="mt-2 text-[10px] text-gray-500 dark:text-gray-400 uppercase tracking-wide">Quick actions</div>
                                    <div class="inline-block space-y-1 mt-1" x-data="{ active: null }">
                                        <button type="button" @click="active = active === 'approve' ? null : 'approve'" class="px-2 py-0.5 text-xs bg-emerald-600/80 hover:bg-emerald-600 text-white rounded opacity-90">Approve</button>
                                        <button type="button" @click="active = active === 'reject' ? null : 'reject'" class="px-2 py-0.5 text-xs bg-red-600/80 hover:bg-red-600 text-white rounded opacity-90">Reject</button>
                                        <button type="button" @click="active = active === 'override' ? null : 'override'" class="px-2 py-0.5 text-xs bg-amber-600/80 hover:bg-amber-600 text-white rounded opacity-90">Override</button>
                                        <div x-show="active === 'approve'" x-cloak class="mt-1">
                                            <form method="POST" action="{{ route('admin.conflict-records.approve', $record) }}">
                                                @csrf
                                                <textarea name="resolution_reason" rows="2" required minlength="10" placeholder="Reason (min 10 chars)" class="w-full text-xs border rounded px-2 py-1 mb-1 dark:bg-gray-700 dark:border-gray-600"></textarea>
                                                <button type="submit" class="px-2 py-0.5 text-xs bg-emerald-600 text-white rounded">Submit</button>
                                            </form>
                                        </div>
                                        <div x-show="active === 'reject'" x-cloak class="mt-1">
                                            <form method="POST" action="{{ route('admin.conflict-records.reject', $record) }}">
                                                @csrf
                                                <textarea name="resolution_reason" rows="2" required minlength="10" placeholder="Reason (min 10 chars)" class="w-full text-xs border rounded px-2 py-1 mb-1 dark:bg-gray-700 dark:border-gray-600"></textarea>
                                                <button type="submit" class="px-2 py-0.5 text-xs bg-red-600 text-white rounded">Submit</button>
                                            </form>
                                        </div>
                                        <div x-show="active === 'override'" x-cloak class="mt-1">
                                            <form method="POST" action="{{ route('admin.conflict-records.override', $record) }}">
                                                @csrf
                                                <textarea name="resolution_reason" rows="2" required minlength="10" placeholder="Reason (min 10 chars)" class="w-full text-xs border rounded px-2 py-1 mb-1 dark:bg-gray-700 dark:border-gray-600"></textarea>
                                                <button type="submit" class="px-2 py-0.5 text-xs bg-amber-600 text-white rounded">Submit</button>
                                            </form>
                                        </div>
                                    </div>
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
