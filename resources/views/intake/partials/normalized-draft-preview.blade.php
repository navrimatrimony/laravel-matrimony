@php
    $draftPreview = is_array($normalizedDraftPreview ?? null) ? $normalizedDraftPreview : [];
    $draftAvailable = ! empty($draftPreview['available']);
    $draftSections = is_array($draftPreview['sections'] ?? null) ? $draftPreview['sections'] : [];
    $sectionKeys = ['personal', 'family', 'contacts', 'addresses', 'property', 'horoscope', 'relatives', 'review_needed'];
@endphp

<section
    class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 lg:p-5 space-y-4"
    aria-labelledby="intake-normalized-draft-heading"
>
    <div>
        <h2 id="intake-normalized-draft-heading" class="text-base font-semibold border-b border-gray-200 dark:border-gray-600 pb-2">
            {{ __('intake.normalized_draft_heading') }}
        </h2>
        <p class="mt-2 text-xs text-gray-600 dark:text-gray-400 leading-relaxed">
            {{ __('intake.normalized_draft_disclaimer') }}
        </p>
    </div>

    @if (! empty($draftPreview['build_error']))
        <div class="rounded border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/30 px-3 py-2 text-xs text-amber-900 dark:text-amber-100">
            {{ __('intake.normalized_draft_error') }}
        </div>
    @elseif (! $draftAvailable)
        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ __('intake.normalized_draft_unavailable') }}
        </p>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach ($sectionKeys as $sectionKey)
                @php
                    $rows = is_array($draftSections[$sectionKey] ?? null) ? $draftSections[$sectionKey] : [];
                    $isReview = $sectionKey === 'review_needed';
                @endphp
                <div class="rounded border border-gray-200 dark:border-gray-600 p-3 min-w-0">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">
                        @if ($isReview)
                            {{ __('intake.normalized_draft_review_needed') }}
                        @else
                            {{ __('intake.normalized_draft_section_'.$sectionKey) }}
                        @endif
                    </h3>

                    @if ($rows === [])
                        <p class="text-xs text-gray-500 dark:text-gray-400 italic">
                            @if ($isReview)
                                {{ __('intake.normalized_draft_no_review_flags') }}
                            @else
                                {{ __('intake.normalized_draft_no_data') }}
                            @endif
                        </p>
                    @else
                        <dl class="space-y-2 text-xs">
                            @foreach ($rows as $row)
                                @if (! is_array($row))
                                    @continue
                                @endif
                                <div class="{{ $isReview ? 'rounded bg-amber-50 dark:bg-amber-950/25 border border-amber-200 dark:border-amber-800 px-2 py-1.5' : '' }}">
                                    <dt class="font-medium text-gray-700 dark:text-gray-300 break-words">{{ $row['label'] ?? '' }}</dt>
                                    <dd class="mt-0.5 text-gray-800 dark:text-gray-100 whitespace-pre-wrap break-words">{{ $row['value'] ?? '' }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if (config('app.debug') && ! empty($draftPreview['raw_draft_json']))
        <details class="rounded border border-dashed border-violet-400 dark:border-violet-700 bg-violet-50/80 dark:bg-violet-950/30 p-3 text-xs">
            <summary class="cursor-pointer font-medium text-violet-900 dark:text-violet-100 select-none">
                {{ __('intake.normalized_draft_debug_heading') }}
            </summary>
            <pre class="mt-2 whitespace-pre-wrap break-words font-mono text-gray-800 dark:text-gray-200 max-h-64 overflow-auto">{{ $draftPreview['raw_draft_json'] }}</pre>
        </details>
    @endif
</section>
