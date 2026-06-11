@extends('layouts.app')

@php
    $exportTypeLabels = collect($summary['export_types'])
        ->mapWithKeys(fn (string $type) => [$type => ucwords(str_replace('_', ' ', $type))])
        ->all();
@endphp

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8">
    <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
        <div>
            <p class="text-sm font-semibold text-gray-500 dark:text-gray-400">Suchak records</p>
            <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">Export / Retention Center</h1>
        </div>
        <a href="{{ route('suchak.dashboard') }}" class="inline-flex justify-center rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-100 dark:hover:bg-gray-700">
            Dashboard
        </a>
    </div>

    @if (session('success'))
        <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">
            {{ session('success') }}
        </div>
    @endif

    @if (session('error'))
        <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">
            {{ session('error') }}
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Create Export</h2>
            <form method="POST" action="{{ route('suchak.export-retention.exports.store') }}" class="mt-4 space-y-4">
                @csrf
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                    Export type
                    <select name="export_type" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        @foreach ($exportTypeLabels as $type => $label)
                            <option value="{{ $type }}" @selected(old('export_type') === $type)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                        From
                        <input type="date" name="period_start" value="{{ old('period_start') }}" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    </label>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                        To
                        <input type="date" name="period_end" value="{{ old('period_end') }}" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    </label>
                </div>
                <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-200">
                    <input type="checkbox" name="include_private_contact" value="1" class="mt-1 rounded border-gray-300 text-indigo-600">
                    <span>Include private contact fields</span>
                </label>
                <button type="submit" class="inline-flex w-full justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                    Generate CSV
                </button>
            </form>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 lg:col-span-2">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Recent Exports</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2">Type</th>
                            <th class="px-3 py-2">Rows</th>
                            <th class="px-3 py-2">Sensitive</th>
                            <th class="px-3 py-2">Generated</th>
                            <th class="px-3 py-2">File</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($summary['recent_exports'] as $export)
                            <tr class="text-gray-700 dark:text-gray-200">
                                <td class="px-3 py-2">{{ $exportTypeLabels[$export->export_type] ?? $export->export_type }}</td>
                                <td class="px-3 py-2">{{ $export->row_count }}</td>
                                <td class="px-3 py-2">{{ $export->sensitive_access_status }}</td>
                                <td class="px-3 py-2">{{ optional($export->generated_at)->format('Y-m-d H:i') }}</td>
                                <td class="px-3 py-2">
                                    <a href="{{ route('suchak.export-retention.exports.download', $export) }}" class="font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">
                                        Download
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-3 py-6 text-center text-sm text-gray-500 dark:text-gray-400">No business exports yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Retention Rules</h2>
            <div class="mt-4 space-y-3">
                @foreach ($summary['retention_rules'] as $rule)
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-700 dark:bg-gray-900">
                        <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $rule->rule_name }}</div>
                        <div class="mt-1 text-gray-600 dark:text-gray-300">{{ $rule->record_type }} · retain {{ $rule->retention_days }} days · archive after {{ $rule->archive_after_days }} days</div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Archive Runs</h2>
            <div class="mt-4 space-y-3">
                @forelse ($summary['recent_archive_runs'] as $run)
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-700 dark:bg-gray-900">
                        <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $run->record_type }} · {{ $run->run_status }}</div>
                        <div class="mt-1 text-gray-600 dark:text-gray-300">Candidates {{ $run->candidate_record_count }} · retained {{ $run->retained_record_count }} · deleted {{ $run->deleted_record_count }}</div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400">No retention archive runs yet.</p>
                @endforelse
            </div>
        </section>
    </div>
</div>
@endsection
