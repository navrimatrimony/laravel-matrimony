@php
    $draftPreview = is_array($normalizedDraftPreview ?? null) ? $normalizedDraftPreview : [];
    $draftAvailable = ! empty($draftPreview['available']);
    $detectedButNotIncluded = is_array($draftPreview['detected_but_not_included'] ?? null) ? $draftPreview['detected_but_not_included'] : [];
    $draftSections = is_array($draftPreview['sections'] ?? null) ? $draftPreview['sections'] : [];
    $hideEmptySections = ! empty($hideEmptySections);
    $adminLightMode = ! empty($adminLightMode);
    $sectionKeys = array_keys($draftSections);
    if ($sectionKeys === []) {
        $sectionKeys = array_values(array_unique(array_merge(
            ['review_needed'],
            config('field_catalog.section_order', [])
        )));
    }
    $sectionLabels = config('field_catalog.section_labels', []);
    $reviewNeededRows = is_array($draftSections['review_needed'] ?? null) ? $draftSections['review_needed'] : [];
    $detectedAlertCount = count($detectedButNotIncluded);
    $reviewAlertCount = count($reviewNeededRows);
    $showReviewAlertRow = $detectedAlertCount > 0 || $reviewAlertCount > 0;
    $draftParsedReconciliation = is_array($draftPreview['draft_parsed_reconciliation'] ?? null) ? $draftPreview['draft_parsed_reconciliation'] : [];
    $reconciliationAvailable = ! empty($draftParsedReconciliation['available']);
    $draftNotInParsedRows = is_array($draftParsedReconciliation['draft_not_in_parsed'] ?? null) ? $draftParsedReconciliation['draft_not_in_parsed'] : [];
    $parsedNotInDraftRows = is_array($draftParsedReconciliation['parsed_not_in_draft'] ?? null) ? $draftParsedReconciliation['parsed_not_in_draft'] : [];
    $draftNotInParsedCount = count($draftNotInParsedRows);
    $parsedNotInDraftCount = count($parsedNotInDraftRows);
    $reconciliationAlertCount = $draftNotInParsedCount + $parsedNotInDraftCount;
    $draftCorrectionApplyEnabled = ! empty($draftCorrectionApplyEnabled);
    $draftCorrectionApplyRoute = (string) ($draftCorrectionApplyRoute ?? '');
@endphp

<section
    @class([
        'rounded-lg shadow p-4 lg:p-5 space-y-4 border',
        'bg-white border-sky-200 text-gray-900' => $adminLightMode,
        'bg-white dark:bg-gray-800 border-sky-200 dark:border-sky-800' => ! $adminLightMode,
    ])
    aria-labelledby="intake-normalized-draft-heading"
