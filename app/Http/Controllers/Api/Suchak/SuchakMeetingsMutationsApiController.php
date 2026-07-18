<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakPipeline;
use App\Models\SuchakVisitConfirmation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakVisitConfirmationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Thin adapters over SuchakVisitConfirmationService schedule/complete.
 */
class SuchakMeetingsMutationsApiController extends Controller
{
    public function schedule(
        Request $request,
        SuchakVisitConfirmationService $visitConfirmationService,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return response()->json(['success' => false, 'message' => 'Suchak account is required.'], 403);
        }

        $validated = $request->validate([
            'pipeline_id' => ['required', 'integer', 'exists:suchak_pipelines,id'],
            'scheduled_for' => ['nullable', 'date'],
            'schedule_note' => ['nullable', 'string', 'max:1000'],
            'payment_context_id' => ['nullable', 'integer', 'exists:suchak_payment_contexts,id'],
        ]);

        $pipeline = SuchakPipeline::query()->findOrFail((int) $validated['pipeline_id']);
        if ((int) $pipeline->selected_suchak_account_id !== (int) $user->suchakAccount->id) {
            return response()->json(['success' => false, 'message' => 'Pipeline does not belong to this Suchak account.'], 403);
        }

        try {
            $visit = $visitConfirmationService->scheduleVisit(
                $pipeline,
                $user,
                $validated,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Visit scheduled.',
            'data' => [
                'visit_id' => $visit->id,
                'visit_status' => $visit->visit_status,
                'scheduled_for' => $visit->scheduled_for?->toIso8601String(),
            ],
        ], 201);
    }

    public function complete(
        Request $request,
        SuchakVisitConfirmation $visit,
        SuchakVisitConfirmationService $visitConfirmationService,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return response()->json(['success' => false, 'message' => 'Suchak account is required.'], 403);
        }

        if ((int) $visit->suchak_account_id !== (int) $user->suchakAccount->id) {
            return response()->json(['success' => false, 'message' => 'Visit not found for this account.'], 404);
        }

        $validated = $request->validate([
            'completion_note' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $updated = $visitConfirmationService->markSuchakCompleted(
                $visit,
                $user,
                $validated,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Visit marked complete.',
            'data' => [
                'visit_id' => $updated->id,
                'visit_status' => $updated->visit_status,
            ],
        ]);
    }
}
