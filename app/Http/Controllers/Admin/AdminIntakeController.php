<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ParseIntakeJob;
use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Models\ConflictRecord;
use App\Models\MatrimonyProfile;
use App\Services\ExtendedFieldService;
use App\Services\Intake\IntakeExtractionReuseResolver;
use App\Services\Intake\IntakeLocationSuggestionLayerService;
use App\Services\Intake\IntakeReviewParseInputTextResolver;
use App\Services\IntakeApprovalService;
use App\Services\IntakeManualOcrPreparedService;
use App\Services\Parsing\ParserStrategyResolver;
use App\Services\Parsing\ProviderResolver;
use App\Support\IntakePreviewDiagnosticsPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| AdminIntakeController — Phase-5 Intake Architecture Refactor
|--------------------------------------------------------------------------
|
| Biodata intake list, show, and attach. Moved from AdminController.
| No profile mutation; attach updates only intake.matrimony_profile_id and intake.intake_status.
|
*/
class AdminIntakeController extends Controller
{
    /**
     * Phase-4 Day-4: List biodata intakes (admin only).
     * Read-only list view.
     */
    public function biodataIntakesIndex(Request $request)
    {
        $perPage = (int) $request->input('per_page', 15);
        $perPage = $perPage >= 1 && $perPage <= 100 ? $perPage : 15;
        $intakes = BiodataIntake::with(['uploadedByUser:id,name,email', 'profile:id,full_name'])
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        return view('admin.intake.index', compact('intakes'));
    }

