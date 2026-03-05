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
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block font-medium text-gray-700 dark:text-gray-200 mb-1">Active parser</label>
                        <select name="intake_active_parser" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1">
                            <option value="rules_only" {{ $activeParser === 'rules_only' ? 'selected' : '' }}>Rules only</option>
                            <option value="ai_v1" {{ $activeParser === 'ai_v1' ? 'selected' : '' }}>AI v1 (hybrid)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-medium text-gray-700 dark:text-gray-200 mb-1">OCR provider</label>
                        <select name="intake_ocr_provider" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1">
                            <option value="tesseract" {{ $ocrProvider === 'tesseract' ? 'selected' : '' }}>Local Tesseract</option>
                            <option value="cloud_vision" {{ $ocrProvider === 'cloud_vision' ? 'selected' : '' }}>Cloud OCR (future)</option>
                            <option value="off" {{ $ocrProvider === 'off' ? 'selected' : '' }}>Off (text only)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-medium text-gray-700 dark:text-gray-200 mb-1">OCR language hint</label>
                        <select name="intake_ocr_language_hint" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1">
                            <option value="mr" {{ $ocrLanguageHint === 'mr' ? 'selected' : '' }}>Marathi</option>
                            <option value="en" {{ $ocrLanguageHint === 'en' ? 'selected' : '' }}>English</option>
                            <option value="mixed" {{ $ocrLanguageHint === 'mixed' ? 'selected' : '' }}>Marathi + English</option>
                        </select>
                    </div>
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

        <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-200 dark:border-gray-600 space-y-3">
            <p class="font-semibold text-sm text-gray-800 dark:text-gray-100">Confidence &amp; auto-apply policy</p>
            <div class="space-y-4 text-sm">
                <div>
                    <label class="block font-medium text-gray-700 dark:text-gray-200 mb-1">High confidence threshold</label>
                    <input type="number" step="0.01" name="intake_confidence_high_threshold" min="0.5" max="0.99" value="{{ number_format($confidenceHighThreshold, 2) }}" class="w-28 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        Fields with confidence below this value will be highlighted for review.
                    </p>
                </div>
                <div>
                    <p class="block font-medium text-gray-700 dark:text-gray-200 mb-1">Fields allowed for auto-apply</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">
                        Only these core fields may be auto-prefilled when confidence is high. Contact fields are always manual.
                    </p>
                    @php
                        $fieldLabels = [
                            'full_name' => 'Full name',
                            'date_of_birth' => 'Date of birth',
                            'gender' => 'Gender',
                            'religion' => 'Religion',
                            'caste' => 'Caste',
                            'sub_caste' => 'Sub caste',
                            'marital_status' => 'Marital status',
                        ];
                    @endphp
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                        @foreach ($fieldLabels as $key => $label)
                            <label class="inline-flex items-center gap-2">
                                <input type="checkbox" name="intake_auto_apply_fields[]" value="{{ $key }}" {{ in_array($key, $autoApplyFields, true) ? 'checked' : '' }} class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                                <span class="text-gray-800 dark:text-gray-100">{{ $label }}</span>
                            </label>
                        @endforeach
                        <label class="inline-flex items-center gap-2 opacity-70 cursor-not-allowed">
                            <input type="checkbox" disabled checked class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                            <span class="text-gray-800 dark:text-gray-100">Primary contact number (manual review only)</span>
                        </label>
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

