@extends('layouts.admin')

@section('content')
@php
    $fields = $reviewFields ?? [];
    $safeTh = (float) ($safeConfidenceThreshold ?? 0.85);
    $expectedMap = collect($fields)->mapWithKeys(static fn ($f) => [$f['id'] => $f['current_display']])->all();
@endphp
<div class="max-w-7xl mx-auto pb-28" id="suggestions-review-root"
     data-safe-threshold="{{ e(number_format($safeTh, 2, '.', '')) }}"
     data-msg-no-action="{{ e(\App\Support\ErrorFactory::adminSuggestionsChooseActionBeforeApply()->message) }}">
    <div class="flex flex-col lg:flex-row lg:items-start gap-6">
        <div class="flex-1 min-w-0">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Suggestion review</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Intake #{{ $intake->id }} · Profile #{{ $profile->id }} — {{ $profile->full_name ?? '—' }}
                    </p>
                </div>
                <a href="{{ route('admin.biodata-intakes.show', $intake) }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">← Back to intake</a>
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

            <form id="review-apply-form" method="POST" action="{{ route('admin.suggestions.review.apply', $intake) }}">
                @csrf
                <input type="hidden" name="review_payload" id="review_payload" value="">

                <div class="mb-4 flex flex-wrap gap-2 text-xs">
                    <button type="button" data-filter="all" class="filter-btn px-3 py-1.5 rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 font-medium">All</button>
                    <button type="button" data-filter="changed" class="filter-btn px-3 py-1.5 rounded-md border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50 text-gray-700 dark:text-gray-200">Changed only</button>
                    <button type="button" data-filter="lowconf" class="filter-btn px-3 py-1.5 rounded-md border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50 text-gray-700 dark:text-gray-200">Low confidence</button>
                    <button type="button" data-filter="conflict" class="filter-btn px-3 py-1.5 rounded-md border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50 text-gray-700 dark:text-gray-200">Conflicts only</button>
                </div>

                <div class="mb-4 flex flex-wrap items-center gap-3">
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer select-none">
                        <input type="checkbox" id="filter-actionable" class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500">
                        <span>Actionable only</span>
                    </label>
                    <span class="text-xs text-gray-500 dark:text-gray-400">Show undecided rows, or any with a conflict hint, or any changed value.</span>
                </div>

                <div class="space-y-4" id="field-cards">
                    @forelse ($fields as $f)
                        @php
                            $id = $f['id'];
                            $conf = $f['confidence'];
                            $lowConf = $conf !== null && (float) $conf < $safeTh;
                            $identical = !empty($f['identical']);
                        @endphp
                        <article
                            class="review-card border border-gray-200 dark:border-gray-600 rounded-lg p-4 bg-white dark:bg-gray-800 shadow-sm transition-shadow"
                            data-field-card
                            data-row-id="{{ $id }}"
                            data-identical="{{ $identical ? '1' : '0' }}"
                            data-lowconf="{{ $lowConf ? '1' : '0' }}"
                            data-has-conflict="{{ !empty($f['has_conflict']) ? '1' : '0' }}"
                            data-confidence="{{ $conf !== null ? e((string) $conf) : '' }}"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                                <div>
                                    <span class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">{{ $f['scope_label'] }}</span>
                                    <span class="ml-2 font-mono text-sm text-gray-900 dark:text-gray-100">{{ $f['field_key'] }}</span>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($conf !== null)
                                        <span class="text-xs px-2 py-0.5 rounded-full {{ $lowConf ? 'bg-amber-100 dark:bg-amber-900/40 text-amber-900 dark:text-amber-100' : 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-900 dark:text-emerald-100' }}">
                                            Conf {{ number_format((float) $conf, 2) }}
                                        </span>
                                    @else
                                        <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">No confidence</span>
                                    @endif
                                    @if (!empty($f['has_conflict']))
                                        <span class="text-xs font-semibold text-red-600 dark:text-red-400">Conflict detected</span>
                                    @endif
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-3 diff-grid {{ $identical ? 'hidden' : '' }}">
                                <div class="bg-gray-100 dark:bg-gray-700/50 p-2 rounded border {{ $identical ? 'border-gray-200 dark:border-gray-600' : 'border-amber-300 dark:border-amber-700' }}">
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Current</p>
                                    <p class="text-sm text-gray-900 dark:text-gray-100 whitespace-pre-wrap break-words min-h-[2rem] review-current-display">{{ $f['current_display'] !== '' ? $f['current_display'] : '—' }}</p>
                                </div>
                                <div class="bg-sky-50 dark:bg-sky-900/20 p-2 rounded border border-sky-200 dark:border-sky-800 {{ $identical ? 'opacity-80' : '' }}">
                                    <p class="text-xs text-sky-600 dark:text-sky-300 mb-1">Incoming</p>
                                    <p class="text-sm text-gray-900 dark:text-gray-100 whitespace-pre-wrap break-words min-h-[2rem]">{{ $f['incoming_display'] !== '' ? $f['incoming_display'] : '—' }}</p>
                                </div>
                            </div>

                            @if ($identical)
                                <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">
                                    <button type="button" class="toggle-identical underline">Show / hide identical row</button>
                                </p>
                            @endif

                            <div class="flex flex-wrap gap-2 mt-2">
                                <button type="button" data-action="accept" class="px-3 py-1.5 rounded-md bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold">Accept</button>
                                <button type="button" data-action="reject" class="px-3 py-1.5 rounded-md bg-gray-500 hover:bg-gray-600 text-white text-xs font-semibold">Reject</button>
                                <button type="button" data-action="flag" class="px-3 py-1.5 rounded-md bg-amber-500 hover:bg-amber-600 text-white text-xs font-semibold">Flag</button>
                                <span class="decision-label text-xs text-gray-500 dark:text-gray-400 self-center ml-2" data-decision-display></span>
                            </div>
                        </article>
                    @empty
                        <p class="text-gray-500 dark:text-gray-400">No reviewable rows.</p>
                    @endforelse
                </div>

                <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                    Apply uses <code class="text-[11px]">MutationService::applyManualSnapshot</code>. The server re-checks each field against the database; mismatches create system conflicts instead of silent updates.
                </p>
            </form>
        </div>

        <aside class="w-full lg:w-64 shrink-0 lg:sticky lg:top-6">
            <div class="rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-800/80 p-4 text-sm">
                <h2 class="font-semibold text-gray-800 dark:text-gray-100 mb-3">Summary</h2>
                <ul class="space-y-2 text-gray-700 dark:text-gray-300">
                    <li><span class="text-gray-500 dark:text-gray-400">Total fields</span> <span id="sum-total" class="font-semibold float-right">{{ count($fields) }}</span></li>
                    <li><span class="text-gray-500 dark:text-gray-400">Accepted</span> <span id="sum-accept" class="font-semibold float-right text-emerald-600">0</span></li>
                    <li><span class="text-gray-500 dark:text-gray-400">Rejected</span> <span id="sum-reject" class="font-semibold float-right text-gray-600">0</span></li>
                    <li><span class="text-gray-500 dark:text-gray-400">Flagged</span> <span id="sum-flag" class="font-semibold float-right text-amber-600">0</span></li>
                    <li><span class="text-gray-500 dark:text-gray-400">Unset</span> <span id="sum-unset" class="font-semibold float-right">{{ count($fields) }}</span></li>
                </ul>
            </div>
        </aside>
    </div>
</div>

<div class="fixed bottom-0 left-0 right-0 z-50 border-t border-gray-200 dark:border-gray-700 bg-white/95 dark:bg-gray-900/95 backdrop-blur-sm shadow-[0_-4px_20px_rgba(0,0,0,0.06)]">
    <div class="max-w-7xl mx-auto px-4 py-3 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-2">
            <button type="button" id="accept-safe" class="px-3 py-2 rounded-md bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold">
                Accept all safe
            </button>
            <button type="button" id="reject-all" class="px-3 py-2 rounded-md bg-gray-600 hover:bg-gray-700 text-white text-xs font-semibold">
                Reject all
            </button>
        </div>
        <button type="submit" form="review-apply-form" id="apply-btn" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md font-semibold text-sm">
            Apply reviewed changes
        </button>
    </div>
</div>

<script type="application/json" id="expected-current-map">@json($expectedMap)</script>
@vite('resources/js/admin/suggestions-review.js')
@endsection
