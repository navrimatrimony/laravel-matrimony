@extends('layouts.admin')

@section('content')
@php
    $fieldDisplayLabel = $fieldDisplayLabel ?? Str::headline(str_replace('_', ' ', $record->field_name));
    $latestIntake = $latestIntake ?? null;
    $recentMutationLog = $recentMutationLog ?? [];
@endphp
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6 max-w-4xl mx-auto">
    <div class="flex justify-between items-start mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Conflict #{{ $record->id }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $fieldDisplayLabel }} <span class="text-gray-400">({{ $record->field_type }})</span> · Source: {{ $record->source }} · Detected: {{ $record->detected_at?->format('Y-m-d H:i') ?? '—' }}</p>
        </div>
        <a href="{{ route('admin.conflict-records.index') }}" class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-md text-sm">← Back to list</a>
    </div>

    @if (session('success'))
        <div class="mb-4 px-4 py-2 rounded bg-emerald-50 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-200 text-sm">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-4 px-4 py-2 rounded bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200 text-sm">{{ $errors->first() }}</div>
    @endif

    <section class="mb-4 p-4 rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/40">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-3">Conflict context</h2>
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm text-gray-800 dark:text-gray-200">
            <div class="sm:col-span-2">
                <dt class="text-gray-500 dark:text-gray-400 text-xs">Profile</dt>
                <dd>
                    @if($profile)
                        <a href="{{ route('admin.profiles.show', $profile->id) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline font-medium">#{{ $profile->id }}</a>
                        <span class="text-gray-600 dark:text-gray-300"> — {{ $profile->full_name ?? '—' }}</span>
                        @if(filled($profile->lifecycle_state ?? null))
                            <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold uppercase bg-slate-200 dark:bg-slate-600 text-slate-800 dark:text-slate-100">{{ $profile->lifecycle_state }}</span>
                        @endif
                    @else
                        <span class="text-gray-500">Not found (ID {{ $record->profile_id }})</span>
                    @endif
                </dd>
            </div>
            @if($latestIntake)
                <div class="sm:col-span-2">
                    <dt class="text-gray-500 dark:text-gray-400 text-xs">Intake</dt>
                    <dd><a href="{{ route('admin.biodata-intakes.show', $latestIntake) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline text-sm">Latest biodata intake #{{ $latestIntake->id }}</a> <span class="text-gray-400 text-xs">(most recent linked to this profile)</span></dd>
                </div>
            @endif
            <div>
                <dt class="text-gray-500 dark:text-gray-400 text-xs">Field</dt>
                <dd><span class="font-medium">{{ $fieldDisplayLabel }}</span> <span class="text-gray-400 dark:text-gray-500 text-xs font-mono">· {{ $record->field_name }}</span></dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400 text-xs">Resolution</dt>
                <dd>
                    <span class="px-2 py-0.5 rounded text-xs font-semibold
                        @if($record->resolution_status === 'PENDING') bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-200
                        @elseif($record->resolution_status === 'APPROVED') bg-emerald-100 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-200
                        @elseif($record->resolution_status === 'REJECTED') bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-200
                        @else bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300
                        @endif">{{ $record->resolution_status }}</span>
                </dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-gray-500 dark:text-gray-400 text-xs">Current value</dt>
                <dd class="text-xs break-words whitespace-pre-wrap rounded border border-red-200/80 dark:border-red-900/50 bg-red-50/80 dark:bg-red-900/20 px-2 py-1.5 mt-0.5">{{ Str::limit($record->old_value ?? '—', 2000) }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-gray-500 dark:text-gray-400 text-xs">Proposed value</dt>
                <dd class="text-xs break-words whitespace-pre-wrap rounded border border-emerald-200/80 dark:border-emerald-900/50 bg-emerald-50/80 dark:bg-emerald-900/20 px-2 py-1.5 mt-0.5">{{ Str::limit($record->new_value ?? '—', 2000) }}</dd>
            </div>
        </dl>
    </section>

    <section class="mb-6 p-3 rounded-lg border border-amber-200/80 dark:border-amber-800/60 bg-amber-50/60 dark:bg-amber-900/15">
        <p class="text-xs text-amber-950/90 dark:text-amber-100/90 leading-relaxed">
            Some conflicts reflect a proposed change that cannot be applied as a simple field swap—for example when governance blocks overwrites, a field is locked after user edit, or the system detected a duplicate or policy clash. This screen does not classify the underlying cause; use profile state, field registry locks, and the recent mutation log below for context.
        </p>
    </section>

    <section class="mb-6 p-4 rounded-lg border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-900/20">
        <h2 class="text-sm font-semibold text-blue-900 dark:text-blue-100 mb-2">What each resolution means</h2>
        <ul class="text-sm text-blue-900/90 dark:text-blue-100/90 space-y-1 list-disc list-inside">
            <li><strong>Approve</strong> — accept the proposed new value and store it on the profile.</li>
            <li><strong>Reject</strong> — keep the current existing value; discard the proposed change for this conflict.</li>
            <li><strong>Override</strong> — admin exception with a recorded reason (governed path; use only when policy allows).</li>
        </ul>
    </section>

    @if(count($recentMutationLog) > 0)
        <section class="mb-6">
            <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Recent Phase-5 mutation log</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">Read-only: last {{ count($recentMutationLog) }} row(s) from <code class="text-[11px]">profile_change_history</code> for this profile (newest first).</p>
            <div class="overflow-x-auto rounded border border-gray-200 dark:border-gray-600">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-xs">
                    <thead class="bg-gray-50 dark:bg-gray-700/80">
                        <tr>
                            <th class="px-2 py-2 text-left font-medium text-gray-600 dark:text-gray-300 whitespace-nowrap">Changed</th>
                            <th class="px-2 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Field</th>
                            <th class="px-2 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Old</th>
                            <th class="px-2 py-2 text-left font-medium text-gray-600 dark:text-gray-300">New</th>
                            <th class="px-2 py-2 text-left font-medium text-gray-600 dark:text-gray-300 whitespace-nowrap">Source</th>
                            <th class="px-2 py-2 text-left font-medium text-gray-600 dark:text-gray-300 whitespace-nowrap">Actor</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-800">
                        @foreach ($recentMutationLog as $row)
                            <tr class="align-top">
                                <td class="px-2 py-1.5 text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($row['changed_at'])->format('Y-m-d H:i') }}</td>
                                <td class="px-2 py-1.5 font-mono text-[11px] text-gray-800 dark:text-gray-200">{{ Str::limit($row['field_name'], 48) }}</td>
                                <td class="px-2 py-1.5 text-gray-700 dark:text-gray-300 break-words max-w-[140px]">{{ Str::limit($row['old_value'] ?? '—', 80) }}</td>
                                <td class="px-2 py-1.5 text-gray-700 dark:text-gray-300 break-words max-w-[140px]">{{ Str::limit($row['new_value'] ?? '—', 80) }}</td>
                                <td class="px-2 py-1.5 text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $row['source'] ?? '—' }}</td>
                                <td class="px-2 py-1.5 text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $row['actor'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- Side-by-side diff --}}
    <section class="mb-6">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Value diff</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="rounded-lg border-2 border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-4">
                <p class="text-xs font-medium text-red-700 dark:text-red-300 uppercase mb-2">Current / Old value</p>
                <p class="text-sm text-gray-900 dark:text-gray-100 break-words whitespace-pre-wrap">{{ $record->old_value ?? '—' }}</p>
            </div>
            <div class="rounded-lg border-2 border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-900/20 p-4">
                <p class="text-xs font-medium text-emerald-700 dark:text-emerald-300 uppercase mb-2">Proposed / New value</p>
                <p class="text-sm text-gray-900 dark:text-gray-100 break-words whitespace-pre-wrap">{{ $record->new_value ?? '—' }}</p>
            </div>
        </div>
    </section>

    {{-- Resolution status --}}
    <section class="mb-6 p-4 rounded-lg border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800/50">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Resolution status</h2>
        <p class="text-sm text-gray-800 dark:text-gray-200">
            <span class="px-2 py-0.5 rounded text-xs font-semibold
                @if($record->resolution_status === 'PENDING') bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-200
                @elseif($record->resolution_status === 'APPROVED') bg-emerald-100 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-200
                @elseif($record->resolution_status === 'REJECTED') bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-200
                @else bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300
                @endif">{{ $record->resolution_status }}</span>
        </p>
        @if($record->resolution_status !== 'PENDING')
            @if($record->resolved_at)
                <p class="text-sm text-gray-600 dark:text-gray-400 mt-2"><strong class="text-gray-700 dark:text-gray-300">Resolved at:</strong> {{ $record->resolved_at->format('Y-m-d H:i') }}</p>
            @endif
            @if($record->resolution_reason)
                <p class="text-sm text-gray-600 dark:text-gray-400 mt-2"><strong class="text-gray-700 dark:text-gray-300">Resolution reason:</strong> {{ $record->resolution_reason }}</p>
            @endif
        @endif
    </section>

    {{-- Resolution form (only when PENDING) --}}
    @if($record->resolution_status === 'PENDING' && $canResolve ?? false)
        <section class="pt-4 border-t border-gray-200 dark:border-gray-600">
            <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Resolve conflict</h2>
            <div class="flex flex-wrap gap-4">
                <form method="POST" action="{{ route('admin.conflict-records.approve', $record) }}" class="flex flex-col gap-2 max-w-md">
                    @csrf
                    <textarea name="resolution_reason" rows="3" required minlength="10" placeholder="Reason for approving new value (min 10 chars)" class="w-full text-sm border border-gray-300 dark:border-gray-600 rounded px-3 py-2 bg-white dark:bg-gray-700"></textarea>
                    <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-sm font-medium w-fit">Approve new value</button>
                </form>
                <form method="POST" action="{{ route('admin.conflict-records.reject', $record) }}" class="flex flex-col gap-2 max-w-md">
                    @csrf
                    <textarea name="resolution_reason" rows="3" required minlength="10" placeholder="Reason for rejecting (min 10 chars)" class="w-full text-sm border border-gray-300 dark:border-gray-600 rounded px-3 py-2 bg-white dark:bg-gray-700"></textarea>
                    <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-md text-sm font-medium w-fit">Reject</button>
                </form>
                <form method="POST" action="{{ route('admin.conflict-records.override', $record) }}" class="flex flex-col gap-2 max-w-md">
                    @csrf
                    <textarea name="resolution_reason" rows="3" required minlength="10" placeholder="Reason for override (min 10 chars)" class="w-full text-sm border border-gray-300 dark:border-gray-600 rounded px-3 py-2 bg-white dark:bg-gray-700"></textarea>
                    <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-md text-sm font-medium w-fit">Override</button>
                </form>
            </div>
        </section>
    @endif
</div>
@endsection
