@extends('layouts.admin')

@section('content')
@php
    $rows = $reviewRows ?? [];
    $groupOrder = ['core' => 'Core', 'extended' => 'Extended', 'snapshot' => 'Snapshot / places', 'entities' => 'Entities'];
    $grouped = collect($rows)->groupBy('group');
@endphp
<div class="max-w-7xl mx-auto pb-28 px-4 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Review intake suggestions</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Profile #{{ $profile->id }} — {{ $profile->full_name ?? '—' }}</p>
        </div>
        <a href="{{ route('admin.intake.index') }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">← Queue</a>
    </div>

    @if (session('success'))
        <div class="mb-4 px-4 py-2 rounded bg-emerald-50 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-200 text-sm">{{ session('success') }}</div>
    @endif
    @if (session('info'))
        <div class="mb-4 px-4 py-2 rounded bg-sky-50 dark:bg-sky-900/30 text-sky-800 dark:text-sky-200 text-sm">{{ session('info') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 px-4 py-2 rounded bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200 text-sm">{{ session('error') }}</div>
    @endif

    <div class="mb-4 flex flex-wrap gap-2">
        <form method="POST" action="{{ route('admin.intake.approve-all', $profile) }}" class="inline" onsubmit="return confirm('Apply every pending suggestion that is not skipped (locked / verified)?');">
            @csrf
            <button type="submit" class="px-3 py-1.5 rounded-md text-xs font-medium bg-emerald-600 text-white hover:bg-emerald-500">Approve all</button>
        </form>
        <form method="POST" action="{{ route('admin.intake.clear', $profile) }}" class="inline" onsubmit="return confirm('Remove all pending suggestions without applying?');">
            @csrf
            <button type="submit" class="px-3 py-1.5 rounded-md text-xs font-medium bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-gray-100 hover:bg-gray-300 dark:hover:bg-gray-600">Clear all</button>
        </form>
    </div>

    <form id="intake-review-form" method="POST" action="{{ route('admin.intake.approve', $profile) }}">
        @csrf
        <div class="mb-4 flex flex-wrap gap-2">
            <button type="submit" class="px-3 py-1.5 rounded-md text-xs font-medium bg-indigo-600 text-white hover:bg-indigo-500">Approve selected</button>
            <button type="submit" formaction="{{ route('admin.intake.reject', $profile) }}" class="px-3 py-1.5 rounded-md text-xs font-medium bg-red-600 text-white hover:bg-red-500">Reject selected</button>
        </div>

        @foreach ($groupOrder as $gKey => $gLabel)
            @php $bucket = $grouped->get($gKey, collect()); @endphp
            @if ($bucket->isEmpty())
                @continue
            @endif
            <section class="mb-8">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-3 border-b border-gray-200 dark:border-gray-600 pb-2">{{ $gLabel }}</h2>
                <div class="space-y-3">
                    @foreach ($bucket as $r)
                        @php
                            $disabled = !empty($r['locked']) || !empty($r['verified_skip']);
                            $oldVal = $r['old_display'] ?? '';
                            $newVal = $r['new_display'] ?? '';
                            $same = trim((string) $oldVal) !== '' && trim((string) $oldVal) === trim((string) $newVal);
                        @endphp
                        <div class="border border-gray-200 dark:border-gray-600 rounded-lg p-4 bg-white dark:bg-gray-800 shadow-sm flex flex-wrap gap-4 items-start">
                            <label class="flex items-start gap-3 min-w-0 flex-1 cursor-pointer select-none {{ $disabled ? 'opacity-60 cursor-not-allowed' : '' }}">
                                <input type="checkbox" name="fields[]" value="{{ $r['id'] }}" class="mt-1 rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500" @if($disabled) disabled @endif>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">{{ $r['group_label'] ?? $gLabel }}</span>
                                    <span class="font-mono text-sm text-gray-900 dark:text-gray-100">{{ $r['field_key'] }}</span>
                                    @if (!empty($r['locked']))
                                        <span class="ml-2 text-xs text-amber-700 dark:text-amber-300">(locked)</span>
                                    @endif
                                    @if (!empty($r['verified_skip']))
                                        <span class="ml-2 text-xs text-sky-700 dark:text-sky-300">(verified contact)</span>
                                    @endif
                                    <span class="mt-2 flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm">
                                        <span class="inline-block px-2 py-0.5 rounded bg-red-50 dark:bg-red-900/30 text-red-900 dark:text-red-100 break-all max-w-full">{{ $oldVal !== '' ? $oldVal : '—' }}</span>
                                        <span class="text-gray-400">→</span>
                                        <span class="inline-block px-2 py-0.5 rounded bg-emerald-50 dark:bg-emerald-900/30 text-emerald-900 dark:text-emerald-100 break-all max-w-full">{{ $newVal !== '' ? $newVal : '—' }}</span>
                                        @if ($same)
                                            <span class="text-xs text-gray-500">(same)</span>
                                        @endif
                                    </span>
                                </span>
                            </label>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach

        @if ($rows === [])
            <p class="text-gray-500 dark:text-gray-400 text-sm">Nothing to review.</p>
        @endif
    </form>
</div>
@endsection
