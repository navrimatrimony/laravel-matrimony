@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Intake engine settings</h1>
    <p class="text-gray-500 dark:text-gray-400 text-sm mb-6">
        Control how many biodata uploads a user can create, and how the OCR + parser behave. This keeps AI costs and performance predictable.
    </p>

    @if (session('success'))
        <p class="text-green-600 dark:text-green-400 text-sm mb-4">{{ session('success') }}</p>
    @endif
    @if ($errors->any())
        <ul class="text-red-600 text-sm mb-4 space-y-1">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('admin.intake-settings.update') }}" class="space-y-6">
        @csrf

        <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-200 dark:border-gray-600 space-y-3">
            <p class="font-semibold text-sm text-gray-800 dark:text-gray-100">Per-user upload limits</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Limits apply per logged-in user. A value of 0 disables the limit.
            </p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div>
                    <label class="block font-medium text-gray-700 dark:text-gray-200 mb-1">Daily limit</label>
                    <input type="number" name="intake_max_daily_per_user" min="0" max="50" value="{{ $dailyLimit }}" class="w-28 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">0 = unlimited per day.</p>
                </div>
                <div>
                    <label class="block font-medium text-gray-700 dark:text-gray-200 mb-1">Monthly limit</label>
                    <input type="number" name="intake_max_monthly_per_user" min="0" max="200" value="{{ $monthlyLimit }}" class="w-28 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">0 = unlimited per month.</p>
                </div>
            </div>
        </div>

        <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-200 dark:border-gray-600 space-y-3">
            <p class="font-semibold text-sm text-gray-800 dark:text-gray-100">File size &amp; pages</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Safety limits for very large PDFs or long documents.
            </p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                <div>
                    <label class="block font-medium text-gray-700 dark:text-gray-200 mb-1">Max PDF size (MB)</label>
                    <input type="number" name="intake_max_pdf_mb" min="1" max="20" value="{{ $maxPdfMb }}" class="w-24 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1">
                </div>
                <div>
                    <label class="block font-medium text-gray-700 dark:text-gray-200 mb-1">Max PDF pages</label>
                    <input type="number" name="intake_max_pdf_pages" min="1" max="50" value="{{ $maxPdfPages }}" class="w-24 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1">
                </div>
                <div>
                    <label class="block font-medium text-gray-700 dark:text-gray-200 mb-1">Max images / intake</label>
                    <input type="number" name="intake_max_images_per_intake" min="1" max="10" value="{{ $maxImagesPerIntake }}" class="w-24 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1">
                </div>
            </div>
        </div>

        <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-200 dark:border-gray-600 space-y-3">
            <p class="font-semibold text-sm text-gray-800 dark:text-gray-100">Global safety cap</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Hard cap on total biodata uploads per day across the whole system. 0 = no global cap.
            </p>
            <div class="text-sm">
                <label class="block font-medium text-gray-700 dark:text-gray-200 mb-1">Global daily cap</label>
                <input type="number" name="intake_global_daily_cap" min="0" max="10000" value="{{ $globalDailyCap }}" class="w-32 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1">
            </div>
        </div>

        <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-200 dark:border-gray-600 space-y-3">
            <p class="font-semibold text-sm text-gray-800 dark:text-gray-100">Privacy &amp; retention</p>
            <div class="space-y-3 text-sm">
                <div>
                    <label class="block font-medium text-gray-700 dark:text-gray-200 mb-1">Keep upload files for (days)</label>
                    <input type="number" name="intake_file_retention_days" min="0" max="365" value="{{ $fileRetentionDays }}" class="w-28 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        After this many days, original PDF/image files will be eligible for deletion by a background cleanup job. 0 = keep forever.
                    </p>
                </div>
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" name="intake_keep_parsed_json_after_purge" value="1" {{ $keepParsedJsonAfterPurge ? 'checked' : '' }} class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                    <span class="text-gray-800 dark:text-gray-100">Keep parsed data (JSON) even after files are purged</span>
                </label>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    When enabled, only the heavy files are removed; structured parsed data stays available for audit and training (subject to policy/consent).
                </p>
            </div>
        </div>

        <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-200 dark:border-gray-600 space-y-3">
            <p class="font-semibold text-sm text-gray-800 dark:text-gray-100">Review workflow</p>
            <div class="space-y-2 text-sm">
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" name="intake_require_admin_before_attach" value="1" {{ $requireAdminBeforeAttach ? 'checked' : '' }} class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                    <span class="text-gray-800 dark:text-gray-100">Require admin approval before attaching intake to a profile</span>
                </label>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    When enabled, user-approved intakes must be reviewed in the admin panel before their data is applied to a profile.
                </p>
            </div>
        </div>

        <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-200 dark:border-gray-600 space-y-3">
            <p class="font-semibold text-sm text-gray-800 dark:text-gray-100">AI &amp; OCR behaviour</p>
            <div class="space-y-3 text-sm">
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" name="intake_auto_parse_enabled" value="1" {{ $autoParseEnabled ? 'checked' : '' }} class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                    <span class="text-gray-800 dark:text-gray-100">Automatically parse after upload</span>
                </label>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    When off, uploads are created in "uploaded" state and can be parsed later from admin tools.
                </p>
                <div>
                    <label class="block font-medium text-gray-700 dark:text-gray-200 mb-1">Processing mode</label>
                    <select name="intake_processing_mode" id="intake_processing_mode" class="w-full max-w-md rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1">
                        <option value="end_to_end" {{ ($processingMode ?? 'end_to_end') === 'end_to_end' ? 'selected' : '' }}>End-to-End</option>
                        <option value="hybrid" {{ ($processingMode ?? '') === 'hybrid' ? 'selected' : '' }}>Hybrid</option>
                    </select>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        End-to-End uses one AI provider for both file transcription and structured parsing. Hybrid lets you mix extraction (including Tesseract) and parser provider separately.
                    </p>
                </div>
                @php
                    $isEndToEnd = ($processingMode ?? 'end_to_end') === 'end_to_end';
                @endphp
                <div id="intake-block-end-to-end" class="space-y-2 rounded border border-transparent p-2 -m-2 {{ $isEndToEnd ? '' : 'hidden' }}">
                    <label class="block font-medium text-gray-700 dark:text-gray-200 mb-1">Primary AI provider</label>
                    <select name="intake_primary_ai_provider" class="intake-e2e-field w-full max-w-md rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1" @disabled(! $isEndToEnd)>
                        <option value="openai" {{ ($primaryAiProvider ?? 'openai') === 'openai' ? 'selected' : '' }}>OpenAI</option>
                        <option value="sarvam" {{ ($primaryAiProvider ?? '') === 'sarvam' ? 'selected' : '' }}>Sarvam</option>
                    </select>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        The selected provider will be used for both file transcription and structured parsing.
                    </p>
                </div>
                <div id="intake-block-hybrid" class="space-y-3 rounded border border-transparent p-2 -m-2 {{ $isEndToEnd ? 'hidden' : '' }}">
                    <div>
                        <label class="block font-medium text-gray-700 dark:text-gray-200 mb-1">File text extraction provider</label>
                        <select name="intake_hybrid_extraction_provider" class="intake-hybrid-field w-full max-w-md rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1" @disabled($isEndToEnd)>
                            <option value="sarvam" {{ ($hybridExtractionProvider ?? '') === 'sarvam' ? 'selected' : '' }}>Sarvam</option>
                            <option value="openai" {{ ($hybridExtractionProvider ?? 'openai') === 'openai' ? 'selected' : '' }}>OpenAI</option>
                            <option value="tesseract" {{ ($hybridExtractionProvider ?? '') === 'tesseract' ? 'selected' : '' }}>Tesseract</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-medium text-gray-700 dark:text-gray-200 mb-1">Structured parser provider</label>
                        <select name="intake_hybrid_parser_provider" class="intake-hybrid-field w-full max-w-md rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1" @disabled($isEndToEnd)>
                            <option value="sarvam" {{ ($hybridParserProvider ?? '') === 'sarvam' ? 'selected' : '' }}>Sarvam</option>
                            <option value="openai" {{ ($hybridParserProvider ?? 'openai') === 'openai' ? 'selected' : '' }}>OpenAI</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-medium text-gray-700 dark:text-gray-200 mb-1">OCR fallback</label>
                        <select name="intake_hybrid_ocr_fallback" class="intake-hybrid-field w-full max-w-md rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1" @disabled($isEndToEnd)>
                            <option value="tesseract" {{ ($hybridOcrFallback ?? 'tesseract') === 'tesseract' ? 'selected' : '' }}>Tesseract</option>
                            <option value="off" {{ ($hybridOcrFallback ?? '') === 'off' ? 'selected' : '' }}>Off</option>
                        </select>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Used when extraction is not Sarvam/OpenAI (e.g. Tesseract path).</p>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block font-medium text-gray-700 dark:text-gray-200 mb-1">OCR language hint</label>
                        <select name="intake_ocr_language_hint" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1">
                            <option value="mr" {{ $ocrLanguageHint === 'mr' ? 'selected' : '' }}>Marathi</option>
                            <option value="en" {{ $ocrLanguageHint === 'en' ? 'selected' : '' }}>English</option>
                            <option value="mixed" {{ $ocrLanguageHint === 'mixed' ? 'selected' : '' }}>Marathi + English</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-medium text-gray-700 dark:text-gray-200 mb-1">Parse retry limit</label>
                        <input type="number" name="intake_parse_retry_limit" min="0" max="5" value="{{ $parseRetryLimit }}" class="w-24 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            Maximum number of times the system will retry parsing after an error. 0 = no retries.
                        </p>
                    </div>
                </div>
            </div>
            <script>
                (function () {
                    var modeSel = document.getElementById('intake_processing_mode');
                    var blockE2e = document.getElementById('intake-block-end-to-end');
                    var blockHyb = document.getElementById('intake-block-hybrid');
                    if (!modeSel || !blockE2e || !blockHyb) return;
                    function sync() {
                        var m = modeSel.value;
                        var e2e = m === 'end_to_end';
                        blockE2e.classList.toggle('hidden', !e2e);
                        blockHyb.classList.toggle('hidden', e2e);
                        blockE2e.querySelectorAll('.intake-e2e-field').forEach(function (el) { el.disabled = !e2e; });
                        blockHyb.querySelectorAll('.intake-hybrid-field').forEach(function (el) { el.disabled = e2e; });
                    }
                    modeSel.addEventListener('change', sync);
                    sync();
                })();
            </script>
        </div>

        <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-200 dark:border-gray-600 space-y-3">
            <p class="font-semibold text-sm text-gray-800 dark:text-gray-100">Confidence-based review highlighting</p>
            <div class="space-y-4 text-sm">
                <div>
                    <label class="block font-medium text-gray-700 dark:text-gray-200 mb-1">High confidence threshold</label>
                    <input type="number" step="0.01" name="intake_confidence_high_threshold" min="0.5" max="0.99" value="{{ number_format($confidenceHighThreshold, 2) }}" class="w-28 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        This threshold is used only to highlight low-confidence fields during preview. It does not change how values are written to profiles or how governed approval and mutation run.
                    </p>
                    <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        This setting is used only for operator guidance in preview screens. All profile updates still go through governed mutation and approval flows.
                    </div>
                </div>
            </div>
        </div>

        <div class="pt-2">
            <button type="submit" class="px-5 py-2.5 bg-indigo-600 text-white rounded-md font-semibold text-sm hover:bg-indigo-700">
                Save settings
            </button>
        </div>
    </form>
</div>
@endsection

