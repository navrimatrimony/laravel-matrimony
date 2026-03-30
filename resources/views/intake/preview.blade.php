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
        <div class="mb-3 rounded border-2 border-dashed border-amber-600 bg-amber-50 dark:bg-amber-950/40 p-3 text-xs font-mono text-amber-950 dark:text-amber-100" role="region" aria-label="OCR debug">
            <p class="font-bold mb-2 text-sm">{{ __('intake.ocr_debug_block_title') }}</p>
            <ul class="space-y-1 list-disc list-inside">
                <li>Active parser mode: {{ $ocrDebugMeta['active_parser_mode'] ?? '—' }}</li>
                <li>Intake parser_version: {{ $ocrDebugMeta['intake_parser_version'] ?? '—' }}</li>
                <li>UI preset: {{ $ocrDebugMeta['ui_preprocessing_preset'] ?? '—' }}</li>
                <li>Resolved preset: {{ $ocrDebugMeta['preset_resolved'] ?? '—' }}</li>
                <li>Preprocess used: {{ ! empty($ocrDebugMeta['preprocess_used']) ? 'yes' : 'no' }}</li>
                <li>Fallback: {{ ! empty($ocrDebugMeta['fallback_used']) ? 'yes' : 'no' }}</li>
                <li>Skipped reason: {{ $ocrDebugMeta['skipped_preprocessing_reason'] ?? '—' }}</li>
                <li>Derived kept on disk: {{ ! empty($ocrDebugMeta['derived_kept_on_disk']) ? 'yes' : 'no' }}</li>
                <li>Final OCR input path: {{ $ocrDebugMeta['final_ocr_input_path'] ?? '—' }}</li>
                <li>Driver: {{ $ocrDebugMeta['driver'] ?? '—' }}</li>
                <li>Applied steps: {{ implode(', ', $ocrDebugMeta['applied_steps'] ?? []) }}</li>
                <li>{{ __('intake.ocr_debug_effective_source') }}: {{ $ocrDebugMeta['ocr_source_type_effective'] ?? '—' }}</li>
                @if (! empty($ocrDebugMeta['parse_input_source']))
                    <li>Parse input source: {{ $ocrDebugMeta['parse_input_source'] }}</li>
                    <li>AI extract ok: {{ ! empty($ocrDebugMeta['parse_input_ok']) ? 'yes' : 'no' }}</li>
                    <li>AI extraction provider (transcription): {{ $ocrDebugMeta['parse_input_provider'] ?? '—' }} @if (! empty($ocrDebugMeta['parse_input_provider_source'])) (source: {{ $ocrDebugMeta['parse_input_provider_source'] }}) @endif</li>
                    <li>Extraction model (if applicable): {{ $ocrDebugMeta['parse_input_model'] ?? '—' }}</li>
                    <li>AI source field: {{ $ocrDebugMeta['parse_input_source_field'] ?? '—' }}</li>
                    <li>AI source path: {{ $ocrDebugMeta['parse_input_relative_path'] ?? '—' }}</li>
                    <li>AI text quality ok: {{ ! empty($ocrDebugMeta['parse_input_text_quality_ok']) ? 'yes' : 'no' }}</li>
                    <li>AI text chars/lines: {{ $ocrDebugMeta['parse_input_text_chars'] ?? '—' }} / {{ $ocrDebugMeta['parse_input_text_lines'] ?? '—' }}</li>
                    <li>AI text alpha ratio: {{ $ocrDebugMeta['parse_input_text_alpha_ratio'] ?? '—' }}</li>
                    @if (array_key_exists('parse_input_vision_detail', $ocrDebugMeta))
                        <li>Vision detail: {{ $ocrDebugMeta['parse_input_vision_detail'] }}</li>
                    @endif
                    @if (! empty($ocrDebugMeta['parse_input_original_image_width']) || ! empty($ocrDebugMeta['parse_input_original_image_height']))
                        <li>Source image WxH: {{ $ocrDebugMeta['parse_input_original_image_width'] ?? '—' }}×{{ $ocrDebugMeta['parse_input_original_image_height'] ?? '—' }}</li>
                    @endif
                    @if (! empty($ocrDebugMeta['parse_input_ai_request_image_width']) || ! empty($ocrDebugMeta['parse_input_ai_request_image_height']))
                        <li>AI request payload WxH: {{ $ocrDebugMeta['parse_input_ai_request_image_width'] ?? '—' }}×{{ $ocrDebugMeta['parse_input_ai_request_image_height'] ?? '—' }}</li>
                    @endif
                    @if (array_key_exists('parse_input_ai_request_payload_enhanced', $ocrDebugMeta))
                        <li>AI request payload enhanced (upscale/orient): {{ ! empty($ocrDebugMeta['parse_input_ai_request_payload_enhanced']) || ! empty($ocrDebugMeta['parse_input_ai_request_orientation_corrected']) ? 'yes' : 'no' }}</li>
                    @endif
                    @if (! empty($ocrDebugMeta['parse_input_extracted_text_line_count']))
                        <li>Extracted line count (post-sanitize): {{ $ocrDebugMeta['parse_input_extracted_text_line_count'] }}</li>
                    @endif
                    @if (! empty($ocrDebugMeta['parse_input_text_quality_reason']))
                        <li>AI text quality reason: {{ $ocrDebugMeta['parse_input_text_quality_reason'] }}</li>
                    @endif
                    @if (! empty($ocrDebugMeta['parse_input_sarvam_job_id']))
                        <li>Sarvam job: {{ $ocrDebugMeta['parse_input_sarvam_job_id'] }} ({{ $ocrDebugMeta['parse_input_sarvam_job_state'] ?? '—' }})</li>
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
                <li>{{ __('intake.ocr_debug_parse_uses_manual') }}: {{ ! empty($ocrDebugMeta['parse_uses_manual_prepared']) ? 'yes' : 'no' }}</li>
                @if (! empty($ocrDebugMeta['manual_prepared_storage_relative']))
                    <li>{{ __('intake.ocr_debug_manual_path') }}: {{ $ocrDebugMeta['manual_prepared_storage_relative'] }}</li>
                    <li><a href="{{ route('intake.manual-prepared-image', $intake) }}" class="underline text-amber-900 dark:text-amber-200" target="_blank" rel="noopener">{{ __('intake.ocr_debug_link_manual') }}</a></li>
                @endif
                @if (! empty($ocrDebugMeta['ocr_pipeline']))
                    <li>OCR pipeline (last extract): {{ $ocrDebugMeta['ocr_pipeline'] }}</li>
                @endif
                <li>Original WxH / size: {{ $ocrDebugMeta['original_width'] ?? '?' }}×{{ $ocrDebugMeta['original_height'] ?? '?' }} — {{ $ocrDebugMeta['original_filesize'] ?? '?' }} bytes</li>
                <li>Derived WxH / size: {{ $ocrDebugMeta['derived_width'] ?? '—' }}×{{ $ocrDebugMeta['derived_height'] ?? '—' }} — {{ $ocrDebugMeta['derived_filesize'] ?? '—' }} bytes</li>
                @if (! empty($ocrDebugMeta['ocr_quality']) && is_array($ocrDebugMeta['ocr_quality']))
                    <li>OCR quality (parse input): score {{ $ocrDebugMeta['ocr_quality']['score'] ?? '—' }}, low={{ ! empty($ocrDebugMeta['ocr_quality']['is_low']) ? 'yes' : 'no' }}, reasons: {{ implode(', ', $ocrDebugMeta['ocr_quality']['reasons'] ?? []) }}</li>
                @endif
            </ul>
            <p class="mt-2 break-all">Original path: {{ $ocrDebugMeta['original_absolute_path'] ?? '—' }}</p>
            <p class="mt-1 break-all">Derived path: {{ $ocrDebugMeta['derived_absolute_path'] ?? '—' }}</p>
            @if (($ocrDebugMeta['kind'] ?? '') === 'image' && $intake->file_path)
                <p class="mt-2 flex flex-wrap gap-3">
                    <a href="{{ route('intake.debug.ocr-artifact', ['intake' => $intake, 'which' => 'original']) }}" class="underline text-amber-900 dark:text-amber-200" target="_blank" rel="noopener">{{ __('intake.ocr_debug_link_original') }}</a>
                    @if (! empty($ocrDebugMeta['derived_absolute_path']) && is_file($ocrDebugMeta['derived_absolute_path']))
                        <a href="{{ route('intake.debug.ocr-artifact', ['intake' => $intake, 'which' => 'derived']) }}" class="underline text-amber-900 dark:text-amber-200" target="_blank" rel="noopener">{{ __('intake.ocr_debug_link_derived') }}</a>
                    @endif
                </p>
            @endif
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
                <p class="text-xs text-gray-600 dark:text-gray-400 mb-2 shrink-0">बायोडाटा मधून काढलेला स्ट्रक्चर्ड डेटा. खालील फॉर्म याच्या आधारे भरलेला आहे.</p>
                <div class="intake-preview-scroll-panel rounded border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-900/40 p-3 text-xs text-gray-800 dark:text-gray-100 leading-relaxed font-mono">
                    @if($parsedJsonForDisplay !== '')
                        <pre class="m-0 whitespace-pre-wrap break-words">{!! $parsedJsonForDisplay !!}</pre>
                    @else
                        <span class="text-amber-600 dark:text-amber-400">Parsed JSON उपलब्ध नाही.</span>
                    @endif
                </div>
            </div>
        </section>

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
            @include('matrimony.profile.wizard.sections.full_form')
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
})();
</script>
@endsection