    /**
     * Phase-4 Day-4: Show biodata intake sandbox (admin only).
     * Read-only view. NO parsing. NO profile mutation.
     */
    public function showBiodataIntake(BiodataIntake $intake)
    {
        $intake->load(['uploadedByUser:id,name,email', 'profile:id,full_name,lifecycle_state,pending_intake_suggestions_json']);
        $showAdminReextractAction = app(ProviderResolver::class)->parseJobUsesAiVisionExtraction();
        $reviewParse = app(IntakeReviewParseInputTextResolver::class)->resolve($intake);

        // Display-only diagnostics: use existing cached parse_input_debug + current parser mode resolution.
        $active = app(ParserStrategyResolver::class)->resolveActiveMode();
        $mode = app(ParserStrategyResolver::class)->normalizeMode($intake->parser_version ?: $active);
        $dbg = Cache::get('intake.parse_input_debug.'.$intake->id);
        $dbg = is_array($dbg) ? $dbg : [];
        $ocrQuality = Cache::get('intake.parse_ocr_quality.'.$intake->id);
        $ocrQuality = is_array($ocrQuality) ? $ocrQuality : [];

        $diagnosticsUnavailableReason = null;
        $meta = [];
        $diagnostics = null;

        if ($dbg !== []) {
            $meta = [
                'active_parser_mode' => $mode,
                'parse_input_source' => (string) ($dbg['parse_input_source'] ?? ''),
                'parse_input_provider' => (string) ($dbg['provider'] ?? ''),
                'parse_input_provider_source' => (string) ($dbg['provider_source'] ?? ''),
                'parse_input_ok' => (bool) ($dbg['ok'] ?? false),
                'parse_input_ai_extraction_skipped' => (bool) ($dbg['ai_extraction_skipped'] ?? false),
                'parse_input_canonical_transcript_source' => (string) ($dbg['canonical_transcript_source'] ?? ''),
                'parse_input_fallback_reason' => (string) ($dbg['fallback_reason'] ?? ''),
                'parse_input_extraction_reused' => $dbg['extraction_reused'] ?? null,
                'parse_input_extraction_reused_from' => (string) ($dbg['extraction_reused_from'] ?? ''),
                'parse_input_reused_source_intake_id' => $dbg['reused_source_intake_id'] ?? null,
                'parse_input_paid_extraction_api_called' => (bool) ($dbg['paid_extraction_api_called'] ?? false),
                'parse_input_parse_input_only_job' => (bool) ($dbg['parse_input_only_job'] ?? false),
                'parse_input_text_quality_ok' => $dbg['text_quality_ok'] ?? null,
                'parse_input_text_chars' => $dbg['text_chars'] ?? null,
                'parse_input_text_lines' => $dbg['text_lines'] ?? null,
                'parse_input_reason' => (string) ($dbg['reason'] ?? ''),
                'parse_input_source_field' => (string) ($dbg['source_field'] ?? ''),
                'ocr_source_type_effective' => 'cache_only_admin_view',
            ];

            $diagnostics = IntakePreviewDiagnosticsPresenter::summarize($intake, $meta);
        } else {
            $diagnosticsUnavailableReason = 'Diagnostics unavailable (parse_input_debug cache missing/expired for this intake).';
        }

        $attachedProfile = $intake->profile;

        $pendingConflictsForProfile = collect();
        if ($attachedProfile) {
            $pendingConflictsForProfile = ConflictRecord::query()
                ->where('profile_id', $attachedProfile->id)
                ->where('resolution_status', 'PENDING')
                ->orderByDesc('detected_at')
                ->get(['id', 'field_name', 'detected_at']);
        }
        $pendingConflictCount = $pendingConflictsForProfile->count();
        $recentPendingConflicts = $pendingConflictsForProfile->take(5);
        $pendingConflictFieldNames = $pendingConflictsForProfile
            ->pluck('field_name')
            ->map(static fn ($f) => (string) $f)
            ->unique()
            ->values()
            ->all();

        $suggestionsJson = $attachedProfile?->pending_intake_suggestions_json;
        $suggestionsPayload = is_array($suggestionsJson) ? $suggestionsJson : null;
        $profileForSuggestionContext = $attachedProfile;
        if ($attachedProfile !== null && $suggestionsPayload !== null && $suggestionsPayload !== []) {
            $profileForSuggestionContext = MatrimonyProfile::query()->whereKey($attachedProfile->id)->first()
                ?? $attachedProfile;
        }

        $pendingSuggestionsAdminSummary = $this->buildPendingIntakeSuggestionsAdminSummary(
            $suggestionsPayload,
            $profileForSuggestionContext,
            $pendingConflictFieldNames,
        );
        $pendingSuggestionsCount = $pendingSuggestionsAdminSummary['non_empty_bucket_count'];
        $pendingSuggestionsPresent = $pendingSuggestionsAdminSummary['has_any'];

        $pendingSuggestionsAdminSummary['review_strip'] = [
            'non_empty_bucket_count' => (int) $pendingSuggestionsAdminSummary['non_empty_bucket_count'],
            'core_field_suggestion_row_count' => (int) ($pendingSuggestionsAdminSummary['buckets']['core_field_suggestions']['item_count'] ?? 0),
            'pending_conflict_count' => $pendingConflictCount,
            'profile_attached' => (bool) $attachedProfile,
            'member_suggestion_page_available' => true,
        ];
        $unresolvedLocationOptions = app(IntakeLocationSuggestionLayerService::class)
            ->unresolvedCandidates($intake, 7);

        $requireAdminBeforeAttach = AdminSetting::getBool('intake_require_admin_before_attach', false);
        $snapshotOk = ! empty($intake->approval_snapshot_json) && is_array($intake->approval_snapshot_json);
        $applyReadiness = [
            'user_approved' => (bool) $intake->approved_by_user,
            'attached_profile' => (bool) $intake->matrimony_profile_id,
            'has_snapshot' => $snapshotOk,
            'admin_required' => $requireAdminBeforeAttach,
            'can_admin_apply' => (bool) $intake->approved_by_user
                && (bool) $intake->matrimony_profile_id
                && $requireAdminBeforeAttach
                && $snapshotOk,
        ];

        return view('admin.intake.show', compact(
            'intake',
            'showAdminReextractAction',
            'reviewParse',
            'dbg',
            'ocrQuality',
            'meta',
            'diagnostics',
            'diagnosticsUnavailableReason',
            'active',
            'mode',
            'attachedProfile',
            'pendingConflictCount',
            'recentPendingConflicts',
            'pendingSuggestionsPresent',
            'pendingSuggestionsCount',
            'pendingSuggestionsAdminSummary',
            'unresolvedLocationOptions',
            'requireAdminBeforeAttach',
            'applyReadiness',
        ));
    }

