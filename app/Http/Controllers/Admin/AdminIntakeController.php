<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ParseIntakeJob;
use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Services\Intake\IntakeExtractionReuseResolver;
use App\Services\Intake\IntakeReviewParseInputTextResolver;
use App\Services\IntakeApprovalService;
use App\Services\IntakeManualOcrPreparedService;
use App\Services\Parsing\ParserStrategyResolver;
use App\Services\Parsing\ProviderResolver;
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
        $intake->load(['uploadedByUser:id,name,email', 'profile:id,full_name']);
        $showAdminReextractAction = app(ProviderResolver::class)->parseJobUsesAiVisionExtraction();
        $reviewParse = app(IntakeReviewParseInputTextResolver::class)->resolve($intake);

        return view('admin.intake.show', compact('intake', 'showAdminReextractAction', 'reviewParse'));
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

        return redirect()
            ->route('admin.biodata-intakes.show', $intake)
            ->with('success', 'Intake apply pipeline triggered.')
            ->with('mutation_result', $result);
    }
}
