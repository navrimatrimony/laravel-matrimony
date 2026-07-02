<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\BiodataIntake;
use App\Models\SuchakBiodataIntakeLink;
use App\Models\SuchakProfileRepresentation;
use App\Modules\Suchak\Services\SuchakAccessService;
use App\Services\Intake\IntakeHumanReviewSnapshotService;
use App\Services\Intake\IntakePipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BiodataIntakeReviewSnapshotController extends Controller
{
    /** @var list<string> */
    private const REVIEW_SNAPSHOT_TOP_LEVEL_KEYS = [
        'snapshot_schema_version',
        'section_order',
        'sectioned',
        'missing_map',
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
        'relatives_sectioned',
        'alliance_networks',
        'property_summary',
        'property_assets',
        'horoscope',
        'legal_cases',
        'preferences',
        'extended_narrative',
        'other_relatives_text',
        'confidence_map',
    ];

    public function update(
        Request $request,
        BiodataIntake $intake,
        IntakeHumanReviewSnapshotService $reviewSnapshotService,
        IntakePipelineService $intakePipeline,
        SuchakAccessService $accessService,
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        $account = $user?->suchakAccount;

        abort_unless(
            $user && $account && $accessService->canOwnerPrepareCustomers($account, $user),
            403,
            'Suchak account is not allowed to review this intake.'
        );

        abort_unless(
            $this->suchakCanReviewIntake((int) $account->id, (int) $intake->id),
            403,
            'This biodata intake is not linked to your Suchak account.'
        );

        if ((bool) $intake->approved_by_user || (bool) $intake->intake_locked) {
            return $this->errorResponse(
                $request,
                $intake,
                'Reviewed snapshot cannot be edited after approval or lock.',
                422,
            );
        }

        $validated = $request->validate([
            'reviewed_snapshot' => ['required', 'array'],
        ]);

        $submittedSnapshot = $this->filterReviewSnapshot(
            is_array($validated['reviewed_snapshot'] ?? null) ? $validated['reviewed_snapshot'] : []
        );
        if ($submittedSnapshot === []) {
            return $this->errorResponse(
                $request,
                $intake,
                'Reviewed snapshot is empty or contains no supported intake fields.',
                422,
            );
        }

        $baseSnapshot = is_array($intake->approval_snapshot_json)
            ? $intake->approval_snapshot_json
            : (is_array($intake->parsed_json) ? $intake->parsed_json : []);
        $reviewedSnapshot = array_replace_recursive($baseSnapshot, $submittedSnapshot);
        $reviewedSnapshot = $intakePipeline->normalizeSnapshotForStorage(
            $reviewedSnapshot,
            (int) $user->id,
        );

        $saved = $reviewSnapshotService->saveReviewedSnapshot($intake, $reviewedSnapshot, [
            'reviewed_by_user_id' => (int) $user->id,
            'review_actor_type' => IntakeHumanReviewSnapshotService::ACTOR_SUCHAK,
            'review_surface' => IntakeHumanReviewSnapshotService::SURFACE_WEBSITE,
            'approval_policy' => IntakeHumanReviewSnapshotService::POLICY_PHASE2D_SUCHAK_REVIEW_V1,
            'approval_status' => IntakeHumanReviewSnapshotService::STATUS_REVIEWED,
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Reviewed snapshot saved.',
                'intake_id' => (int) $saved->id,
                'approval_status' => $saved->approval_status,
                'review_actor_type' => $saved->review_actor_type,
                'review_surface' => $saved->review_surface,
                'reviewed_at' => optional($saved->reviewed_at)->toISOString(),
                'approval_snapshot' => $saved->approval_snapshot_json,
            ]);
        }

        return redirect()
            ->route('intake.status', $saved)
            ->with('success', 'Reviewed snapshot saved. Profile data was not modified.');
    }

    private function suchakCanReviewIntake(int $accountId, int $intakeId): bool
    {
        $linked = SuchakBiodataIntakeLink::query()
            ->where('suchak_account_id', $accountId)
            ->where('biodata_intake_id', $intakeId)
            ->where('source_status', '!=', SuchakBiodataIntakeLink::STATUS_CANCELLED)
            ->exists();
        if ($linked) {
            return true;
        }

        return SuchakProfileRepresentation::query()
            ->where('suchak_account_id', $accountId)
            ->where('biodata_intake_id', $intakeId)
            ->whereNotIn('representation_status', [
                SuchakProfileRepresentation::STATUS_REVOKED,
                SuchakProfileRepresentation::STATUS_EXPIRED,
                SuchakProfileRepresentation::STATUS_REJECTED,
                SuchakProfileRepresentation::STATUS_SUSPENDED,
                SuchakProfileRepresentation::STATUS_CANDIDATE_DEACTIVATED,
            ])
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function filterReviewSnapshot(array $snapshot): array
    {
        $allowed = array_flip(self::REVIEW_SNAPSHOT_TOP_LEVEL_KEYS);
        $filtered = [];
        foreach ($snapshot as $key => $value) {
            if (isset($allowed[$key])) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    private function errorResponse(
        Request $request,
        BiodataIntake $intake,
        string $message,
        int $status,
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $message,
            ], $status);
        }

        return redirect()
            ->route('intake.status', $intake)
            ->with('error', $message);
    }
}