>
    <div>
        <h2 id="intake-normalized-draft-heading" class="text-base font-semibold border-b border-gray-200 dark:border-gray-600 pb-2 text-sky-900 dark:text-sky-100">
            {{ __('intake.normalized_draft_heading') }}
        </h2>
        <p class="mt-2 text-xs text-sky-900/90 dark:text-sky-100/90 leading-relaxed rounded border border-sky-200 dark:border-sky-800 bg-sky-50 dark:bg-sky-950/30 px-3 py-2">
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
        @if ($showReviewAlertRow)
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
                <details open class="group min-w-0 rounded-lg border border-rose-300 dark:border-rose-700 bg-rose-50 dark:bg-rose-950/20 overflow-hidden">
                    <summary class="cursor-pointer select-none list-none flex flex-wrap items-center justify-between gap-2 px-3 py-2.5 border-b border-rose-200 dark:border-rose-800 bg-rose-100/80 dark:bg-rose-950/40 hover:bg-rose-100 dark:hover:bg-rose-950/50">
                        <span class="text-sm font-semibold text-rose-900 dark:text-rose-100">
                            {{ __('intake.normalized_draft_detected_not_included_heading') }}
                        </span>
                        <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-rose-800 dark:text-rose-200">
                            <span class="rounded-full bg-rose-200/80 dark:bg-rose-900/60 px-2 py-0.5">{{ $detectedAlertCount }}</span>
                            <span class="text-rose-600 dark:text-rose-300 group-open:hidden">{{ __('intake.normalized_draft_panel_expand') }}</span>
                            <span class="text-rose-600 dark:text-rose-300 hidden group-open:inline">{{ __('intake.normalized_draft_panel_collapse') }}</span>
                        </span>
                    </summary>
                    <div class="px-3 py-3 space-y-2">
                        <p class="text-xs text-rose-900/90 dark:text-rose-100/90">
                            {{ __('intake.normalized_draft_detected_not_included_help') }}
                            <span class="block mt-1 text-rose-800/80 dark:text-rose-100/80">{{ __('intake.normalized_draft_correction_help') }}</span>
                        </p>
                        @if ($detectedAlertCount === 0)
                            <p class="text-xs text-rose-800/70 dark:text-rose-200/70 italic">{{ __('intake.normalized_draft_panel_none') }}</p>
                        @else
                            <dl class="space-y-2 text-xs max-h-80 overflow-y-auto pr-1">
                                @foreach ($detectedButNotIncluded as $row)
                                    @if (! is_array($row))
                                        @continue
                                    @endif
                                    <div class="rounded border border-rose-200 dark:border-rose-800 bg-white/70 dark:bg-gray-900/30 px-2 py-1.5 space-y-1">
                                        <div>
                                            <span class="font-semibold text-gray-700 dark:text-gray-300">
                                                @if (! empty($row['source_line_no']))
                                                    {{ __('intake.normalized_draft_source_line_with_no', ['n' => (int) $row['source_line_no']]) }}:
                                                @else
                                                    {{ __('intake.normalized_draft_source_line_label') }}:
                                                @endif
                                            </span>
                                            <span class="text-gray-800 dark:text-gray-100 whitespace-pre-wrap break-words">{{ (string) ($row['value'] ?? '') }}</span>
                                        </div>
                                        @if (! empty($row['draft_shows']))
                                            <div>
                                                <span class="font-semibold text-emerald-800 dark:text-emerald-200">{{ __('intake.normalized_draft_in_draft_label') }}:</span>
                                                <span class="text-gray-800 dark:text-gray-100 break-words">{{ (string) $row['draft_shows'] }}</span>
                                            </div>
                                        @endif
                                        @if (! empty($row['missing_field']) || ! empty($row['missing_value']))
                                            <div>
                                                <span class="font-semibold text-amber-900 dark:text-amber-100">{{ __('intake.normalized_draft_missing_label') }}:</span>
                                                <span class="text-gray-800 dark:text-gray-100 break-words">
                                                    {{ (string) ($row['missing_field'] ?? '') }}
                                                    @if (! empty($row['missing_value']))
                                                        <span class="font-medium"> = {{ (string) $row['missing_value'] }}</span>
                                                    @endif
                                                </span>
                                            </div>
                                        @endif
                                        @if (! empty($row['correction_target']))
                                            <div>
                                                <span class="font-semibold text-sky-900 dark:text-sky-100">{{ __('intake.normalized_draft_add_to_label') }}:</span>
                                                <span class="text-gray-800 dark:text-gray-100 break-words">{{ (string) $row['correction_target'] }}</span>
                                            </div>
                                        @endif
                                        @if (! empty($row['can_apply']))
                                            <div class="pt-0.5">
                                                @include('intake.partials._draft-correction-apply-form', [
                                                    'applyField' => $row['apply_field'] ?? '',
                                                    'applyValue' => $row['apply_value'] ?? '',
                                                    'draftCorrectionApplyEnabled' => $draftCorrectionApplyEnabled,
                                                    'draftCorrectionApplyRoute' => $draftCorrectionApplyRoute,
                                                ])
                                            </div>
                                        @endif
                                        @if (! empty($row['reason']))
                                            <p class="text-[11px] font-medium text-rose-900 dark:text-rose-100">
                                                <span class="font-semibold">{{ __('intake.normalized_draft_issue_label') }}:</span>
                                                {{ (string) $row['reason'] }}
                                                @if (! empty($row['suggested_section']))
                                                    <span class="font-normal text-rose-800 dark:text-rose-200"> · {{ __('intake.normalized_draft_review_suggested_section_prefix') }} {{ (string) $row['suggested_section'] }}</span>
                                                @endif
                                            </p>
                                        @elseif (! empty($row['suggested_section']))
                                            <p class="text-[11px] text-rose-800 dark:text-rose-200">
                                                {{ __('intake.normalized_draft_review_suggested_section_prefix') }} {{ (string) $row['suggested_section'] }}
                                            </p>
                                        @endif
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    </div>
                </details>

                <details open class="group min-w-0 rounded-lg border-2 border-amber-400 dark:border-amber-600 bg-amber-50/70 dark:bg-amber-950/20 overflow-hidden">
                    <summary class="cursor-pointer select-none list-none flex flex-wrap items-center justify-between gap-2 px-3 py-2.5 border-b border-amber-300 dark:border-amber-700 bg-amber-100/80 dark:bg-amber-950/40 hover:bg-amber-100 dark:hover:bg-amber-950/50">
                        <span class="text-sm font-semibold text-amber-900 dark:text-amber-100">
                            {{ __('intake.normalized_draft_review_needed') }}
                        </span>
                        <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-amber-800 dark:text-amber-200">
                            <span class="rounded-full bg-amber-200/80 dark:bg-amber-900/60 px-2 py-0.5">{{ $reviewAlertCount }}</span>
                            <span class="text-amber-700 dark:text-amber-300 group-open:hidden">{{ __('intake.normalized_draft_panel_expand') }}</span>
                            <span class="text-amber-700 dark:text-amber-300 hidden group-open:inline">{{ __('intake.normalized_draft_panel_collapse') }}</span>
                        </span>
                    </summary>
                    <div class="px-3 py-3 space-y-2">
                        @if ($reviewAlertCount > 0)
                            <p class="text-xs text-amber-900/90 dark:text-amber-100/90">{{ __('intake.normalized_draft_correction_help') }}</p>
                        @endif
                        @if ($reviewAlertCount === 0)
                            <p class="text-xs text-amber-800/70 dark:text-amber-200/70 italic">{{ __('intake.normalized_draft_no_review_flags') }}</p>
                        @else
                            <dl class="space-y-2 text-xs max-h-80 overflow-y-auto pr-1">
                                @foreach ($reviewNeededRows as $row)
                                    @if (! is_array($row))
                                        @continue
                                    @endif
                                    <div class="rounded bg-amber-100/80 dark:bg-amber-950/35 border border-amber-300 dark:border-amber-700 px-2 py-1.5 space-y-1">
                                        @if (! empty($row['label']))
                                            <div class="font-semibold text-amber-950 dark:text-amber-100">{{ (string) $row['label'] }}</div>
                                        @endif
                                        @if (! empty($row['source_text']))
                                            <div>
                                                <span class="font-semibold text-gray-700 dark:text-gray-300">
                                                    @if (! empty($row['source_line_no']))
                                                        {{ __('intake.normalized_draft_source_line_with_no', ['n' => (int) $row['source_line_no']]) }}:
                                                    @else
                                                        {{ __('intake.normalized_draft_source_line_label') }}:
                                                    @endif
                                                </span>
                                                <span class="text-gray-800 dark:text-gray-100 whitespace-pre-wrap break-words">{{ (string) $row['source_text'] }}</span>
                                            </div>
                                        @endif
                                        @if (! empty($row['value']))
                                            <div>
                                                <span class="font-semibold text-amber-950 dark:text-amber-100">{{ __('intake.normalized_draft_issue_label') }}:</span>
                                                <span class="text-gray-800 dark:text-gray-100 break-words">{{ (string) $row['value'] }}</span>
                                            </div>
                                        @endif
                                        @if (! empty($row['missing_field']) || ! empty($row['missing_value']))
                                            <div>
                                                <span class="font-semibold text-amber-900 dark:text-amber-100">{{ __('intake.normalized_draft_missing_label') }}:</span>
                                                <span class="text-gray-800 dark:text-gray-100 break-words">
                                                    {{ (string) ($row['missing_field'] ?? '') }}
                                                    @if (! empty($row['missing_value']))
                                                        <span class="font-medium"> = {{ (string) $row['missing_value'] }}</span>
                                                    @endif
                                                </span>
                                            </div>
                                        @endif
                                        @if (! empty($row['correction_target']))
                                            <div>
                                                <span class="font-semibold text-sky-900 dark:text-sky-100">{{ __('intake.normalized_draft_add_to_label') }}:</span>
                                                <span class="text-gray-800 dark:text-gray-100 break-words">{{ (string) $row['correction_target'] }}</span>
                                            </div>
                                        @elseif (! empty($row['suggested_section']))
                                            <div>
                                                <span class="font-semibold text-sky-900 dark:text-sky-100">{{ __('intake.normalized_draft_add_to_label') }}:</span>
                                                <span class="text-gray-800 dark:text-gray-100 break-words">{{ (string) $row['suggested_section'] }}</span>
                                            </div>
                                        @endif
                                        @if (! empty($row['review_hint']))
                                            <p class="mt-1 text-[11px] font-medium text-amber-800 dark:text-amber-200">
                                                {{ $row['review_hint'] }}
                                            </p>
                                        @endif
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    </div>
                </details>
            </div>
        @endif

        @if ($reconciliationAvailable)
            <details open class="group min-w-0 rounded-lg border-2 border-violet-400 dark:border-violet-600 bg-violet-50/60 dark:bg-violet-950/20 overflow-hidden">
                <summary class="cursor-pointer select-none list-none flex flex-wrap items-center justify-between gap-2 px-3 py-2.5 border-b border-violet-300 dark:border-violet-700 bg-violet-100/80 dark:bg-violet-950/40 hover:bg-violet-100 dark:hover:bg-violet-950/50">
                    <span class="text-sm font-semibold text-violet-950 dark:text-violet-100">
                        {{ __('intake.normalized_draft_reconciliation_heading') }}
                    </span>
                    <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-violet-800 dark:text-violet-200">
                        <span class="rounded-full bg-violet-200/80 dark:bg-violet-900/60 px-2 py-0.5">{{ $reconciliationAlertCount }}</span>
                        <span class="text-violet-700 dark:text-violet-300 group-open:hidden">{{ __('intake.normalized_draft_panel_expand') }}</span>
                        <span class="text-violet-700 dark:text-violet-300 hidden group-open:inline">{{ __('intake.normalized_draft_panel_collapse') }}</span>
                    </span>
                </summary>
                <div class="px-3 py-3 space-y-3">
                    <p class="text-xs text-violet-950/90 dark:text-violet-100/90 leading-relaxed">
                        {{ __('intake.normalized_draft_reconciliation_help') }}
                    </p>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
                        <div class="min-w-0 rounded-lg border border-orange-300 dark:border-orange-700 bg-orange-50/70 dark:bg-orange-950/20 overflow-hidden">
                            <div class="px-3 py-2 border-b border-orange-200 dark:border-orange-800 bg-orange-100/80 dark:bg-orange-950/40">
                                <h4 class="text-xs font-semibold text-orange-950 dark:text-orange-100">
                                    {{ __('intake.normalized_draft_draft_not_in_parsed_heading') }}
                                    <span class="ml-1 rounded-full bg-orange-200/80 dark:bg-orange-900/60 px-1.5 py-0.5">{{ $draftNotInParsedCount }}</span>
                                </h4>
                            </div>
                            <div class="px-3 py-2">
                                @if ($draftNotInParsedCount === 0)
                                    <p class="text-xs text-orange-900/70 dark:text-orange-100/70 italic">{{ __('intake.normalized_draft_panel_none') }}</p>
                                @else
                                    <dl class="space-y-2 text-xs max-h-72 overflow-y-auto pr-1">
                                        @foreach ($draftNotInParsedRows as $row)
                                            @if (! is_array($row))
                                                @continue
                                            @endif
                                            <div class="rounded border border-orange-200 dark:border-orange-800 bg-white/70 dark:bg-gray-900/30 px-2 py-1.5 space-y-1">
                                                <div class="font-semibold text-orange-950 dark:text-orange-100 break-words">
                                                    {{ (string) ($row['field_label'] ?? '') }}
                                                    @if (! empty($row['section_label']))
                                                        <span class="font-normal text-orange-800 dark:text-orange-200"> · {{ (string) $row['section_label'] }}</span>
                                                    @endif
                                                </div>
                                                <div>
                                                    <span class="font-semibold text-emerald-900 dark:text-emerald-100">{{ __('intake.normalized_draft_reconciliation_draft_value_label') }}:</span>
                                                    <span class="text-gray-800 dark:text-gray-100 break-words">{{ (string) ($row['draft_value'] ?? '') }}</span>
                                                </div>
                                                @if (! empty($row['parsed_value']))
                                                    <div>
                                                        <span class="font-semibold text-sky-900 dark:text-sky-100">{{ __('intake.normalized_draft_reconciliation_parsed_value_label') }}:</span>
                                                        <span class="text-gray-800 dark:text-gray-100 break-words">{{ (string) $row['parsed_value'] }}</span>
                                                    </div>
                                                @else
                                                    <div>
                                                        <span class="font-semibold text-sky-900 dark:text-sky-100">{{ __('intake.normalized_draft_reconciliation_parsed_value_label') }}:</span>
                                                        <span class="text-gray-500 dark:text-gray-400 italic">{{ __('intake.normalized_draft_empty_field') }}</span>
                                                    </div>
                                                @endif
                                                @php
                                                    $reconKind = (string) ($row['kind'] ?? '');
                                                @endphp
                                                @if ($reconKind === 'value_mismatch')
                                                    <p class="text-[11px] font-medium text-orange-900 dark:text-orange-100">{{ __('intake.normalized_draft_reconciliation_kind_value_mismatch') }}</p>
                                                @else
                                                    <p class="text-[11px] font-medium text-orange-900 dark:text-orange-100">{{ __('intake.normalized_draft_reconciliation_kind_missing_in_parsed') }}</p>
                                                @endif
                                            </div>
                                        @endforeach
                                    </dl>
                                @endif
                            </div>
                        </div>

                        <div class="min-w-0 rounded-lg border border-sky-300 dark:border-sky-700 bg-sky-50/70 dark:bg-sky-950/20 overflow-hidden">
                            <div class="px-3 py-2 border-b border-sky-200 dark:border-sky-800 bg-sky-100/80 dark:bg-sky-950/40">
                                <h4 class="text-xs font-semibold text-sky-950 dark:text-sky-100">
                                    {{ __('intake.normalized_draft_parsed_not_in_draft_heading') }}
                                    <span class="ml-1 rounded-full bg-sky-200/80 dark:bg-sky-900/60 px-1.5 py-0.5">{{ $parsedNotInDraftCount }}</span>
                                </h4>
                            </div>
                            <div class="px-3 py-2">
                                @if ($parsedNotInDraftCount === 0)
                                    <p class="text-xs text-sky-900/70 dark:text-sky-100/70 italic">{{ __('intake.normalized_draft_panel_none') }}</p>
                                @else
                                    <dl class="space-y-2 text-xs max-h-72 overflow-y-auto pr-1">
                                        @foreach ($parsedNotInDraftRows as $row)
                                            @if (! is_array($row))
                                                @continue
                                            @endif
                                            <div class="rounded border border-sky-200 dark:border-sky-800 bg-white/70 dark:bg-gray-900/30 px-2 py-1.5 space-y-1">
                                                <div class="font-semibold text-sky-950 dark:text-sky-100 break-words">
                                                    {{ (string) ($row['field_label'] ?? '') }}
                                                    @if (! empty($row['section_label']))
                                                        <span class="font-normal text-sky-800 dark:text-sky-200"> · {{ (string) $row['section_label'] }}</span>
                                                    @endif
                                                </div>
                                                <div>
                                                    <span class="font-semibold text-emerald-900 dark:text-emerald-100">{{ __('intake.normalized_draft_reconciliation_draft_value_label') }}:</span>
                                                    <span class="text-gray-500 dark:text-gray-400 italic">{{ __('intake.normalized_draft_empty_field') }}</span>
                                                </div>
                                                <div>
                                                    <span class="font-semibold text-sky-900 dark:text-sky-100">{{ __('intake.normalized_draft_reconciliation_parsed_value_label') }}:</span>
                                                    <span class="text-gray-800 dark:text-gray-100 break-words">{{ (string) ($row['parsed_value'] ?? '') }}</span>
                                                </div>
                                                <p class="text-[11px] font-medium text-sky-900 dark:text-sky-100">{{ __('intake.normalized_draft_reconciliation_kind_missing_in_draft') }}</p>
                                            </div>
                                        @endforeach
                                    </dl>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </details>
        @endif

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach ($sectionKeys as $sectionKey)
                @php
                    if ($sectionKey === 'review_needed') {
                        continue;
                    }
                    $rows = is_array($draftSections[$sectionKey] ?? null) ? $draftSections[$sectionKey] : [];
                    $isReview = $sectionKey === 'review_needed';
                    if ($hideEmptySections && ! $isReview && $sectionKey !== 'photo' && $rows === []) {
                        continue;
                    }
                    $isHoroscopeSection = $sectionKey === 'horoscope';
                    $photoPreview = is_array($intakePhotoPreview ?? null) ? $intakePhotoPreview : [];
                    $showPhotoPreview = $sectionKey === 'photo' && ! empty($photoPreview['show_in_normalized_preview']);
                    $horoscopeBasicLabels = [
                        __('components.horoscope.mangal_dosh'),
                        __('components.horoscope.navras_name'),
                        __('components.horoscope.devak'),
                        __('components.horoscope.kul'),
                        __('components.horoscope.gotra'),
                        __('components.horoscope.birth_weekday'),
                    ];
                    $horoscopeDetailLabels = [
                        __('components.horoscope.nakshatra'),
                        __('components.horoscope.charan'),
                        __('components.horoscope.rashi'),
                        __('components.horoscope.gan'),
                        __('components.horoscope.nadi'),
                        __('components.horoscope.yoni'),
                        __('components.horoscope.varna'),
                        __('components.horoscope.vashya'),
                        __('components.horoscope.rashi_lord'),
                    ];
                @endphp
                <div @class([
                    'rounded p-3 min-w-0',
                    'md:col-span-2 border-2 border-amber-400 dark:border-amber-600 bg-amber-50/70 dark:bg-amber-950/20' => $isReview,
                    'border border-gray-200 dark:border-gray-600' => ! $isReview,
                ])>
                    <h3 @class([
                        'text-sm font-semibold mb-2',
                        'text-amber-900 dark:text-amber-100' => $isReview,
                        'text-gray-900 dark:text-gray-100' => ! $isReview,
                    ])>
                        @if ($isReview)
                            {{ __('intake.normalized_draft_review_needed') }}
                        @elseif ($isHoroscopeSection)
                            {{ __('intake.normalized_draft_section_horoscope_religious') }}
                        @else
                            {{ __($sectionLabels[$sectionKey] ?? $sectionKey) }}
                        @endif
                    </h3>

                    @if ($showPhotoPreview)
                        @if (! empty($photoPreview['available']) && ! empty($photoPreview['thumbnail_url']))
                            <div class="space-y-2 text-xs">
                                <img src="{{ $photoPreview['thumbnail_url'] }}" alt="Biodata photo candidate preview" class="h-24 w-24 rounded object-cover border border-gray-200 dark:border-gray-600">
                                <p class="text-gray-500 dark:text-gray-400">Preview only. Not saved as profile photo yet.</p>
                            </div>
                        @else
                            <p class="text-xs text-gray-500 dark:text-gray-400 italic">
                                {{ $photoPreview['message'] ?? 'Candidate photo extraction is not available yet.' }}
                            </p>
                        @endif
                    @elseif ($rows === [])
                        <p class="text-xs text-gray-500 dark:text-gray-400 italic">
                            @if ($isReview)
                                {{ __('intake.normalized_draft_no_review_flags') }}
                            @else
                                {{ __('intake.normalized_draft_no_data') }}
                            @endif
                        </p>
                    @elseif ($isHoroscopeSection)
                        @php
                            $basicRows = [];
                            $detailRows = [];
                            $otherRows = [];
                            foreach ($rows as $row) {
                                if (! is_array($row)) {
                                    continue;
                                }
                                $label = (string) ($row['label'] ?? '');
                                if (in_array($label, $horoscopeBasicLabels, true)) {
                                    $basicRows[] = $row;
                                    continue;
                                }
                                if (in_array($label, $horoscopeDetailLabels, true)) {
                                    $detailRows[] = $row;
                                    continue;
                                }
                                $otherRows[] = $row;
                            }
                            $horoscopeGroups = [
                                [
                                    'heading' => __('intake.normalized_draft_horoscope_basic_heading'),
                                    'rows' => $basicRows,
                                    'card' => 'rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50/60 dark:bg-gray-800/40 p-3',
                                ],
                                [
                                    'heading' => __('intake.normalized_draft_horoscope_details_heading'),
                                    'rows' => array_merge($detailRows, $otherRows),
                                    'card' => 'rounded-lg border border-blue-200 dark:border-blue-800 bg-blue-50/60 dark:bg-blue-950/20 p-3',
                                ],
                            ];
                        @endphp
                        <div class="space-y-4">
                            @foreach ($horoscopeGroups as $group)
                                <div class="{{ $group['card'] }}">
                                    <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">
                                        {{ $group['heading'] }}
                                    </h4>
                                    @if (($group['rows'] ?? []) === [])
                                        <p class="text-xs text-gray-500 dark:text-gray-400 italic">
                                            {{ __('intake.normalized_draft_no_data') }}
                                        </p>
                                    @else
                                        <dl class="space-y-2 text-xs">
                                            @foreach (($group['rows'] ?? []) as $row)
                                                @php
                                                    $needsReview = ! empty($row['needs_review']);
                                                    $rowLabel = (string) ($row['label'] ?? '');
                                                    $rowValue = (string) ($row['value'] ?? '');
                                                    $rowClasses = $needsReview
                                                        ? 'rounded bg-amber-50 dark:bg-amber-950/25 border border-amber-200 dark:border-amber-800 px-2 py-1.5'
                                                        : '';
                                                @endphp
                                                <div class="{{ $rowClasses }}">
                                                    <div class="flex flex-wrap items-start gap-2">
                                                        <span class="font-medium text-gray-700 dark:text-gray-300 break-words">{{ $rowLabel }}:</span>
                                                        <span class="text-gray-800 dark:text-gray-100 whitespace-pre-wrap break-words">{{ $rowValue }}</span>
                                                        @if ($needsReview)
                                                            <span class="inline-flex items-center rounded-full border border-amber-500 bg-amber-100 dark:bg-amber-900/50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900 dark:text-amber-100">
                                                                {{ __('intake.normalized_draft_needs_review_badge') }}
                                                            </span>
                                                        @endif
                                                    </div>
                                                    @if (! empty($row['review_hint']))
                                                        <p class="mt-1 text-[11px] font-medium text-amber-800 dark:text-amber-200">
                                                            {{ $row['review_hint'] }}
                                                        </p>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </dl>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @else
                        <dl class="space-y-2 text-xs">
                            @foreach ($rows as $row)
                                @if (! is_array($row))
                                    @continue
                                @endif
                                @php
                                    $needsReview = ! empty($row['needs_review']);
                                    $rowLabel = (string) ($row['label'] ?? '');
                                    $rowValue = (string) ($row['value'] ?? '');
                                    $displayLabel = (string) ($row['display_label'] ?? $rowLabel);
                                    $displayHeadingText = (string) ($row['display_heading_text'] ?? $rowValue);
                                    $rowVariant = (string) ($row['row_variant'] ?? '');
                                    $hasApply = ! empty($row['can_apply']);
                                    $isGroupHeading = $rowVariant === 'group_heading';
                                    $isGroupedDetail = in_array($rowVariant, ['group_detail', 'group_detail_value_only'], true);
                                    $isSuggestedCorrection = $rowVariant === 'suggested_correction';
                                    $spacingBefore = ! empty($row['spacing_before']);
                                    $isPropertySection = $sectionKey === 'property';
                                    $isPropertyAssetHeading = $isPropertySection && preg_match('/^(?:Property Asset|मालमत्ता साधन) \d+$/u', $rowLabel) === 1;
                                    $hidePropertyNotMentioned = $isPropertySection && ! $isPropertyAssetHeading && $rowValue === __('intake.normalized_draft_not_mentioned');
                                @endphp
                                @if ($hidePropertyNotMentioned)
                                    @continue
                                @endif
                                @php
                                    $rowClasses = $isSuggestedCorrection
                                        ? 'rounded border border-dashed border-emerald-400 bg-emerald-50/80 dark:bg-emerald-950/20 px-2 py-1.5'
                                        : ($hasApply
                                            ? 'rounded border border-emerald-300 bg-emerald-50/70 dark:bg-emerald-950/20 px-2 py-1.5'
                                            : ($isReview
                                                ? 'rounded bg-amber-100/80 dark:bg-amber-950/35 border border-amber-300 dark:border-amber-700 px-2 py-1.5'
                                                : ($needsReview
                                                    ? 'rounded bg-amber-50 dark:bg-amber-950/25 border border-amber-200 dark:border-amber-800 px-2 py-1.5'
                                                    : '')));
                                @endphp
                                <div class="{{ $rowClasses }}">
                                    @if ($isSuggestedCorrection)
                                        <div class="flex flex-wrap items-start gap-2">
                                            <span class="font-medium text-emerald-900 dark:text-emerald-100 break-words">{{ $rowLabel }}:</span>
                                            <span class="text-gray-800 dark:text-gray-100 whitespace-pre-wrap break-words">{{ $rowValue }}</span>
                                            <span class="inline-flex items-center rounded-full border border-emerald-500 bg-emerald-100 dark:bg-emerald-900/50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-900 dark:text-emerald-100">
                                                {{ __('intake.normalized_draft_section_suggestion_badge') }}
                                            </span>
                                            @include('intake.partials._draft-correction-apply-form', [
                                                'applyField' => $row['apply_field'] ?? '',
                                                'applyValue' => $row['apply_value'] ?? '',
                                                'draftCorrectionApplyEnabled' => $draftCorrectionApplyEnabled,
                                                'draftCorrectionApplyRoute' => $draftCorrectionApplyRoute,
                                            ])
                                        </div>
                                        @if (! empty($row['correction_hint']))
                                            <p class="mt-1 text-[11px] text-emerald-900 dark:text-emerald-100">{{ (string) $row['correction_hint'] }}</p>
                                        @endif
                                    @elseif ($isPropertyAssetHeading)
                                        <dt class="font-semibold text-gray-900 dark:text-gray-100 break-words flex flex-wrap items-center gap-2 pt-1">
                                            <span>{{ $rowLabel }}</span>
                                            @if ($needsReview && ! $isReview)
                                                <span class="inline-flex items-center rounded-full border border-amber-500 bg-amber-100 dark:bg-amber-900/50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900 dark:text-amber-100">
                                                    {{ __('intake.normalized_draft_needs_review_badge') }}
                                                </span>
                                            @endif
                                        </dt>
                                        @if (! empty($row['review_hint']))
                                            <p class="mt-1 text-[11px] font-medium text-amber-800 dark:text-amber-200">
                                                {{ $row['review_hint'] }}
                                            </p>
                                        @endif
                                    @elseif ($isGroupHeading)
                                        <div @class([
                                            'font-semibold text-gray-900 dark:text-gray-100 break-words',
                                            'mt-4' => $spacingBefore,
                                        ])>
                                            {{ $displayHeadingText }}
                                            @if ($needsReview && ! $isReview)
                                                <span class="ml-2 inline-flex items-center rounded-full border border-amber-500 bg-amber-100 dark:bg-amber-900/50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900 dark:text-amber-100">
                                                    {{ __('intake.normalized_draft_needs_review_badge') }}
                                                </span>
                                            @endif
                                        </div>
                                        @if (! empty($row['review_hint']))
                                            <p class="mt-1 text-[11px] font-medium text-amber-800 dark:text-amber-200">
                                                {{ $row['review_hint'] }}
                                            </p>
                                        @endif
                                    @elseif ($isPropertySection)
                                        <dd class="mt-0.5 pl-3 text-gray-800 dark:text-gray-100 break-words">
                                            <span class="font-medium text-gray-700 dark:text-gray-300">{{ $rowLabel }}:</span>
                                            <span>{{ $rowValue }}</span>
                                            @if ($needsReview && ! $isReview)
                                                <span class="ml-2 inline-flex items-center rounded-full border border-amber-500 bg-amber-100 dark:bg-amber-900/50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900 dark:text-amber-100">
                                                    {{ __('intake.normalized_draft_needs_review_badge') }}
                                                </span>
                                            @endif
                                        </dd>
                                        @if (! empty($row['review_hint']))
                                            <p class="mt-1 pl-3 text-[11px] font-medium text-amber-800 dark:text-amber-200">
                                                {{ $row['review_hint'] }}
                                            </p>
                                        @endif
                                    @elseif ($isGroupedDetail)
                                        <div class="flex flex-wrap items-start gap-2 pl-3">
                                            @if ($rowVariant === 'group_detail_value_only')
                                                <span class="text-gray-800 dark:text-gray-100 whitespace-pre-wrap break-words">{{ $rowValue }}</span>
                                            @else
                                                <span class="font-medium text-gray-700 dark:text-gray-300 break-words">{{ $displayLabel }}:</span>
                                                <span class="text-gray-800 dark:text-gray-100 whitespace-pre-wrap break-words">{{ $rowValue }}</span>
                                            @endif
                                            @if ($needsReview && ! $isReview)
                                                <span class="inline-flex items-center rounded-full border border-amber-500 bg-amber-100 dark:bg-amber-900/50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900 dark:text-amber-100">
                                                    {{ __('intake.normalized_draft_needs_review_badge') }}
                                                </span>
                                            @endif
                                        </div>
                                        @if (! empty($row['review_hint']))
                                            <p class="mt-1 pl-3 text-[11px] font-medium text-amber-800 dark:text-amber-200">
                                                {{ $row['review_hint'] }}
                                            </p>
                                        @endif
                                    @else
                                        <div class="flex flex-wrap items-start gap-2">
                                            <span class="font-medium text-gray-700 dark:text-gray-300 break-words">{{ $rowLabel }}:</span>
                                            <span class="text-gray-800 dark:text-gray-100 whitespace-pre-wrap break-words">{{ $rowValue }}</span>
                                            @if ($needsReview && ! $isReview)
                                                <span class="inline-flex items-center rounded-full border border-amber-500 bg-amber-100 dark:bg-amber-900/50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900 dark:text-amber-100">
                                                    {{ __('intake.normalized_draft_needs_review_badge') }}
                                                </span>
                                            @endif
                                        </div>
                                        @if (! empty($row['review_hint']))
                                            <p class="mt-1 text-[11px] font-medium text-amber-800 dark:text-amber-200">
                                                {{ $row['review_hint'] }}
                                            </p>
                                        @endif
                                        @if (! empty($row['can_apply']))
                                            <div class="mt-1">
                                                @include('intake.partials._draft-correction-apply-form', [
                                                    'applyField' => $row['apply_field'] ?? '',
                                                    'applyValue' => $row['apply_value'] ?? '',
                                                    'draftCorrectionApplyEnabled' => $draftCorrectionApplyEnabled,
                                                    'draftCorrectionApplyRoute' => $draftCorrectionApplyRoute,
                                                ])
                                            </div>
                                        @endif
                                    @endif
                                </div>
                            @endforeach
                        </dl>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if (! empty($draftPreview['raw_draft_json']))
        <details class="rounded border border-dashed border-violet-400 dark:border-violet-700 bg-violet-50/80 dark:bg-violet-950/30 p-3 text-xs">
            <summary class="cursor-pointer font-medium text-violet-900 dark:text-violet-100 select-none">
                Raw normalized draft JSON
            </summary>
            <pre class="mt-2 whitespace-pre-wrap break-words font-mono text-gray-800 dark:text-gray-200 max-h-64 overflow-auto">{{ $draftPreview['raw_draft_json'] }}</pre>
        </details>
    @endif
</section>