    /**
     * Phase-4 Day-4: Attach intake to profile (reference-only).
     * Updates ONLY intake.matrimony_profile_id and intake.intake_status.
     * MUST NOT modify matrimony_profiles table or any profile field.
     */
    public function attachBiodataIntake(Request $request, BiodataIntake $intake)
    {
        // Guard: Only DRAFT intakes can be attached
        if ($intake->intake_status !== BiodataIntake::STATUS_DRAFT) {
            return redirect()
                ->route('admin.biodata-intakes.show', $intake)
                ->withErrors(['attach' => 'Only DRAFT intakes can be attached to a profile.']);
        }

        $request->validate([
            'matrimony_profile_id' => ['required', 'integer', 'exists:matrimony_profiles,id'],
        ]);

        // Update ONLY intake fields
        $intake->update([
            'matrimony_profile_id' => (int) $request->matrimony_profile_id,
            'intake_status' => BiodataIntake::STATUS_ATTACHED,
        ]);

        // Explicitly verify: NO profile mutation
        // No MatrimonyProfile::update() calls
        // No field mapping
        // No data transfer

        return redirect()
            ->route('admin.biodata-intakes.show', $intake)
            ->with('success', 'Intake attached to profile. No profile data was modified.');
    }

    /**
     * Re-parse a single intake using the current active parser version.
     * ParseIntakeJob always recomputes parsed_json from this intake's extract/OCR text (no cross-intake parsed_json reuse).
     */
    public function reparse(BiodataIntake $intake)
    {
        Log::info('AdminIntakeController::reparse() hit', [
            'intake_id' => $intake->id,
            'timestamp' => now()->toIso8601String(),
        ]);

        $hasManualPrepared = app(IntakeManualOcrPreparedService::class)->exists($intake);
        $rawBlank = ($intake->raw_ocr_text === null || $intake->raw_ocr_text === '');
        $active = app(\App\Services\Parsing\ParserStrategyResolver::class)->resolveActiveMode();
        $isAiVision = $active === \App\Services\Parsing\ParserStrategyResolver::MODE_AI_VISION_EXTRACT_V1;

        // Only block reparse when we have no parse input at all.
        // For ai_vision_extract_v1, raw_ocr_text may be blank and that's OK as long as the file exists.
        if ($rawBlank && ! $hasManualPrepared && ! $isAiVision) {
            Log::warning('AdminIntakeController::reparse() early return: raw_ocr_text empty (non-ai-vision mode)', ['intake_id' => $intake->id]);

            return redirect()
                ->route('admin.biodata-intakes.show', $intake)
                ->with('error', 'Cannot re-parse: raw OCR text is empty.');
        }

        $intake->parse_status = 'pending';
        $intake->save();

        IntakeExtractionReuseResolver::flagNextParseJobAsParseInputOnly((int) $intake->id);
        ParseIntakeJob::dispatch($intake->id, true);
        Log::info('AdminIntakeController::reparse() dispatch called', ['intake_id' => $intake->id]);

        return redirect()
            ->route('admin.biodata-intakes.show', $intake)
            ->with('success', 'Re-parse job dispatched. Refresh after a few seconds.');
    }

    /**
     * Dispatch parse with a fresh paid vision extraction (skips parse-input-only and transient reuse).
     */
    public function reExtract(BiodataIntake $intake)
    {
        $hasManualPrepared = app(IntakeManualOcrPreparedService::class)->exists($intake);
        $rawBlank = ($intake->raw_ocr_text === null || $intake->raw_ocr_text === '');
        $usesVisionExtract = app(ProviderResolver::class)->parseJobUsesAiVisionExtraction();

        if ($rawBlank && ! $hasManualPrepared && ! $usesVisionExtract) {
            return redirect()
                ->route('admin.biodata-intakes.show', $intake)
                ->with('error', 'Cannot re-extract: raw OCR text is empty.');
        }
        if (! $usesVisionExtract) {
            return redirect()
                ->route('admin.biodata-intakes.show', $intake)
                ->with('error', 'Re-extract is only available when the parse job uses AI vision extraction.');
        }

        Cache::forget('intake.parse_ocr_debug.'.$intake->id);
        Cache::forget('intake.parse_input_debug.'.$intake->id);
        Cache::forget('intake.parse_input_text.'.$intake->id);

        $intake->parse_status = 'pending';
        $intake->save();

        IntakeExtractionReuseResolver::flagNextParseJobAsReExtract((int) $intake->id);
        ParseIntakeJob::dispatch($intake->id, true);
        Log::info('AdminIntakeController::reExtract() dispatch called', ['intake_id' => $intake->id]);

        return redirect()
            ->route('admin.biodata-intakes.show', $intake)
            ->with('success', 'Re-extract job dispatched (fresh vision extraction). Refresh after a few seconds.');
    }

