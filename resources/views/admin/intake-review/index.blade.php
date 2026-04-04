@extends('layouts.admin')

@section('content')
<div class="max-w-7xl mx-auto pb-16 px-4 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Intake suggestions queue</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Profiles with pending <code class="text-xs bg-gray-100 dark:bg-gray-800 px-1 rounded">pending_intake_suggestions_json</code></p>
        </div>
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

    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 shadow-sm">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-900/50 text-left text-gray-600 dark:text-gray-300">
                    <th class="px-4 py-3 font-semibold">Profile ID</th>
                    <th class="px-4 py-3 font-semibold">Name</th>
                    <th class="px-4 py-3 font-semibold text-right">Pending</th>
                    <th class="px-4 py-3 font-semibold w-32"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($profiles as $p)
                    @php
                        $cnt = (int) ($p->pending_suggestions_count ?? 0);
                    @endphp
                    <tr class="hover:bg-gray-50/80 dark:hover:bg-gray-700/40">
                        <td class="px-4 py-3 font-mono text-gray-900 dark:text-gray-100">{{ $p->id }}</td>
                        <td class="px-4 py-3 text-gray-800 dark:text-gray-100">{{ $p->full_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            @if ($cnt > 0)
                                <span class="inline-flex items-center justify-center min-w-[2rem] px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-100 dark:bg-amber-900/50 text-amber-900 dark:text-amber-100">{{ $cnt }}</span>
                            @else
                                <span class="text-gray-400">0</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if ($cnt > 0)
                                <a href="{{ route('admin.intake.show', $p) }}" class="inline-flex items-center px-3 py-1.5 rounded-md text-xs font-medium bg-indigo-600 text-white hover:bg-indigo-500">View</a>
                            @else
                                <span class="text-xs text-gray-400">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">No profiles with stored pending JSON.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">
        {{ $profiles->links() }}
    </div>
</div>
@endsection
