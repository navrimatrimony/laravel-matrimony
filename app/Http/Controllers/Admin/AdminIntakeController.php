<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ParseIntakeJob;
use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Models\ConflictRecord;
use App\Services\Intake\IntakeExtractionReuseResolver;
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
        $pendingConflictCount = 0;
        $recentPendingConflicts = collect();
        if ($attachedProfile) {
            $pendingConflictCount = ConflictRecord::query()
                ->where('profile_id', $attachedProfile->id)
                ->where('resolution_status', 'PENDING')
                ->count();
            $recentPendingConflicts = ConflictRecord::query()
                ->where('profile_id', $attachedProfile->id)
                ->where('resolution_status', 'PENDING')
                ->orderByDesc('detected_at')
                ->limit(5)
                ->get();
        }

        $suggestionsJson = $attachedProfile?->pending_intake_suggestions_json;
        $pendingSuggestionsCount = 0;
        if ($attachedProfile && is_array($suggestionsJson) && $suggestionsJson !== []) {
            foreach ($suggestionsJson as $bucket) {
                if (is_array($bucket)) {
                    if ($bucket !== []) {
                        $pendingSuggestionsCount++;
                    }
                } elseif ($bucket !== null && trim((string) $bucket) !== '') {
                    $pendingSuggestionsCount++;
                }
            }
        }
        $pendingSuggestionsPresent = $pendingSuggestionsCount > 0;

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
}