    /**
     * Apply an already approved intake to its matrimony profile (admin-trigger).
     * Uses IntakeApprovalService pipeline when approved_by_user=true and snapshot present.
     */
    public function applyToProfile(BiodataIntake $intake)
    {
        if (! $intake->approved_by_user) {
            return redirect()
                ->route('admin.biodata-intakes.show', $intake)
                ->with('error', 'Cannot apply: intake is not yet approved by the user.');
        }

        if (! $intake->matrimony_profile_id) {
            return redirect()
                ->route('admin.biodata-intakes.show', $intake)
                ->with('error', 'Cannot apply: intake is not attached to any profile.');
        }

        $requireAdmin = AdminSetting::getBool('intake_require_admin_before_attach', false);
        if (! $requireAdmin) {
            return redirect()
                ->route('admin.biodata-intakes.show', $intake)
                ->with('error', 'Admin-triggered apply is only needed when admin approval is required.');
        }

        if (empty($intake->approval_snapshot_json) || ! is_array($intake->approval_snapshot_json)) {
            return redirect()
                ->route('admin.biodata-intakes.show', $intake)
                ->with('error', 'Cannot apply: approval snapshot is missing.');
        }

        $result = app(IntakeApprovalService::class)->approve($intake, (int) auth()->id(), $intake->approval_snapshot_json);

        $redirect = redirect()
            ->route('admin.biodata-intakes.show', $intake)
            ->with('mutation_result', $result);

        if (($result['already_applied'] ?? false) === true) {
            return $redirect->with('success', 'Intake was already applied earlier. No new mutation was performed.');
        }

        if (($result['blocked'] ?? null) === 'profile_conflict_pending') {
            return $redirect->with('error', 'Apply blocked: attached profile already has pending conflicts. Resolve them before applying this intake.');
        }

        if (($result['conflict_detected'] ?? false) === true) {
            return $redirect->with('warning', 'Intake apply completed, but conflicts were created and must be reviewed before final trust-safe completion.');
        }

        if (($result['mutation_success'] ?? false) === true) {
            return $redirect->with('success', 'Intake applied to profile successfully.');
        }

        return $redirect->with('success', 'Intake apply pipeline triggered.');
    }

