@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6">Admin Overview</h1>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 p-5">
            <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Total profiles</p>
            <p class="text-2xl font-bold text-gray-800 dark:text-gray-100 mt-1">{{ $totalProfiles }}</p>
        </div>
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 p-5">
            <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Active</p>
            <p class="text-2xl font-bold text-emerald-600 mt-1">{{ $activeProfiles }}</p>
        </div>
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 p-5">
            <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Suspended</p>
            <p class="text-2xl font-bold text-amber-600 mt-1">{{ $suspendedProfiles }}</p>
        </div>
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 p-5">
            <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Showcase</p>
            <p class="text-2xl font-bold text-sky-600 mt-1">{{ $demoProfiles }}</p>
        </div>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 p-5">
            <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Pending abuse reports</p>
            <p class="text-2xl font-bold text-gray-800 dark:text-gray-100 mt-1">{{ $pendingAbuseReports }}</p>
            <a href="{{ route('admin.abuse-reports.index') }}" class="inline-block mt-3 text-sm font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">View reports →</a>
        </div>
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 p-5">
            <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Biodata Intakes</p>
            <p class="text-2xl font-bold text-gray-800 dark:text-gray-100 mt-1">{{ $totalBiodataIntakes ?? 0 }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Last 7 days: <span class="font-semibold">{{ $intakeLast7Count ?? 0 }}</span> ·
                Last 30 days: <span class="font-semibold">{{ $intakeLast30Count ?? 0 }}</span>
            </p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Parsed: <span class="font-semibold">{{ $intakeLast30Parsed ?? 0 }}</span> ·
                Errors: <span class="font-semibold">{{ $intakeLast30Errors ?? 0 }}</span>
            </p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Avg parse: <span class="font-semibold">{{ $intakeAvgParseMs ?? 0 }} ms</span> ·
                Avg edits: <span class="font-semibold">{{ number_format($intakeAvgManualEdits ?? 0, 1) }}</span> ·
                Avg auto-filled: <span class="font-semibold">{{ number_format($intakeAvgAutoFilled ?? 0, 1) }}</span>
            </p>
            <a href="{{ route('admin.biodata-intakes.index') }}" class="inline-block mt-3 text-sm font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">View intakes →</a>
        </div>
    </div>
</div>
@endsection
