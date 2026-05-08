@extends('layouts.admin')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="mb-6">
        <a href="{{ route('admin.data-engine.marathi-columns') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">&larr; Back to Marathi column report</a>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-2">Fill pending Marathi</h1>
        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1 font-mono">
            {{ $pair['table'] }}.{{ $pair['mr'] }} <span class="text-gray-400">←</span> {{ $pair['base'] }}
        </p>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-md bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-200">
            {{ session('status') }}
        </div>
    @endif

    @if (session('error'))
        <div class="mb-4 rounded-md bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-800 dark:text-red-200">
            {{ session('error') }}
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-6">
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4">
            <p class="text-xs uppercase tracking-wide text-gray-500">Expected rows (has {{ $pair['base'] }})</p>
            <p class="mt-1 text-2xl font-bold">{{ number_format($counts['expected']) }}</p>
        </div>
        <div class="rounded-xl border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-950/30 p-4">
            <p class="text-xs uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Filled</p>
            <p class="mt-1 text-2xl font-bold text-emerald-700 dark:text-emerald-300">{{ number_format($counts['filled']) }}</p>
        </div>
        <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 p-4">
            <p class="text-xs uppercase tracking-wide text-amber-700 dark:text-amber-300">Still pending</p>
            <p class="mt-1 text-2xl font-bold text-amber-700 dark:text-amber-300">{{ number_format($counts['pending']) }}</p>
        </div>
    </div>

    @if ($duplicateScopedByParent)
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
            Duplicate check: the same Marathi text cannot be reused for two different rows under the same <code class="text-xs bg-gray-100 dark:bg-gray-900 px-1 rounded">parent_id</code> (same taluka block). Different parents may share the same spelling.
        </p>
    @else
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
            This table has no <code class="text-xs bg-gray-100 dark:bg-gray-900 px-1 rounded">parent_id</code> column; duplicate Marathi values are not blocked automatically.
        </p>
    @endif

    <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Pending rows (newest id last)</h2>
            <span class="text-xs text-gray-500">{{ $rows->total() }} total</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                        @if (! empty($show_parent_id))
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">parent_id</th>
                        @endif
                        @if (! empty($show_type))
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">type</th>
                        @endif
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ $pair['base'] }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ $pair['mr'] }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($rows as $r)
                        @php
                            $baseField = $pair['base'];
                            $mrField = $pair['mr'];
                            $baseVal = $r->$baseField ?? '';
                        @endphp
                        <tr class="align-top">
                            <td class="px-4 py-3 font-mono text-xs tabular-nums">{{ $r->id }}</td>
                            @if (! empty($show_parent_id))
                                <td class="px-4 py-3 font-mono text-xs">{{ $r->parent_id ?? '—' }}</td>
                            @endif
                            @if (! empty($show_type))
                                <td class="px-4 py-3">{{ $r->type ?? '—' }}</td>
                            @endif
                            <td class="px-4 py-3 max-w-xs break-words">{{ $baseVal }}</td>
                            <td class="px-4 py-3 min-w-[14rem]">
                                <form method="post" action="{{ route('admin.data-engine.mr-fill.update', ['row' => $r->id]) }}" class="flex flex-col gap-2">
                                    @csrf
                                    <input type="hidden" name="table" value="{{ $pair['table'] }}" />
                                    <input type="hidden" name="base" value="{{ $pair['base'] }}" />
                                    <input type="hidden" name="mr" value="{{ $pair['mr'] }}" />
                                    <input type="text" name="marathi" value="" dir="auto" lang="mr"
                                        class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-2 py-1.5 text-sm"
                                        placeholder="मराठी नाव" autocomplete="off" />
                                    <button type="submit" class="self-start rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500">
                                        Save
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ 3 + (! empty($show_parent_id) ? 1 : 0) + (! empty($show_type) ? 1 : 0) }}" class="px-4 py-8 text-center text-gray-500">No pending rows for this pair. Run <strong>Analyze</strong> again after bulk imports.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($rows->hasPages())
            <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
                {{ $rows->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
