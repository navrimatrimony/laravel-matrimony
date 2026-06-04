@extends('layouts.app')

@section('content')
<div class="container max-w-6xl mx-auto py-8 px-4">
    <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
        <a href="{{ route('intake.index') }}" class="hover:underline">← {{ __('intake.my_biodata_uploads') }}</a>
    </p>
    <h1 class="text-2xl font-bold mb-2">{{ __('intake.intake_preview') }}</h1>
    @if(!empty($ocrPresetFeedback))
        <div class="mb-3 rounded-lg border border-sky-200 dark:border-sky-800 bg-sky-50 dark:bg-sky-900/25 px-3 py-2 text-sm text-sky-900 dark:text-sky-100" role="status">
            <span class="font-medium">{{ __('intake.ocr_enhancement_badge_prefix') }}</span>
            {{ $ocrPresetFeedback }}
        </div>
    @else
        <p class="mb-3 rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-800/40 px-3 py-2 text-xs text-gray-600 dark:text-gray-400" role="status">
            {{ __('intake.ocr_enhancement_generic') }}
        </p>
    @endif

    @if (config('app.debug') && is_array($ocrDriverCapability ?? null) && config('ocr.preprocessing.debug_expose_derived_notice'))
        <div class="mb-3 rounded border border-violet-300 dark:border-violet-700 bg-violet-50 dark:bg-violet-950/30 p-3 text-xs font-mono text-violet-950 dark:text-violet-100" role="region" aria-label="OCR preprocessing capability (local only)">
            <p class="font-bold mb-2 text-sm">OCR image preprocessing — runtime capability (APP_DEBUG)</p>
            <ul class="space-y-1 list-disc list-inside">
                <li>Imagick usable: {{ ! empty($ocrDriverCapability['imagick_available']) ? 'yes' : 'no' }}</li>
                <li>GD usable: {{ ! empty($ocrDriverCapability['gd_available']) ? 'yes' : 'no' }}</li>
                <li>Resolved driver (no source file): {{ $ocrDriverCapability['resolved_driver'] ?? '—' }}</li>
                @if (($ocrDriverCapability['resolved_driver'] ?? '') === 'none')
                    <li>Skip reason: {{ $ocrDriverCapability['skipped_reason_if_none'] ?? '—' }}</li>
                @endif
            </ul>
        </div>
    @endif

    @if (config('app.debug') && is_array($ocrDebugMeta ?? null) && config('ocr.preprocessing.debug_expose_derived_notice'))
        <div class="mb-3 rounded border-2 border-dashed border-emerald-700/80 dark:border-emerald-500/60 bg-emerald-50 dark:bg-emerald-950/35 p-4 text-sm text-emerald-950 dark:text-emerald-100" role="region" aria-label="{{ __('intake.diagnostics_heading') }}">
            @php
                $diag = $ocrDebugMeta['diagnostics_summary'] ?? [];
            @endphp
            <p class="text-xs font-mono text-emerald-800/90 dark:text-emerald-200/90 mb-3">{{ __('intake.ocr_debug_block_title') }}</p>

            @if (! empty($diag) && is_array($diag))
                <h2 class="font-bold text-base mb-3 text-emerald-900 dark:text-emerald-50">{{ __('intake.diagnostics_heading') }}</h2>
                <dl class="grid grid-cols-1 sm:grid-cols-[minmax(8rem,12rem)_1fr] gap-x-4 gap-y-2 not-italic">
                    <dt class="text-emerald-800/90 dark:text-emerald-300/90 font-medium">{{ __('intake.diagnostics_label_parser_mode') }}</dt>
                    <dd>{{ $diag['parser_mode_label'] ?? __('intake.diagnostics_not_available') }}</dd>
                    <dt class="text-emerald-800/90 dark:text-emerald-300/90 font-medium">{{ __('intake.diagnostics_label_autofill_source') }}</dt>
                    <dd>{{ $diag['autofill_source_label'] ?? __('intake.diagnostics_not_available') }}</dd>
                    <dt class="text-emerald-800/90 dark:text-emerald-300/90 font-medium">{{ __('intake.diagnostics_label_ai_provider') }}</dt>
                    <dd>{{ $diag['ai_provider_label'] ?? __('intake.diagnostics_not_used') }}</dd>
                    <dt class="text-emerald-800/90 dark:text-emerald-300/90 font-medium">{{ __('intake.diagnostics_label_transcript_used') }}</dt>
                    <dd>{{ $diag['transcript_used_label'] ?? __('intake.diagnostics_not_available') }}</dd>
                    <dt class="text-emerald-800/90 dark:text-emerald-300/90 font-medium">{{ __('intake.diagnostics_label_fallback_used') }}</dt>
                    <dd>{{ $diag['fallback_used_label'] ?? __('intake.diagnostics_no') }}</dd>
                    <dt class="text-emerald-800/90 dark:text-emerald-300/90 font-medium">{{ __('intake.diagnostics_label_fallback_reason') }}</dt>
                    <dd>{{ $diag['fallback_reason_label'] ?? __('intake.diagnostics_not_available') }}</dd>
                    <dt class="text-emerald-800/90 dark:text-emerald-300/90 font-medium">{{ __('intake.diagnostics_label_recommended_action') }}</dt>
                    <dd class="font-medium">{{ $diag['recommended_action_label'] ?? __('intake.diagnostics_action_review_preview') }}</dd>
                </dl>
                @if (! empty($ocrDebugMeta['diagnostics_technical_note']))
                    <p class="mt-3 text-xs text-emerald-800/80 dark:text-emerald-300/80">{{ $ocrDebugMeta['diagnostics_technical_note'] }}</p>
                @endif
            @endif

            <details class="mt-4 rounded border border-emerald-600/40 dark:border-emerald-500/30 bg-white/60 dark:bg-emerald-950/50 p-3 text-xs font-mono">
                <summary class="cursor-pointer font-semibold text-emerald-900 dark:text-emerald-100 select-none">{{ __('intake.diagnostics_technical_heading') }}</summary>
                <ul class="mt-3 space-y-1 list-disc list-inside text-emerald-950 dark:text-emerald-100">
                    @if (! empty($diag['internal_active_parser_mode'] ?? null))
                        <li>{{ __('intake.diagnostics_internal_active_parser_mode') }}: <code class="break-all">{{ $diag['internal_active_parser_mode'] }}</code></li>
                    @endif
                    @if (! empty($diag['internal_parse_input_source'] ?? null))
                        <li>{{ __('intake.diagnostics_internal_parse_input_source') }}: <code class="break-all">{{ $diag['internal_parse_input_source'] }}</code></li>
                    @endif
                    <li>Active parser mode (raw): {{ $ocrDebugMeta['active_parser_mode'] ?? __('intake.diagnostics_not_available') }}</li>
                    <li>Intake parser_version: {{ $ocrDebugMeta['intake_parser_version'] ?? __('intake.diagnostics_not_available') }}</li>
                    <li>UI preset: {{ $ocrDebugMeta['ui_preprocessing_preset'] ?? __('intake.diagnostics_not_available') }}</li>
                    <li>Resolved preset: {{ $ocrDebugMeta['preset_resolved'] ?? __('intake.diagnostics_not_available') }}</li>
                    <li>Preprocess used: {{ ! empty($ocrDebugMeta['preprocess_used']) ? __('intake.diagnostics_yes') : __('intake.diagnostics_no') }}</li>
                    <li>Fallback (preprocess): {{ ! empty($ocrDebugMeta['fallback_used']) ? __('intake.diagnostics_yes') : __('intake.diagnostics_no') }}</li>
                    <li>Skipped reason: {{ $ocrDebugMeta['skipped_preprocessing_reason'] ?? __('intake.diagnostics_not_available') }}</li>
                    <li>Derived kept on disk: {{ ! empty($ocrDebugMeta['derived_kept_on_disk']) ? __('intake.diagnostics_yes') : __('intake.diagnostics_no') }}</li>
                    <li>Final OCR input path: <span class="break-all">{{ $ocrDebugMeta['final_ocr_input_path'] ?? __('intake.diagnostics_not_available') }}</span></li>
                    <li>Driver: {{ $ocrDebugMeta['driver'] ?? __('intake.diagnostics_not_available') }}</li>
                    <li>Applied steps: {{ implode(', ', $ocrDebugMeta['applied_steps'] ?? []) }}</li>
                    <li>{{ __('intake.ocr_debug_effective_source') }}: {{ $ocrDebugMeta['ocr_source_type_effective'] ?? __('intake.diagnostics_not_available') }}</li>
                    @if (! empty($ocrDebugMeta['parse_input_source']))
                        <li>Parse input source (raw): {{ $ocrDebugMeta['parse_input_source'] }}</li>
                        @if (! empty($ocrDebugMeta['parse_input_canonical_transcript_source']))
                            <li>Canonical transcript source (raw): {{ $ocrDebugMeta['parse_input_canonical_transcript_source'] }}</li>
                        @endif
                        @if (! empty($ocrDebugMeta['parse_input_fallback_reason']))
                            <li>Explicit fallback reason (code): {{ $ocrDebugMeta['parse_input_fallback_reason'] }}</li>
                        @endif
                        @if (array_key_exists('parse_input_ai_extraction_skipped', $ocrDebugMeta))
                            <li>AI extraction skipped (re-parse): {{ ! empty($ocrDebugMeta['parse_input_ai_extraction_skipped']) ? __('intake.diagnostics_yes') : __('intake.diagnostics_no') }}</li>
                        @endif
                        <li>AI extract ok: {{ ! empty($ocrDebugMeta['parse_input_ok']) ? __('intake.diagnostics_yes') : __('intake.diagnostics_no') }}</li>
                        <li>AI extraction provider (raw): {{ $ocrDebugMeta['parse_input_provider'] ?? __('intake.diagnostics_not_used') }} @if (! empty($ocrDebugMeta['parse_input_provider_source'])) (source: {{ $ocrDebugMeta['parse_input_provider_source'] }}) @endif</li>
                        <li>Extraction model: {{ $ocrDebugMeta['parse_input_model'] ?? __('intake.diagnostics_not_available') }}</li>
                        <li>AI source field: {{ $ocrDebugMeta['parse_input_source_field'] ?? __('intake.diagnostics_not_available') }}</li>
                        <li>AI source path: <span class="break-all">{{ $ocrDebugMeta['parse_input_relative_path'] ?? __('intake.diagnostics_not_available') }}</span></li>
                        <li>AI text quality ok: {{ ! empty($ocrDebugMeta['parse_input_text_quality_ok']) ? __('intake.diagnostics_yes') : __('intake.diagnostics_no') }}</li>
                        <li>AI text chars/lines: {{ $ocrDebugMeta['parse_input_text_chars'] ?? __('intake.diagnostics_not_available') }} / {{ $ocrDebugMeta['parse_input_text_lines'] ?? __('intake.diagnostics_not_available') }}</li>
                        <li>AI text alpha ratio: {{ $ocrDebugMeta['parse_input_text_alpha_ratio'] ?? __('intake.diagnostics_not_available') }}</li>
                        @if (array_key_exists('parse_input_vision_detail', $ocrDebugMeta))
                            <li>Vision detail: {{ $ocrDebugMeta['parse_input_vision_detail'] }}</li>
                        @endif
                        @if (! empty($ocrDebugMeta['parse_input_original_image_width']) || ! empty($ocrDebugMeta['parse_input_original_image_height']))
                            <li>Source image WxH: {{ $ocrDebugMeta['parse_input_original_image_width'] ?? __('intake.diagnostics_not_available') }}×{{ $ocrDebugMeta['parse_input_original_image_height'] ?? __('intake.diagnostics_not_available') }}</li>
                        @endif
                        @if (! empty($ocrDebugMeta['parse_input_ai_request_image_width']) || ! empty($ocrDebugMeta['parse_input_ai_request_image_height']))
                            <li>AI request payload WxH: {{ $ocrDebugMeta['parse_input_ai_request_image_width'] ?? __('intake.diagnostics_not_available') }}×{{ $ocrDebugMeta['parse_input_ai_request_image_height'] ?? __('intake.diagnostics_not_available') }}</li>
                        @endif
                        @if (array_key_exists('parse_input_ai_request_payload_enhanced', $ocrDebugMeta))
                            <li>AI request payload enhanced: {{ ! empty($ocrDebugMeta['parse_input_ai_request_payload_enhanced']) || ! empty($ocrDebugMeta['parse_input_ai_request_orientation_corrected']) ? __('intake.diagnostics_yes') : __('intake.diagnostics_no') }}</li>
                        @endif
                        @if (! empty($ocrDebugMeta['parse_input_extracted_text_line_count']))
                            <li>Extracted line count (post-sanitize): {{ $ocrDebugMeta['parse_input_extracted_text_line_count'] }}</li>
                        @endif
                        @if (! empty($ocrDebugMeta['parse_input_text_quality_reason']))
                            <li>AI text quality reason: {{ $ocrDebugMeta['parse_input_text_quality_reason'] }}</li>
                        @endif
                        @if (! empty($ocrDebugMeta['parse_input_sarvam_job_id']))
                            <li>Sarvam job: {{ $ocrDebugMeta['parse_input_sarvam_job_id'] }} ({{ $ocrDebugMeta['parse_input_sarvam_job_state'] ?? __('intake.diagnostics_not_available') }})</li>
                        @endif
                        @if (! empty($ocrDebugMeta['parse_input_reason']))
                            <li>AI extract reason (code): {{ $ocrDebugMeta['parse_input_reason'] }}</li>
                        @endif
                        @if (! empty($ocrDebugMeta['parse_input_failure_detail']))
                            <li>AI transcription failure (detail): {{ $ocrDebugMeta['parse_input_failure_detail'] }}</li>
                        @endif
                        @if (! empty($ocrDebugMeta['parse_input_quality_failure_detail']))
                            <li>AI text quality gate: {{ $ocrDebugMeta['parse_input_quality_failure_detail'] }}</li>
                        @endif
                        @if (! empty($ocrDebugMeta['parse_input_response_body_snippet']))
                            <li>Provider HTTP body (snippet): {{ $ocrDebugMeta['parse_input_response_body_snippet'] }}</li>
                        @endif
                    @endif
                    <li>{{ __('intake.ocr_debug_parse_uses_manual') }}: {{ ! empty($ocrDebugMeta['parse_uses_manual_prepared']) ? __('intake.diagnostics_yes') : __('intake.diagnostics_no') }}</li>
                    @if (! empty($ocrDebugMeta['manual_prepared_storage_relative']))
                        <li>{{ __('intake.ocr_debug_manual_path') }}: {{ $ocrDebugMeta['manual_prepared_storage_relative'] }}</li>
                        <li><a href="{{ route('intake.manual-prepared-image', $intake) }}" class="underline text-emerald-900 dark:text-emerald-200" target="_blank" rel="noopener">{{ __('intake.ocr_debug_link_manual') }}</a></li>
                    @endif
                    @if (! empty($ocrDebugMeta['ocr_pipeline']))
                        <li>OCR pipeline (last extract): {{ $ocrDebugMeta['ocr_pipeline'] }}</li>
                    @endif
                    <li>Original WxH / size: {{ $ocrDebugMeta['original_width'] ?? '?' }}×{{ $ocrDebugMeta['original_height'] ?? '?' }} — {{ $ocrDebugMeta['original_filesize'] ?? '?' }} bytes</li>
                    <li>Derived WxH / size: {{ $ocrDebugMeta['derived_width'] ?? __('intake.diagnostics_not_available') }}×{{ $ocrDebugMeta['derived_height'] ?? __('intake.diagnostics_not_available') }} — {{ $ocrDebugMeta['derived_filesize'] ?? __('intake.diagnostics_not_available') }} bytes</li>
                    @if (! empty($ocrDebugMeta['ocr_quality']) && is_array($ocrDebugMeta['ocr_quality']))
                        <li>OCR quality (parse input): score {{ $ocrDebugMeta['ocr_quality']['score'] ?? __('intake.diagnostics_not_available') }}, low={{ ! empty($ocrDebugMeta['ocr_quality']['is_low']) ? __('intake.diagnostics_yes') : __('intake.diagnostics_no') }}, reasons: {{ implode(', ', $ocrDebugMeta['ocr_quality']['reasons'] ?? []) }}</li>
                    @endif
                </ul>
                <p class="mt-2 break-all text-emerald-950 dark:text-emerald-100">Original path: {{ $ocrDebugMeta['original_absolute_path'] ?? __('intake.diagnostics_not_available') }}</p>
                <p class="mt-1 break-all text-emerald-950 dark:text-emerald-100">Derived path: {{ $ocrDebugMeta['derived_absolute_path'] ?? __('intake.diagnostics_not_available') }}</p>
                @if (($ocrDebugMeta['kind'] ?? '') === 'image' && $intake->file_path)
                    <p class="mt-2 flex flex-wrap gap-3">
                        <a href="{{ route('intake.debug.ocr-artifact', ['intake' => $intake, 'which' => 'original']) }}" class="underline text-emerald-900 dark:text-emerald-200" target="_blank" rel="noopener">{{ __('intake.ocr_debug_link_original') }}</a>
                        @if (! empty($ocrDebugMeta['derived_absolute_path']) && is_file($ocrDebugMeta['derived_absolute_path']))
                            <a href="{{ route('intake.debug.ocr-artifact', ['intake' => $intake, 'which' => 'derived']) }}" class="underline text-emerald-900 dark:text-emerald-200" target="_blank" rel="noopener">{{ __('intake.ocr_debug_link_derived') }}</a>
                        @endif
                    </p>
                @endif
            </details>
        </div>
    @endif

    {{--
        Future manual OCR assist: any edit pipeline must write a NEW derived artifact only; never overwrite the stored
        original upload (SSOT: biodata_intakes.file_path blob stays immutable after create).
    --}}
    @php
        $ocrCompareAvailable = config('app.debug')
            && is_array($ocrDebugMeta ?? null)
            && ($ocrDebugMeta['kind'] ?? '') === 'image'
            && ! empty($intake->file_path)
            && is_file(storage_path('app/private/'.$intake->file_path))
            && ! empty($ocrDebugMeta['derived_absolute_path'])
            && is_file($ocrDebugMeta['derived_absolute_path']);
        $ocrOriginalUrl = $ocrCompareAvailable
            ? route('intake.debug.ocr-artifact', ['intake' => $intake, 'which' => 'original'])
            : null;
        $ocrEnhancedUrl = $ocrCompareAvailable
            ? route('intake.debug.ocr-artifact', ['intake' => $intake, 'which' => 'derived'])
            : null;
    @endphp
    @if ($ocrCompareAvailable && $ocrOriginalUrl && $ocrEnhancedUrl)
        <section
            class="mb-4 rounded-xl border-2 border-violet-300 dark:border-violet-700 bg-violet-50/90 dark:bg-violet-950/40 p-4 sm:p-5"
            aria-label="{{ __('intake.ocr_compare_heading') }}"
            x-data="{ view: 'side', blend: 55 }"
        >
            <h2 class="text-lg font-bold text-violet-900 dark:text-violet-100 mb-1">{{ __('intake.ocr_compare_heading') }}</h2>
            <p class="text-xs text-violet-800/80 dark:text-violet-200/80 mb-3">APP_DEBUG — {{ __('intake.ocr_debug_block_title') }}</p>

            <div class="flex flex-wrap items-center gap-2 mb-4">
                <span class="text-xs font-medium text-violet-900 dark:text-violet-100 mr-1">{{ __('intake.ocr_compare_side_by_side') }} / {{ __('intake.ocr_compare_overlay') }}:</span>
                <button
                    type="button"
                    @click="view = 'side'"
                    class="px-3 py-1.5 text-xs font-medium rounded-lg border transition"
                    :class="view === 'side' ? 'bg-violet-600 text-white border-violet-600' : 'bg-white dark:bg-gray-800 text-violet-800 dark:text-violet-200 border-violet-300 dark:border-violet-600'"
                >{{ __('intake.ocr_compare_side_by_side') }}</button>
                <button
                    type="button"
                    @click="view = 'overlay'"
                    class="px-3 py-1.5 text-xs font-medium rounded-lg border transition"
                    :class="view === 'overlay' ? 'bg-violet-600 text-white border-violet-600' : 'bg-white dark:bg-gray-800 text-violet-800 dark:text-violet-200 border-violet-300 dark:border-violet-600'"
                >{{ __('intake.ocr_compare_overlay') }}</button>
                <span x-show="view === 'overlay'" x-cloak class="flex items-center gap-2 text-xs text-violet-900 dark:text-violet-200 ml-2">
                    <label for="ocr-compare-blend" class="whitespace-nowrap">{{ __('intake.ocr_compare_blend_label') }}</label>
                    <input id="ocr-compare-blend" type="range" min="0" max="100" x-model="blend" class="w-28 sm:w-36 accent-violet-600">
                    <span class="tabular-nums w-8" x-text="Math.round(blend) + '%'"></span>
                </span>
            </div>

            {{-- Side-by-side: stacked on small screens, two columns on md+ --}}
            <div x-show="view === 'side'" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="rounded-lg border border-violet-200 dark:border-violet-800 bg-white dark:bg-gray-900 p-2 overflow-hidden">
                    <p class="text-xs font-semibold text-center text-violet-800 dark:text-violet-200 mb-2">{{ __('intake.ocr_compare_original_label') }}</p>
                    <div class="flex justify-center items-center bg-gray-100 dark:bg-gray-950 rounded min-h-[160px] max-h-[55vh] overflow-auto">
                        <img src="{{ $ocrOriginalUrl }}" alt="{{ __('intake.ocr_compare_original_label') }}" class="max-w-full max-h-[55vh] w-auto h-auto object-contain">
                    </div>
                </div>
                <div class="rounded-lg border border-violet-200 dark:border-violet-800 bg-white dark:bg-gray-900 p-2 overflow-hidden">
                    <p class="text-xs font-semibold text-center text-violet-800 dark:text-violet-200 mb-2">{{ __('intake.ocr_compare_enhanced_label') }}</p>
                    <div class="flex justify-center items-center bg-gray-100 dark:bg-gray-950 rounded min-h-[160px] max-h-[55vh] overflow-auto">
                        <img src="{{ $ocrEnhancedUrl }}" alt="{{ __('intake.ocr_compare_enhanced_label') }}" class="max-w-full max-h-[55vh] w-auto h-auto object-contain">
                    </div>
                </div>
            </div>

            {{-- Overlay: same max height, enhanced on top with adjustable opacity --}}
            <div x-show="view === 'overlay'" x-cloak class="rounded-lg border border-violet-200 dark:border-violet-800 bg-gray-100 dark:bg-gray-950 p-2">
                <div class="relative flex justify-center items-center mx-auto max-h-[55vh] min-h-[200px] w-full overflow-hidden">
                    <img src="{{ $ocrOriginalUrl }}" alt="" class="relative z-0 max-w-full max-h-[55vh] object-contain select-none" draggable="false">
                    <img
                        src="{{ $ocrEnhancedUrl }}"
                        alt=""
                        class="absolute z-10 left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 max-w-full max-h-[55vh] object-contain pointer-events-none select-none"
                        draggable="false"
                        :style="'opacity: ' + (blend / 100)"
                    >
                </div>
                <p class="text-center text-[10px] text-violet-700 dark:text-violet-300 mt-1">{{ __('intake.ocr_compare_original_label') }} (खाली) + {{ __('intake.ocr_compare_enhanced_label') }} (वर, पारदर्शकता समायोजित)</p>
            </div>

            <dl class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1 text-xs text-violet-900 dark:text-violet-100">
                <div><dt class="inline font-semibold">{{ __('intake.ocr_compare_selected_preset') }}:</dt> <dd class="inline">{{ $ocrDebugMeta['ui_preprocessing_preset'] ?? '—' }}</dd></div>
                <div><dt class="inline font-semibold">{{ __('intake.ocr_compare_resolved_preset') }}:</dt> <dd class="inline">{{ $ocrDebugMeta['preset_resolved'] ?? '—' }}</dd></div>
                <div><dt class="inline font-semibold">{{ __('intake.ocr_compare_preprocess_used') }}:</dt> <dd class="inline">{{ ! empty($ocrDebugMeta['preprocess_used']) ? 'yes' : 'no' }}</dd></div>
                <div><dt class="inline font-semibold">{{ __('intake.ocr_compare_fallback_used') }}:</dt> <dd class="inline">{{ ! empty($ocrDebugMeta['fallback_used']) ? 'yes' : 'no' }}</dd></div>
            </dl>

            <p class="mt-3 text-xs text-violet-800 dark:text-violet-200 border-t border-violet-200 dark:border-violet-700 pt-3">
                {{ __('intake.ocr_compare_meta', [
                    'ow' => $ocrDebugMeta['original_width'] ?? '—',
                    'oh' => $ocrDebugMeta['original_height'] ?? '—',
                    'osize' => $ocrDebugMeta['original_filesize'] ?? '—',
                    'ew' => $ocrDebugMeta['derived_width'] ?? '—',
                    'eh' => $ocrDebugMeta['derived_height'] ?? '—',
                    'esize' => $ocrDebugMeta['derived_filesize'] ?? '—',
                ]) }}
            </p>

            <p class="mt-2 text-xs font-medium text-violet-900 dark:text-violet-100">{{ __('intake.ocr_compare_original_unchanged') }}</p>

            <div class="mt-3 space-y-1.5 text-xs text-violet-900/95 dark:text-violet-100/95 leading-relaxed border-l-2 border-violet-400 pl-3">
                <p>{{ __('intake.ocr_compare_hint_better') }}</p>
                <p>{{ __('intake.ocr_compare_hint_no_diff') }}</p>
            </div>

        </section>
    @endif

    @if (! empty($manualCropEligible) && ! empty($manualCropOriginalUrl))
        <section id="intake-manual-crop-section" class="mb-6 rounded-xl border border-emerald-300 dark:border-emerald-700 bg-emerald-50/90 dark:bg-emerald-950/30 p-4 sm:p-5" aria-labelledby="intake-manual-crop-heading">
            <h2 id="intake-manual-crop-heading" class="text-lg font-bold text-emerald-900 dark:text-emerald-100 mb-1">{{ __('intake.manual_crop_heading') }}</h2>
            <p class="text-sm text-emerald-900/90 dark:text-emerald-100/90 mb-2">{{ __('intake.manual_crop_intro') }}</p>
            <p class="text-xs font-medium text-emerald-800 dark:text-emerald-200 mb-2">
                {{ __('intake.ocr_parse_source_label') }}:
                <span class="font-semibold">{{ ! empty($manualPreparedExists) ? __('intake.ocr_source_manual_prepared') : __('intake.ocr_source_original_upload') }}</span>
                @if (config('app.debug') && is_array($ocrQualityEvaluation ?? null))
                    <span class="ml-2 text-violet-800 dark:text-violet-200">({{ __('intake.ocr_quality_score_debug', ['score' => $ocrQualityEvaluation['score'] ?? '—']) }})</span>
                @endif
            </p>
            @if (! empty($showOcrLowQualityWarning))
                <div class="mb-3 rounded-lg border border-amber-500 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-400 dark:bg-amber-950/40 dark:text-amber-100" role="status">
                    {{ __('intake.ocr_low_quality_manual_crop_hint') }}
                </div>
            @endif
            @if (! empty($manualPreparedExists))
                <p class="text-xs font-medium text-emerald-800 dark:text-emerald-200 mb-2">{{ __('intake.manual_crop_parse_note') }}</p>
            @endif
            <div class="rounded-lg border border-emerald-200 dark:border-emerald-800 bg-black/[0.03] dark:bg-white/[0.04] p-2 max-h-[70vh] overflow-auto">
                <div id="intake-manual-crop-stage" class="relative inline-block">
                    <img
                        id="intake-manual-crop-img"
                        src="{{ $manualCropOriginalUrl }}"
                        alt=""
                        class="relative z-0 block max-w-full select-none"
                        draggable="false"
                        data-save-url="{{ route('intake.manual-crop-save', $intake) }}"
                        data-msg-corners="{{ e(\App\Support\ErrorFactory::intakeManualCropCornersTooSmall()->message) }}"
                        data-msg-no-redirect="{{ e(\App\Support\ErrorFactory::intakeManualCropNoRedirect()->message) }}"
                        data-msg-save-failed="{{ e(\App\Support\ErrorFactory::intakeManualCropWarpSaveFailed()->message) }}"
                    >

                    {{-- 4-corner selection overlay (free crop/perspective) --}}
                    <svg
                        class="pointer-events-none absolute inset-0 z-[5] h-full w-full overflow-visible"
                        aria-hidden="true"
                    >
                        <polygon
                            id="intake-manual-crop-polygon"
                            points="0,0 100,0 100,100 0,100"
                            fill="rgba(16, 185, 129, 0.12)"
                            stroke="rgb(5, 150, 105)"
                            stroke-width="3"
                            vector-effect="non-scaling-stroke"
                        />
                    </svg>

                    <div
                        data-intake-corner="tl"
                        class="absolute z-20 flex h-11 w-11 cursor-grab items-center justify-center touch-manipulation"
                        style="left: 6%; top: 6%; transform: translate(-50%, -50%);"
                        aria-hidden="true"
                    ><span class="h-4 w-4 rounded-full border-2 border-white bg-emerald-600 shadow pointer-events-none"></span></div>
                    <div
                        data-intake-corner="tr"
                        class="absolute z-20 flex h-11 w-11 cursor-grab items-center justify-center touch-manipulation"
                        style="left: 94%; top: 6%; transform: translate(-50%, -50%);"
                        aria-hidden="true"
                    ><span class="h-4 w-4 rounded-full border-2 border-white bg-emerald-600 shadow pointer-events-none"></span></div>
                    <div
                        data-intake-corner="br"
                        class="absolute z-20 flex h-11 w-11 cursor-grab items-center justify-center touch-manipulation"
                        style="left: 94%; top: 94%; transform: translate(-50%, -50%);"
                        aria-hidden="true"
                    ><span class="h-4 w-4 rounded-full border-2 border-white bg-emerald-600 shadow pointer-events-none"></span></div>
                    <div
                        data-intake-corner="bl"
                        class="absolute z-20 flex h-11 w-11 cursor-grab items-center justify-center touch-manipulation"
                        style="left: 6%; top: 94%; transform: translate(-50%, -50%);"
                        aria-hidden="true"
                    ><span class="h-4 w-4 rounded-full border-2 border-white bg-emerald-600 shadow pointer-events-none"></span></div>
                    @if (! empty($photoCandidateCropEligible))
                        <div
                            id="intake-photo-candidate-box"
                            class="hidden absolute z-30 border-2 border-sky-500 bg-sky-400/15 cursor-move"
                            style="left: 20%; top: 8%; width: 30%; height: 40%;"
                            data-profile-crop-aspect="0.75"
                        >
                            <span class="absolute -right-2 -bottom-2 h-5 w-5 rounded-full border-2 border-white bg-sky-600 shadow cursor-nwse-resize" data-photo-candidate-resize="br"></span>
                        </div>
                    @endif
                </div>
            </div>
            <div class="mt-3 flex flex-wrap gap-2 items-center">
                <button type="button" id="intake-crop-rotate-left" class="px-3 py-1.5 text-xs font-medium rounded-lg border border-emerald-600 text-emerald-800 dark:text-emerald-100 bg-white dark:bg-gray-900 hover:bg-emerald-100/50 dark:hover:bg-emerald-900/40">{{ __('intake.manual_crop_rotate_left') }}</button>
                <button type="button" id="intake-crop-rotate-right" class="px-3 py-1.5 text-xs font-medium rounded-lg border border-emerald-600 text-emerald-800 dark:text-emerald-100 bg-white dark:bg-gray-900 hover:bg-emerald-100/50 dark:hover:bg-emerald-900/40">{{ __('intake.manual_crop_rotate_right') }}</button>
                <button type="button" id="intake-crop-rotate-fine-ccw" class="px-3 py-1.5 text-xs font-medium rounded-lg border border-emerald-500 text-emerald-800 dark:text-emerald-100 bg-white dark:bg-gray-900 hover:bg-emerald-100/50 dark:hover:bg-emerald-900/40">{{ __('intake.manual_crop_rotate_fine_ccw') }}</button>
                <button type="button" id="intake-crop-rotate-fine-cw" class="px-3 py-1.5 text-xs font-medium rounded-lg border border-emerald-500 text-emerald-800 dark:text-emerald-100 bg-white dark:bg-gray-900 hover:bg-emerald-100/50 dark:hover:bg-emerald-900/40">{{ __('intake.manual_crop_rotate_fine_cw') }}</button>
                <button type="button" id="intake-crop-reset" class="px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-400 text-gray-800 dark:text-gray-100 bg-white dark:bg-gray-900 hover:bg-gray-100 dark:hover:bg-gray-800">{{ __('intake.manual_crop_reset') }}</button>
            </div>
            <div class="mt-4 flex flex-wrap gap-3 items-center">
                <button
                    type="button"
                    id="intake-crop-save"
                    class="px-4 py-2 text-sm font-semibold rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white"
                    data-saving-text="{{ __('intake.manual_crop_saving') }}"
                >{{ __('intake.manual_crop_save') }}</button>
                @if (! empty($manualPreparedExists))
                    <button
                        type="button"
                        id="intake-crop-clear"
                        class="px-4 py-2 text-sm font-medium rounded-lg border border-amber-600 text-amber-800 dark:text-amber-200 bg-white dark:bg-gray-900 hover:bg-amber-50 dark:hover:bg-amber-950/30"
                        data-confirm-message="{{ __('intake.manual_crop_clear_confirm') }}"
                    >{{ __('intake.manual_crop_clear') }}</button>
                    <form id="intake-manual-crop-clear-form" method="POST" action="{{ route('intake.manual-crop-clear', $intake) }}" class="hidden">
                        @csrf
                    </form>
                @endif
            </div>
            @if (! empty($photoCandidateCropEligible))
                <div id="intake-photo-candidate-controls" class="mt-4 rounded-lg border border-sky-200 dark:border-sky-800 bg-sky-50/80 dark:bg-sky-950/30 p-3">
                    <div class="flex flex-wrap gap-2 items-center">
                        <button type="button" id="intake-photo-candidate-mode-ocr" class="px-3 py-1.5 text-xs font-medium rounded-lg border border-emerald-600 text-emerald-800 dark:text-emerald-100 bg-white dark:bg-gray-900 hover:bg-emerald-100/50 dark:hover:bg-emerald-900/40">OCR document crop</button>
                        <button type="button" id="intake-photo-candidate-auto" class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-sky-600 text-sky-800 dark:text-sky-100 bg-white dark:bg-gray-900 hover:bg-sky-100/50 dark:hover:bg-sky-900/40">Auto-detect profile photo</button>
                        <button
                            type="button"
                            id="intake-photo-candidate-save"
                            class="px-4 py-2 text-sm font-semibold rounded-lg bg-sky-600 hover:bg-sky-700 text-white"
                            data-save-url="{{ route('intake.photo-candidate-crop-save', $intake) }}"
                            data-saving-text="Saving candidate..."
                            data-msg-crop-too-small="Select a larger candidate photo area."
                            data-msg-save-failed="Candidate photo crop save failed."
                        >Save profile photo crop</button>
                        @if (! empty($photoCandidateExists))
                            <button
                                type="button"
                                id="intake-photo-candidate-clear"
                                class="px-4 py-2 text-sm font-medium rounded-lg border border-amber-600 text-amber-800 dark:text-amber-200 bg-white dark:bg-gray-900 hover:bg-amber-50 dark:hover:bg-amber-950/30"
                                data-confirm-message="Clear saved candidate crop?"
                            >Clear profile photo crop</button>
                            <form id="intake-photo-candidate-clear-form" method="POST" action="{{ route('intake.photo-candidate-crop-clear', $intake) }}" class="hidden">
                                @csrf
                            </form>
                        @endif
                    </div>
                    <p id="intake-photo-candidate-message" class="mt-2 text-xs font-medium text-sky-900 dark:text-sky-100">
                        @if (! empty($photoCandidateAutoSaved))
                            Auto-cropped from biodata image. Adjust and save again if needed.
                        @elseif (is_array($photoCandidateSuggestion ?? null) && ! empty($photoCandidateSuggestion['available']))
                            Detected candidate photo area. Adjust if needed, then save.
                        @else
                            Could not auto-detect profile photo. Please adjust crop manually.
                        @endif
                    </p>
                    @if (! empty($photoCandidateExists))
                        <p class="mt-1 text-xs font-medium text-sky-800 dark:text-sky-200">Candidate crop exists. It is preview-only and not approved as a profile photo.</p>
                    @endif
                </div>
                <script type="application/json" id="intake-photo-candidate-suggestion">{!! json_encode(array_merge(is_array($photoCandidateSuggestion ?? null) ? $photoCandidateSuggestion : [], ['auto_saved' => ! empty($photoCandidateAutoSaved)]), JSON_UNESCAPED_UNICODE) !!}</script>
            @endif
        </section>
        @if (! empty($autoCropSuggestion) && is_array($autoCropSuggestion))
            <script type="application/json" id="intake-auto-crop-suggestion">{!! json_encode($autoCropSuggestion, JSON_UNESCAPED_UNICODE) !!}</script>
        @endif
        @if (! empty($showOcrLowQualityWarning))
            <script>
                document.getElementById('intake-manual-crop-section')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            </script>
        @endif
        @vite(['resources/js/intake-preview-crop.js'])
    @endif

    <p class="text-gray-600 dark:text-gray-400 text-sm mb-2">{{ __('intake.preview_review_intro') }}</p>
    <p class="text-gray-500 dark:text-gray-500 text-xs mb-4">{{ __('intake.preview_two_column_intro') }}</p>

    <div class="mb-6 flex flex-wrap items-center gap-3">
        <form method="POST" action="{{ route('intake.reparse', $intake) }}" class="inline" onsubmit="return confirm(@json(__('intake.reparse_confirm')));">
            @csrf
            <button type="submit" class="px-3 py-1.5 text-sm border border-amber-500 text-amber-700 dark:text-amber-400 dark:border-amber-400 rounded hover:bg-amber-50 dark:hover:bg-amber-900/20" title="{{ __('intake.reparse_button_help') }}">
                {{ __('intake.reparse_button') }}
            </button>
        </form>
        @if (! empty($showIntakeReextractAction))
            <form method="POST" action="{{ route('intake.re-extract', $intake) }}" class="inline" onsubmit="return confirm(@json(__('intake.reextract_confirm')));">
                @csrf
                <button type="submit" class="px-3 py-1.5 text-sm border border-violet-500 text-violet-700 dark:text-violet-300 dark:border-violet-400 rounded hover:bg-violet-50 dark:hover:bg-violet-900/20" title="{{ __('intake.reextract_button_help') }}">
                    {{ __('intake.reextract_button') }}
                </button>
            </form>
        @endif
        <span class="text-xs text-gray-500 dark:text-gray-400">— {{ __('intake.reparse_hint') }}</span>
    </div>

    <form id="intake-preview-form" method="POST" action="{{ route('intake.approve', $intake) }}" class="space-y-8">
        @csrf

        @php
            $sectionSourceKeys = $sectionSourceKeys ?? [];
            $coreData = $sections['core']['data'] ?? $data['core'] ?? [];
            $parsedJsonForDisplay = isset($data) && is_array($data) ? json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
            $parsedJsonDisplaySections = is_array($parsedJsonDisplaySections ?? null) ? $parsedJsonDisplaySections : [];
        @endphp

        {{-- One row: Left = Raw biodata text, Right = Parsed JSON — both with scroll (slider) for small windows --}}
        <style>
            .intake-preview-scroll-panel {
                max-height: min(50vh, 20rem);
                min-height: 10rem;
                overflow-y: auto;
                overflow-x: auto;
                scrollbar-gutter: stable;
                -webkit-overflow-scrolling: touch;
            }
            .intake-preview-scroll-panel::-webkit-scrollbar { width: 10px; height: 10px; }
            .intake-preview-scroll-panel::-webkit-scrollbar-track { background: rgb(243 244 246); border-radius: 4px; }
            .dark .intake-preview-scroll-panel::-webkit-scrollbar-track { background: rgb(31 41 55); }
            .intake-preview-scroll-panel::-webkit-scrollbar-thumb { background: rgb(156 163 175); border-radius: 4px; }
            .intake-preview-scroll-panel::-webkit-scrollbar-thumb:hover { background: rgb(107 114 128); }
            .dark .intake-preview-scroll-panel::-webkit-scrollbar-thumb { background: rgb(75 85 99); }
            .dark .intake-preview-scroll-panel::-webkit-scrollbar-thumb:hover { background: rgb(107 114 128); }
        </style>
        <section class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            {{-- Left: Parse input text (same string the parser used for parsed_json) --}}
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 lg:p-5 flex flex-col min-w-0">
                <h2 class="text-base font-semibold mb-2 border-b border-gray-200 dark:border-gray-600 pb-2 shrink-0">{{ __('intake.parse_input_text_heading') }}</h2>
                @if(!empty($missingCriticalFields))
                    <div class="mb-2 text-xs text-red-700 dark:text-red-400 shrink-0">
                        <p class="font-semibold mb-1">⚠️ खालील महत्वाच्या फील्डमध्ये मूल्य भरलेले नाही:</p>
                        <ul class="list-disc list-inside space-y-0.5">
                            @foreach($missingCriticalFields as $fieldKey)
                                @php
                                    $normalizedKey = \Illuminate\Support\Str::startsWith($fieldKey, 'profile.') ? \Illuminate\Support\Str::after($fieldKey, 'profile.') : $fieldKey;
                                    $label = __('profile.' . $normalizedKey);
                                    if ($label === 'profile.' . $normalizedKey) { $label = $fieldKey; }
                                @endphp
                                <li>{{ $label }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if (!empty($previewParseProvenance['heading_key'] ?? null))
                    <p class="text-xs text-sky-900 dark:text-sky-100 mb-2 shrink-0 rounded border border-sky-200 dark:border-sky-800 bg-sky-50 dark:bg-sky-950/35 px-2 py-1.5 leading-snug font-medium">{{ __($previewParseProvenance['heading_key'], $previewParseProvenance['params'] ?? []) }}</p>
                @endif
                @if (($previewRawTextSource ?? '') === 'ai_vision_unavailable')
                    <p class="text-xs text-amber-800 dark:text-amber-200 mb-2 shrink-0 rounded border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 px-2 py-1.5 whitespace-pre-wrap">{{ $rawOcrTextForPreview ?? '' }}</p>
                @elseif (($previewRawTextSource ?? '') !== 'empty')
                    <p class="text-xs text-gray-600 dark:text-gray-400 mb-2 shrink-0">{{ __('intake.parse_input_text_help') }}</p>
                @endif
                @if (($previewRawTextSource ?? '') === 'ocr_transient')
                    <p class="text-xs text-gray-500 dark:text-gray-500 mb-2 shrink-0">{{ __('intake.preview_parse_input_ocr_transient_note') }}</p>
                @endif
                @if (! empty($manualPreparedExists))
                    <p class="text-xs text-amber-700 dark:text-amber-300 mb-2 shrink-0 font-medium">{{ __('intake.manual_crop_parse_note') }}</p>
                @endif
                <div class="intake-preview-scroll-panel rounded border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-900/40 p-3 text-xs text-gray-800 dark:text-gray-100 whitespace-pre-wrap leading-relaxed font-mono">
                    {{ $rawOcrTextForPreview ?? '' }}
                </div>
                @if (config('app.debug') && config('intake.debug_show_stored_raw_ocr'))
                    <details class="mt-3 rounded border border-dashed border-amber-400 dark:border-amber-700 bg-amber-50/80 dark:bg-amber-950/30 p-2 text-xs">
                        <summary class="cursor-pointer font-medium text-amber-900 dark:text-amber-200">{{ __('intake.debug_stored_raw_ocr_heading') }}</summary>
                        <pre class="mt-2 whitespace-pre-wrap break-words text-gray-800 dark:text-gray-200 max-h-48 overflow-auto">{{ $intake->raw_ocr_text ?? '' }}</pre>
                    </details>
                @endif
            </div>
            {{-- Right: Parsed JSON --}}
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 lg:p-5 flex flex-col min-w-0">
                <h2 class="text-base font-semibold mb-2 border-b border-gray-200 dark:border-gray-600 pb-2 shrink-0">Parsed JSON</h2>
                <p class="text-xs text-gray-600 dark:text-gray-400 mb-2 shrink-0">बायोडाटा मधून काढलेला स्ट्रक्चर्ड डेटा. खालील blocks section-wise ठेवले आहेत, म्हणजे प्रत्येक भाग वेगळा तपासता येईल.</p>
                <div class="intake-preview-scroll-panel rounded border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-900/40 p-3 text-xs text-gray-800 dark:text-gray-100 leading-relaxed font-mono">
                    @if(!empty($parsedJsonDisplaySections))
                        <div class="space-y-3">
                            @foreach($parsedJsonDisplaySections as $jsonSection)
                                <details class="rounded border border-gray-200 dark:border-gray-700 bg-white/80 dark:bg-gray-950/40" @if(empty($jsonSection['is_empty'])) open @endif>
                                    <summary class="cursor-pointer select-none px-3 py-2 font-semibold text-gray-900 dark:text-gray-100">
                                        {{ $jsonSection['label'] ?? 'Parsed JSON section' }}
                                        @if(!empty($jsonSection['is_empty']))
                                            <span class="ml-2 text-[11px] font-normal text-gray-500 dark:text-gray-400">(No extracted values)</span>
                                        @endif
                                    </summary>
                                    <div class="border-t border-gray-200 dark:border-gray-700 px-3 py-2">
                                        <pre class="m-0 whitespace-pre-wrap break-words">{{ $jsonSection['json'] ?? '{}' }}</pre>
                                    </div>
                                </details>
                            @endforeach
                            <details class="rounded border border-dashed border-gray-300 dark:border-gray-600 bg-gray-100/80 dark:bg-gray-950/50">
                                <summary class="cursor-pointer select-none px-3 py-2 font-semibold text-gray-900 dark:text-gray-100">Full raw Parsed JSON</summary>
                                <div class="border-t border-gray-200 dark:border-gray-700 px-3 py-2">
                                    @if($parsedJsonForDisplay !== '')
                                        <pre class="m-0 whitespace-pre-wrap break-words">{{ $parsedJsonForDisplay }}</pre>
                                    @else
                                        <span class="text-amber-600 dark:text-amber-400">Parsed JSON उपलब्ध नाही.</span>
                                    @endif
                                </div>
                            </details>
                        </div>
                    @else
                        <span class="text-amber-600 dark:text-amber-400">Parsed JSON उपलब्ध नाही.</span>
                    @endif
                </div>
            </div>
        </section>

        @include('intake.partials.normalized-draft-preview', [
            'normalizedDraftPreview' => $normalizedDraftPreview ?? null,
            'intakePhotoPreview' => $intakePhotoPreview ?? null,
        ])

        {{-- Fields with OCR "not found" show empty and get .ocr-field-missing (no placeholder text); server still expects placeholder value on submit when empty. --}}
        <style>
            .ocr-field-missing {
                border-color: rgb(245 158 11) !important;
                background-color: rgb(254 243 199) !important;
            }
            .dark .ocr-field-missing {
                border-color: rgb(217 119 6) !important;
                background-color: rgb(69 26 3) !important;
            }
            .ocr-field-missing-wrap .religion-input.ocr-field-missing,
            .ocr-field-missing-wrap .caste-input.ocr-field-missing,
            .ocr-field-missing-wrap .subcaste-input.ocr-field-missing {
                border-color: rgb(245 158 11) !important;
                background-color: rgb(254 243 199) !important;
            }
            .dark .ocr-field-missing-wrap .religion-input.ocr-field-missing,
            .dark .ocr-field-missing-wrap .caste-input.ocr-field-missing,
            .dark .ocr-field-missing-wrap .subcaste-input.ocr-field-missing {
                border-color: rgb(217 119 6) !important;
                background-color: rgb(69 26 3) !important;
            }
        </style>

        {{-- Form: edit parsed data and submit --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 space-y-8">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-600 pb-2">तपासा आणि सुधारा — फॉर्म</h2>
            @php
                $editableFormSections = $editableFormSections ?? [];
                $sectionPrefixes = [
                    'basic-info' => $corePrefix,
                    'physical' => $corePrefix,
                    'education-career' => $corePrefix,
                    'family-details' => $corePrefix,
                    'siblings' => $siblingsPrefix,
                    'relatives' => $relativesPaternalPrefix,
                    'alliance' => $relativesMaternalPrefix,
                    'property' => $propertyPrefix,
                    'horoscope' => $horoscopePrefix,
                    'about-me' => $narrativePrefix,
                ];
            @endphp
            @foreach ($editableFormSections as $section)
                @php
                    $partial = $section['partial'] ?? null;
                    $sectionKey = $section['key'] ?? null;
                    $includeData = [];
                    if (is_string($sectionKey) && array_key_exists($sectionKey, $sectionPrefixes)) {
                        $includeData['namePrefix'] = $sectionPrefixes[$sectionKey];
                    }
                @endphp
                @if (is_string($partial) && $partial !== '')
                    @include($partial, $includeData)
                @endif
            @endforeach
        </section>

        {{-- Scroll anchor at bottom --}}
        <div id="scroll-bottom-anchor" class="h-2"></div>

        {{-- Confirmation and Submit --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 sticky bottom-0 border-t">
            <label class="flex items-center gap-3 cursor-pointer mb-4">
                <input type="checkbox" id="confirm_verified" name="confirm_verified" value="1" class="rounded border-gray-300">
                <span class="font-medium text-gray-800 dark:text-gray-200">मी सर्व माहिती तपासली आहे</span>
            </label>
            <button type="submit" id="approve_btn" disabled class="px-6 py-3 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-md font-medium">
                {{ __('intake.approve_apply_button') }}
            </button>
            <p id="gating-message" class="mt-2 text-sm text-amber-600 dark:text-amber-400 hidden">खाली स्क्रोल करा, बॉक्स चेक करा आणि सर्व "सुधारणा आवश्यक" फील्ड भरा.</p>
        </section>
    </form>
</div>

<script>
(function() {
    const form = document.getElementById('intake-preview-form');
    const approveBtn = document.getElementById('approve_btn');
    const confirmCheck = document.getElementById('confirm_verified');
    const gatingMessage = document.getElementById('gating-message');
    const anchor = document.getElementById('scroll-bottom-anchor');

    const requiredCorrectionFields = @json($requiredCorrectionFields ?? []);
    var fieldToName = function(f) {
        if (f === 'religion') return 'religion_id';
        if (f === 'caste') return 'caste_id';
        if (f === 'sub_caste') return 'sub_caste_id';
        return f;
    };
    const requiredSelectors = requiredCorrectionFields.length ? requiredCorrectionFields.map(function(f) { return 'input[name="snapshot[core][' + fieldToName(f) + ']"]'; }).join(',') : '';
    const placeholderNotFound = @json($placeholderNotFound ?? '⟪NOT FOUND IN OCR⟫');
    const placeholderSelectRequired = @json($placeholderSelectRequired ?? '⟪SELECT REQUIRED⟫');
    const resolveLocationUrl = @json(route('intake.resolve-location', $intake));
    const unresolvedLocationOptions = @json($unresolvedLocationOptions ?? []);
    const suggestionMapData = @json($suggestionMap ?? []);
    const previewFieldSuggestions = @json($previewFieldSuggestions ?? []);
    const intakeParseSuggestionLabel = @json(__('intake.parse_suggestion_from_biodata'));
    const intakeParseApplyLabel = @json(__('intake.parse_suggestion_apply'));

    function isScrolledToBottom() {
        if (!anchor) return false;
        var rect = anchor.getBoundingClientRect();
        return rect.top <= (window.innerHeight + 80);
    }

    function allRequiredCorrectionsFilled() {
        if (!requiredSelectors) return true;
        var inputs = form.querySelectorAll(requiredSelectors);
        for (var i = 0; i < inputs.length; i++) {
            var v = String(inputs[i].value || '').trim();
            if (v === '' || v === placeholderNotFound || v === placeholderSelectRequired) return false;
        }
        return true;
    }

    function updateButton() {
        var atBottom = isScrolledToBottom();
        var checked = confirmCheck && confirmCheck.checked;
        var filled = allRequiredCorrectionsFilled();
        var canSubmit = atBottom && checked && filled;
        approveBtn.disabled = !canSubmit;
        if (!canSubmit && gatingMessage) gatingMessage.classList.remove('hidden');
        else if (gatingMessage) gatingMessage.classList.add('hidden');
    }

    if (confirmCheck) confirmCheck.addEventListener('change', updateButton);
    window.addEventListener('scroll', function() { updateButton(); }, { passive: true });
    form.querySelectorAll('input').forEach(function(inp) {
        inp.addEventListener('input', updateButton);
        inp.addEventListener('change', updateButton);
        if (inp.hasAttribute('data-ocr-missing')) {
            inp.addEventListener('input', function() {
                if (String(inp.value || '').trim() !== '') {
                    inp.classList.remove('ocr-field-missing');
                    inp.removeAttribute('data-ocr-missing');
                    inp.removeAttribute('data-placeholder-value');
                    inp.closest('.ocr-field-missing-wrap')?.classList.remove('ocr-field-missing-wrap');
                }
            });
            inp.addEventListener('change', function() {
                if (String(inp.value || '').trim() !== '') {
                    inp.classList.remove('ocr-field-missing');
                    inp.removeAttribute('data-ocr-missing');
                    inp.removeAttribute('data-placeholder-value');
                    inp.closest('.ocr-field-missing-wrap')?.classList.remove('ocr-field-missing-wrap');
                }
            });
        }
    });
    updateButton();

    form.addEventListener('submit', function(e) {
        form.querySelectorAll('input[data-ocr-missing="1"]').forEach(function(el) {
            if (!el.name || el.name.indexOf('snapshot[core]') !== 0) return;
            var v = String(el.value || '').trim();
            if (v === '') {
                var ph = el.getAttribute('data-placeholder-value');
                if (ph) el.value = ph;
            }
        });
        // When birth place is text-only (no city selected), submit it so approve can set birth_place_text.
        var birthWrap = form.querySelector('[data-location-context="birth"]');
        var birthCityHidden = form.querySelector('input[name="snapshot[core][birth_city_id]"]');
        var birthPlaceHidden = document.getElementById('intake_birth_place_text');
        if (birthWrap && birthCityHidden && birthPlaceHidden) {
            var displayInput = birthWrap.querySelector('.location-typeahead-input');
            if ((birthCityHidden.value === '' || birthCityHidden.value === null) && displayInput && String(displayInput.value || '').trim() !== '') {
                birthPlaceHidden.value = String(displayInput.value).trim();
            } else if (birthCityHidden.value !== '' && birthCityHidden.value != null) {
                birthPlaceHidden.value = '';
            }
        }
    });

    // Revert / use-candidate (full_form basic_info may render these)
document.querySelectorAll('.revert-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var field = btn.getAttribute('data-field');
        var input = document.querySelector('input[name="snapshot[core][' + field + ']"]');
        if (input && input.dataset.original !== undefined) {
            input.value = input.dataset.original;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
        if (btn.classList.contains('revert-btn-after-apply')) {
            btn.classList.add('hidden');
        }
    });
});
document.querySelectorAll('.use-candidate-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var field = btn.getAttribute('data-field');
        var val = btn.getAttribute('data-value');
        var input = document.querySelector('input[name="snapshot[core][' + field + ']"]');
        if (input && val !== null) {
            input.value = val;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
    });
});
    form.querySelectorAll('.remove-row').forEach(function(btn) {
        btn.addEventListener('click', function() { btn.closest('.contact-row, .child-row, .education-row, .career-row, .address-row, .preference-row')?.remove(); });
    });

    function bindLocationApplyButtons(root) {
        root.querySelectorAll('.intake-loc-apply-btn').forEach(function(btn) {
            if (btn.dataset.boundLocApply === '1') return;
            btn.dataset.boundLocApply = '1';
            btn.addEventListener('click', function() {
                var field = btn.getAttribute('data-field');
                var cityId = btn.getAttribute('data-city-id');
                if (!field || !cityId) return;
                btn.disabled = true;
                fetch(resolveLocationUrl, {
                    method: 'PATCH',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                    },
                    body: JSON.stringify({ field: field, city_id: parseInt(cityId, 10) })
                }).then(function(r){ return r.json().then(function(j){ return { ok: r.ok, data: j }; }); })
                  .then(function(res) {
                      if (res.ok && res.data && res.data.success) {
                          var note = btn.closest('[data-intake-location-suggestion="1"]');
                          var payload = res.data;
                          if (!payload.display_label && note) {
                              var lbl = note.querySelector('.font-medium');
                              if (lbl) payload.display_label = String(lbl.textContent || '').trim();
                          }
                          var locApi = window.LocationTypeahead;
                          var wrapper = locApi && typeof locApi.wrapperForIntakeField === 'function'
                              ? locApi.wrapperForIntakeField(form, field, btn)
                              : null;
                          if (wrapper && locApi && typeof locApi.applySelection === 'function') {
                              locApi.applySelection(wrapper, payload);
                          }
                          if (note) note.remove();
                      } else {
                          btn.disabled = false;
                          window.alert((res.data && res.data.message) ? res.data.message : 'Could not resolve this location.');
                      }
                  }).catch(function() {
                      btn.disabled = false;
                      window.alert('Network error while resolving location.');
                  });
            });
        });
    }

    function renderInlineLocationUi(targetWrap, loc) {
        if (!targetWrap || !loc) return;
        if (targetWrap.querySelector('[data-intake-location-suggestion="1"]')) return;
        var opts = Array.isArray(loc.options) ? loc.options : [];
        if (opts.length === 0) return;

        opts.forEach(function(opt) {
            var note = document.createElement('div');
            note.className = 'mt-1.5 flex flex-wrap items-center gap-2 text-xs text-indigo-900 dark:text-indigo-100 rounded-md border border-indigo-200 dark:border-indigo-800 bg-indigo-50/80 dark:bg-indigo-950/40 px-2 py-1.5';
            note.dataset.intakeLocationSuggestion = '1';

            var label = document.createElement('span');
            label.textContent = intakeParseSuggestionLabel;
            note.appendChild(label);

            var valSpan = document.createElement('span');
            valSpan.className = 'font-medium text-indigo-950 dark:text-indigo-50';
            valSpan.textContent = String(opt.display_label || opt.name || opt.city_name || '—');
            note.appendChild(valSpan);

            var applyBtn = document.createElement('button');
            applyBtn.type = 'button';
            applyBtn.className = 'intake-loc-apply-btn px-2 py-0.5 rounded bg-indigo-600 hover:bg-indigo-700 text-white text-[11px] font-semibold';
            applyBtn.title = @json(__('intake.parse_suggestion_click_hint'));
            applyBtn.textContent = intakeParseApplyLabel;
            applyBtn.setAttribute('data-field', String(loc.field_key || ''));
            applyBtn.setAttribute('data-city-id', String(opt.city_id || ''));
            note.appendChild(applyBtn);

            targetWrap.appendChild(note);
        });
        bindLocationApplyButtons(targetWrap);
    }

    function findLocationSuggestionAnchor(dom) {
        if (!dom || !dom.type) return null;
        if (dom.type === 'location_context') {
            return form.querySelector('[data-location-context="' + dom.value + '"]');
        }
        if (dom.type === 'parents_address_row') {
            var pRows = document.getElementById('parents-address-rows');
            var pRow = pRows ? (pRows.querySelector('.parents-address-row[data-row-index="' + dom.index + '"]') || pRows.children[dom.index]) : null;
            return pRow ? (pRow.querySelector('.location-typeahead-wrapper')?.closest('.min-w-0') || pRow) : null;
        }
        if (dom.type === 'self_address_row') {
            var sRows = document.getElementById('self-address-rows');
            var sRow = sRows ? (sRows.querySelector('.self-address-row[data-row-index="' + dom.index + '"]') || sRows.children[dom.index]) : null;
            return sRow ? (sRow.querySelector('.location-typeahead-wrapper')?.closest('.min-w-0') || sRow) : null;
        }
        if (dom.type === 'relatives_row') {
            var prefix = dom.container === 'relatives_maternal_family'
                ? 'snapshot[relatives_maternal_family]'
                : 'snapshot[relatives_parents_family]';
            var relInput = form.querySelector('input[name="' + prefix + '[' + dom.index + '][notes]"]')
                || form.querySelector('input[name="' + prefix + '[' + dom.index + '][occupation]"]');
            return relInput ? (relInput.closest('.relation-engine-row') || relInput.closest('.min-w-0') || relInput.parentElement) : null;
        }
        return null;
    }

    function resolveInlineTargets() {
        if (!Array.isArray(unresolvedLocationOptions)) return;
        unresolvedLocationOptions.forEach(function(loc) {
            var anchor = findLocationSuggestionAnchor(loc.dom_anchor || {});
            if (!anchor) {
                var field = String((loc && loc.field_key) || '');
                if (field === 'birth_place') {
                    anchor = form.querySelector('[data-location-context="birth"]');
                } else if (field === 'native_place') {
                    anchor = form.querySelector('[data-location-context="native"]');
                } else if (field === 'work_location') {
                    anchor = form.querySelector('[data-location-context="work"]');
                }
            }
            renderInlineLocationUi(anchor, loc);
        });
    }

    function normSuggestionText(s) {
        return String(s || '').normalize('NFKC').replace(/\s+/g, ' ').trim();
    }

    function pickVisibleElementByName(fieldName) {
        var nodes = form.querySelectorAll('[name="' + fieldName + '"]');
        for (var i = 0; i < nodes.length; i++) {
            var n = nodes[i];
            if (n.type !== 'hidden') return n;
        }
        return nodes[0] || null;
    }

    function decorateField(el, message, suggestions) {
        if (!el) return;
        if (el.dataset.intakeHintApplied === '1') return;
        el.dataset.intakeHintApplied = '1';
        var note = document.createElement('div');
        note.className = 'mt-1 text-xs text-amber-800 dark:text-amber-200';
        var html = '<div>' + message + '</div>';
        if (Array.isArray(suggestions) && suggestions.length) {
            html += '<div class="mt-1 text-[11px]">Suggestions: ' + suggestions.join(' / ') + '</div>';
        }
        note.innerHTML = html;
        if (el.parentElement) {
            el.parentElement.appendChild(note);
        }
    }

    function snapHeightCmToPicker(cm) {
        if (cm < 137) return 136;
        if (cm > 213) return 214;
        var best = 163;
        var minDiff = 9999;
        for (var inches = 54; inches <= 84; inches++) {
            var v = Math.round(inches * 2.54);
            var d = Math.abs(v - cm);
            if (d < minDiff) {
                minDiff = d;
                best = v;
            }
        }
        return best;
    }

    function applyHeightCmToPicker(cmRaw) {
        var cm = parseInt(String(cmRaw), 10);
        if (!cm || cm < 1) return;
        var snapped = snapHeightCmToPicker(cm);
        var hidden = form.querySelector('[name="snapshot[core][height_cm]"]');
        if (hidden) {
            hidden.value = String(snapped);
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
        }
        var picker = form.querySelector('.height-picker');
        if (picker) {
            var sel = picker.querySelector('select');
            if (sel) {
                sel.value = String(snapped);
                sel.dispatchEvent(new Event('change', { bubbles: true }));
            }
            if (window.Alpine) {
                try {
                    var data = Alpine.$data(picker);
                    if (data && typeof data.heightCm !== 'undefined') {
                        data.heightCm = snapped;
                    }
                } catch (e) { /* ignore */ }
            }
        }
    }

    function applyIntakeValueToField(cfg, applyValue) {
        if (!cfg || applyValue === undefined || applyValue === null) return;
        var coreKey = cfg.core_key || cfg.key || '';
        if (coreKey === 'height_cm') {
            applyHeightCmToPicker(applyValue);
            return;
        }
        if (coreKey === 'blood_group_id') {
            var bgId = parseInt(String(applyValue), 10);
            if (!bgId) return;
            var bgSel = form.querySelector('select[name="snapshot[core][blood_group_id]"]');
            if (bgSel) {
                bgSel.value = String(bgId);
                bgSel.dispatchEvent(new Event('change', { bubbles: true }));
            }
            return;
        }
        if (cfg.key === 'gender') {
            var gid = parseInt(String(applyValue), 10);
            if (!gid) return;
            var hiddenGender = form.querySelector('[name="snapshot[core][gender_id]"]');
            if (hiddenGender) {
                hiddenGender.value = String(gid);
                hiddenGender.dispatchEvent(new Event('change', { bubbles: true }));
            }
            form.querySelectorAll('[x-data] button[type="button"]').forEach(function(btn) {
                var click = btn.getAttribute('@click') || btn.getAttribute('x-on:click') || '';
                if (click.indexOf('genderId = ' + gid) !== -1 || click.indexOf('genderId=' + gid) !== -1) {
                    btn.click();
                }
            });
            return;
        }
        if (cfg.key === 'religion' || cfg.key === 'caste' || cfg.key === 'sub_caste') {
            var payload = applyValue;
            if (typeof payload === 'string') {
                try { payload = JSON.parse(payload); } catch (e) { payload = null; }
            }
            if (!payload || typeof payload !== 'object') return;
            var hiddenKey = cfg.key === 'religion' ? 'religion_id' : (cfg.key === 'caste' ? 'caste_id' : 'sub_caste_id');
            var labelKey = cfg.key === 'religion' ? 'religion_label' : (cfg.key === 'caste' ? 'caste_label' : 'subcaste_label');
            var componentRoot = form.querySelector('.religion-caste-component');
            var hiddenEl = componentRoot
                ? componentRoot.querySelector('.' + (cfg.key === 'sub_caste' ? 'subcaste-hidden' : (cfg.key + '-hidden')))
                : form.querySelector('[name="snapshot[core][' + hiddenKey + ']"]');
            var visibleEl = componentRoot
                ? componentRoot.querySelector('.' + (cfg.key === 'sub_caste' ? 'subcaste' : cfg.key) + '-input')
                : form.querySelector('.' + (cfg.key === 'sub_caste' ? 'subcaste' : cfg.key) + '-input');
            var idNum = payload[hiddenKey] !== undefined && payload[hiddenKey] !== null
                ? parseInt(String(payload[hiddenKey]), 10)
                : 0;
            if (hiddenEl && idNum > 0) {
                hiddenEl.value = String(idNum);
                hiddenEl.dispatchEvent(new Event('change', { bubbles: true }));
            }
            if (visibleEl) {
                if (visibleEl.disabled) visibleEl.disabled = false;
                if (payload[labelKey] !== undefined && payload[labelKey] !== null) {
                    visibleEl.value = String(payload[labelKey]);
                }
                visibleEl.dispatchEvent(new Event('input', { bubbles: true }));
            }
            return;
        }
        var el = pickVisibleElementByName(cfg.name);
        if (!el) return;
        el.value = String(applyValue);
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function cfgFromSuggestionRow(row) {
        var coreKey = row.core_key || row.key || '';
        var sk = row.key || coreKey;
        return {
            key: sk,
            core_key: coreKey,
            name: row.form_name || ('snapshot[core][' + coreKey + ']'),
            fallbackSelector: sk === 'religion' ? '.religion-input' : (sk === 'caste' ? '.caste-input' : (sk === 'sub_caste' ? '.subcaste-input' : null))
        };
    }

    function biodataLabelFromSuggestionMap(key) {
        var sug = (suggestionMapData && typeof suggestionMapData === 'object') ? suggestionMapData[key] : null;
        if (!sug || !sug.profile_existing) return '';
        return String(sug.intake_display_value || sug.corrected_value || sug.suggested_value || '').trim();
    }

    function mergeParseSuggestionRows() {
        var rows = Array.isArray(previewFieldSuggestions) ? previewFieldSuggestions.slice() : [];
        var seen = {};
        rows.forEach(function(r) { if (r && r.key) seen[r.key] = true; });
        ['religion', 'caste', 'sub_caste'].forEach(function(key) {
            if (seen[key]) return;
            var intakeDisplay = biodataLabelFromSuggestionMap(key);
            if (!intakeDisplay) return;
            var coreKey = key === 'religion' ? 'religion_id' : (key === 'caste' ? 'caste_id' : 'sub_caste_id');
            var sug = suggestionMapData[key];
            rows.push({
                key: key,
                core_key: coreKey,
                form_name: 'snapshot[core][' + coreKey + ']',
                profile_display: String(sug.current_value || sug.selected_value || '').trim(),
                intake_display: intakeDisplay,
                intake_apply: sug.intake_apply
            });
            seen[key] = true;
        });
        return rows;
    }

    function renderIntakeParseSuggestions() {
        var rows = mergeParseSuggestionRows();
        var seen = {};
        rows.forEach(function(row) {
            if (!row || !row.key || seen[row.key]) return;
            seen[row.key] = true;
            var intakeDisplay = String(row.intake_display || '').trim();
            if (!intakeDisplay) return;
            var cfg = cfgFromSuggestionRow(row);
            var el = pickVisibleElementByName(cfg.name);
            if (!el && cfg.fallbackSelector) el = form.querySelector(cfg.fallbackSelector);
            if (!el) return;
            var compareEl = el;
            if (cfg.fallbackSelector) {
                var visEl = form.querySelector(cfg.fallbackSelector);
                if (visEl) compareEl = visEl;
            }

            var fieldText = normSuggestionText(compareEl.value);
            var intakeNorm = normSuggestionText(intakeDisplay);
            var profileNorm = normSuggestionText(row.profile_display || '');
            if (intakeNorm !== '' && (fieldText === intakeNorm || (profileNorm !== '' && profileNorm === intakeNorm))) {
                return;
            }
            if (cfg.key === 'religion' || cfg.key === 'caste' || cfg.key === 'sub_caste') {
                var hidKey = cfg.key === 'religion' ? 'religion_id' : (cfg.key === 'caste' ? 'caste_id' : 'sub_caste_id');
                var hiddenIdEl = form.querySelector('[name="snapshot[core][' + hidKey + ']"]');
                var applyObj = row.intake_apply;
                if (hiddenIdEl && applyObj && typeof applyObj === 'object' && applyObj[hidKey] !== undefined && applyObj[hidKey] !== null) {
                    var hidId = parseInt(String(hiddenIdEl.value || ''), 10);
                    var applyId = parseInt(String(applyObj[hidKey]), 10);
                    if (hidId > 0 && applyId > 0 && hidId === applyId
                        && (fieldText === intakeNorm || (profileNorm !== '' && fieldText === profileNorm))) {
                        return;
                    }
                }
            }
            var wrap = el.closest('.min-w-0') || el.parentElement;
            if (!wrap || wrap.querySelector('[data-intake-parse-suggestion="1"]')) return;

            var note = document.createElement('div');
            note.className = 'mt-1.5 flex flex-wrap items-center gap-2 text-xs text-indigo-900 dark:text-indigo-100 rounded-md border border-indigo-200 dark:border-indigo-800 bg-indigo-50/80 dark:bg-indigo-950/40 px-2 py-1.5';
            note.dataset.intakeParseSuggestion = '1';

            var label = document.createElement('span');
            label.textContent = intakeParseSuggestionLabel;
            note.appendChild(label);

            var valSpan = document.createElement('span');
            valSpan.className = 'font-medium text-indigo-950 dark:text-indigo-50';
            valSpan.textContent = intakeDisplay;
            note.appendChild(valSpan);

            var applyBtn = document.createElement('button');
            applyBtn.type = 'button';
            applyBtn.className = 'intake-parse-apply-btn px-2 py-0.5 rounded bg-indigo-600 hover:bg-indigo-700 text-white text-[11px] font-semibold';
            applyBtn.title = @json(__('intake.parse_suggestion_click_hint'));
            applyBtn.textContent = intakeParseApplyLabel;
            applyBtn.addEventListener('click', function() {
                var applyVal = row.intake_apply !== undefined ? row.intake_apply : intakeDisplay;
                if (cfg.key === 'religion' || cfg.key === 'caste' || cfg.key === 'sub_caste') {
                    if (typeof applyVal !== 'object' || applyVal === null) {
                        var hidKey = cfg.key === 'religion' ? 'religion_id' : (cfg.key === 'caste' ? 'caste_id' : 'sub_caste_id');
                        var lblKey = cfg.key === 'religion' ? 'religion_label' : (cfg.key === 'caste' ? 'caste_label' : 'subcaste_label');
                        var o = {};
                        if (typeof applyVal === 'number' || (typeof applyVal === 'string' && /^\d+$/.test(String(applyVal)))) {
                            o[hidKey] = parseInt(String(applyVal), 10);
                        }
                        o[lblKey] = intakeDisplay;
                        applyVal = o;
                    } else if (!applyVal.subcaste_label && !applyVal.caste_label && !applyVal.religion_label) {
                        var lk = cfg.key === 'religion' ? 'religion_label' : (cfg.key === 'caste' ? 'caste_label' : 'subcaste_label');
                        applyVal[lk] = intakeDisplay;
                    }
                }
                applyIntakeValueToField(cfg, applyVal);
                var noteEl = applyBtn.closest('[data-intake-parse-suggestion="1"]');
                if (noteEl) noteEl.remove();
                if (el) delete el.dataset.intakeParseSuggestion;
            });
            note.appendChild(applyBtn);

            wrap.appendChild(note);
            el.dataset.intakeParseSuggestion = '1';
        });
    }

    function applyCoreFieldAdvisories() {
        var map = suggestionMapData && typeof suggestionMapData === 'object' ? suggestionMapData : {};
        var fields = [
            { key: 'religion', name: 'snapshot[core][religion_id]', fallbackSelector: '.religion-input', label: 'Religion' },
            { key: 'caste', name: 'snapshot[core][caste_id]', fallbackSelector: '.caste-input', label: 'Caste' },
            { key: 'sub_caste', name: 'snapshot[core][sub_caste_id]', fallbackSelector: '.subcaste-input', label: 'Sub caste' },
            { key: 'marital_status', name: 'snapshot[core][marital_status_id]', fallbackSelector: 'select[name="snapshot[marital_status_id]"]', label: 'Marital status' },
            { key: 'mother_tongue', name: 'snapshot[core][mother_tongue_id]', fallbackSelector: null, label: 'Mother tongue' },
            { key: 'highest_education', name: 'snapshot[core][highest_education]', fallbackSelector: null, label: 'Highest education' },
            { key: 'working_with_type', name: 'snapshot[core][working_with_type_id]', fallbackSelector: null, label: 'Working with' },
            { key: 'profession', name: 'snapshot[core][profession_id]', fallbackSelector: null, label: 'Profession' }
        ];

        fields.forEach(function(cfg) {
            var el = pickVisibleElementByName(cfg.name);
            if (!el && cfg.fallbackSelector) el = form.querySelector(cfg.fallbackSelector);
            if (!el) return;

            var rawVal = (el.value === undefined || el.value === null) ? '' : String(el.value).trim();
            var hasValue = rawVal !== '' && rawVal !== '0';
            var sug = map[cfg.key] || null;
            var candidates = [];
            if (sug && Array.isArray(sug.candidates)) {
                for (var i = 0; i < sug.candidates.length; i++) {
                    var v = String((sug.candidates[i] && sug.candidates[i].value) || '').trim();
                    if (v && candidates.indexOf(v) === -1) candidates.push(v);
                    if (candidates.length >= 3) break;
                }
            }
            var needsReview = !!(sug && (sug.needs_review || sug.required_missing));
            var biodataLabel = biodataLabelFromSuggestionMap(cfg.key);
            if (biodataLabel !== '') {
                var vis = cfg.fallbackSelector ? form.querySelector(cfg.fallbackSelector) : el;
                if (vis && normSuggestionText(vis.value) !== normSuggestionText(biodataLabel)) {
                    return;
                }
            }
            if (sug && sug.profile_existing && hasValue) {
                return;
            }
            if (cfg.key === 'religion' || cfg.key === 'caste' || cfg.key === 'sub_caste') {
                var hidKey = cfg.key === 'religion' ? 'religion_id' : (cfg.key === 'caste' ? 'caste_id' : 'sub_caste_id');
                var hiddenIdEl = form.querySelector('[name="snapshot[core][' + hidKey + ']"]');
                if (hiddenIdEl && sug && sug.intake_apply && typeof sug.intake_apply === 'object' && sug.intake_apply[hidKey]) {
                    if (parseInt(String(hiddenIdEl.value || ''), 10) === parseInt(String(sug.intake_apply[hidKey]), 10)) {
                        return;
                    }
                }
            }
            if (!hasValue || needsReview) {
                var msg = !hasValue
                    ? (cfg.label + ' unresolved. Please review and select.')
                    : (cfg.label + ' needs review.');
                decorateField(el, msg, candidates);
            }
        });
    }

    resolveInlineTargets();
    bindLocationApplyButtons(document);
    applyCoreFieldAdvisories();
    renderIntakeParseSuggestions();
})();
</script>
@endsection
