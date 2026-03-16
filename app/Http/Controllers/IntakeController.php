<?php

namespace App\Http\Controllers;

use App\Jobs\ParseIntakeJob;
use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Services\IntakeApprovalService;
use App\Services\OcrService;
use App\Services\Preview\PreviewSectionMapper;
use Illuminate\Http\Request;
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
                    $parser = new PdfParser();
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
                    ->withErrors(['file' => __('intake.ocr_extraction_failed') . ' ' . $e->getMessage()])
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
        if (empty($data) || !is_array($data)) {
            abort(400);
        }

        $mapper = new PreviewSectionMapper();
        $sections = $mapper->map($data);

        // Display: use approval_snapshot_json['core'] for form values when present, else parsed_json (already in sections).
        if (! empty($intake->approval_snapshot_json) && is_array($intake->approval_snapshot_json)
            && isset($intake->approval_snapshot_json['core']) && is_array($intake->approval_snapshot_json['core'])) {
            $sections['core'] = $sections['core'] ?? [];
            $sections['core']['data'] = $intake->approval_snapshot_json['core'];
        }

        $confidenceMap = $data['confidence_map'] ?? [];
        if (!is_array($confidenceMap)) {
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

        session(['preview_seen_' . $intake->id => true]);

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
        $rawOcrText = $intake->raw_ocr_text ?? '';
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
        // Copy every other key from parsed core so form/engines see 100% of biodata (e.g. primary_contact_number, father_name, mother_name, height_cm, annual_income, birth_place string).
        foreach ($coreData as $k => $v) {
            if (! property_exists($intakeProfile, $k)) {
                $intakeProfile->{$k} = $v;
            }
        }
        // Resolve gender_id from text if needed (OCR often has "Male"/"Female").
        if (empty($intakeProfile->gender_id) && ! empty($coreData['gender'])) {
            $genderText = is_scalar($coreData['gender']) ? trim((string) $coreData['gender']) : '';
            if ($genderText !== '') {
                $g = \App\Models\MasterGender::where('is_active', true)
                    ->where(function ($q) use ($genderText) {
                        $q->where('key', 'like', $genderText)->orWhere('label', 'like', $genderText);
                    })->first();
                if ($g) {
                    $intakeProfile->gender_id = $g->id;
                }
            }
        }
        $intakeProfile->birthPlaceDisplay = '';
        if (! empty($intakeProfile->birth_city_id)) {
            $intakeProfile->birthPlaceDisplay = \App\Models\City::where('id', $intakeProfile->birth_city_id)->value('name') ?? '';
        }
        // Resolve birth_place string (from parser) to location IDs so Basic Info birth-place typeahead shows value.
        if (empty($intakeProfile->birth_city_id) && ! empty($intakeProfile->birth_place) && is_scalar($intakeProfile->birth_place)) {
            $birthPlaceStr = trim((string) $intakeProfile->birth_place);
            if ($birthPlaceStr !== '' && $birthPlaceStr !== \App\Services\Ocr\OcrSuggestionEngine::PLACEHOLDER_NOT_FOUND) {
                $cityQuery = \App\Models\City::where('name', 'like', $birthPlaceStr . '%');
                if (\Illuminate\Support\Facades\Schema::hasColumn((new \App\Models\City)->getTable(), 'name_mr')) {
                    $cityQuery->orWhere('name_mr', 'like', $birthPlaceStr . '%');
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
        if ($relLabel !== '' && $relLabel !== \App\Services\Ocr\OcrSuggestionEngine::PLACEHOLDER_NOT_FOUND && $relLabel !== \App\Services\Ocr\OcrSuggestionEngine::PLACEHOLDER_SELECT_REQUIRED) {
            $rel = \App\Models\Religion::where('is_active', true)->where('label', $relLabel)->first();
            if ($rel) {
                $intakeProfile->religion_id = $rel->id;
                $intakeProfile->religion_label = $rel->label;
            } else {
                $intakeProfile->religion_label = $relLabel;
            }
        }
        if ($intakeProfile->religion_id && $casteLabel !== '' && $casteLabel !== \App\Services\Ocr\OcrSuggestionEngine::PLACEHOLDER_NOT_FOUND && $casteLabel !== \App\Services\Ocr\OcrSuggestionEngine::PLACEHOLDER_SELECT_REQUIRED) {
            $c = \App\Models\Caste::where('religion_id', $intakeProfile->religion_id)->where('label', $casteLabel)->first();
            if ($c) {
                $intakeProfile->caste_id = $c->id;
                $intakeProfile->caste_label = $c->label;
            } else {
                $intakeProfile->caste_label = $casteLabel;
            }
        }
        if ($intakeProfile->caste_id && $subLabel !== '' && $subLabel !== \App\Services\Ocr\OcrSuggestionEngine::PLACEHOLDER_NOT_FOUND && $subLabel !== \App\Services\Ocr\OcrSuggestionEngine::PLACEHOLDER_SELECT_REQUIRED) {
            $s = \App\Models\SubCaste::where('caste_id', $intakeProfile->caste_id)->where('label', $subLabel)->first();
            if ($s) {
                $intakeProfile->sub_caste_id = $s->id;
                $intakeProfile->subcaste_label = $s->label;
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
            $rawText = $intake->raw_ocr_text ?? '';
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
        $horoscopeRulesJson = app(\App\Services\HoroscopeRuleService::class)->getRulesForFrontend();
        $horoscopeSource = $sections['horoscope']['data'] ?? ($intake->approval_snapshot_json['horoscope'] ?? []);
        $horoscopeRow = is_array($horoscopeSource) && isset($horoscopeSource[0]) ? $horoscopeSource[0] : (is_array($horoscopeSource) ? $horoscopeSource : []);
        $horoscopeRow = is_object($horoscopeRow) ? (array) $horoscopeRow : $horoscopeRow;
        $horoscopeDependencyWarnings = app(\App\Services\HoroscopeRuleService::class)->getValidationWarningsForUI($horoscopeRow)['warnings'];

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
        $relativesFromSnapshot = $snapshot['relatives'] ?? [];
        $siblingRowsFromRelatives = $this->extractSiblingRowsFromParsedRelatives($relativesFromSnapshot);
        $profileSiblings = $this->mergeParsedSiblingsIntoProfileSiblings($profileSiblings, $siblingRowsFromRelatives);
        $relativesOnly = $this->excludeSiblingRelationsFromRelatives($relativesFromSnapshot);
        $hasSiblings = isset($snapshot['core']['has_siblings']) ? (bool) $snapshot['core']['has_siblings'] : $profileSiblings->isNotEmpty();
        [$builtPaternal, $builtMaternal, $dajiRows] = $this->partitionAndStructureRelativesForIntake($relativesOnly);
        $profileRelativesParentsFamily = isset($snapshot['relatives_parents_family']) && is_array($snapshot['relatives_parents_family'])
            ? collect($snapshot['relatives_parents_family'])->map(fn ($r) => (object) (is_array($r) ? $r : []))
            : $builtPaternal;
        $profileRelativesMaternalFamily = isset($snapshot['relatives_maternal_family']) && is_array($snapshot['relatives_maternal_family'])
            ? collect($snapshot['relatives_maternal_family'])->map(fn ($r) => (object) (is_array($r) ? $r : []))
            : $builtMaternal;
        // दाजी = बहिणीचा नवरा: merge into sibling panel (first sister's spouse) so it saves in the same place
        $profileSiblings = $this->mergeDajiIntoSiblings($profileSiblings, $dajiRows);
        $profile_property_summary = $snapshot['property_summary'] ?? null;
        $profile_property_assets = collect($snapshot['property_assets'] ?? []);
        $profile_horoscope_data = is_array($horoscopeRow) ? (object) $horoscopeRow : $horoscopeRow;
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
        $profileEducation = collect();
        $profileCareer = collect();
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

        return view('intake.preview', compact(
            'intake',
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
     * Approve intake. Uses edited snapshot from form when present; else parsed_json.
     * No profile update here; IntakeApprovalService only updates biodata_intakes.
     */
    public function approve(Request $request, BiodataIntake $intake)
    {
        if ((int) $intake->uploaded_by !== (int) auth()->id()) {
            abort(403, __('intake.only_approve_own'));
        }
        if (! session('preview_seen_' . $intake->id)) {
            abort(403);
        }

        $snapshot = $request->input('snapshot');
        if (is_array($snapshot)) {
            $base = is_array($intake->parsed_json) ? $intake->parsed_json : [];
            // Centralized full_form: contacts section and education/career history may submit at top level (no snapshot prefix).
            if (is_array($request->input('contacts'))) {
                $snapshot['contacts'] = $request->input('contacts');
            }
            if (is_array($request->input('education_history'))) {
                $snapshot['education_history'] = $request->input('education_history');
            }
            if (is_array($request->input('career_history'))) {
                $snapshot['career_history'] = $request->input('career_history');
            }
            $core = $snapshot['core'] ?? [];
            if (is_array($core)) {
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
                $structured = [
                    'relation_type' => $englishType,
                    'name' => $directName,
                    'occupation' => $row['occupation'] ?? null,
                    'contact_number' => $row['contact_number'] ?? null,
                    'notes' => $notes !== '' ? $notes : '',
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
                    $name = preg_replace('/^\s*[+\-*\.\s०-९0-9]+\s*/u', '', $name);
                    $name = trim($name);
                }
                if ($name === '' || $name === null) {
                    $name = '';
                }
                if ($isDaji) {
                    $dajiRows[] = [
                        'name' => $name ?? '',
                        'address_line' => $addressOrNotes !== '' ? $addressOrNotes : '',
                        'occupation_title' => $row['occupation'] ?? null,
                        'contact_number' => $row['contact_number'] ?? null,
                    ];
                } else {
                    $structured = [
                        'relation_type' => $englishType,
                        'name' => $name ?? '',
                        'occupation' => $row['occupation'] ?? null,
                        'contact_number' => $row['contact_number'] ?? null,
                        'notes' => $addressOrNotes !== '' ? $addressOrNotes : (string) $segment,
                    ];
                    if ($isMaternal) {
                        $maternal[] = (object) $structured;
                    } else {
                        $paternal[] = (object) $structured;
                    }
                }
            }
        }
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
        $spouse = [
            'name' => $firstDaji['name'] ?? '',
            'address_line' => $firstDaji['address_line'] ?? '',
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
            $relatives[] = [
                'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                'relation_type' => $relationType ?: '',
                'name' => $name ?: '',
                'occupation' => trim((string) ($row['occupation'] ?? '')) ?: null,
                'city_id' => ! empty($row['city_id']) ? (int) $row['city_id'] : null,
                'state_id' => ! empty($row['state_id']) ? (int) $row['state_id'] : null,
                'contact_number' => trim((string) ($row['contact_number'] ?? '')) ?: null,
                'notes' => trim((string) ($row['notes'] ?? '')) ?: null,
                'is_primary_contact' => ! empty($row['is_primary_contact']),
            ];
        }
        return $relatives;
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

        return view('intake.status', compact('intake'));
    }
}
