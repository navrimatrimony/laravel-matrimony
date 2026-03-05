<?php

namespace App\Http\Controllers;

use App\Jobs\ParseIntakeJob;
use App\Models\BiodataIntake;
use App\Services\IntakeApprovalService;
use App\Services\OcrService;
use App\Services\Preview\PreviewSectionMapper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'file' => ['nullable', 'file', 'max:10240', 'required_without:raw_text'],
        ]);

        $path = null;
        $originalName = null;
        $rawText = null;

        if ($request->hasFile('file')) {
            $path = $request->file('file')->store('intakes');
            $originalName = $request->file('file')->getClientOriginalName();
            try {
                $rawText = $ocrService->extractTextFromPath($path, $originalName);
            } catch (\Throwable $e) {
                return redirect()->back()
                    ->withErrors(['file' => 'OCR extraction failed. ' . $e->getMessage()])
                    ->withInput();
            }
        } else {
            $rawText = $request->input('raw_text', '');
        }

        $intake = null;
        DB::transaction(function () use ($path, $originalName, $rawText, &$intake) {
            $intake = BiodataIntake::create([
                'uploaded_by' => auth()->id(),
                'file_path' => $path,
                'original_filename' => $originalName,
                'raw_ocr_text' => $rawText,
                'intake_status' => 'uploaded',
                'parse_status' => 'pending',
                'approved_by_user' => false,
                'intake_locked' => false,
                'snapshot_schema_version' => 1,
            ]);
        });

        ParseIntakeJob::dispatch($intake->id);

        return redirect()->route('intake.status', $intake->id)
    ->with('success', 'Intake uploaded successfully.');
    }

    /**
     * Show preview. Phase-5 Day-19: Editable snapshot, confidence enforcement, lifecycle transition.
     * When parse_status = 'parsed' and intake has linked profile: transition profile to awaiting_user_approval.
     * No profile mutation in preview; only biodata_intakes may be modified later on approve.
     */
	 
    public function preview(BiodataIntake $intake)
    {
        if ((int) $intake->uploaded_by !== (int) auth()->id()) {
            abort(403, 'You can only preview your own biodata uploads.');
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

        // Warning styling only (confidence < 0.75); does not block Approve.
        $warningFields = [];
        foreach ($confidenceMap as $field => $conf) {
            if ((float) $conf < 0.75) {
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
            'legal_cases' => 'legal_cases',
            'preferences' => 'preferences',
            'narrative' => 'extended_narrative',
        ];

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

            $needsReview = $prefillConf < 0.85;
            $mode = ($prefillConf >= $confThreshold && ! $requiredMissing) ? 'auto_prefill' : 'manual_apply';

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

        // Build profile-like object for religion-caste-selector (resolve labels to IDs when possible).
        $coreData = $sections['core']['data'] ?? [];
        $intakeProfile = (object) [
            'religion_id' => $coreData['religion_id'] ?? '',
            'caste_id' => $coreData['caste_id'] ?? '',
            'sub_caste_id' => $coreData['sub_caste_id'] ?? '',
            'religion_label' => '',
            'caste_label' => '',
            'subcaste_label' => '',
        ];
        $relLabel = is_scalar($coreData['religion'] ?? null) ? trim((string) $coreData['religion']) : '';
        $casteLabel = is_scalar($coreData['caste'] ?? null) ? trim((string) $coreData['caste']) : '';
        $subLabel = is_scalar($coreData['sub_caste'] ?? null) ? trim((string) $coreData['sub_caste']) : '';
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
        $maritalKeys = ['never_married', 'divorced', 'separated', 'widowed'];
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
                $ms = $maritalStatuses->first(fn ($s) => strcasecmp($s->label ?? '', $maritalText) === 0 || strcasecmp($s->key ?? '', $maritalText) === 0 || strcasecmp(str_replace(' ', '_', $s->label ?? ''), $maritalText) === 0);
                if ($ms) {
                    $maritalStatusId = $ms->id;
                }
            }
        }
        $intakeProfile->marital_status_id = $maritalStatusId ?? '';
        $childrenData = $sections['children']['data'] ?? [];
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
            'maritalStatuses',
            'profileMarriages',
            'profileChildren',
            'childLivingWithOptions'
        ));
    }

    /**
     * Approve intake. Uses edited snapshot from form when present; else parsed_json.
     * No profile update here; IntakeApprovalService only updates biodata_intakes.
     */
    public function approve(Request $request, BiodataIntake $intake)
    {
        if ((int) $intake->uploaded_by !== (int) auth()->id()) {
            abort(403, 'You can only approve your own biodata uploads.');
        }
        if (! session('preview_seen_' . $intake->id)) {
            abort(403);
        }

        $snapshot = $request->input('snapshot');
        if (is_array($snapshot)) {
            $base = is_array($intake->parsed_json) ? $intake->parsed_json : [];
            $snapshot = $this->normalizeApprovalSnapshot(array_merge($base, $snapshot));
            // MutationService expects marital_status_id on each profile_marriages row; inject from core.
            if (! empty($snapshot['core']['marital_status_id']) && is_array($snapshot['marriages'] ?? null)) {
                foreach (array_keys($snapshot['marriages']) as $i) {
                    if (is_array($snapshot['marriages'][$i])) {
                        $snapshot['marriages'][$i]['marital_status_id'] = $snapshot['core']['marital_status_id'];
                    }
                }
            }
        } else {
            $snapshot = null;
        }

        $result = app(IntakeApprovalService::class)->approve($intake, (int) auth()->id(), $snapshot);
        return redirect()->route('intake.status', $intake->id)
    ->with('success', 'Intake approved successfully.')
    ->with('mutation_result', $result);
    }

    /**
     * Ensure snapshot has SSOT top-level keys (all present, empty array when missing).
     * Scalar keys (horoscope, property_summary, extended_narrative) preserve string/null.
     */
    private function normalizeApprovalSnapshot(array $snapshot): array
    {
        $keys = [
            'core', 'contacts', 'children', 'marriages', 'siblings', 'education_history', 'career_history',
            'addresses', 'relatives', 'property_summary', 'property_assets', 'horoscope',
            'legal_cases', 'preferences', 'extended_narrative', 'confidence_map',
        ];
        $scalarKeys = ['property_summary', 'horoscope', 'extended_narrative'];
        $out = [];
        foreach ($keys as $k) {
            if (in_array($k, $scalarKeys, true)) {
                $out[$k] = array_key_exists($k, $snapshot) ? $snapshot[$k] : null;
            } else {
                $out[$k] = isset($snapshot[$k]) && is_array($snapshot[$k]) ? $snapshot[$k] : [];
            }
        }
        // Contacts: ensure phone_number for sync (legacy "number" from parsed/old forms).
        if (isset($out['contacts']) && is_array($out['contacts'])) {
            foreach ($out['contacts'] as $i => $row) {
                if (is_array($row) && isset($row['number']) && empty($row['phone_number'])) {
                    $out['contacts'][$i]['phone_number'] = $row['number'];
                }
            }
        }
        return $out;
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
            abort(403, 'You can only view status of your own biodata uploads.');
        }

        return view('intake.status', compact('intake'));
    }
}
