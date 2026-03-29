<?php

namespace App\Http\Controllers;

use App\Jobs\ParseIntakeJob;
use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Services\AiVisionExtractionService;
use App\Services\IntakeApprovalService;
use App\Services\IntakeManualOcrPreparedService;
use App\Services\MutationService;
use App\Services\OcrService;
use App\Services\Parsing\ParserStrategyResolver;
use App\Services\Preview\PreviewSectionMapper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser as PdfParser;

/*
|--------------------------------------------------------------------------
| IntakeController — Phase-5 User-Side Intake UI Foundation
|--------------------------------------------------------------------------
|
| User-side intake flow: upload form, preview, approval, status.
| SSOT: OCR runs BEFORE intake creation; raw_ocr_text set at insert only.
|
*/
class IntakeController extends Controller
{
    /**
     * List current user's biodata intakes (Point 2: User intake history page).
     */
    public function index()
    {
        $intakes = BiodataIntake::where('uploaded_by', auth()->id())
            ->orderByDesc('created_at')
            ->get();

        return view('intake.index', compact('intakes'));
    }

    /**
     * Show upload form.
     */
    public function uploadForm()
    {
        return view('intake.upload');
    }

    /**
     * Phase-5 Day-18 Step-1: OCR before create. Store biodata intake with final raw_ocr_text.
     * ParseIntakeJob only parses; does not modify raw_ocr_text.
     */
    public function store(Request $request, OcrService $ocrService)
    {
        $request->validate([
            'raw_text' => ['nullable', 'string', 'required_without:file'],
            // Hard safety ceiling (KB); fine-tuned per-type via AdminSetting below.
            'file' => ['nullable', 'file', 'max:20480', 'required_without:raw_text'],
        ]);

        // Day-35: Per-user intake rate limits (daily/monthly) from admin settings.
        $userId = auth()->id();
        $dailyLimit = (int) AdminSetting::getValue('intake_max_daily_per_user', '0');
        $monthlyLimit = (int) AdminSetting::getValue('intake_max_monthly_per_user', '0');
        $globalDailyCap = (int) AdminSetting::getValue('intake_global_daily_cap', '0');
        if ($dailyLimit > 0) {
            $todayCount = BiodataIntake::where('uploaded_by', $userId)
                ->whereDate('created_at', today())
                ->count();
            if ($todayCount >= $dailyLimit) {
                return redirect()->back()
                    ->withErrors(['file' => __('intake.daily_limit_reached_try_tomorrow')])
                    ->withInput();
            }
        }
        if ($monthlyLimit > 0) {
            $monthCount = BiodataIntake::where('uploaded_by', $userId)
                ->whereYear('created_at', now()->year)
                ->whereMonth('created_at', now()->month)
                ->count();
            if ($monthCount >= $monthlyLimit) {
                return redirect()->back()
                    ->withErrors(['file' => __('intake.monthly_limit_reached')])
                    ->withInput();
            }
        }

        // Global daily cap across all users (infrastructure safety).
        if ($globalDailyCap > 0) {
            $todaysTotal = BiodataIntake::whereDate('created_at', today())->count();
            if ($todaysTotal >= $globalDailyCap) {
                Log::warning('Intake global daily cap hit', [
                    'user_id' => $userId,
                    'cap' => $globalDailyCap,
                ]);

                return redirect()->back()
                    ->withErrors(['file' => __('intake.global_cap_try_tomorrow')])
                    ->withInput();
            }
        }

        $path = null;
        $originalName = null;
        $rawText = null;

        if ($request->hasFile('file')) {
            $uploaded = $request->file('file');
            $originalName = $uploaded->getClientOriginalName();
            $ext = strtolower($uploaded->getClientOriginalExtension());

            // Type-specific limits: PDF size/pages, images-per-intake placeholder (for future multi-upload).
            $maxPdfMb = (int) AdminSetting::getValue('intake_max_pdf_mb', '10');
            $maxPdfPages = (int) AdminSetting::getValue('intake_max_pdf_pages', '8');
            $maxImagesPerIntake = (int) AdminSetting::getValue('intake_max_images_per_intake', '5');

            if ($ext === 'pdf' && $maxPdfMb > 0) {
                $sizeBytes = $uploaded->getSize();
                $limitBytes = $maxPdfMb * 1024 * 1024;
                if ($sizeBytes !== null && $sizeBytes > $limitBytes) {
                    return redirect()->back()
                        ->withErrors(['file' => __('intake.pdf_too_large', ['max_mb' => $maxPdfMb])])
                        ->withInput();
                }
            }

            if ($ext === 'pdf' && $maxPdfPages > 0) {
                try {
                    $parser = new PdfParser;
                    $pdf = $parser->parseFile($uploaded->getRealPath());
                    $pages = $pdf->getPages();
                    $pageCount = is_array($pages) ? count($pages) : 0;
                    if ($pageCount > $maxPdfPages) {
                        return redirect()->back()
                            ->withErrors(['file' => __('intake.pdf_too_many_pages', ['max_pages' => $maxPdfPages])])
                            ->withInput();
                    }
                } catch (\Throwable $e) {
                    // If page counting fails, do not block upload; size limit above still protects us.
                    Log::warning('Failed to count PDF pages for intake', [
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Single-image upload today; this is a placeholder for future multi-image engine.
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true) && $maxImagesPerIntake > 0) {
                $imagesInThisIntake = 1;
                if ($imagesInThisIntake > $maxImagesPerIntake) {
                    return redirect()->back()
                        ->withErrors(['file' => __('intake.too_many_images_reduce')])
                        ->withInput();
                }
            }

            $path = $uploaded->store('intakes');
            try {
                $rawText = $ocrService->extractTextFromPath($path, $originalName);
            } catch (\Throwable $e) {
                return redirect()->back()
                    ->withErrors(['file' => __('intake.ocr_extraction_failed').' '.$e->getMessage()])
                    ->withInput();
            }
        } else {
            $rawText = $request->input('raw_text', '');
        }

        $intake = null;
        DB::transaction(function () use ($path, $originalName, $rawText, &$intake) {
            $hash = $rawText !== null ? hash('sha256', (string) $rawText) : null;
            $parserMode = app(\App\Services\Parsing\ParserStrategyResolver::class)->resolveActiveMode();

            $intake = BiodataIntake::create([
                'uploaded_by' => auth()->id(),
                'file_path' => $path,
                'original_filename' => $originalName,
                'raw_ocr_text' => $rawText,
                'intake_status' => 'uploaded',
                'parse_status' => 'pending',
                'parser_version' => $parserMode,
                'content_hash' => $hash,
                'approved_by_user' => false,
                'intake_locked' => false,
                'snapshot_schema_version' => 1,
            ]);
        });

        // Honour admin toggle for auto-parse.
        $autoParse = \App\Models\AdminSetting::getBool('intake_auto_parse_enabled', true);
        if ($autoParse) {
            ParseIntakeJob::dispatch($intake->id);
        }

        return redirect()->route('intake.status', $intake->id)
            ->with('success', __('intake.uploaded_successfully'));
    }

    /**
     * Show preview. Phase-5 Day-19: Editable snapshot, confidence enforcement, lifecycle transition.
     * When parse_status = 'parsed' and intake has linked profile: transition profile to awaiting_user_approval.
     * No profile mutation in preview; only biodata_intakes may be modified later on approve.
     */
    public function preview(BiodataIntake $intake)
    {
        $isOwner = (int) $intake->uploaded_by === (int) auth()->id();
        $isAdmin = auth()->user()?->isAnyAdmin() ?? false;
        if (! $isOwner && ! $isAdmin) {
            abort(403, __('intake.only_preview_own'));
        }
        if ($intake->approved_by_user) {
            return redirect()->route('intake.status', $intake->id);
        }
        if ($intake->parse_status !== 'parsed') {
            abort(403);
        }

        $data = $intake->parsed_json;
        if (empty($data) || ! is_array($data)) {
            abort(400);
        }

        $mapper = new PreviewSectionMapper;
        $sections = $mapper->map($data);

        // Raw text panel: stored OCR, transient OCR for preview, or cached AI vision parse input (never mutates raw_ocr_text).
        $previewRaw = $this->resolvePreviewRawParseInputText($intake);
        $rawOcrTextForPreview = $previewRaw['text'];
        $previewRawTextSource = $previewRaw['source'];
        $previewParseProvenance = $previewRaw['provenance'] ?? ['heading_key' => 'intake.preview_source_unknown', 'params' => []];
        $rawOcrTextForSuggestions = $previewRawTextSource === 'ai_vision_unavailable' ? '' : $rawOcrTextForPreview;

        // Preview-only hints (siblings/relatives/taluka): prefer stored OCR; if blank and parse used AI vision cache, use that text (never persisted).
        $rawTextForPreviewEnhancements = trim((string) ($intake->raw_ocr_text ?? '')) !== ''
            ? (string) ($intake->raw_ocr_text ?? '')
            : ((($previewRawTextSource ?? '') === 'ai_vision_cache') ? $rawOcrTextForPreview : '');

        // Display: use approval_snapshot_json['core'] for form values when present, else parsed_json (already in sections).
        if (! empty($intake->approval_snapshot_json) && is_array($intake->approval_snapshot_json)
            && isset($intake->approval_snapshot_json['core']) && is_array($intake->approval_snapshot_json['core'])) {
            $sections['core'] = $sections['core'] ?? [];
            $sections['core']['data'] = $intake->approval_snapshot_json['core'];
        }

        $confidenceMap = $data['confidence_map'] ?? [];
        if (! is_array($confidenceMap)) {
            $confidenceMap = [];
        }

        // Phase-5C minimal: only these six are required for preview gating (asterisk + सुधारणा आवश्यक).
        $criticalFields = [
            'full_name',
            'gender',
            'date_of_birth',
            'religion',
            'caste',
            'sub_caste',
        ];

        // Core source for required-correction check: approval_snapshot_json first, else parsed_json. Not $sections.
        $approvalCore = null;
        if (! empty($intake->approval_snapshot_json) && is_array($intake->approval_snapshot_json)
            && isset($intake->approval_snapshot_json['core']) && is_array($intake->approval_snapshot_json['core'])) {
            $approvalCore = $intake->approval_snapshot_json['core'];
        }
        $coreForRequiredCheck = $approvalCore ?? ($data['core'] ?? []);
        if (! is_array($coreForRequiredCheck)) {
            $coreForRequiredCheck = [];
        }

        // Required correction = Phase-5C fields empty after trim(). confidence_map does NOT affect this.
        // Religion/Caste/Subcaste: consider filled if either label (religion/caste/sub_caste) or ID (religion_id/caste_id/sub_caste_id) is set.
        $requiredCorrectionFields = [];
        foreach ($criticalFields as $field) {
            $val = $coreForRequiredCheck[$field] ?? null;
            $trimmed = ($val !== null && $val !== '') ? trim((string) $val) : '';
            if ($trimmed === '—' || $trimmed === '-') {
                $trimmed = '';
            }
            if ($field === 'religion' && $trimmed === '' && ! empty($coreForRequiredCheck['religion_id'])) {
                $trimmed = 'set';
            }
            if ($field === 'caste' && $trimmed === '' && ! empty($coreForRequiredCheck['caste_id'])) {
                $trimmed = 'set';
            }
            if ($field === 'sub_caste' && $trimmed === '' && ! empty($coreForRequiredCheck['sub_caste_id'])) {
                $trimmed = 'set';
            }
            if ($trimmed === '') {
                $requiredCorrectionFields[] = $field;
            }
        }

        // Warning styling only (confidence below high-threshold); does not block Approve.
        $warningFields = [];
        $highConfThreshold = (float) \App\Models\AdminSetting::getValue('intake_confidence_high_threshold', '0.85');
        if ($highConfThreshold <= 0 || $highConfThreshold >= 1) {
            $highConfThreshold = 0.85;
        }
        foreach ($confidenceMap as $field => $conf) {
            if ((float) $conf < $highConfThreshold) {
                $warningFields[] = $field;
            }
        }

        $missingCriticalFields = [];
        foreach ($criticalFields as $field) {
            $val = $coreForRequiredCheck[$field] ?? null;
            $trimmed = ($val !== null && $val !== '') ? trim((string) $val) : '';

            if ($trimmed === '—' || $trimmed === '-') {
                $trimmed = '';
            }

            if ($trimmed === '') {
                $missingCriticalFields[] = $field;
            }
        }

        // Day-19: When preview loaded and intake has linked profile with state 'parsed' → awaiting_user_approval
        if ($intake->matrimony_profile_id) {
            $profile = \App\Models\MatrimonyProfile::find($intake->matrimony_profile_id);
            if ($profile && (($profile->lifecycle_state ?? '') === 'parsed')) {
                try {
                    \App\Services\ProfileLifecycleService::transitionTo($profile, 'awaiting_user_approval', auth()->user());
                } catch (\Throwable $e) {
                    // Log but do not block preview
                    report($e);
                }
            }
        }

        session(['preview_seen_'.$intake->id => true]);

        $sectionSourceKeys = [
            'core' => 'core',
            'contacts' => 'contacts',
            'children' => 'children',
            'education' => 'education_history',
            'career' => 'career_history',
            'addresses' => 'addresses',
            'property_summary' => 'property_summary',
            'property_assets' => 'property_assets',
            'horoscope' => 'horoscope',
            'preferences' => 'preferences',
            'narrative' => 'extended_narrative',
        ];

        // Sync primary contact between core and contacts for preview (no new numbers, only reuse).
        $corePrimary = null;
        $coreSource = $data['core'] ?? [];
        if (is_array($coreSource)) {
            $corePrimary = $coreSource['primary_contact_number'] ?? null;
        }
        if ($corePrimary !== null && $corePrimary !== '') {
            $contacts = $sections['contacts']['data'] ?? [];
            if (! is_array($contacts)) {
                $contacts = [];
            }
            if (isset($contacts[0]) && is_array($contacts[0])) {
                $contacts[0]['phone_number'] = $contacts[0]['phone_number'] ?? $contacts[0]['number'] ?? $corePrimary;
                $contacts[0]['phone_number'] = $corePrimary;
                $contacts[0]['is_primary'] = $contacts[0]['is_primary'] ?? 1;
                $contacts[0]['relation_type'] = $contacts[0]['relation_type'] ?? 'self';
                $contacts[0]['contact_name'] = $contacts[0]['contact_name'] ?? 'Primary';
            } else {
                // Create a primary contact row using the same primary number (no new number generation).
                $contacts[0] = [
                    'phone_number' => $corePrimary,
                    'relation_type' => 'self',
                    'contact_name' => 'Primary',
                    'is_primary' => 1,
                ];
            }
            $sections['contacts']['data'] = array_values($contacts);
        }

        // === No-Empty Required Fields: candidates + best prefill or placeholder ===
        $requiredCoreFields = [
            'full_name', 'date_of_birth', 'gender', 'religion', 'caste', 'sub_caste', 'primary_contact_number',
        ];
        $suggestionEnabledFields = [
            'full_name', 'date_of_birth', 'gender', 'religion', 'caste', 'sub_caste',
            'marital_status', 'primary_contact_number',
        ];
        $suggestionMap = [];
        $coreDataForSuggestion = $sections['core']['data'] ?? [];
        $rawOcrText = $rawOcrTextForSuggestions;
        $suggestionEngine = app(\App\Services\Ocr\OcrSuggestionEngine::class);
        $placeholderNotFound = \App\Services\Ocr\OcrSuggestionEngine::PLACEHOLDER_NOT_FOUND;
        $placeholderSelectRequired = \App\Services\Ocr\OcrSuggestionEngine::PLACEHOLDER_SELECT_REQUIRED;
        $dropdownRequiredFields = ['religion'];
        $confThreshold = (float) config('intake.suggestion_autofill_confidence', 0.70);
        $usageThreshold = (int) config('intake.suggestion_autofill_usage_count', 25);
        $autoApplyJson = \App\Models\AdminSetting::getValue('intake_auto_apply_fields', '[]');
        $autoApplyFields = json_decode($autoApplyJson, true);
        if (! is_array($autoApplyFields)) {
            $autoApplyFields = [];
        }

        foreach ($suggestionEnabledFields as $fieldKey) {
            $value = $coreDataForSuggestion[$fieldKey] ?? null;
            $currentValue = $value !== null && $value !== '' ? (is_scalar($value) ? trim((string) $value) : $value) : '';
            if (is_array($currentValue)) {
                $currentValue = '';
            }
            if ($currentValue === '—' || $currentValue === '-' || $currentValue === '–') {
                $currentValue = '';
            }

            $candidates = $suggestionEngine->getCandidates($fieldKey, $currentValue, $rawOcrText);
            $best = $candidates[0] ?? null;
            $isRequired = in_array($fieldKey, $requiredCoreFields, true);
            $currentEmpty = $currentValue === '' || $currentValue === null;

            $casteOriginalOcr = null;

            if ($currentEmpty && $best && $best['value'] !== '' && $best['value'] !== $placeholderNotFound && $best['value'] !== $placeholderSelectRequired) {
                $selectedValue = $best['value'];
                $prefillConf = (float) ($best['confidence'] ?? 0);
                $prefillSource = (string) ($best['source'] ?? 'raw_text');
                $requiredMissing = false;
            } elseif ($currentEmpty && $isRequired) {
                $selectedValue = in_array($fieldKey, $dropdownRequiredFields, true)
                    ? $placeholderSelectRequired
                    : $placeholderNotFound;
                $prefillConf = 0.0;
                $prefillSource = 'none';
                $requiredMissing = true;
            } else {
                $selectedValue = $currentValue;
                $prefillConf = $best ? (float) ($best['confidence'] ?? 0) : 0.0;
                $prefillSource = $best ? (string) ($best['source'] ?? 'none') : 'none';
                $requiredMissing = false;
                if ($fieldKey === 'caste' && $currentValue !== '' && $best && (float) ($best['confidence'] ?? 0) >= 0.75) {
                    $resolved = $suggestionEngine->resolveCasteToCanonical($currentValue);
                    if ($resolved !== null && $resolved === (string) ($best['value'] ?? '')) {
                        $selectedValue = $resolved;
                        $casteOriginalOcr = $currentValue;
                    }
                }
            }

            $needsReview = $prefillConf < $highConfThreshold;
            $canAutoApply = in_array($fieldKey, $autoApplyFields, true);
            $mode = ($prefillConf >= $confThreshold && ! $requiredMissing && $canAutoApply) ? 'auto_prefill' : 'manual_apply';

            $suggestionMap[$fieldKey] = [
                'field_key' => $fieldKey,
                'current_value' => $currentValue,
                'selected_value' => $selectedValue,
                'suggested_value' => $best['value'] ?? null,
                'corrected_value' => $best['value'] ?? null,
                'candidates' => $candidates,
                'original_value_snapshot' => $currentValue,
                'original_ocr_value' => $fieldKey === 'caste' ? $casteOriginalOcr : null,
                'prefill_confidence' => $prefillConf,
                'prefill_source' => $prefillSource,
                'prefill_reason' => $currentEmpty && $best ? 'best_candidate' : ($requiredMissing ? 'placeholder' : 'existing'),
                'needs_review' => $needsReview,
                'required_missing' => $requiredMissing,
                'suggestion_source' => $prefillSource,
                'pattern_confidence' => $prefillConf,
                'usage_count' => 0,
                'mode' => $mode,
                'can_revert' => true,
            ];

            if ($currentEmpty && ($selectedValue !== $currentValue || $requiredMissing)) {
                $sections['core']['data'][$fieldKey] = $selectedValue;
            }
            if ($fieldKey === 'caste' && $casteOriginalOcr !== null && $selectedValue !== $currentValue) {
                $sections['core']['data'][$fieldKey] = $selectedValue;
            }
        }

        // Caste → Religion fallback: when religion is placeholder and caste has a canonical (current or best candidate), infer religion from DB.
        $religionEntry = $suggestionMap['religion'] ?? null;
        $casteEntry = $suggestionMap['caste'] ?? null;
        if ($religionEntry && $casteEntry) {
            $relSelected = $religionEntry['selected_value'] ?? '';
            $casteSelected = $casteEntry['selected_value'] ?? '';
            $relIsPlaceholder = $relSelected === $placeholderNotFound || $relSelected === $placeholderSelectRequired;
            $canonicalCaste = null;
            if ($casteSelected !== '' && $casteSelected !== $placeholderNotFound && $casteSelected !== $placeholderSelectRequired) {
                $canonicalCaste = $suggestionEngine->resolveCasteToCanonical($casteSelected);
            }
            if ($canonicalCaste === null) {
                $bestCaste = $casteEntry['candidates'][0] ?? null;
                if ($bestCaste && ($bestCaste['value'] ?? '') !== '' && (float) ($bestCaste['confidence'] ?? 0) >= 0.70) {
                    $canonicalCaste = $suggestionEngine->resolveCasteToCanonical((string) $bestCaste['value']) === (string) $bestCaste['value']
                        ? (string) $bestCaste['value']
                        : null;
                }
            }
            if ($relIsPlaceholder && $canonicalCaste !== null) {
                $dep = $suggestionEngine->getReligionFromCasteDependency($canonicalCaste);
                if (! empty($dep['religions'])) {
                    if ($dep['single']) {
                        $relLabel = $dep['religions'][0];
                        $suggestionMap['religion']['selected_value'] = $relLabel;
                        $suggestionMap['religion']['prefill_confidence'] = 0.75;
                        $suggestionMap['religion']['prefill_source'] = 'dependency_infer';
                        $suggestionMap['religion']['suggestion_source'] = 'dependency_infer';
                        $suggestionMap['religion']['needs_review'] = true;
                        $suggestionMap['religion']['inferred_from_caste'] = true;
                        $candidates = $suggestionMap['religion']['candidates'];
                        $hasDep = false;
                        foreach ($candidates as $c) {
                            if (($c['value'] ?? '') === $relLabel && ($c['source'] ?? '') === 'dependency_infer') {
                                $hasDep = true;
                                break;
                            }
                        }
                        if (! $hasDep) {
                            array_unshift($candidates, ['value' => $relLabel, 'confidence' => 0.75, 'source' => 'dependency_infer']);
                            $suggestionMap['religion']['candidates'] = array_slice($candidates, 0, 3);
                        }
                        $suggestionMap['religion']['suggested_value'] = $relLabel;
                        $suggestionMap['religion']['corrected_value'] = $relLabel;
                        $sections['core']['data']['religion'] = $relLabel;
                    } else {
                        foreach ($dep['religions'] as $r) {
                            $suggestionMap['religion']['candidates'][] = ['value' => $r, 'confidence' => 0.70, 'source' => 'dependency_infer'];
                        }
                        $suggestionMap['religion']['selected_value'] = $placeholderSelectRequired;
                        $sections['core']['data']['religion'] = $placeholderSelectRequired;
                    }
                }
            }
        }

        // Build profile-like object for Basic Info engine (wizard same UI in intake).
        // Ensure 100% of parsed core fields appear in the form: set all known keys, then copy any remaining from parser.
        $coreData = $sections['core']['data'] ?? [];
        if (! is_array($coreData)) {
            $coreData = [];
        }

        // Resolve religion/caste/sub_caste/complexion/mother_tongue text → IDs so form hidden inputs and submit have IDs (edit then shows correctly).
        $coreData = $this->normalizeIntakeCoreForStorage($coreData);
        $sections['core']['data'] = $coreData;

        // --- Physical section safety-net (height + complexion only, additive, SSOT-safe) ---
        // काही deployments मध्ये AI-first parser जुन्या version ने चालू असू शकतो (queue worker reload न झालेला),
        // म्हणून preview साठी raw_ocr_text वरून minimum fallback काढून coreData मध्ये फक्त missing असतील तेव्हाच भरतो.
        $rawOcrTextForPhysical = (string) ($intake->raw_ocr_text ?? '');
        if ($rawOcrTextForPhysical === '' && ($previewRawTextSource ?? '') === 'ai_vision_cache') {
            $rawOcrTextForPhysical = $rawOcrTextForPreview;
        }
        if ($rawOcrTextForPhysical !== '') {
            // Height fallback: handle normal आणि थोडे garbled cases (फूट/कूट + इंच).
            if (empty($coreData['height_cm'])) {
                if (preg_match('/(\d{1,2})\s*[फक][ुू]ट\s*(\d{1,2})/u', $rawOcrTextForPhysical, $mHt)) {
                    $feet = (int) $mHt[1];
                    $inch = (int) $mHt[2];
                    $totalInches = $feet * 12 + $inch;
                    $coreData['height_cm'] = round($totalInches * 2.54, 2);
                } elseif (preg_match('/ऊंची\s*[:\-]?\s*([0-9]{1,2})\s*[,\/\- ]\s*([0-9]{1,2})/u', $rawOcrTextForPhysical, $mHt2)) {
                    $feet = (int) $mHt2[1];
                    $inch = (int) $mHt2[2];
                    $totalInches = $feet * 12 + $inch;
                    $coreData['height_cm'] = round($totalInches * 2.54, 2);
                }
            }
            // Complexion fallback: वर्ण :- गोरा / सावळा / निमगोरा / निमगोटा इ.
            if (empty($coreData['complexion'])) {
                if (preg_match('/वर्ण\s*[:\-]?\s*([^\r\n]+)/u', $rawOcrTextForPhysical, $mCx)) {
                    $cx = trim($mCx[1]);
                    if ($cx !== '') {
                        $coreData['complexion'] = $cx;
                    }
                }
            }
        }

        // Preview-only: normalize birth_time text (e.g. "रात्री 09 वा.45 मि.") → "HH:MM" so basic_info time picker pre-fills.
        $btRaw = is_scalar($coreData['birth_time'] ?? null) ? trim((string) $coreData['birth_time']) : '';
        if ($btRaw !== '') {
            // Already normalized?
            if (! preg_match('/^\d{1,2}:\d{2}(\s*(AM|PM))?$/iu', $btRaw)) {
                $period = null; // 'AM' | 'PM' | null
                if (mb_stripos($btRaw, 'सकाळी') !== false) {
                    $period = 'AM';
                } elseif (mb_stripos($btRaw, 'दुपारी') !== false) {
                    $period = 'PM';
                } elseif (mb_stripos($btRaw, 'सायंकाळी') !== false || mb_stripos($btRaw, 'सायंकाळ') !== false) {
                    $period = 'PM';
                } elseif (mb_stripos($btRaw, 'रात्री') !== false || mb_stripos($btRaw, 'रात्रीचे') !== false) {
                    $period = 'PM';
                }

                // Extract hour + minute allowing Marathi digits + junk between.
                if (preg_match('/([०-९0-9]{1,2})[^\d०-९]+([०-९0-9]{1,2})/u', $btRaw, $mBt)) {
                    $toLatin = function (string $v): int {
                        $map = ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9'];
                        $out = '';
                        foreach (preg_split('//u', $v, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
                            $out .= $map[$ch] ?? $ch;
                        }

                        return (int) $out;
                    };
                    $h = $toLatin($mBt[1]);
                    $min = $toLatin($mBt[2]);
                    if ($h >= 0 && $h <= 23 && $min >= 0 && $min <= 59) {
                        // Convert to 24h based on period hint.
                        if ($period === 'PM' && $h < 12) {
                            $h += 12;
                        } elseif ($period === 'AM' && $h === 12) {
                            $h = 0;
                        }
                        $coreData['birth_time'] = sprintf('%02d:%02d', $h, $min);
                    }
                }
            }
        }

        // Preview-only: default mother_tongue_id = Marathi when biodata looks predominantly Marathi and mother_tongue_id is empty.
        if (empty($coreData['mother_tongue_id'] ?? null) && empty($intakeProfile->mother_tongue_id ?? null)) {
            $rawText = (string) ($intake->raw_ocr_text ?? '');
            if ($rawText === '' && ($previewRawTextSource ?? '') === 'ai_vision_cache') {
                $rawText = $rawOcrTextForPreview;
            }
            if ($rawText !== '') {
                // If app locale is Marathi OR there's at least one Devanagari char, assume Marathi.
                $locale = app()->getLocale();
                $hasDevanagari = preg_match('/[\x{0900}-\x{097F}]/u', $rawText) === 1;
                if ($locale === 'mr' || $hasDevanagari) {
                    $mt = \App\Models\MasterMotherTongue::where('is_active', true)
                        ->where('key', 'marathi')
                        ->first();
                    if ($mt) {
                        $coreData['mother_tongue_id'] = $mt->id;
                        if (isset($intakeProfile) && is_object($intakeProfile)) {
                            $intakeProfile->mother_tongue_id = $mt->id;
                        }
                    }
                }
            }
        }

        // Normalize horoscope text → master IDs so form dropdowns show correct selection (nakshatra_id, rashi_id, gan_id, nadi_id).
        $horoscopeData = $sections['horoscope']['data'] ?? [];
        if (is_array($horoscopeData) && ! empty($horoscopeData)) {
            $rows = isset($horoscopeData[0]) ? $horoscopeData : [$horoscopeData];
            $rows = app(\App\Services\Parsing\IntakeControlledFieldNormalizer::class)->normalizeHoroscopeRows($rows);
            if (! empty($rows)) {
                $sections['horoscope']['data'] = $rows;
                // Copy blood_group from horoscope to core when core has none, so physical engine can resolve blood_group_id.
                $firstHoroscope = is_array($rows[0]) ? $rows[0] : (array) $rows[0];
                $hg = $firstHoroscope['blood_group'] ?? null;
                if (is_scalar($hg) && trim((string) $hg) !== '' && (empty($coreData['blood_group']) || trim((string) $coreData['blood_group']) === '')) {
                    $sanitized = \App\Services\BiodataParserService::sanitizeBloodGroupValue(trim((string) $hg));
                    if ($sanitized !== null) {
                        $coreData['blood_group'] = $sanitized;
                    }
                }
            }
        }

        $intakeProfile = (object) [
            'full_name' => is_scalar($coreData['full_name'] ?? null) ? trim((string) $coreData['full_name']) : '',
            'date_of_birth' => is_scalar($coreData['date_of_birth'] ?? null) ? trim((string) $coreData['date_of_birth']) : null,
            'gender_id' => $coreData['gender_id'] ?? null,
            'birth_time' => is_scalar($coreData['birth_time'] ?? null) ? trim((string) $coreData['birth_time']) : '',
            'birth_city_id' => $coreData['birth_city_id'] ?? null,
            'birth_taluka_id' => $coreData['birth_taluka_id'] ?? null,
            'birth_district_id' => $coreData['birth_district_id'] ?? null,
            'birth_state_id' => $coreData['birth_state_id'] ?? null,
            'religion_id' => $coreData['religion_id'] ?? '',
            'caste_id' => $coreData['caste_id'] ?? '',
            'sub_caste_id' => $coreData['sub_caste_id'] ?? '',
            'religion_label' => '',
            'caste_label' => '',
            'subcaste_label' => '',
        ];
        // Centralized deterministic controlled-field normalization (no fuzzy/contains-based dropdown forcing).
        $coreData = app(\App\Services\Parsing\IntakeControlledFieldNormalizer::class)->normalizeCore($coreData);
        // Copy every other key from parsed core so form/engines see 100% of biodata (e.g. primary_contact_number, father_name, mother_name, height_cm, annual_income, birth_place string).
        foreach ($coreData as $k => $v) {
            if (! property_exists($intakeProfile, $k)) {
                $intakeProfile->{$k} = $v;
            }
        }
        // Updated coreData should also flow back into $sections so blade includes (e.g. physical-engine via :values="$coreData") see complexion_id, blood_group_id, etc.
        $sections['core']['data'] = $coreData;
        if (empty($intakeProfile->gender_id) && ! empty($coreData['gender_id'])) {
            $intakeProfile->gender_id = $coreData['gender_id'];
        }
        $intakeProfile->birthPlaceDisplay = '';
        if (! empty($intakeProfile->birth_city_id)) {
            $intakeProfile->birthPlaceDisplay = \App\Models\City::where('id', $intakeProfile->birth_city_id)->value('name') ?? '';
        }
        // Resolve birth_place string (from parser) to location IDs so Basic Info birth-place typeahead shows value.
        if (empty($intakeProfile->birth_city_id) && ! empty($intakeProfile->birth_place) && is_scalar($intakeProfile->birth_place)) {
            $birthPlaceStr = trim((string) $intakeProfile->birth_place);
            if ($birthPlaceStr !== '' && $birthPlaceStr !== \App\Services\Ocr\OcrSuggestionEngine::PLACEHOLDER_NOT_FOUND) {
                $cityQuery = \App\Models\City::where('name', 'like', $birthPlaceStr.'%');
                if (\Illuminate\Support\Facades\Schema::hasColumn((new \App\Models\City)->getTable(), 'name_mr')) {
                    $cityQuery->orWhere('name_mr', 'like', $birthPlaceStr.'%');
                }
                $city = $cityQuery->first();
                if ($city) {
                    $intakeProfile->birth_city_id = $city->id;
                    $intakeProfile->birth_taluka_id = $city->taluka_id;
                    $intakeProfile->birthPlaceDisplay = $city->name ?? '';
                    $taluka = $city->taluka;
                    if ($taluka) {
                        $intakeProfile->birth_district_id = $taluka->district_id ?? null;
                        $district = $taluka->district;
                        if ($district) {
                            $intakeProfile->birth_state_id = $district->state_id ?? null;
                        }
                    }
                } else {
                    $intakeProfile->birthPlaceDisplay = $birthPlaceStr;
                }
            }
        }
        $genders = \App\Models\MasterGender::where('is_active', true)->whereIn('key', ['male', 'female'])
            ->orderByRaw("CASE WHEN `key` = 'male' THEN 1 ELSE 2 END")->get();
        $relLabel = is_scalar($coreData['religion'] ?? null) ? trim((string) $coreData['religion']) : '';
        $casteLabel = is_scalar($coreData['caste'] ?? null) ? trim((string) $coreData['caste']) : '';
        $subLabel = is_scalar($coreData['sub_caste'] ?? null) ? trim((string) $coreData['sub_caste']) : '';

        // Special normalization for common Marathi pattern: "हिंदू- ९६ कूळी मराठा" →
        // religion = हिंदू, caste = मराठा, sub_caste = 96 कूळी
        if ($casteLabel !== '') {
            $hasMaratha = mb_stripos($casteLabel, 'मराठा') !== false;
            // Extract "96 कूळी" / "९६ कूळी" / "96 kuli" as sub_caste when present.
            if ($subLabel === '' &&
                preg_match('/(९६|96)\s*[कक][ुू]ळी|96\s*kuli/iu', $casteLabel, $m)
            ) {
                $subLabel = trim((string) $m[0]);
            }
            if ($hasMaratha) {
                $casteLabel = 'मराठा';
            }
        }
        $ph = \App\Services\Ocr\OcrSuggestionEngine::PLACEHOLDER_NOT_FOUND;
        $ph2 = \App\Services\Ocr\OcrSuggestionEngine::PLACEHOLDER_SELECT_REQUIRED;
        $resolver = app(\App\Services\MasterData\ReligionCasteSubCasteResolver::class);

        $canRel = $relLabel !== '' && $relLabel !== $ph && $relLabel !== $ph2;
        $canCas = $casteLabel !== '' && $casteLabel !== $ph && $casteLabel !== $ph2;
        $canSub = $subLabel !== '' && $subLabel !== $ph && $subLabel !== $ph2;

        $existingReligionId = is_numeric($intakeProfile->religion_id ?? null) ? (int) $intakeProfile->religion_id : null;
        $existingCasteId = is_numeric($intakeProfile->caste_id ?? null) ? (int) $intakeProfile->caste_id : null;
        $existingSubCasteId = is_numeric($intakeProfile->sub_caste_id ?? null) ? (int) $intakeProfile->sub_caste_id : null;

        $resolved = $resolver->resolve(
            $canRel ? $relLabel : null,
            $canCas ? $casteLabel : null,
            $canSub ? $subLabel : null,
            $existingReligionId,
            $existingCasteId,
            $existingSubCasteId
        );

        $thr = 0.86;
        if ($canRel) {
            if ($resolved['religion_id'] !== null && $resolved['religion_confidence'] >= $thr) {
                $rel = \App\Models\Religion::find($resolved['religion_id']);
                if ($rel) {
                    $intakeProfile->religion_id = $rel->id;
                    $intakeProfile->religion_label = $rel->label_en ?? $rel->label;
                }
            } else {
                $intakeProfile->religion_label = $relLabel;
            }
        }
        if ($canCas && $intakeProfile->religion_id) {
            if ($resolved['caste_id'] !== null && $resolved['caste_confidence'] >= $thr) {
                $c = \App\Models\Caste::find($resolved['caste_id']);
                if ($c) {
                    $intakeProfile->caste_id = $c->id;
                    $intakeProfile->caste_label = $c->label_en ?? $c->label;
                }
            } else {
                $intakeProfile->caste_label = $casteLabel;
            }
        }
        if ($canSub && $intakeProfile->caste_id) {
            if ($resolved['sub_caste_id'] !== null && $resolved['sub_caste_confidence'] >= $thr) {
                $s = \App\Models\SubCaste::find($resolved['sub_caste_id']);
                if ($s) {
                    $intakeProfile->sub_caste_id = $s->id;
                    $intakeProfile->subcaste_label = $s->label_en ?? $s->label;
                }
            } else {
                $intakeProfile->subcaste_label = $subLabel;
            }
        }
        if ($intakeProfile->religion_label === '' && $relLabel !== '') {
            $intakeProfile->religion_label = $relLabel;
        }
        if ($intakeProfile->caste_label === '' && $casteLabel !== '') {
            $intakeProfile->caste_label = $casteLabel;
        }
        if ($intakeProfile->subcaste_label === '' && $subLabel !== '') {
            $intakeProfile->subcaste_label = $subLabel;
        }
        // When we have IDs from normalizeIntakeCoreForStorage but labels were not set (e.g. Marathi text), set labels from DB so selector shows text.
        if (! empty($intakeProfile->religion_id) && empty($intakeProfile->religion_label)) {
            $r = \App\Models\Religion::find($intakeProfile->religion_id);
            if ($r) {
                $intakeProfile->religion_label = $r->label_en ?? $r->label;
            }
        }
        if (! empty($intakeProfile->caste_id) && empty($intakeProfile->caste_label)) {
            $c = \App\Models\Caste::find($intakeProfile->caste_id);
            if ($c) {
                $intakeProfile->caste_label = $c->label_en ?? $c->label;
            }
        }
        if (! empty($intakeProfile->sub_caste_id) && empty($intakeProfile->subcaste_label)) {
            $s = \App\Models\SubCaste::find($intakeProfile->sub_caste_id);
            if ($s) {
                $intakeProfile->subcaste_label = $s->label_en ?? $s->label;
            }
        }

        // Marital Engine (intake): resolve marital_status text → id; build profile/marriages/children for engine.
        $maritalKeys = ['never_married', 'divorced', 'annulled', 'separated', 'widowed'];
        $maritalStatuses = \App\Models\MasterMaritalStatus::where('is_active', true)
            ->whereIn('key', $maritalKeys)
            ->get()
            ->sortBy(fn ($s) => array_search($s->key, $maritalKeys, true) !== false ? array_search($s->key, $maritalKeys, true) : 999)
            ->values();
        if ($maritalStatuses->isEmpty()) {
            $maritalStatuses = \App\Models\MasterMaritalStatus::where('is_active', true)->get();
        }
        $approvalCore = $intake->approval_snapshot_json['core'] ?? $coreData;
        $maritalStatusId = $approvalCore['marital_status_id'] ?? null;
        if ($maritalStatusId === null || $maritalStatusId === '') {
            $maritalText = is_scalar($approvalCore['marital_status'] ?? null) ? trim((string) $approvalCore['marital_status']) : '';
            if ($maritalText !== '' && $maritalText !== \App\Services\Ocr\OcrSuggestionEngine::PLACEHOLDER_NOT_FOUND && $maritalText !== \App\Services\Ocr\OcrSuggestionEngine::PLACEHOLDER_SELECT_REQUIRED) {
                $maritalTextNorm = str_replace(' ', '_', mb_strtolower($maritalText));
                if ($maritalTextNorm === 'unmarried') {
                    $maritalTextNorm = 'never_married';
                }
                $ms = $maritalStatuses->first(fn ($s) => strcasecmp($s->label ?? '', $maritalText) === 0 || strcasecmp($s->key ?? '', $maritalText) === 0 || strcasecmp(str_replace(' ', '_', $s->label ?? ''), $maritalText) === 0 || strcasecmp($s->key ?? '', $maritalTextNorm) === 0);
                if ($ms) {
                    $maritalStatusId = $ms->id;
                }
            }
        }
        $childrenData = $sections['children']['data'] ?? [];
        $hasChildrenData = count($childrenData) > 0;
        if (($maritalStatusId === null || $maritalStatusId === '') && $hasChildrenData) {
            $rawText = $rawTextForPreviewEnhancements;
            if (preg_match('/घटस्फोट|divorce|divorced|separated/i', $rawText)) {
                $ms = $maritalStatuses->first(fn ($s) => ($s->key ?? '') === 'divorced');
                if ($ms) {
                    $maritalStatusId = $ms->id;
                }
            }
            if (($maritalStatusId === null || $maritalStatusId === '') && (preg_match('/विधवा|विधुर|widow|widowed|widower/i', $rawText))) {
                $ms = $maritalStatuses->first(fn ($s) => ($s->key ?? '') === 'widowed');
                if ($ms) {
                    $maritalStatusId = $ms->id;
                }
            }
            if (($maritalStatusId === null || $maritalStatusId === '') && (preg_match('/नाममात्र\s*घटस्फोट|annulled|annulment/i', $rawText))) {
                $ms = $maritalStatuses->first(fn ($s) => ($s->key ?? '') === 'annulled');
                if ($ms) {
                    $maritalStatusId = $ms->id;
                }
            }
            if (($maritalStatusId === null || $maritalStatusId === '') && preg_match('/separated/i', $rawText)) {
                $ms = $maritalStatuses->first(fn ($s) => ($s->key ?? '') === 'separated');
                if ($ms) {
                    $maritalStatusId = $ms->id;
                }
            }
        }
        if ($maritalStatusId === null || $maritalStatusId === '') {
            $ms = $maritalStatuses->first(fn ($s) => ($s->key ?? '') === 'never_married');
            if ($ms) {
                $maritalStatusId = $ms->id;
            }
        }
        $intakeProfile->marital_status_id = $maritalStatusId ?? '';
        $hasChildren = isset($approvalCore['has_children']) ? (int) $approvalCore['has_children'] : (count($childrenData) > 0 ? 1 : null);
        $intakeProfile->has_children = $hasChildren;
        // Contacts tab: self contact engine expects $self_contacts with phone_number + preference; seed it from primary_contact_number.
        $primaryContact = is_scalar($coreData['primary_contact_number'] ?? null) ? trim((string) $coreData['primary_contact_number']) : '';
        $self_contacts = [];
        if ($primaryContact !== '') {
            $digits = preg_replace('/\D/', '', $primaryContact);
            if ($digits !== '') {
                $self_contacts[] = (object) [
                    'phone_number' => $digits,
                    'contact_preference' => 'whatsapp',
                    'is_whatsapp' => true,
                ];
            }
        }
        $profileMarriages = collect();
        $marriagesFromSnapshot = $intake->approval_snapshot_json['marriages'] ?? $data['marriages'] ?? [];
        if (is_array($marriagesFromSnapshot) && isset($marriagesFromSnapshot[0])) {
            $profileMarriages = collect([(object) $marriagesFromSnapshot[0]]);
        } elseif (is_array($marriagesFromSnapshot) && ! isset($marriagesFromSnapshot[0])) {
            $profileMarriages = collect([(object) $marriagesFromSnapshot]);
        }
        $profileChildren = collect();
        foreach ($childrenData as $idx => $ch) {
            $row = is_array($ch) ? $ch : ['name' => $ch, 'dob' => '', 'gender' => '', 'age' => '', 'child_living_with_id' => ''];
            $profileChildren->push((object) [
                'id' => $row['id'] ?? null,
                'gender' => $row['gender'] ?? $row['child_gender'] ?? '',
                'age' => $row['age'] ?? $row['child_age'] ?? '',
                'child_living_with_id' => $row['child_living_with_id'] ?? '',
            ]);
        }
        $livingKeys = ['with_parent', 'with_other_parent', 'joint', 'other'];
        $childLivingWithOptions = \App\Models\MasterChildLivingWith::where('is_active', true)
            ->whereIn('key', $livingKeys)
            ->get()
            ->sortBy(fn ($o) => array_search($o->key, $livingKeys, true) !== false ? array_search($o->key, $livingKeys, true) : 999)
            ->values();
        if ($childLivingWithOptions->isEmpty()) {
            $childLivingWithOptions = \App\Models\MasterChildLivingWith::where('is_active', true)->get();
        }

        $rashis = \App\Models\MasterRashi::where('is_active', true)->get();
        $nakshatras = \App\Models\MasterNakshatra::where('is_active', true)->get();
        $gans = \App\Models\MasterGan::where('is_active', true)->get();
        $nadis = \App\Models\MasterNadi::where('is_active', true)->get();
        $yonis = \App\Models\MasterYoni::where('is_active', true)->get();
        $mangalDoshTypes = \App\Models\MasterMangalDoshType::where('is_active', true)->get();
        $varnas = \Illuminate\Support\Facades\DB::table('master_varnas')->where('is_active', true)->orderBy('label')->get();
        $vashyas = \Illuminate\Support\Facades\DB::table('master_vashyas')->where('is_active', true)->orderBy('label')->get();
        $rashiLords = \Illuminate\Support\Facades\DB::table('master_rashi_lords')->where('is_active', true)->orderBy('label')->get();
        $horoscopeRuleService = app(\App\Services\HoroscopeRuleService::class);
        $horoscopeRulesJson = $horoscopeRuleService->getRulesForFrontend();
        $horoscopeSource = $sections['horoscope']['data'] ?? ($intake->approval_snapshot_json['horoscope'] ?? []);
        $horoscopeRow = is_array($horoscopeSource) && isset($horoscopeSource[0]) ? $horoscopeSource[0] : (is_array($horoscopeSource) ? $horoscopeSource : []);
        $horoscopeRow = is_object($horoscopeRow) ? (array) $horoscopeRow : $horoscopeRow;
        // Compute dependency warnings (Rashi/Gan/Nadi/Yoni) for preview UI, same as wizard.
        $horoscopeValidation = $horoscopeRuleService->getValidationWarningsForUI($horoscopeRow);
        $horoscopeDependencyWarnings = $horoscopeValidation['warnings'] ?? [];

        // Centralized full form: same sections as wizard full; prefixes so form names are snapshot[...].
        $snapshot = $intake->approval_snapshot_json ?? $data;
        $corePrefix = 'snapshot[core]';
        $horoscopePrefix = 'snapshot[horoscope][0]';
        $siblingsPrefix = 'snapshot[siblings]';
        $relativesPaternalPrefix = 'snapshot[relatives_parents_family]';
        $relativesMaternalPrefix = 'snapshot[relatives_maternal_family]';
        $propertyPrefix = 'snapshot';
        $narrativePrefix = 'snapshot[extended_narrative]';
        $profile = $intakeProfile;
        $currentSection = 'full';
        $profileSiblings = collect($snapshot['siblings'] ?? [])->map(fn ($r) => (object) (is_array($r) ? $r : []));
        // Important: approval_snapshot_json may exist with partial data (e.g., core edits only).
        // For relatives building, always fall back to parsed_json ($data) when snapshot lacks relatives.
        $relativesFromSnapshot = $snapshot['relatives'] ?? ($data['relatives'] ?? []);
        // Preview-only safety-net: जर parsed relatives मध्ये एकही "चुलते" / paternal uncle नसेल,
        // पण raw biodata text मध्ये "चुलते" block स्पष्ट असेल तर, तेथून चुलते rows तयार करा.
        if (is_array($relativesFromSnapshot)) {
            $hasChulate = false;
            foreach ($relativesFromSnapshot as $rr) {
                $rrArr = is_array($rr) ? $rr : (array) $rr;
                $relKey = trim((string) ($rrArr['relation_type'] ?? $rrArr['relation'] ?? ''));
                if ($relKey === 'चुलते') {
                    $hasChulate = true;
                    break;
                }
            }
            if (! $hasChulate) {
                $raw = $rawTextForPreviewEnhancements;
                if ($raw !== '' && mb_strpos($raw, 'चुलते') !== false) {
                    $lines = preg_split("/\r\n|\r|\n/u", $raw) ?: [];
                    $inChulate = false;
                    $paternalFromRaw = [];
                    foreach ($lines as $ln) {
                        $line = trim($ln);
                        if ($line === '') {
                            if ($inChulate) {
                                // रिकामी line आली की chulate block संपला असे समजा.
                                break;
                            }

                            continue;
                        }
                        if (mb_strpos($line, 'चुलते') !== false) {
                            $inChulate = true;
                            // या ओळीतच "चुलते २- श्री. अनिल ..." असा first uncle असेल तर तोही capture कर.
                            $posSri = mb_strpos($line, 'श्री.');
                            if ($posSri !== false) {
                                $lineAfter = trim(mb_substr($line, $posSri));
                                if ($lineAfter !== '') {
                                    $name = null;
                                    $addr = null;
                                    if (preg_match('/श्री\.?\s*([^(]+?)\s*\(([^)]+)\)/u', $lineAfter, $mHead)) {
                                        $name = trim($mHead[1]);
                                        $addr = trim($mHead[2]);
                                    } elseif (preg_match('/श्री\.?\s*(.+)/u', $lineAfter, $mHead)) {
                                        $name = trim($mHead[1]);
                                    }
                                    if ($name !== null && $name !== '') {
                                        $paternalFromRaw[] = [
                                            'relation_type' => 'चुलते',
                                            'name' => $name,
                                            'occupation' => null,
                                            'address_line' => $addr ?? '',
                                            'contact_number' => null,
                                            'notes' => $addr ?? '',
                                        ];
                                    }
                                }
                            }

                            continue;
                        }
                        if (! $inChulate) {
                            continue;
                        }
                        // पुढे "मामा" / "इतर नातेवाईक" / इ. आले की block संपला असे समजा.
                        if (mb_strpos($line, 'मामा') !== false || mb_strpos($line, 'आजोळ') !== false || mb_strpos($line, 'इतर नातेवाईक') !== false) {
                            break;
                        }
                        if (mb_strpos($line, 'श्री.') === false) {
                            continue;
                        }
                        // Pattern: "श्री. अनिल भाऊराव पाटील ( वाघोली, पुणे)" → नाव + address.
                        $name = null;
                        $addr = null;
                        if (preg_match('/श्री\.?\s*([^(]+?)\s*\(([^)]+)\)/u', $line, $m)) {
                            $name = trim($m[1]);
                            $addr = trim($m[2]);
                        } elseif (preg_match('/श्री\.?\s*(.+)/u', $line, $m)) {
                            $name = trim($m[1]);
                        }
                        if ($name !== null && $name !== '') {
                            $paternalFromRaw[] = [
                                'relation_type' => 'चुलते',
                                'name' => $name,
                                'occupation' => null,
                                'address_line' => $addr ?? '',
                                'contact_number' => null,
                                'notes' => $addr ?? '',
                            ];
                        }
                    }
                    if (! empty($paternalFromRaw)) {
                        $relativesFromSnapshot = array_merge($relativesFromSnapshot, $paternalFromRaw);
                    }
                }
            }
        }
        $siblingRowsFromRelatives = $this->extractSiblingRowsFromParsedRelatives($relativesFromSnapshot);
        $profileSiblings = $this->mergeParsedSiblingsIntoProfileSiblings($profileSiblings, $siblingRowsFromRelatives);
        $relativesOnly = $this->excludeSiblingRelationsFromRelatives($relativesFromSnapshot);
        $hasSiblings = isset($snapshot['core']['has_siblings']) ? (bool) $snapshot['core']['has_siblings'] : $profileSiblings->isNotEmpty();
        [$builtPaternal, $builtMaternal, $dajiRows] = $this->partitionAndStructureRelativesForIntake($relativesOnly);

        // Prefer user-edited relatives_parents_family when it has meaningful names; otherwise fall back to parsed/built relatives.
        if (isset($snapshot['relatives_parents_family']) && is_array($snapshot['relatives_parents_family'])) {
            $fromSnapshotParents = collect($snapshot['relatives_parents_family'])->map(fn ($r) => (object) (is_array($r) ? $r : []));
            $hasAnyParentName = $fromSnapshotParents->contains(function ($row) {
                $name = trim((string) ($row->name ?? ''));

                return $name !== '';
            });
            $profileRelativesParentsFamily = $hasAnyParentName || $builtPaternal->isEmpty()
                ? $fromSnapshotParents
                : $builtPaternal;
        } else {
            $profileRelativesParentsFamily = $builtPaternal;
        }

        // Same strategy for maternal relatives: keep user edits when they added names; else use parsed/built data.
        if (isset($snapshot['relatives_maternal_family']) && is_array($snapshot['relatives_maternal_family'])) {
            $fromSnapshotMaternal = collect($snapshot['relatives_maternal_family'])->map(fn ($r) => (object) (is_array($r) ? $r : []));
            $hasAnyMaternalName = $fromSnapshotMaternal->contains(function ($row) {
                $name = trim((string) ($row->name ?? ''));

                return $name !== '';
            });
            $profileRelativesMaternalFamily = $hasAnyMaternalName || $builtMaternal->isEmpty()
                ? $fromSnapshotMaternal
                : $builtMaternal;
        } else {
            $profileRelativesMaternalFamily = $builtMaternal;
        }
        // Ensure one explicit Maternal address (Ajol) row from raw OCR when missing.
        if ($profileRelativesMaternalFamily->where('relation_type', 'maternal_address_ajol')->isEmpty()) {
            $rawAjolText = $rawTextForPreviewEnhancements;
            if ($rawAjolText !== '') {
                // आजोळ block नंतरच्या ओळी split करून, पहिली खऱ्या अर्थाने "पत्ता" असलेली line शोध.
                if (preg_match('/आजोळ[^\r\n]*(.*)$/um', $rawAjolText, $mHead)) {
                    $after = (string) $mHead[1];
                    $lines = preg_split("/\r\n|\r|\n/u", $after) ?: [];
                    $candidate = null;
                    foreach ($lines as $ln) {
                        $ln = trim($ln);
                        if ($ln === '') {
                            continue;
                        }
                        // "१) ..." / "२) ..." सारख्या purely नावाच्या ओळी skip कर.
                        if (preg_match('/^[०-९0-9]+\)/u', $ln)) {
                            continue;
                        }
                        // Address साठी typical संकेत: "मु.पो." किंवा "रा." किंवा "ता." / "जि."
                        if (mb_strpos($ln, 'मु.पो.') !== false || mb_strpos($ln, 'रा.') !== false || mb_strpos($ln, 'ता.') !== false || mb_strpos($ln, 'जि.') !== false) {
                            $candidate = $ln;
                            break;
                        }
                    }
                    $ajolLine = $candidate !== null ? trim($candidate) : '';
                    if ($ajolLine !== '') {
                        $profileRelativesMaternalFamily->push((object) [
                            'relation_type' => 'maternal_address_ajol',
                            'name' => '',
                            'occupation' => null,
                            'contact_number' => null,
                            'notes' => '',
                            'address_line' => $ajolLine,
                        ]);
                    }
                }
            }
        }
        // दाजी = बहिणीचा नवरा: merge into sibling panel (first sister's spouse) so it saves in the same place
        $profileSiblings = $this->mergeDajiIntoSiblings($profileSiblings, $dajiRows);
        // Raw OCR मधून उरलेली माहिती (भाऊचा पत्ता/नोकरी, बहिणीचं नाव) siblings मध्ये भरा — additive only.
        $profileSiblings = $this->enrichSiblingsFromRawText($profileSiblings, $rawTextForPreviewEnhancements);
        $profile_property_summary = $snapshot['property_summary'] ?? null;
        $profile_property_assets = collect($snapshot['property_assets'] ?? []);
        $profile_horoscope_data = is_array($horoscopeRow) ? (object) $horoscopeRow : $horoscopeRow;
        // Education & career history from snapshot so intake preview form pre-fills parsed rows.
        $profileEducation = collect($snapshot['education_history'] ?? [])->map(
            fn ($r) => (object) (is_array($r) ? $r : (array) $r)
        );
        $profileCareer = collect($snapshot['career_history'] ?? [])->map(
            fn ($r) => (object) (is_array($r) ? $r : (array) $r)
        );
        // Preview-only: derive highest_education / specialization / company_name / work location core fields from history rows when missing.
        if (empty($coreData['highest_education']) && $profileEducation->isNotEmpty()) {
            $firstEdu = (array) $profileEducation->first();
            $degreeCode = null;
            $degreeText = trim((string) ($firstEdu['degree'] ?? ''));
            $instText = trim((string) ($firstEdu['institution'] ?? ''));

            // Try to resolve to a concrete EducationDegree row:
            // 1) title exactly matches "BE - Computer Engineering" style composite,
            // 2) else by code/title = raw degreeText.
            if ($degreeText !== '') {
                $candidateTitle = $instText !== '' ? ($degreeText.' - '.$instText) : $degreeText;
                $deg = \App\Models\EducationDegree::query()
                    ->where('title', $candidateTitle)
                    ->orWhere(function ($q) use ($degreeText) {
                        $q->where('code', $degreeText)
                            ->orWhere('title', $degreeText);
                    })
                    ->first();

                // Fallback: normalize codes/titles (remove dots/spaces etc.) and match common patterns.
                if (! $deg) {
                    $normalize = static function (string $v): string {
                        $v = strtoupper($v);

                        return preg_replace('/[^A-Z]/', '', $v) ?? '';
                    };
                    $needle = $normalize($degreeText);
                    if ($needle !== '') {
                        $allDegrees = \App\Models\EducationDegree::all();
                        $matches = $allDegrees->filter(function ($row) use ($needle, $normalize) {
                            $codeNorm = $normalize((string) ($row->code ?? ''));
                            $titleNorm = $normalize((string) ($row->title ?? ''));

                            return $codeNorm === $needle || $titleNorm === $needle;
                        });
                        if ($matches->count() === 1) {
                            $deg = $matches->first();
                        } elseif ($matches->count() === 0 && $needle === 'BE') {
                            // Special-case: BE → pick B.E / B.Tech under Engineering.
                            $deg = \App\Models\EducationDegree::query()
                                ->whereHas('category', fn ($q) => $q->where('name', 'Engineering'))
                                ->where(function ($q) {
                                    $q->where('code', 'like', '%B.E%')
                                        ->orWhere('title', 'like', '%B.E%')
                                        ->orWhere('code', 'like', '%B.Tech%')
                                        ->orWhere('title', 'like', '%B.Tech%');
                                })
                                ->orderBy('sort_order')
                                ->first();
                        }
                    }
                }

                if ($deg) {
                    $degreeCode = $deg->code;
                }
            }

            // highest_education: prefer canonical code, else fall back to plain degree text.
            $coreData['highest_education'] = $degreeCode ?? $degreeText;

            // Specialization: if empty, use institution (e.g. "Computer Engineering") or explicit specialization field.
            if (empty($coreData['specialization'])) {
                if (! empty($firstEdu['specialization'] ?? null)) {
                    $coreData['specialization'] = trim((string) $firstEdu['specialization']);
                } elseif ($instText !== '') {
                    $coreData['specialization'] = $instText;
                }
            }
        }
        // Mirror derived education fields back onto profile object so shared engines (education-occupation-income-engine) can read them.
        if (! empty($coreData['highest_education'] ?? null)) {
            $intakeProfile->highest_education = $coreData['highest_education'];
        }
        if (! empty($coreData['specialization'] ?? null)) {
            $intakeProfile->specialization = $coreData['specialization'];
        }

        if ($profileCareer->isNotEmpty()) {
            $firstJob = (array) $profileCareer->first();
            if (empty($coreData['company_name']) && ! empty($firstJob['company'] ?? null)) {
                $coreData['company_name'] = trim((string) $firstJob['company']);
            }
            // Preview-only: Work location text from career_history.location when missing.
            if (empty($coreData['work_location_text'] ?? null) && ! empty($firstJob['location'] ?? null)) {
                $coreData['work_location_text'] = trim((string) $firstJob['location']);
            }
        }
        if (! empty($coreData['company_name'] ?? null)) {
            $intakeProfile->company_name = $coreData['company_name'];
        }
        if (! empty($coreData['work_location_text'] ?? null)) {
            $intakeProfile->work_location_text = $coreData['work_location_text'];
        }
        // Parents home address + mother contact number from parsed/AI snapshot.
        $addresses = $snapshot['addresses'] ?? [];
        if (is_array($addresses)) {
            $firstAddr = isset($addresses[0]) ? (is_array($addresses[0]) ? $addresses[0] : (array) $addresses[0]) : null;
            $rawAddr = $firstAddr && isset($firstAddr['raw']) ? trim((string) $firstAddr['raw']) : '';
            $base = $firstAddr && isset($firstAddr['address_line']) ? trim((string) $firstAddr['address_line']) : '';
            $talukaText = is_scalar($firstAddr['taluka'] ?? null) ? trim((string) $firstAddr['taluka']) : '';
            $districtText = is_scalar($firstAddr['district'] ?? null) ? trim((string) $firstAddr['district']) : '';

            if ($rawAddr !== '') {
                // Start from raw address, then softly append taluka/district if missing so कि parsed JSON मधली extra माहिती हरवू नये.
                $addrLine = $rawAddr;
                if ($talukaText !== '' && mb_strpos($addrLine, $talukaText) === false) {
                    $addrLine .= ($addrLine !== '' ? ', ' : '').'ता. '.$talukaText;
                }
                if ($districtText !== '' && mb_strpos($addrLine, $districtText) === false) {
                    $addrLine .= ($addrLine !== '' ? ', ' : '').'जि. '.$districtText;
                }
            } else {
                $parts = [];
                if ($base !== '') {
                    $parts[] = $base;
                }
                if ($talukaText !== '') {
                    $parts[] = 'ता. '.$talukaText;
                }
                if ($districtText !== '') {
                    $parts[] = 'जि. '.$districtText;
                }
                $addrLine = implode(', ', $parts);
            }
            // Global fallback: जर addresses[0] मधे taluka रिकामा असेल पण raw_ocr_text मधे "ता. X" असेल आणि तो address मध्ये नसेल, तर insert कर.
            if ($talukaText === '') {
                $rawOcr = $rawTextForPreviewEnhancements;
                if (
                    $rawOcr !== '' &&
                    mb_strpos($addrLine, 'ता.') === false &&
                    // "ता. माळशिरस" किंवा "ता.- माळशिरस" दोन्ही capture होण्यासाठी hyphen skip कर.
                    preg_match('/ता\.\s*[-–—]?\s*([^\s,\.\r\n]+)/u', $rawOcr, $mTal)
                ) {
                    $fromTextTaluka = trim($mTal[1]);
                    if ($fromTextTaluka !== '' && mb_strpos($addrLine, $fromTextTaluka) === false) {
                        $insert = 'ता. '.$fromTextTaluka;
                        // जर address मध्ये आधीच "जि." असेल, तर गावानंतर पण जिल्ह्याआधी taluka insert कर (गाव, ता., जि. असा sequence).
                        $posJilha = mb_strpos($addrLine, 'जि.');
                        if ($posJilha !== false) {
                            $before = rtrim(mb_substr($addrLine, 0, $posJilha), ' ,');
                            $after = ltrim(mb_substr($addrLine, $posJilha), ' ,');
                            $addrLine = $before.', '.$insert.', '.$after;
                        } else {
                            // अन्यथा शेवटी append कर.
                            $addrLine .= ($addrLine !== '' ? ', ' : '').$insert;
                        }
                    }
                }
            }
            if ($addrLine !== '' && empty($profile->address_line)) {
                $profile->address_line = $addrLine;
            }
        }
        $primaryContact = $snapshot['core']['primary_contact_number'] ?? null;
        if (is_scalar($primaryContact)) {
            $primaryContact = preg_replace('/\D+/', '', (string) $primaryContact) ?: null;
            if ($primaryContact && empty($profile->mother_contact_1)) {
                $profile->mother_contact_1 = $primaryContact;
            }
        }
        $extendedNarrative = $snapshot['extended_narrative'] ?? ($sections['narrative']['data'] ?? null);
        $extendedAttrs = is_array($extendedNarrative) ? (object) $extendedNarrative : (is_object($extendedNarrative) ? $extendedNarrative : (object) ['narrative_about_me' => '', 'narrative_expectations' => '', 'additional_notes' => '']);
        $prefs = $snapshot['preferences'] ?? [];
        $prefRow = is_array($prefs) && isset($prefs[0]) ? $prefs[0] : (is_array($prefs) ? $prefs : []);
        $preferenceCriteria = (object) $prefRow;
        $preferredDistrictIds = $prefRow['preferred_district_ids'] ?? [];
        $preferredReligionIds = $prefRow['preferred_religion_ids'] ?? [];
        $preferredCasteIds = $prefRow['preferred_caste_ids'] ?? [];
        $assetTypes = \App\Models\MasterAssetType::where('is_active', true)->get();
        $ownershipTypes = \App\Models\MasterOwnershipType::where('is_active', true)->get();
        $relationTypesParentsFamily = [
            ['value' => 'native_place', 'label' => 'Native Place'],
            ['value' => 'paternal_grandfather', 'label' => 'Paternal Grandfather'],
            ['value' => 'paternal_grandmother', 'label' => 'Paternal Grandmother'],
            ['value' => 'paternal_uncle', 'label' => 'Paternal Uncle (chulte)'],
            ['value' => 'wife_paternal_uncle', 'label' => 'Wife of Paternal Uncle'],
            ['value' => 'paternal_aunt', 'label' => 'Paternal Aunt (atya)'],
            ['value' => 'husband_paternal_aunt', 'label' => 'Husband of Paternal Aunt'],
            ['value' => 'Cousin', 'label' => 'Cousin'],
        ];
        $relationTypesMaternalFamily = [
            ['value' => 'maternal_address_ajol', 'label' => 'Maternal address (Ajol)'],
            ['value' => 'maternal_grandfather', 'label' => 'Maternal Grandfather'],
            ['value' => 'maternal_grandmother', 'label' => 'Maternal Grandmother'],
            ['value' => 'maternal_uncle', 'label' => 'Maternal Uncle (mama)'],
            ['value' => 'wife_maternal_uncle', 'label' => 'Wife of Maternal Uncle'],
            ['value' => 'maternal_aunt', 'label' => 'Maternal Aunt (mavshi)'],
            ['value' => 'husband_maternal_aunt', 'label' => 'Husband of Maternal Aunt'],
            ['value' => 'maternal_cousin', 'label' => 'Maternal Cousin'],
        ];
        $familyTypes = \App\Models\MasterFamilyType::where('is_active', true)->get();
        $currencies = \App\Models\MasterIncomeCurrency::where('is_active', true)->get();
        $complexions = \App\Models\MasterComplexion::where('is_active', true)->orderBy('id')->get();
        $bloodGroups = \App\Models\MasterBloodGroup::where('is_active', true)->orderBy('id')->get();
        $physicalBuilds = \App\Models\MasterPhysicalBuild::where('is_active', true)->orderBy('id')->get();
        $diets = \App\Models\MasterDiet::where('is_active', true)->orderBy('sort_order')->get();
        $smokingStatuses = \App\Models\MasterSmokingStatus::where('is_active', true)->orderBy('sort_order')->get();
        $drinkingStatuses = \App\Models\MasterDrinkingStatus::where('is_active', true)->orderBy('sort_order')->get();
        $motherTongues = \App\Models\MasterMotherTongue::where('is_active', true)->orderBy('sort_order')->orderBy('label')->get(['id', 'key', 'label']);
        $religions = \App\Models\Religion::where('is_active', true)->orderBy('label')->get(['id', 'label']);
        $rashiAshtakootaJson = [];
        $talukasByDistrict = \App\Models\Taluka::all()->groupBy('district_id')->map(fn ($col) => $col->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values()->toArray())->toArray();
        $districtsByState = \App\Models\District::all()->groupBy('state_id')->map(fn ($col) => $col->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values()->toArray())->toArray();
        $stateIdToCountryId = \App\Models\State::all()->pluck('country_id', 'id')->toArray();
        $otherRelativesText = is_scalar($snapshot['other_relatives_text'] ?? null) ? (string) $snapshot['other_relatives_text'] : (is_scalar($snapshot['core']['other_relatives_text'] ?? null) ? (string) $snapshot['core']['other_relatives_text'] : '');
        // Fallback: old parsed_json had "इतर नातेवाईक" in relatives[] as relation_type इतर/Other — show that notes in Other Relatives textarea so user sees it and it can be saved.
        if ($otherRelativesText === '' && ! empty($snapshot['relatives']) && is_array($snapshot['relatives'])) {
            foreach ($snapshot['relatives'] as $rel) {
                $r = is_array($rel) ? $rel : (array) $rel;
                $rt = trim((string) ($r['relation_type'] ?? ''));
                if ($rt === 'इतर' || $rt === 'Other') {
                    $notes = trim((string) ($r['notes'] ?? ''));
                    if ($notes !== '') {
                        $otherRelativesText = preg_replace('/^.*?इतर\s*नातेवाईक\s*[:-]\s*/u', '', $notes);
                        $otherRelativesText = trim(preg_replace('/\s+/u', ' ', $otherRelativesText));
                        break;
                    }
                }
            }
        }
        $birthPlaceDisplay = $profile->birthPlaceDisplay ?? '';

        $ocrPresetFeedback = null;

        $ocrDebugMeta = null;
        $ocrDriverCapability = null;
        if (config('app.debug') && (bool) config('ocr.preprocessing.debug_expose_derived_notice', true)) {
            $ocrDebugMeta = $this->buildPreviewOcrDebugMeta($intake);
        }

        $manualPreparedSvc = app(IntakeManualOcrPreparedService::class);
        $uploadRel = (string) ($intake->file_path ?? '');
        $uploadExt = strtolower(pathinfo($uploadRel, PATHINFO_EXTENSION));
        $manualCropEligible = $uploadRel !== ''
            && in_array($uploadExt, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
        $manualCropOriginalUrl = $manualCropEligible ? route('intake.biodata-original', $intake) : null;
        $manualPreparedExists = $manualPreparedSvc->exists($intake);

        $autoCropSuggestion = null;

        $ocrQualityEvaluation = Cache::get('intake.parse_ocr_quality.'.$intake->id);
        if (! is_array($ocrQualityEvaluation)) {
            $ocrQualityEvaluation = null;
        }

        $showOcrLowQualityWarning = (bool) ($ocrQualityEvaluation['is_low'] ?? false)
            && $manualCropEligible
            && ! $manualPreparedExists;

        return view('intake.preview', compact(
            'intake',
            'rawOcrTextForPreview',
            'previewRawTextSource',
            'previewParseProvenance',
            'ocrPresetFeedback',
            'ocrDebugMeta',
            'ocrDriverCapability',
            'manualCropEligible',
            'manualCropOriginalUrl',
            'manualPreparedExists',
            'autoCropSuggestion',
            'ocrQualityEvaluation',
            'showOcrLowQualityWarning',
            'sections',
            'confidenceMap',
            'criticalFields',
            'missingCriticalFields',
            'requiredCorrectionFields',
            'warningFields',
            'data',
            'sectionSourceKeys',
            'suggestionMap',
            'placeholderNotFound',
            'placeholderSelectRequired',
            'intakeProfile',
            'genders',
            'maritalStatuses',
            'profileMarriages',
            'profileChildren',
            'childLivingWithOptions',
            'rashis',
            'nakshatras',
            'gans',
            'nadis',
            'yonis',
            'mangalDoshTypes',
            'varnas',
            'vashyas',
            'rashiLords',
            'horoscopeRulesJson',
            'horoscopeDependencyWarnings',
            'corePrefix',
            'horoscopePrefix',
            'siblingsPrefix',
            'relativesPaternalPrefix',
            'relativesMaternalPrefix',
            'propertyPrefix',
            'narrativePrefix',
            'profile',
            'currentSection',
            'profileSiblings',
            'hasSiblings',
            'profileRelativesParentsFamily',
            'profileRelativesMaternalFamily',
            'profile_property_summary',
            'profile_property_assets',
            'profile_horoscope_data',
            'extendedAttrs',
            'preferenceCriteria',
            'preferredDistrictIds',
            'preferredReligionIds',
            'preferredCasteIds',
            'assetTypes',
            'ownershipTypes',
            'relationTypesParentsFamily',
            'relationTypesMaternalFamily',
            'profileEducation',
            'profileCareer',
            'familyTypes',
            'currencies',
            'complexions',
            'bloodGroups',
            'physicalBuilds',
            'diets',
            'smokingStatuses',
            'drinkingStatuses',
            'motherTongues',
            'religions',
            'rashiAshtakootaJson',
            'talukasByDistrict',
            'districtsByState',
            'stateIdToCountryId',
            'otherRelativesText',
            'birthPlaceDisplay'
        ));
    }

    /**
     * Re-run parse on this intake so updated parser rules (height, religion/caste, etc.) apply.
     * Sets parse_status = pending and dispatches ParseIntakeJob with forceRecompute.
     * User must own the intake or be admin (same as preview).
     */
    public function reparse(BiodataIntake $intake)
    {
        $isOwner = (int) $intake->uploaded_by === (int) auth()->id();
        $isAdmin = auth()->user()?->isAnyAdmin() ?? false;
        if (! $isOwner && ! $isAdmin) {
            abort(403, __('intake.only_preview_own'));
        }
        if ($intake->approved_by_user) {
            return redirect()->route('intake.preview', $intake)
                ->with('info', 'या intake चे अप्रूव्हल झाले आहे; पुन्हा पार्स करता येत नाही.');
        }
        $manualPreparedSvc = app(IntakeManualOcrPreparedService::class);
        $hasStoredOcr = $intake->raw_ocr_text !== null && trim((string) $intake->raw_ocr_text) !== '';
        if (! $hasStoredOcr && ! $manualPreparedSvc->exists($intake)) {
            return redirect()->route('intake.preview', $intake)
                ->with('error', __('intake.reparse_requires_ocr_or_manual'));
        }

        $this->forgetIntakeParseOcrDebugCache($intake);
        $intake->update(['parse_status' => 'pending']);
        ParseIntakeJob::dispatch($intake->id, true);

        return redirect()->route('intake.index')
            ->with('success', 'पुन्हा पार्स चालू केले. काही सेकंदांनी या intake चे प्रिव्ह्यू पुन्हा उघडा — अद्ययावत माहिती दिसेल.');
    }

    /**
     * Approve intake. Uses edited snapshot from form when present; else parsed_json.
     * No profile update here; IntakeApprovalService only updates biodata_intakes.
     */
    public function approve(Request $request, BiodataIntake $intake)
    {
        if ((int) $intake->uploaded_by !== (int) auth()->id()) {
            abort(403, __('intake.only_approve_own'));
        }
        if (! session('preview_seen_'.$intake->id)) {
            abort(403);
        }

        $snapshot = $request->input('snapshot');
        if (is_array($snapshot)) {
            $base = is_array($intake->parsed_json) ? $intake->parsed_json : [];
            // Centralized full_form: contacts, education_history, career_history, siblings, other_relatives_text may submit at top level.
            if (is_array($request->input('contacts'))) {
                $snapshot['contacts'] = $request->input('contacts');
            }
            if (is_array($request->input('siblings'))) {
                $snapshot['siblings'] = $request->input('siblings');
            }
            if ($request->has('has_siblings')) {
                $core = $snapshot['core'] ?? [];
                if (is_array($core)) {
                    $core['has_siblings'] = $request->input('has_siblings');
                    $snapshot['core'] = $core;
                }
            }
            if (is_array($request->input('education_history'))) {
                $snapshot['education_history'] = $request->input('education_history');
            }
            if (is_array($request->input('career_history'))) {
                $snapshot['career_history'] = $request->input('career_history');
            }
            if ($request->has('other_relatives_text')) {
                $txt = trim((string) $request->input('other_relatives_text', ''));
                $snapshot['other_relatives_text'] = $txt !== '' ? $txt : null;
                $core = $snapshot['core'] ?? [];
                if (is_array($core)) {
                    $core['other_relatives_text'] = $snapshot['other_relatives_text'];
                    $snapshot['core'] = $core;
                }
            }
            $core = $snapshot['core'] ?? [];
            if (is_array($core)) {
                // Income engine submits at top level (namePrefix "income") — merge into core so normalize maps to annual_income.
                foreach (['income_amount', 'income_value_type', 'income_private', 'income_period', 'income_normalized_annual_amount', 'income_min_amount', 'income_max_amount', 'income_currency_id', 'family_income_amount', 'family_income_value_type', 'family_income_private', 'family_income_period', 'family_income_normalized_annual_amount', 'family_income_min_amount', 'family_income_max_amount'] as $k) {
                    if ($request->has($k)) {
                        $core[$k] = $request->input($k);
                    }
                }
                if ($request->has('primary_contact_number')) {
                    $core['primary_contact_number'] = $request->input('primary_contact_number');
                }
                if ($request->has('primary_contact_number_2')) {
                    $core['primary_contact_number_2'] = $request->input('primary_contact_number_2');
                }
                if ($request->has('primary_contact_number_3')) {
                    $core['primary_contact_number_3'] = $request->input('primary_contact_number_3');
                }
                if ($request->has('primary_contact_whatsapp')) {
                    $core['primary_contact_whatsapp'] = $request->input('primary_contact_whatsapp');
                }
                if ($request->has('primary_contact_whatsapp_2')) {
                    $core['primary_contact_whatsapp_2'] = $request->input('primary_contact_whatsapp_2');
                }
                if ($request->has('primary_contact_whatsapp_3')) {
                    $core['primary_contact_whatsapp_3'] = $request->input('primary_contact_whatsapp_3');
                }
                $snapshot['core'] = $core;
            }
            // Partner preferences: about_preferences section may submit at top level (preferred_*, preferred_cities, etc.).
            $prefKeys = ['preferred_age_min', 'preferred_age_max', 'preferred_education', 'preferred_city_id', 'preferred_income_min', 'preferred_income_max', 'preferred_caste', 'preferred_city'];
            $hasPref = false;
            foreach ($prefKeys as $pk) {
                if ($request->has($pk)) {
                    $hasPref = true;
                    break;
                }
            }
            if ($hasPref || $request->has('preferred_district_ids') || $request->has('preferred_religion_ids') || $request->has('preferred_caste_ids') || $request->has('preferred_cities')) {
                $prefRow = $snapshot['preferences'][0] ?? [];
                if (! is_array($prefRow)) {
                    $prefRow = [];
                }
                foreach ($prefKeys as $pk) {
                    if ($request->has($pk)) {
                        $prefRow[$pk] = $request->input($pk);
                    }
                }
                if (is_array($request->input('preferred_district_ids'))) {
                    $prefRow['preferred_district_ids'] = $request->input('preferred_district_ids');
                }
                if (is_array($request->input('preferred_religion_ids'))) {
                    $prefRow['preferred_religion_ids'] = $request->input('preferred_religion_ids');
                }
                if (is_array($request->input('preferred_caste_ids'))) {
                    $prefRow['preferred_caste_ids'] = $request->input('preferred_caste_ids');
                }
                if (is_array($request->input('preferred_cities'))) {
                    $prefRow['preferred_cities'] = $request->input('preferred_cities');
                }
                $snapshot['preferences'] = [$prefRow];
            }
            $snapshot = $this->normalizeApprovalSnapshot(array_merge($base, $snapshot));
            $core = $snapshot['core'] ?? [];
            if (is_array($core)) {
                // Ensure birth_place text from parsed base is in core when form didn't send it (birth place typeahead has no name for display-only input).
                $baseCore = $base['core'] ?? [];
                if ((empty($core['birth_place']) || ! is_scalar($core['birth_place'])) && ! empty($baseCore['birth_place']) && is_scalar($baseCore['birth_place']) && trim((string) $baseCore['birth_place']) !== '') {
                    $core['birth_place'] = trim((string) $baseCore['birth_place']);
                    $snapshot['core'] = $core;
                }
                // Remove parser noise "तपासा" from full_name (e.g. from "तपासा आणि सुधारा" form title leaking into parsed name).
                if (isset($core['full_name']) && is_string($core['full_name'])) {
                    $cleaned = preg_replace('/\s*तपासा\s*/u', ' ', $core['full_name']);
                    $cleaned = preg_replace('/\s+/u', ' ', trim($cleaned));
                    if ($cleaned !== $core['full_name']) {
                        $core['full_name'] = $cleaned;
                        $snapshot['core'] = $core;
                    }
                }
            }
            $core = $snapshot['core'] ?? [];
            // MutationService expects marital_status_id on each profile_marriages row; inject from core.
            if (! empty($snapshot['core']['marital_status_id']) && is_array($snapshot['marriages'] ?? null)) {
                foreach (array_keys($snapshot['marriages']) as $i) {
                    if (is_array($snapshot['marriages'][$i])) {
                        $snapshot['marriages'][$i]['marital_status_id'] = $snapshot['core']['marital_status_id'];
                    }
                }
            }
            // Build birth_place from core for MutationService (same shape as wizard buildBasicInfoSnapshot).
            $core = $snapshot['core'] ?? [];
            if (! empty($core['birth_city_id']) || ! empty($core['birth_state_id'])) {
                $snapshot['birth_place'] = [
                    'city_id' => isset($core['birth_city_id']) ? (int) $core['birth_city_id'] : null,
                    'taluka_id' => isset($core['birth_taluka_id']) ? (int) $core['birth_taluka_id'] : null,
                    'district_id' => isset($core['birth_district_id']) ? (int) $core['birth_district_id'] : null,
                    'state_id' => isset($core['birth_state_id']) ? (int) $core['birth_state_id'] : null,
                ];
            } elseif (! empty($core['birth_place']) && is_scalar($core['birth_place']) && trim((string) $core['birth_place']) !== '') {
                // Resolve birth place text (e.g. "माळीनगर. ता.- माळशिरस, जि.सोलापूर") to IDs so edit shows place.
                $birthStr = trim((string) $core['birth_place']);
                $firstPart = trim(preg_replace('/[\s.\-,].*$/u', '', $birthStr));
                if ($firstPart !== '') {
                    $cityQuery = \App\Models\City::where('name', 'like', $firstPart.'%');
                    if (\Illuminate\Support\Facades\Schema::hasColumn((new \App\Models\City)->getTable(), 'name_mr')) {
                        $cityQuery->orWhere('name_mr', 'like', $firstPart.'%');
                    }
                    $city = $cityQuery->first();
                } else {
                    $city = null;
                }
                if ($city) {
                    $snapshot['birth_place'] = [
                        'city_id' => $city->id,
                        'taluka_id' => $city->taluka_id ?? null,
                        'district_id' => $city->taluka?->district_id ?? null,
                        'state_id' => $city->taluka?->district?->state_id ?? null,
                    ];
                    $core['birth_city_id'] = $city->id;
                    $core['birth_taluka_id'] = $city->taluka_id;
                    if ($city->taluka) {
                        $core['birth_district_id'] = $city->taluka->district_id;
                        if ($city->taluka->district) {
                            $core['birth_state_id'] = $city->taluka->district->state_id;
                        }
                    }
                    $core['birth_place_text'] = $birthStr;
                    $snapshot['core'] = $core;
                } else {
                    // City not in DB — store raw text so wizard can show it via birth_place_text.
                    $core['birth_place_text'] = $birthStr;
                    $snapshot['core'] = $core;
                }
            }
        } else {
            $snapshot = null;
        }

        $result = app(IntakeApprovalService::class)->approve($intake, (int) auth()->id(), $snapshot);

        return redirect()->route('intake.status', $intake->id)
            ->with('success', __('intake.approved_successfully'))
            ->with('mutation_result', $result);
    }

    /**
     * Ensure snapshot has SSOT top-level keys (all present, empty array when missing).
     * Horoscope: array of rows for MutationService; if submitted as array use it, else [].
     * property_summary, extended_narrative remain scalar.
     */
    private function normalizeApprovalSnapshot(array $snapshot): array
    {
        $keys = [
            'core',
            'contacts',
            'children',
            'marriages',
            'siblings',
            'education_history',
            'career_history',
            'addresses',
            'relatives',
            'property_summary',
            'property_assets',
            'horoscope',
            'preferences',
            'extended_narrative',
            'confidence_map',
        ];
        $scalarKeys = ['property_summary', 'extended_narrative'];
        $out = [];
        foreach ($keys as $k) {
            if (in_array($k, $scalarKeys, true)) {
                $out[$k] = array_key_exists($k, $snapshot) ? $snapshot[$k] : null;
            } elseif ($k === 'horoscope') {
                $out[$k] = isset($snapshot[$k]) && is_array($snapshot[$k]) ? $snapshot[$k] : [];
            } else {
                $out[$k] = isset($snapshot[$k]) && is_array($snapshot[$k]) ? $snapshot[$k] : [];
            }
        }
        // Centralized full_form (intake) sends relatives_parents_family + relatives_maternal_family; MutationService expects single "relatives" array.
        $paternal = $this->normalizeRelativesRows(isset($snapshot['relatives_parents_family']) && is_array($snapshot['relatives_parents_family']) ? $snapshot['relatives_parents_family'] : []);
        $maternal = $this->normalizeRelativesRows(isset($snapshot['relatives_maternal_family']) && is_array($snapshot['relatives_maternal_family']) ? $snapshot['relatives_maternal_family'] : []);
        if (! empty($paternal) || ! empty($maternal)) {
            $out['relatives'] = array_merge($paternal, $maternal);
        }
        // Contacts: ensure phone_number for sync (legacy "number" from parsed/old forms).
        if (isset($out['contacts']) && is_array($out['contacts'])) {
            foreach ($out['contacts'] as $i => $row) {
                if (is_array($row) && isset($row['number']) && empty($row['phone_number'])) {
                    $out['contacts'][$i]['phone_number'] = $row['number'];
                }
            }
        }

        // Sync primary contact between core and contacts (no new numbers, only reuse existing).
        if (is_array($out['core'])) {
            $corePrimary = $out['core']['primary_contact_number'] ?? null;
            $contacts = $out['contacts'] ?? [];
            if (! is_array($contacts)) {
                $contacts = [];
            }

            // If core has primary number, ensure contacts[0] uses the same.
            if ($corePrimary !== null && $corePrimary !== '') {
                if (isset($contacts[0]) && is_array($contacts[0])) {
                    $contacts[0]['phone_number'] = $corePrimary;
                    $contacts[0]['is_primary'] = $contacts[0]['is_primary'] ?? 1;
                    $contacts[0]['relation_type'] = $contacts[0]['relation_type'] ?? 'self';
                    $contacts[0]['contact_name'] = $contacts[0]['contact_name'] ?? 'Primary';
                } else {
                    $contacts[0] = [
                        'phone_number' => $corePrimary,
                        'relation_type' => 'self',
                        'contact_name' => 'Primary',
                        'is_primary' => 1,
                    ];
                }
            } else {
                // If core primary empty but a contact is marked primary, copy that back to core.
                foreach ($contacts as $row) {
                    if (is_array($row) && ! empty($row['phone_number']) && ! empty($row['is_primary'])) {
                        $out['core']['primary_contact_number'] = $row['phone_number'];
                        break;
                    }
                }
            }

            $out['contacts'] = array_values($contacts);
        }

        // Ensure no contact row has null/empty contact_name (DB NOT NULL); default to 'Self'.
        if (isset($out['contacts']) && is_array($out['contacts'])) {
            foreach ($out['contacts'] as $i => $row) {
                if (is_array($row) && (trim((string) ($row['contact_name'] ?? '')) === '')) {
                    $out['contacts'][$i]['contact_name'] = 'Self';
                }
            }
        }

        // When core has work_location_text but career_history[0].location is empty, copy so apply saves work location to profile_career.
        if (is_array($out['core']) && isset($out['career_history']) && is_array($out['career_history'])) {
            $workLoc = trim((string) ($out['core']['work_location_text'] ?? ''));
            if ($workLoc !== '' && isset($out['career_history'][0]) && is_array($out['career_history'][0])) {
                $first = &$out['career_history'][0];
                if (trim((string) ($first['location'] ?? '')) === '' && trim((string) ($first['work_location'] ?? '')) === '') {
                    $first['location'] = $workLoc;
                }
            }
        }

        // Centralized deterministic controlled-field normalization for full snapshot.
        return app(\App\Services\Parsing\IntakeControlledFieldNormalizer::class)->normalizeSnapshot($out);
    }

    /**
     * Resolve intake core text fields to master IDs before storing approval_snapshot_json.
     * So: intake form data → same shape as wizard; apply just copies snapshot to profile; edit shows as-is.
     */
    private function normalizeIntakeCoreForStorage(array $core): array
    {
        $out = app(\App\Services\Parsing\IntakeControlledFieldNormalizer::class)
            ->normalizeCore($core);

        // Map income-engine keys to profile columns so stored snapshot has annual_income / family_income (apply and edit show same).
        if ((! isset($out['annual_income']) || $out['annual_income'] === '' || $out['annual_income'] === null) && isset($out['income_normalized_annual_amount']) && is_numeric($out['income_normalized_annual_amount'])) {
            $out['annual_income'] = (float) $out['income_normalized_annual_amount'];
        }
        if ((! isset($out['annual_income']) || $out['annual_income'] === '' || $out['annual_income'] === null) && isset($out['income_amount']) && is_numeric($out['income_amount'])) {
            $out['annual_income'] = (float) $out['income_amount'];
        }
        if ((! isset($out['family_income']) || $out['family_income'] === '' || $out['family_income'] === null) && isset($out['family_income_normalized_annual_amount']) && is_numeric($out['family_income_normalized_annual_amount'])) {
            $out['family_income'] = (float) $out['family_income_normalized_annual_amount'];
        }
        if ((! isset($out['family_income']) || $out['family_income'] === '' || $out['family_income'] === null) && isset($out['family_income_amount']) && is_numeric($out['family_income_amount'])) {
            $out['family_income'] = (float) $out['family_income_amount'];
        }

        return $out;
    }

    /**
     * Extract rows from parsed relatives where relation is बहिण or भाऊ (siblings). AI/parser may put them in "relatives" array.
     *
     * @return array<int, array{relation_type: string, name: string, contact_number: string|null, occupation: string|null, ...}>
     */
    private function extractSiblingRowsFromParsedRelatives(array $relatives): array
    {
        $out = [];
        foreach ($relatives as $row) {
            $row = is_array($row) ? $row : (array) $row;
            $relation = trim((string) ($row['relation_type'] ?? $row['relation'] ?? ''));
            if ($relation === 'बहिण' || $relation === 'बहीण') {
                $out[] = [
                    'relation_type' => 'sister',
                    'name' => trim((string) ($row['name'] ?? '')),
                    'contact_number' => trim((string) ($row['contact_number'] ?? '')) ?: null,
                    'occupation' => trim((string) ($row['occupation'] ?? '')) ?: null,
                ];
            } elseif ($relation === 'भाऊ' || $relation === 'बंधू') {
                $out[] = [
                    'relation_type' => 'brother',
                    'name' => trim((string) ($row['name'] ?? '')),
                    'contact_number' => trim((string) ($row['contact_number'] ?? '')) ?: null,
                    'occupation' => trim((string) ($row['occupation'] ?? '')) ?: null,
                ];
            }
        }

        return $out;
    }

    /**
     * Merge parsed sibling rows (from relatives array) into profileSiblings collection for the form.
     */
    private function mergeParsedSiblingsIntoProfileSiblings(\Illuminate\Support\Collection $profileSiblings, array $siblingRows): \Illuminate\Support\Collection
    {
        if (empty($siblingRows)) {
            return $profileSiblings;
        }
        $existing = $profileSiblings->all();
        foreach ($siblingRows as $r) {
            $existing[] = (object) array_merge($r, [
                'id' => null,
                'marital_status' => '',
                'spouse' => [],
                'address_line' => '',
            ]);
        }

        return collect($existing);
    }

    /**
     * Exclude बहिण/भाऊ rows from relatives array so they are not passed to partition (they go to siblings).
     */
    private function excludeSiblingRelationsFromRelatives(array $relatives): array
    {
        $siblingRelations = ['बहिण', 'बहीण', 'भाऊ', 'बंधू'];

        return array_values(array_filter($relatives, function ($row) use ($siblingRelations) {
            $row = is_array($row) ? $row : (array) $row;
            $relation = trim((string) ($row['relation_type'] ?? $row['relation'] ?? ''));

            return ! in_array($relation, $siblingRelations, true);
        }));
    }

    /**
     * Partition parsed relatives into paternal vs maternal and structure each row: split notes by श्री./सौ., extract name and address.
     * दाजी = बहिणीचा नवरा (sister's husband) — not shown in Paternal; returned separately to merge into sibling panel sister's spouse.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: \Illuminate\Support\Collection, 2: array} [paternal, maternal, dajiRows]
     */
    private function partitionAndStructureRelativesForIntake(array $relatives): array
    {
        $paternal = [];
        $maternal = [];
        $dajiRows = [];
        $marathiToPaternal = [
            'दादी' => 'paternal_grandmother',
            'आजी' => 'paternal_grandmother',
            'चुलते' => 'paternal_uncle',
            'काका' => 'paternal_uncle',
            'आत्या' => 'paternal_aunt',
            'काकू' => 'paternal_aunt',
            'Cousin' => 'Cousin',
            'इतर' => 'Other',
            'native_place' => 'native_place',
        ];
        $marathiToMaternal = [
            'मामा' => 'maternal_uncle',
            'मावशी' => 'maternal_aunt',
            'मावशिचा_नवरा' => 'husband_maternal_aunt',
            'आजोळ' => 'maternal_address_ajol',
            'other_maternal' => 'other_maternal',
        ];
        $allPaternalKeys = array_keys($marathiToPaternal);

        foreach ($relatives as $row) {
            $row = is_array($row) ? $row : (array) $row;
            $relationTypeRaw = trim((string) ($row['relation_type'] ?? $row['relation'] ?? ''));
            $notes = trim((string) ($row['notes'] ?? ''));
            $directName = trim((string) ($row['name'] ?? ''));
            if ($relationTypeRaw === '' && $notes === '' && $directName === '') {
                continue;
            }
            if ($relationTypeRaw === 'वडिल' || $relationTypeRaw === 'वडील') {
                continue;
            }
            if (mb_strpos($relationTypeRaw, 'इतर') !== false || mb_strpos($relationTypeRaw, 'नातेवाईक') !== false) {
                $relationTypeRaw = 'इतर';
            }
            // Special case: आत्या block मधली ओळ AI ने "मामा" म्हणून tag केली तर,
            // notes मध्ये "आत्या" असल्यामुळे relationTypeRaw ला "आत्या" normalize करा
            // जेणेकरून तो स्पष्टपणे paternal aunt / तिच्या नवऱ्याच्या bucket मध्ये जाईल.
            if ($relationTypeRaw === 'मामा' && mb_strpos($notes, 'आत्या') !== false) {
                $relationTypeRaw = 'आत्या';
            }
            // Normalize AI relation_type aliases → Marathi buckets (e.g. mama/mami/aunt).
            if (strcasecmp($relationTypeRaw, 'mama') === 0) {
                $relationTypeRaw = 'मामा';
            } elseif (strcasecmp($relationTypeRaw, 'mavshi') === 0 || strcasecmp($relationTypeRaw, 'mavshi_aunt') === 0) {
                $relationTypeRaw = 'मावशी';
            } elseif (strcasecmp($relationTypeRaw, 'mami') === 0) {
                // Mami = wife of maternal uncle → treat as "husband of maternal aunt" bucket for maternal section.
                $relationTypeRaw = 'मावशिचा_नवरा';
            } elseif (strcasecmp($relationTypeRaw, 'aunt') === 0) {
                // Generic aunt in this context is usually paternal; keep as Other so user can classify manually.
                $relationTypeRaw = 'इतर';
            }

            $isMaternal = false;
            $englishType = null;
            $isDaji = ($relationTypeRaw === 'दाजी');
            if ($isDaji) {
            } elseif (isset($marathiToMaternal[$relationTypeRaw])) {
                $isMaternal = true;
                $englishType = $marathiToMaternal[$relationTypeRaw];
            } elseif (isset($marathiToPaternal[$relationTypeRaw])) {
                $englishType = $marathiToPaternal[$relationTypeRaw];
            } else {
                $englishType = 'Other';
            }
            if ($directName !== '') {
                // Clean trailing enumeration markers ("1)", "2)") and stray address prefixes ("मु", "रा", "मो") from names.
                $directName = preg_replace('/\s*[०-९0-9]+\)\s*$/u', '', $directName);
                $directName = preg_replace('/\s*(,?\s*(मु|रा|मो)\.?)\s*$/u', '', $directName);
                $directName = trim((string) $directName);

                $structuredNotes = $notes !== '' ? $notes : '';
                $structuredAddress = $row['address_line'] ?? ($notes !== '' ? $notes : '');
                // Remove trailing section labels like "मावशी : 1)" / "आत्या :" / "मामा :".
                $structuredAddress = preg_replace('/\s*(मावशी|आत्या|मामा)\s*:\s*[०-९0-9]*\)?\s*$/u', '', (string) $structuredAddress);
                // Remove trailing enumeration markers like "2)" that belong to list numbering, not address.
                $structuredAddress = preg_replace('/\s*[०-९0-9]+\)\s*$/u', '', (string) $structuredAddress);
                $structuredAddress = trim((string) $structuredAddress);
                // Address व Additional info duplicate असल्यास, notes रिकामे ठेव.
                if ($structuredNotes !== '' && $structuredNotes === $structuredAddress) {
                    $structuredNotes = '';
                }
                $structured = [
                    'relation_type' => $englishType,
                    'name' => $directName,
                    'occupation' => $row['occupation'] ?? null,
                    'contact_number' => $row['contact_number'] ?? null,
                    'notes' => $structuredNotes,
                    'address_line' => $structuredAddress,
                ];
                if ($isDaji) {
                    $dajiRows[] = [
                        'name' => $directName,
                        'address_line' => $notes,
                        'occupation_title' => $row['occupation'] ?? null,
                        'contact_number' => $row['contact_number'] ?? null,
                    ];
                } elseif ($isMaternal) {
                    $maternal[] = (object) $structured;
                } else {
                    $paternal[] = (object) $structured;
                }

                continue;
            }
            $segments = preg_split('/(?=श्री\.|सौ\.)/u', $notes, -1, PREG_SPLIT_NO_EMPTY);
            $segments = array_map('trim', array_filter($segments, fn ($p) => trim($p) !== ''));
            if (count($segments) === 0) {
                $segments = [$notes];
            }
            foreach ($segments as $segment) {
                $segment = trim($segment);
                if ($segment === '') {
                    continue;
                }
                if (preg_match('/^\s*(मामा|चुलते|चुलती|दाजी|दादी|आजी|इतर)\s*[+\-*\.\s०-९0-9]*$/u', $segment)) {
                    continue;
                }
                $name = null;
                $addressOrNotes = $segment;
                if (preg_match('/श्री\.?\s*([^(]+?)\s*\(([^)]+)\)/u', $segment, $m)) {
                    $name = trim($m[1]);
                    $addressOrNotes = trim($m[2]);
                } elseif (preg_match('/सौ\.?\s*([^(]+?)\s*\(([^)]+)\)/u', $segment, $m)) {
                    $name = trim($m[1]);
                    $addressOrNotes = trim($m[2]);
                } elseif (preg_match('/श्री\.?\s*(.+)/u', $segment, $m)) {
                    $name = trim($m[1]);
                    $addressOrNotes = '';
                } elseif (preg_match('/सौ\.?\s*(.+)/u', $segment, $m)) {
                    $name = trim($m[1]);
                    $addressOrNotes = '';
                }
                if ($name !== null && $name !== '') {
                    // Drop leading bullets/indices, trailing enumeration like "1)" / "2)", and stray address prefixes ("मु", "रा", "मो").
                    $name = preg_replace('/^\s*[+\-*\.\s०-९0-9]+\s*/u', '', $name);
                    $name = preg_replace('/\s*[०-९0-9]+\)\s*$/u', '', $name);
                    $name = preg_replace('/\s*(,?\s*(मु|रा|मो)\.?)\s*$/u', '', $name);
                    $name = trim((string) $name);
                }
                if ($name === '' || $name === null) {
                    $name = '';
                }
                // दाजी साठी: segment मधून "पत्ता. ..." नंतरचा पत्ता वेगळा काढा.
                if ($isDaji) {
                    if (preg_match('/पत्ता\.\s*(.+)$/u', $segment, $mAddr)) {
                        $addrOnly = trim($mAddr[1]);
                        if ($addrOnly !== '') {
                            $addressOrNotes = $addrOnly;
                        }
                    }
                }
                if ($isDaji) {
                    $dajiRows[] = [
                        'name' => $name ?? '',
                        'address_line' => $addressOrNotes !== '' ? $addressOrNotes : '',
                        'occupation_title' => $row['occupation'] ?? null,
                        'contact_number' => $row['contact_number'] ?? null,
                    ];
                } else {
                    $structuredNotes = $addressOrNotes !== '' ? $addressOrNotes : (string) $segment;
                    $structuredAddress = $row['address_line'] ?? ($addressOrNotes !== '' ? $addressOrNotes : (string) $segment);
                    $structuredAddress = preg_replace('/\s*(मावशी|आत्या|मामा)\s*:\s*[०-९0-9]*\)?\s*$/u', '', (string) $structuredAddress);
                    $structuredAddress = preg_replace('/\s*[०-९0-9]+\)\s*$/u', '', (string) $structuredAddress);
                    $structuredAddress = trim((string) $structuredAddress);
                    if ($structuredNotes !== '' && $structuredNotes === $structuredAddress) {
                        $structuredNotes = '';
                    }
                    $structured = [
                        'relation_type' => $englishType,
                        'name' => $name ?? '',
                        'occupation' => $row['occupation'] ?? null,
                        'contact_number' => $row['contact_number'] ?? null,
                        'notes' => $structuredNotes,
                        'address_line' => $structuredAddress,
                    ];
                    if ($isMaternal) {
                        $maternal[] = (object) $structured;
                    } else {
                        $paternal[] = (object) $structured;
                    }
                }
            }
        }

        // Maternal Ajol row: जर आधीच नसेल तर maternal relatives मधल्या address वरून एक row auto-add करा.
        $maternalHasAjol = false;
        foreach ($maternal as $m) {
            $rt = $m->relation_type ?? null;
            if ($rt === 'maternal_address_ajol') {
                $maternalHasAjol = true;
                break;
            }
        }
        if (! $maternalHasAjol) {
            // प्राधान्य: प्रथम मामा च्या address_line ला Ajol समजा (साधारणपणे आजोळ = मामा गाव).
            $ajolAddress = null;
            foreach ($maternal as $m) {
                $rt = $m->relation_type ?? null;
                if ($rt === 'maternal_uncle') {
                    $addrCandidate = trim((string) ($m->address_line ?? $m->notes ?? ''));
                    if ($addrCandidate !== '') {
                        $ajolAddress = $addrCandidate;
                        break;
                    }
                }
            }
            // जर मामा कडे address नसेल तर, कुठल्याही maternal row मधून पहिला non-empty address वापर.
            if ($ajolAddress === null || $ajolAddress === '') {
                foreach ($maternal as $m) {
                    $addrCandidate = trim((string) ($m->address_line ?? $m->notes ?? ''));
                    if ($addrCandidate !== '') {
                        $ajolAddress = $addrCandidate;
                        break;
                    }
                }
            }
            // Raw text मध्ये base address पेक्षा जास्त characters असलेली पूर्ण ओळ असेल तर तीच वापर.
            if ($ajolAddress !== null && $ajolAddress !== '') {
                $raw = (string) ($intake->raw_ocr_text ?? '');
                if ($raw !== '') {
                    $base = preg_quote($ajolAddress, '/');
                    if (@preg_match("/^{$base}.*$/um", $raw, $mFull)) {
                        $line = trim((string) ($mFull[0] ?? ''));
                        if ($line !== '' && mb_strlen($line, 'UTF-8') > mb_strlen($ajolAddress, 'UTF-8')) {
                            $ajolAddress = $line;
                        }
                    }
                }
            }
            if ($ajolAddress !== null && $ajolAddress !== '') {
                // 1) Ajol row add करा.
                $maternal[] = (object) [
                    'relation_type' => 'maternal_address_ajol',
                    'name' => '',
                    'occupation' => null,
                    'contact_number' => null,
                    'notes' => '',
                    'address_line' => $ajolAddress,
                ];
                // 2) जे maternal uncle (mama) चे address फक्त "मु.पो. xyz" इतकेच आहेत, त्यांना full ajol address द्या.
                foreach ($maternal as &$mRow) {
                    $rt = $mRow->relation_type ?? null;
                    if ($rt !== 'maternal_uncle') {
                        continue;
                    }
                    $curAddr = trim((string) ($mRow->address_line ?? ''));
                    if ($curAddr === '' || mb_strpos($ajolAddress, $curAddr) === 0) {
                        $mRow->address_line = $ajolAddress;
                    }
                }
                unset($mRow);
            }
        }

        // Maternal list order: Ajol प्रथम, नंतर मामा, मग मावशी / तिचा नवरा, मग इतर.
        $priorityMap = [
            'maternal_address_ajol' => 0,
            'maternal_uncle' => 1,
            'maternal_aunt' => 2,
            'husband_maternal_aunt' => 3,
        ];
        $indexedMaternal = [];
        foreach ($maternal as $idx => $row) {
            $rt = $row->relation_type ?? null;
            $prio = $priorityMap[$rt] ?? 9;
            $indexedMaternal[] = ['prio' => $prio, 'idx' => $idx, 'row' => $row];
        }
        usort($indexedMaternal, function ($a, $b) {
            if ($a['prio'] === $b['prio']) {
                return $a['idx'] <=> $b['idx'];
            }

            return $a['prio'] <=> $b['prio'];
        });
        $maternal = array_map(fn ($e) => $e['row'], $indexedMaternal);

        return [collect($paternal), collect($maternal), $dajiRows];
    }

    /**
     * Merge दाजी (sister's husband) info from parsed relatives into sibling panel: first sister's spouse, or add one sister row with spouse.
     */
    private function mergeDajiIntoSiblings(\Illuminate\Support\Collection $siblings, array $dajiRows): \Illuminate\Support\Collection
    {
        if (empty($dajiRows)) {
            return $siblings;
        }
        $firstDaji = $dajiRows[0];
        $siblingsArray = $siblings->all();
        $sisterIndex = null;
        foreach ($siblingsArray as $i => $s) {
            $r = is_object($s) ? (array) $s : $s;
            if (($r['relation_type'] ?? '') === 'sister') {
                $sisterIndex = $i;
                break;
            }
        }
        $spouseAddressRaw = (string) ($firstDaji['address_line'] ?? '');
        // Name मधून "पत्ता. ..." काढून टाका, फक्त व्यक्तीचं नाव ठेवा.
        $rawName = (string) ($firstDaji['name'] ?? '');
        $cleanName = preg_replace('/\s*पत्ता\..*$/u', '', $rawName);
        $cleanName = trim($cleanName);
        if ($cleanName === '') {
            $cleanName = $rawName;
        }

        // दाजी: नाव वेगळं, पत्ता Additional info (address_line) मध्ये साध्या text स्वरूपात.
        $spouse = [
            'name' => $cleanName,
            'address_line' => $spouseAddressRaw !== '' ? ('पत्ता. '.$spouseAddressRaw) : '',
            'occupation_title' => $firstDaji['occupation_title'] ?? null,
            'contact_number' => $firstDaji['contact_number'] ?? null,
        ];
        if ($sisterIndex !== null) {
            $s = $siblingsArray[$sisterIndex];
            $r = is_object($s) ? (array) $s : $s;
            $r['marital_status'] = 'married';
            $r['spouse'] = array_merge($r['spouse'] ?? [], $spouse);
            $siblingsArray[$sisterIndex] = (object) $r;
        } else {
            $siblingsArray[] = (object) [
                'relation_type' => 'sister',
                'name' => '',
                'marital_status' => 'married',
                'spouse' => $spouse,
            ];
        }

        return collect($siblingsArray);
    }

    /**
     * Raw OCR मधून भाऊचा पत्ता/नोकरी आणि बहिणीचं नाव siblings मध्ये additive पद्धतीने भरा.
     * SSOT safe: फक्त रिकामी fields भरतो; आधीच असलेले values बदलत नाही.
     */
    private function enrichSiblingsFromRawText(\Illuminate\Support\Collection $siblings, string $rawText): \Illuminate\Support\Collection
    {
        if ($rawText === '' || $siblings->isEmpty()) {
            return $siblings;
        }

        $text = $rawText;
        $siblingsArray = $siblings->all();

        // 1) बहिणीचे नाव: "बहीण २ सौ. पुजा नवनाथ कन्हेरे." → Sister name
        foreach ($siblingsArray as $i => $s) {
            $r = is_object($s) ? (array) $s : $s;
            if (($r['relation_type'] ?? '') === 'sister' && empty($r['name'])) {
                if (preg_match('/बहीण[^\n]*सौ\.?\s*([^\.\n]+)/u', $text, $mSis)) {
                    $name = trim($mSis[1]);
                    if ($name !== '') {
                        $r['name'] = $name;
                        $siblingsArray[$i] = (object) $r;
                    }
                }
                // फक्त पहिल्या matching sister साठी attempt पुरे.
                break;
            }
        }

        // 2) भाऊचा पत्ता + नोकरी: "भाऊ + श्री. समर्थ ... (९१४५...)" नंतरचा local segment वापरा.
        // Address field मध्ये फक्त structured dropdown values (location-typeahead) राहायला हव्यात,
        // त्यामुळे raw address Additional info (notes) मध्ये ठेवतो.
        foreach ($siblingsArray as $i => $s) {
            $r = is_object($s) ? (array) $s : $s;
            if (($r['relation_type'] ?? '') !== 'brother') {
                continue;
            }
            $name = trim((string) ($r['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $pos = mb_stripos($text, $name);
            if ($pos === false) {
                continue;
            }
            $segment = mb_substr($text, $pos);
            // पुढचा major marker (बहीण / इतर नातेवाईक / परिचय पत्र इ.) येईपर्यंतचा भाग घ्या.
            if (preg_match('/(बहीण|इतर नातेवाईक|परिचय पत्र)/u', $segment, $mStop, PREG_OFFSET_CAPTURE)) {
                $segment = mb_substr($segment, 0, $mStop[0][1]);
            }

            // Address: आधी DB मधून exact city/taluka/district match मिळतो का ते बघा; मिळाला तर dropdown IDs + display auto-fill करा.
            // तसेच raw flat/address मजकूर नेहमी Additional info (notes) मध्ये जतन करा.
            if (preg_match('/पत्ता\s*[:\-]\s*([^\r\n]+)/u', $segment, $mAddr)) {
                $addrLine1 = trim($mAddr[1]);
                $addr = $addrLine1;
                // बऱ्याच बायोडाटामध्ये पत्ता दोन ओळींमध्ये असतो; जर पहिली ओळ comma ने संपत असेल तर लगेचच पुढची ओळही address मध्ये merge करा.
                $afterPos = mb_strpos($segment, $mAddr[0]);
                if ($afterPos !== false) {
                    $rest = mb_substr($segment, $afterPos + mb_strlen($mAddr[0]));
                    if (preg_match('/^\s*([^\r\n]+)/u', $rest, $mNext)) {
                        $line2 = trim($mNext[1]);
                        if ($line2 !== '') {
                            $addr = rtrim($addrLine1, ' ,،，').', '.$line2;
                        }
                    }
                }
                if ($addr !== '') {
                    $loc = $this->guessLocationFromAddress($addr);
                    if ($loc !== null) {
                        $r['city_id'] = $loc['city_id'];
                        $r['taluka_id'] = $loc['taluka_id'];
                        $r['district_id'] = $loc['district_id'];
                        $r['state_id'] = $loc['state_id'];
                        if (! empty($loc['display'] ?? '')) {
                            $r['location_display'] = $loc['display'];
                        }
                    }
                    // notes रिकामे असेल तर थेट first line; अन्यथा शेवटी append करा. (loc match झालं वा नाही तरी raw flat-level info हरवू नये.)
                    $existingNotes = trim((string) ($r['notes'] ?? ''));
                    if ($addrLine1 !== '') {
                        $r['notes'] = $existingNotes !== '' ? ($existingNotes.' | '.$addrLine1) : $addrLine1;
                    }
                }
            }
            // Occupation (Sibling Occupation field साठी)
            if (empty($r['occupation'] ?? '') && preg_match('/नोकरी\s*[:\-]\s*([^\r\n]+)/u', $segment, $mOcc)) {
                $occ = trim($mOcc[1]);
                if ($occ !== '') {
                    $r['occupation'] = $occ;
                }
            }
            $siblingsArray[$i] = (object) $r;
        }

        return collect($siblingsArray);
    }

    /**
     * Raw address string मधून DB मधील existing City/Taluka/District/State शोधण्याचा प्रयत्न करा.
     * यशस्वी झाला तर dropdown साठी लागणारे IDs + display text परत करतो; अन्यथा null.
     */
    private function guessLocationFromAddress(string $addr): ?array
    {
        $addrNorm = mb_strtolower(trim($addr));
        if ($addrNorm === '') {
            return null;
        }

        // 1) साधा comma/pipe split करून शेवटचे 1–2 भाग city/district tokens म्हणून घ्या.
        $parts = preg_split('/[,|]/u', $addrNorm);
        $parts = array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
        if (empty($parts)) {
            return null;
        }
        $last = $this->normalizeLocationToken($parts[count($parts) - 1]);
        $secondLast = isset($parts[count($parts) - 2]) ? $this->normalizeLocationToken($parts[count($parts) - 2]) : null;

        // cityToken = secondLast (जर असेल) नाहीतर last; districtToken = last.
        $cityToken = $secondLast ?? $last;
        $districtToken = $last;

        // 2) CityAlias मधून normalized aliases वापरून जास्त smart matching करता येऊ शकेल; आत्ता direct alias_name match.
        $city = null;
        try {
            $city = \App\Models\CityAlias::query()
                ->where('is_active', true)
                ->whereRaw('normalized_alias = ?', [$cityToken])
                ->with('city.taluka.district.state')
                ->first()?->city;
        } catch (\Throwable $e) {
            $city = null;
        }

        // 3) Direct City नावावरून match (उदा. "wagholi,pune" मधून "wagholi").
        if (! $city) {
            try {
                $city = \App\Models\City::query()
                    ->whereRaw('LOWER(TRIM(name)) = ?', [$cityToken])
                    ->whereHas('taluka.district', function ($q) use ($districtToken) {
                        $q->whereRaw('LOWER(name) = ? OR LOWER(name_mr) = ?', [$districtToken, $districtToken]);
                    })
                    ->with('taluka.district.state')
                    ->first();
            } catch (\Throwable $e) {
                $city = null;
            }
        }

        // 4) Fallback: city नाव जुळत नसेल पण districtToken ओळखता आला (उदा. "देहू रोड, पुणे"),
        // तर त्या district मधील canonical city (उदा. Pune City) परत करा.
        if (! $city && $districtToken !== '') {
            try {
                $district = \App\Models\District::query()
                    ->whereRaw('LOWER(name) = ? OR LOWER(name_mr) = ?', [$districtToken, $districtToken])
                    ->first();
                if ($district) {
                    $city = \App\Models\City::query()
                        ->whereHas('taluka', fn ($q) => $q->where('district_id', $district->id))
                        ->whereRaw('LOWER(name) LIKE ?', ['pune city%'])
                        ->with('taluka.district.state')
                        ->first();
                }
            } catch (\Throwable $e) {
                $city = null;
            }
        }

        if (! $city) {
            return null;
        }

        $taluka = $city->taluka;
        $district = $taluka?->district;
        $state = $district && method_exists($district, 'state') ? $district->state : null;

        // UI मध्ये manually निवडल्यास जसा label दिसतो (City, Taluka, District, State),
        // तसाच approximate label तयार करतो.
        $parts = [];
        if (! empty($city->name)) {
            $parts[] = $city->name;
        }
        if (! empty($taluka?->name)) {
            $parts[] = $taluka->name;
        }
        if (! empty($district?->name)) {
            $parts[] = $district->name;
        }
        if (! empty($state?->name)) {
            $parts[] = $state->name;
        }

        return [
            'city_id' => $city->id,
            'taluka_id' => $taluka?->id,
            'district_id' => $district?->id,
            'state_id' => $state?->id,
            'city_name' => $city->name ?? '',
            'display' => implode(', ', $parts),
        ];
    }

    /**
     * Location tokens (city/district भाग) normalize करा: whitespace + शेवटचे . , आणि Devanagari danda काढा.
     */
    private function normalizeLocationToken(string $token): string
    {
        $t = trim($token);
        // Remove trailing punctuation and danda.
        $t = preg_replace('/[[:punct:]।]+$/u', '', $t);

        return trim($t);
    }

    /**
     * Normalize one source of relative rows (relatives_parents_family or relatives_maternal_family) to snapshot format.
     * Same shape as ProfileWizardController::collectRelativesFromRequestSource for MutationService.
     */
    private function normalizeRelativesRows(array $rows): array
    {
        $relatives = [];
        foreach ($rows as $row) {
            $relationType = trim((string) ($row['relation_type'] ?? ''));
            if ($relationType === '') {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if (in_array($relationType, ['maternal_address_ajol', 'native_place'], true)) {
                $name = '';
            }
            $addressLine = trim((string) ($row['address_line'] ?? $row['address'] ?? $row['Address'] ?? ''));
            $address = trim((string) ($row['address'] ?? $row['address_line'] ?? $row['Address'] ?? ''));
            $relatives[] = [
                'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                'relation_type' => $relationType ?: '',
                'name' => $name ?: '',
                'occupation' => trim((string) ($row['occupation'] ?? '')) ?: null,
                'city_id' => ! empty($row['city_id']) ? (int) $row['city_id'] : null,
                'state_id' => ! empty($row['state_id']) ? (int) $row['state_id'] : null,
                'contact_number' => trim((string) ($row['contact_number'] ?? '')) ?: null,
                'notes' => trim((string) ($row['notes'] ?? '')) ?: null,
                'address_line' => $addressLine !== '' ? $addressLine : null,
                'address' => $address !== '' ? $address : null,
                'is_primary_contact' => ! empty($row['is_primary_contact']),
            ];
        }

        return $relatives;
    }

    /**
     * Forget cached parse-time OCR debug so preview does not show a stale effective OCR source after manual save, clear, or reparse.
     */
    private function forgetIntakeParseOcrDebugCache(BiodataIntake $intake): void
    {
        Cache::forget('intake.parse_ocr_debug.'.$intake->id);
        Cache::forget('intake.parse_input_debug.'.$intake->id);
        Cache::forget('intake.parse_input_text.'.$intake->id);
    }

    /**
     * Text shown in the preview "raw text" panel: stored OCR, transient OCR, or cached AI vision parse input.
     * Does not read DB raw_ocr_text for AI mode beyond display rules; never persists AI extract here.
     *
     * @return array{text: string, source: 'ai_vision_cache'|'ai_vision_unavailable'|'stored_ocr'|'ocr_transient'|'empty', provenance: array{heading_key: string, params?: array<string, string>}}
     */
    private function resolvePreviewRawParseInputText(BiodataIntake $intake): array
    {
        $resolver = app(ParserStrategyResolver::class);
        $mode = $resolver->normalizeMode($intake->parser_version ?: $resolver->resolveActiveMode());

        if ($mode === ParserStrategyResolver::MODE_AI_VISION_EXTRACT_V1) {
            $cached = Cache::get('intake.parse_input_text.'.$intake->id);
            $dbg = Cache::get('intake.parse_input_debug.'.$intake->id);
            $dbg = is_array($dbg) ? $dbg : [];

            if (is_string($cached) && trim($cached) !== '') {
                return [
                    'text' => $cached,
                    'source' => 'ai_vision_cache',
                    'provenance' => AiVisionExtractionService::provenanceForPreview($dbg),
                ];
            }

            $msg = $this->buildAiVisionUnavailablePanelMessage($dbg, $intake);
            if ($msg === '') {
                $msg = __('intake.preview_parse_input_ai_unavailable');
                if ($dbg !== []
                    && ($dbg['parse_input_source'] ?? '') === 'ai_vision_extract_v1'
                    && ! empty($dbg['ok'])
                    && ! empty($dbg['text_quality_ok'] ?? true)) {
                    $msg = __('intake.preview_parse_input_ai_cache_missing');
                }
            }

            return [
                'text' => $msg,
                'source' => 'ai_vision_unavailable',
                'provenance' => [
                    'heading_key' => 'intake.preview_ai_transcription_unavailable_title',
                    'params' => [],
                ],
            ];
        }

        $stored = (string) ($intake->raw_ocr_text ?? '');
        if (trim($stored) !== '') {
            return [
                'text' => $stored,
                'source' => 'stored_ocr',
                'provenance' => [
                    'heading_key' => 'intake.preview_source_stored_ocr',
                    'params' => [],
                ],
            ];
        }

        try {
            $ocrResolved = app(OcrService::class)->resolveParseInputText($intake);
            $transient = (string) ($ocrResolved['text'] ?? '');
            if (trim($transient) !== '') {
                return [
                    'text' => $transient,
                    'source' => 'ocr_transient',
                    'provenance' => [
                        'heading_key' => 'intake.preview_source_ocr_parse_input',
                        'params' => [],
                    ],
                ];
            }
        } catch (\Throwable $e) {
            // keep empty
        }

        return [
            'text' => '',
            'source' => 'empty',
            'provenance' => [
                'heading_key' => 'intake.preview_source_empty',
                'params' => [],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $dbg
     */
    private function buildAiVisionUnavailablePanelMessage(array $dbg, BiodataIntake $intake): string
    {
        $parts = [];
        if ($dbg !== []) {
            if (! empty($dbg['failure_detail'])) {
                $parts[] = (string) $dbg['failure_detail'];
            }
            if (! empty($dbg['quality_failure_detail'])) {
                $parts[] = (string) $dbg['quality_failure_detail'];
            }
        }
        $le = trim((string) ($intake->last_error ?? ''));
        if ($le !== '' && $parts === []) {
            $parts[] = __('intake.preview_ai_transcription_failed_code', ['code' => $le]);
        }

        return implode("\n\n", array_filter($parts));
    }

    /**
     * Merge upload-time OCR debug session, last manual-crop session hint, on-disk manual flags,
     * and the last completed parse OCR debug snapshot (cache) when present.
     *
     * @return array<string, mixed>|null
     */
    private function buildPreviewOcrDebugMeta(BiodataIntake $intake): ?array
    {
        if (! config('app.debug')) {
            return null;
        }

        // Always show which mode is active/effective for clarity.
        $resolver = app(\App\Services\Parsing\ParserStrategyResolver::class);
        $base = [
            'active_parser_mode' => $resolver->resolveActiveMode(),
            'intake_parser_version' => $intake->parser_version ? $resolver->normalizeMode($intake->parser_version) : null,
        ];

        $manualSvc = app(IntakeManualOcrPreparedService::class);
        $upload = session('intake_ocr_debug_meta');
        $manualSession = session('intake_manual_ocr_debug_'.$intake->id);

        if (is_array($upload) && (int) ($upload['intake_id'] ?? 0) === (int) $intake->id) {
            $base = array_merge($base, $upload);
        }
        if (is_array($manualSession) && (int) ($manualSession['intake_id'] ?? 0) === (int) $intake->id) {
            $base = array_merge($base, $manualSession);
        }

        $manualOnDisk = $manualSvc->exists($intake);
        $base['parse_uses_manual_prepared'] = $manualOnDisk;
        $base['manual_prepared_storage_relative'] = $manualOnDisk
            ? $manualSvc->relativePath($intake)
            : null;
        $base['ocr_source_type_effective'] = $manualOnDisk ? 'manual_prepared' : 'original';

        $parseOcrDbg = Cache::get('intake.parse_ocr_debug.'.$intake->id);
        if (is_array($parseOcrDbg) && $parseOcrDbg !== []) {
            if (isset($parseOcrDbg['ocr_source_type'])) {
                $base['ocr_source_type_effective'] = $parseOcrDbg['ocr_source_type'];
                $base['parse_uses_manual_prepared'] = $parseOcrDbg['ocr_source_type'] === 'manual_prepared';
            }
            foreach ([
                'ocr_pipeline',
                'final_ocr_input_path',
                'manual_prepared_storage_relative',
                'manual_prepared_absolute_path',
                'original_storage_relative',
                'original_absolute_path',
                'derived_absolute_path',
                'derived_storage_relative',
                'preset_resolved',
                'preset_request',
                'preprocess_used',
                'fallback_used',
                'skipped_preprocessing_reason',
                'driver',
                'applied_steps',
                'kind',
            ] as $k) {
                if (! array_key_exists($k, $parseOcrDbg)) {
                    continue;
                }
                $v = $parseOcrDbg[$k];
                if ($v === null || $v === '') {
                    continue;
                }
                $base[$k] = $v;
            }
        }

        // When parse cache is missing (e.g. legacy/broken intakes), still expose which file exists on disk
        // so preview does not show "—" for paths when we can resolve them.
        if (empty($base['final_ocr_input_path']) || empty($base['original_absolute_path'])) {
            $manualOnDisk = $manualSvc->exists($intake);
            if ($manualOnDisk) {
                $base['ocr_source_type_effective'] = 'manual_prepared_image_path';
                $base['final_ocr_input_path'] = $manualSvc->absolutePath($intake);
                $base['original_absolute_path'] = $manualSvc->absolutePath($intake);
                $base['original_storage_relative'] = $manualSvc->relativePath($intake);
            } else {
                $src = app(OcrService::class)->resolveEffectiveOcrSource($intake);
                if (is_array($src)) {
                    $base['ocr_source_type_effective'] = $src['source_field'] ?? 'file_path';
                    $base['final_ocr_input_path'] = $src['absolute_path'] ?? null;
                    $base['original_absolute_path'] = $src['absolute_path'] ?? null;
                    $base['original_storage_relative'] = $src['relative_path'] ?? null;
                }
            }
        }

        $quality = Cache::get('intake.parse_ocr_quality.'.$intake->id);
        if (is_array($quality) && $quality !== []) {
            $base['ocr_quality'] = $quality;
        }

        $parseInput = Cache::get('intake.parse_input_debug.'.$intake->id);
        if (is_array($parseInput) && $parseInput !== []) {
            $base['parse_input_source'] = $parseInput['parse_input_source'] ?? null;
            $parseInputKeys = [
                'extraction', 'provider', 'provider_source', 'model', 'source_field', 'relative_path', 'absolute_path', 'ok', 'reason',
                'http_status', 'response_body_snippet', 'job_error_message', 'extraction_error', 'failure_detail', 'quality_failure_detail',
                'text_quality_ok', 'text_quality_reason', 'text_chars', 'text_non_space_chars', 'text_lines', 'text_alpha_ratio',
                'sarvam_job_id', 'sarvam_job_state',
                'original_image_width', 'original_image_height', 'ai_request_image_width', 'ai_request_image_height',
                'ai_request_payload_enhanced', 'ai_request_orientation_corrected', 'vision_detail', 'extracted_text_line_count',
            ];
            foreach ($parseInputKeys as $k) {
                if (! array_key_exists($k, $parseInput)) {
                    continue;
                }
                $v = $parseInput[$k];
                if ($v === null) {
                    continue;
                }
                if ($v === '' && ! in_array($k, ['failure_detail', 'quality_failure_detail', 'response_body_snippet'], true)) {
                    continue;
                }
                $base['parse_input_'.$k] = $v;
            }
            // Show file path even if OCR debug cache is absent.
            if (empty($base['final_ocr_input_path']) && ! empty($parseInput['absolute_path'])) {
                $base['final_ocr_input_path'] = $parseInput['absolute_path'];
            }
        }

        if ($base === [] && ! $manualSvc->exists($intake)) {
            return null;
        }

        $base['intake_id'] = (int) $intake->id;

        return $base;
    }

    private function intakeManualCropAccessGranted(BiodataIntake $intake): bool
    {
        $isOwner = (int) $intake->uploaded_by === (int) auth()->id();
        $isAdmin = auth()->user()?->isAnyAdmin() ?? false;

        return $isOwner || $isAdmin;
    }

    /**
     * Authenticated: original biodata image bytes for manual crop UI (never overwrites file_path).
     */
    public function biodataOriginalImage(BiodataIntake $intake)
    {
        if (! $this->intakeManualCropAccessGranted($intake)) {
            abort(403, __('intake.only_preview_own'));
        }

        $rel = $intake->file_path;
        if ($rel === null || $rel === '') {
            abort(404);
        }

        $ext = strtolower(pathinfo((string) $rel, PATHINFO_EXTENSION));
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
            abort(404);
        }

        $full = storage_path('app/private/'.$rel);
        if (! is_file($full) || ! is_readable($full)) {
            abort(404);
        }

        return response()->file($full);
    }

    /**
     * Authenticated: saved manual crop PNG (derived only).
     */
    public function manualPreparedImage(BiodataIntake $intake, IntakeManualOcrPreparedService $manual)
    {
        if (! $this->intakeManualCropAccessGranted($intake)) {
            abort(403, __('intake.only_preview_own'));
        }

        if (! $manual->exists($intake)) {
            abort(404);
        }

        return response()->file($manual->absolutePath($intake));
    }

    /**
     * Store manual crop as ocr-manual-prepared/{id}/manual.png and re-queue parse (OCR runs in job).
     */
    public function saveManualOcrPrepared(Request $request, BiodataIntake $intake, IntakeManualOcrPreparedService $manual)
    {
        if (! $this->intakeManualCropAccessGranted($intake)) {
            abort(403, __('intake.only_preview_own'));
        }

        if ($intake->approved_by_user) {
            return $this->manualCropSaveResponse($request, $intake, false, __('intake.manual_crop_denied_approved'), null);
        }

        $uploadRel = (string) ($intake->file_path ?? '');
        $uploadExt = strtolower(pathinfo($uploadRel, PATHINFO_EXTENSION));
        if ($uploadRel === '' || ! in_array($uploadExt, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
            return $this->manualCropSaveResponse($request, $intake, false, __('intake.manual_crop_not_image_intake'), null);
        }

        $hasFile = $request->hasFile('cropped_image');
        $pointsJson = $request->input('points');
        $rotationDeg = (int) $request->input('rotation_deg', 0);
        $hasPoints = is_string($pointsJson) && trim($pointsJson) !== '';

        if (! $hasFile && ! $hasPoints) {
            return $this->manualCropSaveResponse($request, $intake, false, __('intake.manual_crop_invalid_image'), null);
        }

        try {
            if ($hasFile) {
                $request->validate([
                    'cropped_image' => ['required', 'file', 'max:16384', 'mimes:png,jpeg,jpg,webp'],
                ]);

                $manual->saveFromUploadedFile($intake, $request->file('cropped_image'));
            } else {
                if (is_string($pointsJson) && strlen($pointsJson) > 20000) {
                    return $this->manualCropSaveResponse($request, $intake, false, __('intake.manual_crop_invalid_image'), null);
                }

                $points = json_decode((string) $pointsJson, true);
                if (! is_array($points)) {
                    return $this->manualCropSaveResponse($request, $intake, false, __('intake.manual_crop_invalid_image'), null);
                }

                $manual->saveFromPerspectivePoints($intake, $points, $rotationDeg);
            }
        } catch (\InvalidArgumentException) {
            return $this->manualCropSaveResponse($request, $intake, false, __('intake.manual_crop_invalid_image'), null);
        } catch (\Throwable $e) {
            report($e);

            return $this->manualCropSaveResponse($request, $intake, false, __('intake.manual_crop_save_failed'), null);
        }

        if (config('app.debug')) {
            session()->put('intake_manual_ocr_debug_'.$intake->id, [
                'intake_id' => $intake->id,
                'ocr_source_type' => 'manual_prepared',
                'manual_prepared_storage_relative' => $manual->relativePath($intake),
                'note' => 'Manual crop saved; OCR runs in ParseIntakeJob (no synchronous warm here).',
            ]);
        }

        $this->forgetIntakeParseOcrDebugCache($intake);
        $intake->update(['parse_status' => 'pending']);
        ParseIntakeJob::dispatchAfterResponse($intake->id, true);

        return $this->manualCropSaveResponse(
            $request,
            $intake,
            true,
            __('intake.manual_crop_saved_reparsing'),
            route('intake.status', $intake->id)
        );
    }

    /**
     * Remove manual prepared image; parse again from stored raw_ocr_text.
     */
    public function clearManualOcrPrepared(Request $request, BiodataIntake $intake, IntakeManualOcrPreparedService $manual)
    {
        if (! $this->intakeManualCropAccessGranted($intake)) {
            abort(403, __('intake.only_preview_own'));
        }

        if ($intake->approved_by_user) {
            return redirect()->back()->with('error', __('intake.manual_crop_denied_approved'));
        }

        $manual->delete($intake);
        session()->forget('intake_manual_ocr_debug_'.$intake->id);
        $this->forgetIntakeParseOcrDebugCache($intake);
        $intake->update(['parse_status' => 'pending']);
        ParseIntakeJob::dispatchAfterResponse($intake->id, true);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'message' => __('intake.manual_crop_cleared'),
                'redirect' => route('intake.status', $intake->id),
            ]);
        }

        return redirect()->route('intake.status', $intake->id)
            ->with('success', __('intake.manual_crop_cleared'));
    }

    /**
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    private function manualCropSaveResponse(Request $request, BiodataIntake $intake, bool $ok, string $message, ?string $redirectUrl)
    {
        if ($request->expectsJson() || $request->ajax() || $request->wantsJson() || $request->boolean('json')) {
            return response()->json([
                'ok' => $ok,
                'message' => $message,
                'redirect' => $redirectUrl,
            ], $ok ? 200 : 422);
        }

        if ($ok) {
            return redirect()->to($redirectUrl ?? route('intake.status', $intake->id))
                ->with('success', $message);
        }

        return redirect()->back()->with('error', $message);
    }

    /**
     * Local/dev only: serve original or last derived OCR preprocess artifact for this intake (session-bound).
     */
    public function debugOcrArtifact(Request $request, BiodataIntake $intake)
    {
        abort_unless(config('app.debug'), 404);
        if ((int) $intake->uploaded_by !== (int) auth()->id()) {
            abort(403);
        }

        $which = (string) $request->query('which', 'original');
        if ($which === 'original') {
            $rel = $intake->file_path;
            if ($rel === null || $rel === '') {
                abort(404);
            }
            $path = storage_path('app/private/'.$rel);
        } elseif ($which === 'derived') {
            $m = session('intake_ocr_debug_meta');
            if (! is_array($m) || (int) ($m['intake_id'] ?? 0) !== (int) $intake->id) {
                abort(404);
            }
            $path = $m['derived_absolute_path'] ?? null;
        } elseif ($which === 'manual_prepared') {
            $manual = app(IntakeManualOcrPreparedService::class);
            if (! $manual->exists($intake)) {
                abort(404);
            }
            $path = $manual->absolutePath($intake);
        } else {
            abort(404);
        }

        if ($path === '' || ! is_string($path) || ! is_file($path) || ! is_readable($path)) {
            abort(404);
        }

        return response()->file($path);
    }

    /**
     * Show approval.
     */
    public function approval()
    {
        return view('intake.approval');
    }

    /**
     * Show status.
     */
    public function status(BiodataIntake $intake)
    {
        if ((int) $intake->uploaded_by !== (int) auth()->id()) {
            abort(403, __('intake.only_view_status_own'));
        }

        $ocrPresetFeedback = null;
        $profile = auth()->user()->matrimonyProfile;
        $pendingSuggestions = is_array($profile?->pending_intake_suggestions_json)
            ? $profile->pending_intake_suggestions_json
            : [];

        $parseInputDebug = Cache::get('intake.parse_input_debug.'.$intake->id);
        if (! is_array($parseInputDebug)) {
            $parseInputDebug = null;
        }
        $parseInputTextPreview = Cache::get('intake.parse_input_text.'.$intake->id);
        if (! is_string($parseInputTextPreview)) {
            $parseInputTextPreview = null;
        }

        return view('intake.status', compact('intake', 'ocrPresetFeedback', 'profile', 'pendingSuggestions', 'parseInputDebug', 'parseInputTextPreview'));
    }

    /**
     * Apply one value from profile.pending_intake_suggestions_json (explicit user opt-in).
     */
    public function applyPendingIntakeSuggestion(Request $request, BiodataIntake $intake)
    {
        if ((int) $intake->uploaded_by !== (int) auth()->id()) {
            abort(403, __('intake.only_view_status_own'));
        }

        $validated = $request->validate([
            'scope' => ['required', 'in:core,extended'],
            'field_key' => ['required', 'string', 'max:160'],
        ]);

        $user = auth()->user();
        $profile = $user->matrimonyProfile;
        if (! $profile) {
            return redirect()
                ->route('intake.status', $intake)
                ->with('error', __('intake.apply_suggestion_no_profile'));
        }

        $pending = $profile->pending_intake_suggestions_json;
        if (! is_array($pending)) {
            return redirect()
                ->route('intake.status', $intake)
                ->with('error', __('intake.apply_suggestion_none'));
        }

        $scope = $validated['scope'];
        $key = $validated['field_key'];
        $bucket = $pending[$scope] ?? null;
        if (! is_array($bucket) || ! array_key_exists($key, $bucket)) {
            return redirect()
                ->route('intake.status', $intake)
                ->with('error', __('intake.apply_suggestion_missing'));
        }

        $value = $bucket[$key];
        $mutation = app(MutationService::class);

        if ($scope === 'core') {
            $allowed = $mutation->coreFieldKeysAllowedForIntakeSuggestionApply();
            if (! in_array($key, $allowed, true)) {
                return redirect()
                    ->route('intake.status', $intake)
                    ->with('error', __('intake.apply_suggestion_invalid_field'));
            }
            $snapshot = [
                'snapshot_schema_version' => 1,
                'core' => [$key => $value],
            ];
        } else {
            $snapshot = [
                'snapshot_schema_version' => 1,
                'extended_fields' => [$key => $value],
            ];
        }

        try {
            $mutation->applyManualSnapshot($profile, $snapshot, (int) $user->id, 'manual');
        } catch (\Throwable $e) {
            Log::warning('applyPendingIntakeSuggestion failed', [
                'profile_id' => $profile->id,
                'scope' => $scope,
                'field_key' => $key,
                'message' => $e->getMessage(),
            ]);

            return redirect()
                ->route('intake.status', $intake)
                ->with('error', __('intake.apply_suggestion_failed'));
        }

        unset($pending[$scope][$key]);
        if ($pending[$scope] === []) {
            unset($pending[$scope]);
        }
        if ($scope === 'core' && is_array($pending['core_field_suggestions'] ?? null)) {
            $pending['core_field_suggestions'] = array_values(array_filter(
                $pending['core_field_suggestions'],
                static fn ($row) => ! is_array($row) || ($row['field'] ?? '') !== $key
            ));
            if ($pending['core_field_suggestions'] === []) {
                unset($pending['core_field_suggestions']);
            }
        }
        $profile->pending_intake_suggestions_json = $pending === [] ? null : $pending;
        $profile->save();

        return redirect()
            ->route('intake.status', $intake)
            ->with('success', __('intake.suggestion_approved_success'));
    }

    /**
     * Discard one pending suggestion without mutating profile biodata.
     */
    public function rejectPendingIntakeSuggestion(Request $request, BiodataIntake $intake)
    {
        if ((int) $intake->uploaded_by !== (int) auth()->id()) {
            abort(403, __('intake.only_view_status_own'));
        }

        $validated = $request->validate([
            'scope' => ['required', 'in:core,extended'],
            'field_key' => ['required', 'string', 'max:160'],
        ]);

        $user = auth()->user();
        $profile = $user->matrimonyProfile;
        if (! $profile) {
            return redirect()
                ->route('intake.status', $intake)
                ->with('error', __('intake.apply_suggestion_no_profile'));
        }

        $pending = $profile->pending_intake_suggestions_json;
        if (! is_array($pending)) {
            return redirect()
                ->route('intake.status', $intake)
                ->with('error', __('intake.apply_suggestion_none'));
        }

        $scope = $validated['scope'];
        $key = $validated['field_key'];
        $bucket = $pending[$scope] ?? null;
        if (! is_array($bucket) || ! array_key_exists($key, $bucket)) {
            return redirect()
                ->route('intake.status', $intake)
                ->with('error', __('intake.apply_suggestion_missing'));
        }

        unset($pending[$scope][$key]);
        if ($pending[$scope] === []) {
            unset($pending[$scope]);
        }
        if ($scope === 'core' && is_array($pending['core_field_suggestions'] ?? null)) {
            $pending['core_field_suggestions'] = array_values(array_filter(
                $pending['core_field_suggestions'],
                static fn ($row) => ! is_array($row) || ($row['field'] ?? '') !== $key
            ));
            if ($pending['core_field_suggestions'] === []) {
                unset($pending['core_field_suggestions']);
            }
        }
        $profile->pending_intake_suggestions_json = $pending === [] ? null : $pending;
        $profile->save();

        return redirect()
            ->route('intake.status', $intake)
            ->with('success', __('intake.suggestion_rejected_success'));
    }
}
