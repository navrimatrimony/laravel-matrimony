<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ParseIntakeJob;
use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Models\ConflictRecord;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\ExtendedFieldService;
use App\Services\Intake\IntakeCreationService;
use App\Services\Intake\IntakeExtractionReuseResolver;
use App\Services\Intake\IntakeHumanReviewSnapshotService;
use App\Services\Intake\IntakeLocationSuggestionLayerService;
use App\Services\Intake\IntakePhotoCandidateApplyService;
use App\Services\Intake\IntakePhotoCandidatePreviewService;
use App\Services\Intake\IntakePipelineService;
use App\Services\Intake\IntakePreviewNormalizedDraftPresenter;
use App\Services\Intake\IntakeReviewParseInputTextResolver;
use App\Services\IntakeApprovalService;
use App\Services\IntakeManualOcrPreparedService;
use App\Services\MutationService;
use App\Services\Parsing\ParserStrategyResolver;
use App\Services\Parsing\ProviderResolver;
use App\Support\IntakePreviewDiagnosticsPresenter;
use App\Support\MobileNumber;
use App\Support\Validation\AddressHierarchyRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
    /** @var list<string> */
    private const ADMIN_REVIEW_SNAPSHOT_KEYS = [
        'core',
        'contacts',
        'birth_place',
        'native_place',
        'children',
        'marriages',
        'education_history',
        'career_history',
        'addresses',
        'parents_addresses',
        'self_addresses',
        'siblings',
        'relatives',
        'relatives_parents_family',
        'relatives_maternal_family',
        'alliance_networks',
        'property_summary',
        'property_assets',
        'horoscope',
        'legal_cases',
        'preferences',
        'extended_narrative',
        'other_relatives_text',
    ];

    public function createEntry()
    {
        $users = $this->recentMemberUsers();
        $genders = $this->activeGenders();

        return view('admin.intake.create', compact('users', 'genders'));
    }

    public function createProfileEntry()
    {
        $genders = $this->activeGenders();

        return view('admin.intake.create-profile', compact('genders'));
    }

    public function storeEntry(Request $request, IntakeCreationService $intakeCreation)
    {
        $validated = $request->validate([
            'user_mode' => ['required', Rule::in(['existing', 'new'])],
            'existing_user_id' => ['nullable', 'required_if:user_mode,existing', 'integer', 'exists:users,id'],
            'new_name' => ['nullable', 'required_if:user_mode,new', 'string', 'max:255'],
            'new_mobile' => ['nullable', 'required_if:user_mode,new', 'string', 'max:32'],
            'new_email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'new_gender' => ['nullable', 'required_if:user_mode,new', Rule::exists('master_genders', 'key')->where('is_active', true)],
            'registering_for' => [
                'nullable',
                'required_if:user_mode,new',
                Rule::in(['self', 'parent_guardian', 'sibling', 'relative', 'friend', 'other']),
            ],
            'raw_text' => ['nullable', 'string', 'required_without:file'],
            'file' => ['nullable', 'file', 'max:20480', 'required_without:raw_text'],
        ]);

        if ($validated['user_mode'] === 'existing') {
            $targetUser = User::query()->findOrFail((int) $validated['existing_user_id']);
            if ($targetUser->isAnyAdmin()) {
                return back()
                    ->withInput()
                    ->withErrors(['existing_user_id' => 'Select a non-admin member account.']);
            }

            $intake = $intakeCreation->createForUser(
                (int) $targetUser->id,
                $request->file('file'),
                $request->input('raw_text'),
            );
        } else {
            $mobile = MobileNumber::normalize($validated['new_mobile']);
            if ($mobile === null) {
                return back()
                    ->withInput()
                    ->withErrors(['new_mobile' => __('otp.enter_valid_10_digit_mobile')]);
            }

            Validator::make(
                ['new_mobile' => $mobile],
                ['new_mobile' => ['required', Rule::unique('users', 'mobile')]],
                ['new_mobile.unique' => __('auth.mobile_duplicate_register')]
            )->validate();

            $prepared = $intakeCreation->prepare(
                null,
                $request->file('file'),
                $request->input('raw_text'),
            );

            [$targetUser, $intake] = DB::transaction(function () use ($validated, $mobile, $prepared, $intakeCreation): array {
                $user = User::create([
                    'name' => $validated['new_name'],
                    'email' => ($validated['new_email'] ?? null) ?: null,
                    'mobile' => $mobile,
                    'password' => Hash::make(Str::random(40)),
                    'registering_for' => $validated['registering_for'],
                    'referral_code' => User::generateUniqueReferralCode(),
                ]);

                return [
                    $user,
                    $intakeCreation->persistPrepared((int) $user->id, $prepared),
                ];
            });

            $intakeCreation->dispatchParseIfEnabled($intake);
        }

        return redirect()
            ->route('admin.biodata-intakes.show', $intake)
            ->with('success', "Intake created for {$targetUser->name}. Review parsing before approval.");
    }

    public function storeProfileEntry(Request $request, MutationService $mutationService)
    {
        $validated = $request->validate([
            'new_name' => ['required', 'string', 'max:255'],
            'new_mobile' => ['required', 'string', 'max:32'],
            'new_email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'new_gender' => ['required', Rule::exists('master_genders', 'key')->where('is_active', true)],
            'registering_for' => [
                'required',
                Rule::in(['self', 'parent_guardian', 'sibling', 'relative', 'friend', 'other']),
            ],
        ]);

        $mobile = MobileNumber::normalize($validated['new_mobile']);
        if ($mobile === null) {
            return back()
                ->withInput()
                ->withErrors(['new_mobile' => __('otp.enter_valid_10_digit_mobile')]);
        }

        Validator::make(
            ['new_mobile' => $mobile],
            ['new_mobile' => ['required', Rule::unique('users', 'mobile')]],
            ['new_mobile.unique' => __('auth.mobile_duplicate_register')]
        )->validate();

        $genderId = MasterGender::query()
            ->where('key', $validated['new_gender'])
            ->where('is_active', true)
            ->value('id');

        [$member, $profile] = DB::transaction(function () use ($validated, $mobile, $genderId, $mutationService): array {
            $member = User::create([
                'name' => $validated['new_name'],
                'email' => ($validated['new_email'] ?? null) ?: null,
                'mobile' => $mobile,
                'password' => Hash::make(Str::random(40)),
                'registering_for' => $validated['registering_for'],
                'referral_code' => User::generateUniqueReferralCode(),
            ]);

            $profile = $mutationService->createDraftProfileForUser($member, [
                'gender_id' => $genderId,
                'is_suspended' => \App\Services\Admin\AdminSettingService::isManualProfileActivationRequired(),
            ]);

            return [$member, $profile];
        });

        session([
            'admin_registration_profile_id' => (int) $profile->id,
            'admin_edit_profile_id' => (int) $profile->id,
        ]);

        return redirect()
            ->route('matrimony.profile.wizard.section', [
                'section' => 'full',
                'all' => 1,
                'profile_id' => $profile->id,
            ])
            ->with('success', "Registration created for {$member->name}. Complete the existing Edit all form.");
    }

    private function recentMemberUsers()
    {
        return User::query()
            ->where(function ($query): void {
                $query->whereNull('admin_role')
                    ->where(function ($legacyAdminQuery): void {
                        $legacyAdminQuery->whereNull('is_admin')
                            ->orWhere('is_admin', false);
                    });
            })
            ->latest('id')
            ->limit(500)
            ->get(['id', 'name', 'mobile', 'email']);
    }

    private function activeGenders()
    {
        return MasterGender::query()
            ->where('is_active', true)
            ->whereIn('key', ['male', 'female'])
            ->orderByRaw("CASE WHEN `key` = 'male' THEN 1 ELSE 2 END")
            ->get(['id', 'key', 'label']);
    }

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
        $intake->load(['uploadedByUser:id,name,email', 'reviewedByUser:id,name,email', 'profile:id,full_name,lifecycle_state,pending_intake_suggestions_json']);
        $showAdminReextractAction = app(ProviderResolver::class)->parseJobUsesAiVisionExtraction();
        $reviewParse = app(IntakeReviewParseInputTextResolver::class)->resolve($intake);
        $reviewTextSource = (string) ($reviewParse['source'] ?? 'empty');
        $reviewTextIsBiodata = in_array($reviewTextSource, ['parse_snapshot', 'ai_vision_cache', 'ocr_transient'], true);
        $normalizedDraftPreview = app(IntakePreviewNormalizedDraftPresenter::class)
            ->present(
                (string) ($reviewParse['text'] ?? ''),
                $reviewTextIsBiodata,
                is_array($intake->parsed_json) ? $intake->parsed_json : null
            );
        $intakePhotoPreview = app(IntakePhotoCandidatePreviewService::class)
            ->preview($intake);

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
        $adminReviewSnapshotEditor = $this->adminReviewSnapshotEditor($intake);

        return view('admin.intake.show', compact(
            'intake',
            'showAdminReextractAction',
            'reviewParse',
            'normalizedDraftPreview',
            'intakePhotoPreview',
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
            'adminReviewSnapshotEditor',
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
        $intake->last_error = null;
        $intake->save();

        IntakeExtractionReuseResolver::flagNextParseJobAsParseInputOnly((int) $intake->id);
        if (app()->environment('testing')) {
            ParseIntakeJob::dispatchSync($intake->id, true);
            Log::info('AdminIntakeController::reparse() completed inline', ['intake_id' => $intake->id]);

            return redirect()
                ->route('admin.biodata-intakes.show', $intake)
                ->with('success', 'Re-parse completed. Refresh now to see updated JSON.');
        }

        ParseIntakeJob::dispatch($intake->id, true);
        Log::info('AdminIntakeController::reparse() dispatched async', ['intake_id' => $intake->id]);

        return redirect()
            ->route('admin.biodata-intakes.show', $intake)
            ->with('success', 'Re-parse started in background. Refresh after a few seconds to see updated JSON.');
    }

    public function applyDraftCorrection(BiodataIntake $intake)
    {
        $validated = request()->validate([
            'field' => ['required', 'string', 'max:120'],
            'value' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $result = app(\App\Services\Intake\IntakeNormalizedDraftCorrectionApplier::class)->apply(
                $intake,
                (string) $validated['field'],
                (string) $validated['value']
            );
        } catch (\InvalidArgumentException $e) {
            return redirect()
                ->route('admin.biodata-intakes.show', $intake)
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.biodata-intakes.show', $intake)
            ->with('success', (string) ($result['message'] ?? __('intake.normalized_draft_apply_success')));
    }

    public function updateReviewedSnapshot(
        Request $request,
        BiodataIntake $intake,
        IntakeHumanReviewSnapshotService $reviewSnapshotService,
        IntakePipelineService $intakePipeline,
    ) {
        if ((bool) $intake->approved_by_user || (bool) $intake->intake_locked) {
            return redirect()
                ->route('admin.biodata-intakes.show', $intake)
                ->with('error', 'Reviewed snapshot cannot be edited after approval or lock.');
        }

        $validated = $request->validate([
            'snapshot' => ['required', 'array'],
        ]);

        $source = $this->adminReviewSnapshotSource($intake);
        $sourceSnapshot = $source['snapshot'];
        if ($sourceSnapshot === []) {
            return redirect()
                ->route('admin.biodata-intakes.show', $intake)
                ->with('error', 'No parsed or reviewed snapshot fields are available to save.');
        }

        $submittedSnapshot = is_array($validated['snapshot'] ?? null) ? $validated['snapshot'] : [];
        $reviewedSnapshot = $this->mergeAdminReviewSnapshotValues($sourceSnapshot, $submittedSnapshot);
        $reviewedSnapshot = $intakePipeline->normalizeSnapshotForStorage(
            $reviewedSnapshot,
            $request->user() ? (int) $request->user()->id : null,
        );

        $reviewSnapshotService->saveReviewedSnapshot($intake, $reviewedSnapshot, [
            'reviewed_by_user_id' => $request->user() ? (int) $request->user()->id : null,
            'review_actor_type' => IntakeHumanReviewSnapshotService::ACTOR_ADMIN,
            'review_surface' => IntakeHumanReviewSnapshotService::SURFACE_ADMIN_PANEL,
            'approval_policy' => IntakeHumanReviewSnapshotService::POLICY_PHASE2A_HUMAN_REVIEW_V1,
            'approval_status' => IntakeHumanReviewSnapshotService::STATUS_REVIEWED,
        ]);

        return redirect()
            ->route('admin.biodata-intakes.show', $intake)
            ->with('success', 'Reviewed snapshot saved. Profile data was not modified.');
    }

    public function parseStatus(BiodataIntake $intake)
    {
        return response()->json([
            'parse_status' => (string) ($intake->parse_status ?? ''),
            'last_error' => $intake->last_error,
            'parsed_at' => $intake->parsed_at?->toIso8601String(),
        ]);
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
        if (($result['mutation_success'] ?? false) === true && ($result['already_applied'] ?? false) !== true) {
            app(IntakePhotoCandidateApplyService::class)
                ->applyAfterSuccessfulIntakeMutation($intake->refresh(), $result['profile_id'] ?? null);
        }

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
            'city_id' => ['required', 'integer', AddressHierarchyRules::existsLocationLeafId()],
        ]);

        $resolver = app(IntakeLocationSuggestionLayerService::class);
        $result = $resolver->resolveFieldToCity($intake, (string) $validated['field'], (int) $validated['city_id']);
        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => (string) ($result['message'] ?? 'Could not resolve location field.'),
            ], 422);
        }

        return response()->json(array_merge(
            [
                'success' => true,
                'message' => 'Location resolved.',
            ],
            array_filter([
                'city_id' => $result['city_id'] ?? null,
                'display_label' => $result['display_label'] ?? null,
                'taluka_id' => $result['taluka_id'] ?? null,
                'district_id' => $result['district_id'] ?? null,
                'state_id' => $result['state_id'] ?? null,
            ], fn ($v) => $v !== null)
        ));
    }

    /**
     * @return array{source: string, snapshot: array<string, mixed>}
     */
    private function adminReviewSnapshotSource(BiodataIntake $intake): array
    {
        $approvalSnapshot = $intake->approval_snapshot_json;
        if (is_array($approvalSnapshot) && $approvalSnapshot !== []) {
            return [
                'source' => 'approval_snapshot_json',
                'snapshot' => $this->filterAdminReviewSnapshot($approvalSnapshot),
            ];
        }

        $parsedSnapshot = $intake->parsed_json;
        if (is_array($parsedSnapshot) && $parsedSnapshot !== []) {
            return [
                'source' => 'parsed_json',
                'snapshot' => $this->filterAdminReviewSnapshot($parsedSnapshot),
            ];
        }

        return [
            'source' => 'empty',
            'snapshot' => [],
        ];
    }

    /**
     * @return array{
     *     source: string,
     *     available: bool,
     *     can_save: bool,
     *     field_count: int,
     *     sections: list<array{key: string, label: string, fields: list<array{name: string, old_key: string, label: string, value: string, multiline: bool}>}>
     * }
     */
    private function adminReviewSnapshotEditor(BiodataIntake $intake): array
    {
        $source = $this->adminReviewSnapshotSource($intake);
        $sections = [];
        $fieldCount = 0;

        foreach ($source['snapshot'] as $sectionKey => $sectionValue) {
            $fields = $this->adminReviewSnapshotFields($sectionValue, [$sectionKey]);
            if ($fields === []) {
                continue;
            }

            $fieldCount += count($fields);
            $sections[] = [
                'key' => (string) $sectionKey,
                'label' => $this->adminReviewSnapshotLabel((string) $sectionKey),
                'fields' => $fields,
            ];
        }

        return [
            'source' => $source['source'],
            'available' => $fieldCount > 0,
            'can_save' => ! (bool) $intake->approved_by_user && ! (bool) $intake->intake_locked,
            'field_count' => $fieldCount,
            'sections' => $sections,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function filterAdminReviewSnapshot(array $snapshot): array
    {
        $allowed = array_flip(self::ADMIN_REVIEW_SNAPSHOT_KEYS);
        $filtered = [];
        foreach ($snapshot as $key => $value) {
            if (isset($allowed[$key])) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $submitted
     * @return array<string, mixed>
     */
    private function mergeAdminReviewSnapshotValues(array $base, array $submitted): array
    {
        foreach ($submitted as $key => $value) {
            if (! array_key_exists($key, $base)) {
                continue;
            }

            if (is_array($base[$key])) {
                if (is_array($value)) {
                    $base[$key] = $this->mergeAdminReviewSnapshotValues($base[$key], $value);
                }

                continue;
            }

            $base[$key] = $this->normalizeAdminReviewScalar($value);
        }

        return $base;
    }

    /**
     * @param  list<string|int>  $path
     * @return list<array{name: string, old_key: string, label: string, value: string, multiline: bool}>
     */
    private function adminReviewSnapshotFields(mixed $value, array $path): array
    {
        if (is_array($value)) {
            $fields = [];
            foreach ($value as $key => $nestedValue) {
                $fields = array_merge($fields, $this->adminReviewSnapshotFields($nestedValue, array_merge($path, [$key])));
            }

            return $fields;
        }

        if (! $this->hasAdminReviewDisplayValue($value)) {
            return [];
        }

        $formValue = $this->adminReviewFormValue($value);
        $leaf = end($path);

        return [[
            'name' => $this->adminReviewInputName($path),
            'old_key' => 'snapshot.'.implode('.', array_map('strval', $path)),
            'label' => $this->adminReviewSnapshotLabel((string) $leaf),
            'value' => $formValue,
            'multiline' => str_contains($formValue, "\n") || mb_strlen($formValue) > 90,
        ]];
    }

    private function hasAdminReviewDisplayValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }

        return false;
    }

    private function adminReviewFormValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string) $value);
    }

    private function normalizeAdminReviewScalar(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value !== '' ? $value : null;
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        return null;
    }

    /**
     * @param  list<string|int>  $path
     */
    private function adminReviewInputName(array $path): string
    {
        $name = 'snapshot';
        foreach ($path as $segment) {
            $name .= '['.$segment.']';
        }

        return $name;
    }

    private function adminReviewSnapshotLabel(string $key): string
    {
        if (ctype_digit($key)) {
            return 'Row '.((int) $key + 1);
        }

        return Str::of($key)
            ->replace(['_', '-'], ' ')
            ->title()
            ->toString();
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
        'occupation_title', 'company_name', 'work_location_text',
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