    /**
     * Admin-side explicit intake location resolve into approval_snapshot_json.
     */
    public function resolveLocationSuggestion(Request $request, BiodataIntake $intake)
    {
        if ((bool) $intake->approved_by_user) {
            return response()->json([
                'success' => false,
                'message' => 'Intake is already approved and cannot be edited.',
            ], 422);
        }

        $validated = $request->validate([
            'field' => ['required', 'string', 'max:64'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
        ]);

        $resolver = app(IntakeLocationSuggestionLayerService::class);
        $result = $resolver->resolveFieldToCity($intake, (string) $validated['field'], (int) $validated['city_id']);
        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => (string) ($result['message'] ?? 'Could not resolve location field.'),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Location resolved.',
        ]);
    }

    /** @var list<string> */
    private const ADMIN_INTAKE_PROFILE_SCALAR_KEYS = [
        'full_name', 'gender_id', 'date_of_birth', 'birth_time', 'marital_status_id',
        'religion_id', 'caste_id', 'sub_caste_id', 'highest_education',
        'country_id', 'state_id', 'district_id', 'taluka_id', 'city_id',
        'address_line', 'height_cm', 'weight_kg', 'profile_photo',
        'complexion_id', 'physical_build_id', 'blood_group_id',
        'spectacles_lens', 'physical_condition', 'family_type_id', 'income_currency_id',
        'father_name', 'father_occupation', 'mother_name', 'mother_occupation',
        'specialization', 'occupation_title', 'company_name', 'work_location_text',
        'other_relatives_text', 'birth_place_text',
    ];

    /**
     * Read-only summary of profile.pending_intake_suggestions_json for admin intake show (no writes).
     *
     * @param  array<string, mixed>|null  $payload
     * @param  list<string>  $pendingConflictFieldNames
     * @return array{
     *     has_any: bool,
     *     non_empty_bucket_count: int,
     *     buckets: array<string, array{
     *         key: string,
     *         label: string,
     *         exists: bool,
     *         item_count: int,
     *         preview: mixed
     *     }>
     * }
     */
    private function buildPendingIntakeSuggestionsAdminSummary(
        ?array $payload,
        ?MatrimonyProfile $profile,
        array $pendingConflictFieldNames,
    ): array {
        $order = [
            'core' => 'Core',
            'core_field_suggestions' => 'Core field suggestions',
            'extended' => 'Extended fields',
            'entities' => 'Entities',
            'birth_place' => 'Birth place',
            'native_place' => 'Native place',
            'preferences' => 'Preferences',
            'extended_narrative' => 'Extended narrative',
            'other_relatives_text' => 'Other relatives text',
        ];

        $buckets = [];
        foreach (array_keys($order) as $key) {
            $buckets[$key] = [
                'key' => $key,
                'label' => $order[$key],
                'exists' => false,
                'item_count' => 0,
                'preview' => null,
            ];
        }

        if ($payload === null || $payload === []) {
            return [
                'has_any' => false,
                'non_empty_bucket_count' => 0,
                'buckets' => $buckets,
            ];
        }

        $extendedProfileValues = [];
        if ($profile !== null && isset($payload['extended']) && is_array($payload['extended']) && $payload['extended'] !== []) {
            $extendedProfileValues = ExtendedFieldService::getValuesForProfile($profile);
        }

        foreach (array_keys($order) as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            $raw = $payload[$key];
            $entry = &$buckets[$key];

            if ($key === 'core') {
                if (is_array($raw) && $raw !== []) {
                    $entry['item_count'] = count($raw);
                    $entry['exists'] = $entry['item_count'] > 0;
                    $pairs = [];
                    $n = 0;
                    foreach ($raw as $k => $v) {
                        if ($n >= 40) {
                            break;
                        }
                        $fk = (string) $k;
                        $valuePreview = $this->adminSuggestionPreviewScalar($v, 240);
                        $profPreview = $this->adminProfileScalarPreviewForField($profile, $fk);
                        $pairs[] = [
                            'key' => $fk,
                            'value' => $valuePreview,
                            'profile_value_preview' => $profPreview,
                            'badge' => $this->adminSuggestionPairStateBadge($profPreview, $valuePreview, $fk, $pendingConflictFieldNames),
                        ];
                        $n++;
                    }
                    $entry['preview'] = ['type' => 'key_value', 'pairs' => $pairs];
                }
            } elseif ($key === 'core_field_suggestions') {
                if (is_array($raw) && $raw !== []) {
                    $rows = [];
                    foreach ($raw as $row) {
                        if (! is_array($row)) {
                            continue;
                        }
                        $field = (string) ($row['field'] ?? '');
                        $oldPreview = $this->adminSuggestionPreviewScalar($row['old_value'] ?? null, 240);
                        $newPreview = $this->adminSuggestionPreviewScalar($row['new_value'] ?? null, 240);
                        $mappedPreview = $this->adminProfileScalarPreviewForField($profile, $field);
                        $currentDisplay = $mappedPreview ?? $oldPreview;
                        $currentForHint = trim($mappedPreview ?? $oldPreview);
                        $rows[] = [
                            'field' => $field,
                            'old_value' => $oldPreview,
                            'new_value' => $newPreview,
                            'current_profile_value' => $currentDisplay,
                            'hint' => $this->adminCoreFieldSuggestionHint(
                                $field,
                                $currentForHint,
                                $newPreview,
                                $pendingConflictFieldNames,
                            ),
                        ];
                    }
                    $entry['item_count'] = count($rows);
                    $entry['exists'] = $entry['item_count'] > 0;
                    $entry['preview'] = ['type' => 'core_field_rows', 'rows' => $rows];
                }
            } elseif ($key === 'extended') {
                if (is_array($raw) && $raw !== []) {
                    $entry['item_count'] = count($raw);
                    $entry['exists'] = true;
                    $pairs = [];
                    $n = 0;
                    foreach ($raw as $k => $v) {
                        if ($n >= 40) {
                            break;
                        }
                        $ek = (string) $k;
                        $valuePreview = $this->adminSuggestionPreviewScalar($v, 240);
                        $hasStored = array_key_exists($ek, $extendedProfileValues);
                        $profPreview = $hasStored
                            ? $this->adminSuggestionPreviewScalar($extendedProfileValues[$ek] ?? null, 240)
                            : null;
                        $pairs[] = [
                            'key' => $ek,
                            'value' => $valuePreview,
                            'profile_value_preview' => $profPreview,
                            'badge' => $this->adminSuggestionPairStateBadge($hasStored ? $profPreview : null, $valuePreview, $ek, $pendingConflictFieldNames),
                        ];
                        $n++;
                    }
                    $entry['preview'] = ['type' => 'key_value', 'pairs' => $pairs];
                }
            } elseif ($key === 'entities') {
                if (is_array($raw) && $raw !== []) {
                    $sections = [];
                    foreach ($raw as $entityKey => $section) {
                        if ($section === null) {
                            continue;
                        }
                        if (is_array($section) && $section !== []) {
                            $sections[] = [
                                'name' => (string) $entityKey,
                                'row_count' => $this->adminEntitySectionRowCount($section),
                            ];
                        }
                    }
                    $entry['item_count'] = count($sections);
                    $entry['exists'] = $entry['item_count'] > 0;
                    $entry['preview'] = ['type' => 'entity_sections', 'sections' => $sections];
                }
            } elseif (in_array($key, ['birth_place', 'native_place', 'preferences', 'extended_narrative'], true)) {
                if (is_array($raw) && $raw !== []) {
                    $entry['exists'] = true;
                    $entry['item_count'] = 1;
                    $enc = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                    $entry['preview'] = [
                        'type' => 'json',
                        'text' => is_string($enc) ? $enc : '{}',
                    ];
                }
            } elseif ($key === 'other_relatives_text') {
                $s = trim((string) $raw);
                if ($s !== '') {
                    $entry['exists'] = true;
                    $entry['item_count'] = 1;
                    $entry['preview'] = [
                        'type' => 'text',
                        'text' => strlen($s) > 2000 ? substr($s, 0, 2000).'…' : $s,
                    ];
                }
            }
            unset($entry);
        }

        $nonEmpty = 0;
        foreach ($buckets as $b) {
            if (! empty($b['exists'])) {
                $nonEmpty++;
            }
        }

        return [
            'has_any' => $nonEmpty > 0,
            'non_empty_bucket_count' => $nonEmpty,
            'buckets' => $buckets,
        ];
    }

    private function adminProfileScalarPreviewForField(?MatrimonyProfile $profile, string $fieldKey): ?string
    {
        if ($profile === null || ! in_array($fieldKey, self::ADMIN_INTAKE_PROFILE_SCALAR_KEYS, true)) {
            return null;
        }
        if (! array_key_exists($fieldKey, $profile->getAttributes())) {
            return null;
        }

        return $this->adminSuggestionPreviewScalar($profile->getAttribute($fieldKey), 500);
    }

    /**
     * @param  list<string>  $pendingConflictFieldNames
     */
    private function adminCoreFieldSuggestionHint(
        string $field,
        string $currentForLogic,
        string $newPreview,
        array $pendingConflictFieldNames,
    ): string {
        $cur = trim($currentForLogic);
        $new = trim($newPreview);
        if ($cur === '' && $new !== '') {
            return 'Likely safe fill';
        }
        if ($cur !== '' && $new !== '' && $cur !== $new) {
            return 'Review overwrite';
        }
        if ($pendingConflictFieldNames !== [] && in_array($field, $pendingConflictFieldNames, true)) {
            return 'Check conflict record';
        }

        return 'Value changed';
    }

    /**
     * @param  list<string>  $pendingConflictFieldNames
     */
    private function adminSuggestionPairStateBadge(
        ?string $profilePreview,
        string $suggestionPreview,
        string $fieldKey,
        array $pendingConflictFieldNames,
    ): ?string {
        $p = trim((string) ($profilePreview ?? ''));
        $s = trim($suggestionPreview);
        if ($profilePreview !== null) {
            if ($p === '' && $s !== '') {
                return 'empty → incoming';
            }
            if ($p !== '' && $s !== '' && $p !== $s) {
                return 'existing → suggestion';
            }
        }
        if ($pendingConflictFieldNames !== [] && in_array($fieldKey, $pendingConflictFieldNames, true)) {
            return 'review';
        }

        return null;
    }

    private function adminEntitySectionRowCount(array $section): int
    {
        if ($section === []) {
            return 0;
        }
        if (array_is_list($section)) {
            return count($section);
        }
        foreach ($section as $v) {
            if (! is_array($v)) {
                return 1;
            }
        }

        return count($section);
    }

    private function adminSuggestionPreviewScalar(mixed $v, int $maxLen): string
    {
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_scalar($v)) {
            $s = trim((string) $v);

            return strlen($s) > $maxLen ? substr($s, 0, $maxLen).'…' : $s;
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d H:i:s');
        }
        if (is_array($v)) {
            $enc = json_encode($v, JSON_UNESCAPED_UNICODE);
            $s = is_string($enc) ? $enc : '';

            return strlen($s) > $maxLen ? substr($s, 0, $maxLen).'…' : $s;
        }

        return '';
    }
}
