<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakMarriageOutcome;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCollaborationService;
use App\Modules\Suchak\Services\SuchakMarriageOutcomeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * THE ADMIN'S TWO DOORS ONTO A TERMINAL CLAIM (blueprint D26, §6.2, §2).
 *
 *   POST …/admin/suchak-engagements/{collaboration}/stages/confirm   stand in for the customer
 *   POST …/admin/suchak/marriage-outcomes/{outcome}/void             set a wrong attribution aside
 *
 * Both exist because a rule that lives only in a service is a rule nobody can use. This repository
 * has shipped capability after capability whose only caller was a test, and the two behaviours below
 * were exactly that shape:
 *
 *  - `SuchakCollaborationService::confirmStage()` mapped an admin to ACTOR_ADMIN and its docblock
 *    said "an admin may confirm in their place" — but the only route onto it, on the member API,
 *    404s anybody whose own matrimony profile is not one of the engagement's two candidates. No
 *    admin has ever been able to reach that branch. Meanwhile §2 says the customer is the FAMILY and
 *    usually has no login at all, so for the blueprint's own customer NOBODY could confirm, and the
 *    helper's tranche could never settle. This route is what makes the sentence true.
 *  - `SuchakMarriageOutcomeService::voidClaim()` is §6.2's correction door. Two Suchaks may hold
 *    valid representations on one candidate (§6.2's opening sentence), so both may claim a marriage;
 *    the candidate-level uniqueness that stops the largest sum in the system having two owners used
 *    to make the FIRST unconfirmed claim permanent. An admin can now set a wrong one aside.
 *
 * WHAT THIS IS NOT. It is not a way to make money move: the void refuses a confirmed claim outright,
 * and the confirmation is recorded as ACTOR_ADMIN — never ACTOR_USER — so no trail ever says the
 * family spoke when an administrator spoke for them. Both actions carry the service's Marathi
 * refusals through unchanged as 422s.
 */
class SuchakAdminTerminalStageApiController extends Controller
{
    /**
     * POST /api/v1/admin/suchak-engagements/{collaboration}/stages/confirm
     *
     * Body: `stage_key` (required, one of CONFIRMABLE_STAGES), `confirmation_note` (nullable).
     * 200: `{ success, message, data: { stage_event_id, stage_key, stage_label, confirmed_at,
     *         is_settled, marketplace_stage } }`
     */
    public function confirm(
        Request $request,
        SuchakCollaborationRequest $collaboration,
        SuchakCollaborationService $collaborationService,
    ): JsonResponse {
        /** @var User $admin */
        $admin = $request->user();

        $validated = $request->validate([
            'stage_key' => ['required', 'string', Rule::in(SuchakCollaborationStageEvent::CONFIRMABLE_STAGES)],
            'confirmation_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $event = $collaborationService->confirmStage(
                $collaboration,
                $admin,
                (string) $validated['stage_key'],
                $validated['confirmation_note'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'प्रशासकाच्या वतीने दुजोरा नोंदवला.',
            'data' => [
                'stage_event_id' => (int) $event->id,
                'stage_key' => $event->stage_key,
                'stage_label' => SuchakCollaborationStageEvent::stageLabel((string) $event->stage_key),
                'confirmed_at' => $event->confirmed_at?->toIso8601String(),
                'is_settled' => $event->isSettled(),
                'marketplace_stage' => $collaboration->fresh()?->marketplace_stage,
            ],
        ]);
    }

    /**
     * POST /api/v1/admin/suchak/marriage-outcomes/{outcome}/void
     *
     * Body: `void_reason` (required, max 500).
     * 200: the attribution card of the row that was set aside, `is_voided` true.
     *
     * The reason is REQUIRED and is not a courtesy: this row is evidence, it is never deleted, and a
     * withdrawal with no stated reason is an erasure wearing a timestamp. The row is resolved
     * INCLUDING voided ones so a second attempt is answered by the service's "already set aside"
     * rather than by a 404 that reads as "no such claim".
     */
    public function voidMarriageOutcome(
        Request $request,
        int $outcome,
        SuchakMarriageOutcomeService $marriageOutcomeService,
    ): JsonResponse {
        /** @var User $admin */
        $admin = $request->user();

        $validated = $request->validate([
            'void_reason' => ['required', 'string', 'max:500'],
        ]);

        /** @var SuchakMarriageOutcome|null $claim */
        $claim = SuchakMarriageOutcome::includingVoided()->find($outcome);
        if (! $claim instanceof SuchakMarriageOutcome) {
            return $this->error('ही विवाह नोंद सापडली नाही.', 404);
        }

        try {
            $voided = $marriageOutcomeService->voidClaim(
                $claim,
                $admin,
                (string) $validated['void_reason'],
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'विवाहाची नोंद रद्द केली. आता योग्य सहकार्यावर नोंद करता येईल.',
            'data' => $marriageOutcomeService->attribution($voided),
        ]);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
