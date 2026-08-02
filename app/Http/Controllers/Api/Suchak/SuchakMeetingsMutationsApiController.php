<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakPipeline;
use App\Models\SuchakVisitConfirmation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakVisitConfirmationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            'meeting_mode' => ['nullable', 'string', Rule::in(SuchakVisitConfirmation::MEETING_MODES)],
            'helper_suchak_account_id' => ['nullable', 'integer', 'exists:suchak_accounts,id'],
            // Which agreed plan this meeting is priced under. Optional, because a
            // customer on a single plan needs no answer — but without this key in
            // the rules the controller strips it, and a customer holding two
            // agreed plans could never be scheduled at all: the service refuses
            // to guess between them, and this is the only way to answer it.
            // Ownership, in-force status and supersession stay the service's to
            // check; existence is all the controller can honestly assert.
            'customer_agreement_id' => ['nullable', 'integer', 'exists:suchak_customer_agreements,id'],
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
                'meeting_sequence' => $visit->meeting_sequence,
                'meeting_mode' => $visit->meeting_mode,
                // THIS meeting's fee, alone. D17 — no accumulated total travels
                // with an approval; the cumulative figure belongs on the payments
                // screen, where a person went to look at money.
                'fee_amount' => $visit->fee_amount,
                // Frozen figure, frozen unit — see SuchakMeetingsApiController.
                'fee_currency' => $visit->fee_currency,
                'fee_display' => $visit->fee_display,
            ],
        ], 201);
    }

    /**
     * A meeting that is not going to happen.
     *
     * Without this a no-show strands the pair forever: a scheduled meeting is an
     * open meeting, and an open meeting blocks the next one. Only while it is
     * still merely scheduled — once the Suchak has claimed it happened, a fee
     * claim exists, and the answer to a contested claim is a dispute, not the
     * claiming party quietly deleting its own claim. The service owns both of
     * those rules; this only carries the reason in.
     */
    public function cancel(
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
            'cancellation_reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $updated = $visitConfirmationService->cancelVisit(
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
            'message' => 'Visit cancelled.',
            'data' => [
                'visit_id' => $updated->id,
                'visit_status' => $updated->visit_status,
                'meeting_sequence' => $updated->meeting_sequence,
            ],
        ]);
    }

    /**
     * THE HELPING SUCHAK'S DOOR TO A DISPUTE.
     *
     * `disputeVisit()` admitted only an admin or the requesting member, so the
     * party §7.2's stop-loss exists to protect — the helper, whose claims go
     * unanswered — had no way to open a claim at all. D26 says either Suchak may
     * raise a claim; on a visit, the helper is the one who needs it, because the
     * dispute's payout hold lands on the ARRANGING Suchak's account and that is
     * precisely the leverage §7.2 describes.
     *
     * Only the helper. The arranging Suchak is refused by the service (he is the
     * claimant, not a contestant), and this route deliberately does not check
     * ownership itself — `visitDisputeActorType()` owns that decision, so there
     * is one authorisation path, not two. The 404 below only decides which
     * status a meeting a Suchak has nothing to do with deserves.
     */
    public function dispute(
        Request $request,
        SuchakVisitConfirmation $visit,
        SuchakVisitConfirmationService $visitConfirmationService,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return response()->json(['success' => false, 'message' => 'Suchak account is required.'], 403);
        }

        if ((int) $visit->helper_suchak_account_id !== (int) $user->suchakAccount->id) {
            return response()->json(['success' => false, 'message' => 'Visit not found for this account.'], 404);
        }

        $validated = $request->validate([
            'dispute_reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $updated = $visitConfirmationService->disputeVisit(
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
            'message' => __('suchak.api.meeting.dispute_recorded'),
            'data' => [
                'visit_id' => $updated->id,
                'visit_status' => $updated->visit_status,
                'dispute_id' => $updated->dispute_id,
                'payout_hold_id' => $updated->payout_hold_id,
                'refund_review_status' => $updated->refund_review_status,
            ],
        ]);
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
