<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCollaborationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * The MEMBER half of the marketplace stage ladder (blueprint 6a, D26).
 *
 * The last three stages — marriage settled (लग्न ठरले), engagement (साखरपुडा) and marriage — are
 * claimed by either Suchak and then CONFIRMED by the customer, on the same pattern the meeting
 * engine already uses. `SuchakCollaborationService::confirmStage()` shipped complete and guarded
 * and was reachable only from tests, so a claimed tranche stage could never settle in production.
 *
 * This lives on the member API and not on the Suchak API for the same reason
 * MemberSuchakMeetingApiController does: the actor is the member. It is also a safety property, not
 * a filing preference — the service refuses a PARTICIPATING Suchak's own user but accepts any other
 * user, so exposing this behind the Suchak-account middleware would have let any verified Suchak
 * confirm a stranger's marriage claim and release a success-fee tranche worth tens of thousands.
 * The ownership check below closes that: the confirmer must be one of the two candidates in the
 * engagement.
 *
 * A customer with no login of their own (blueprint section 2) still cannot reach this. Their door
 * is the tokenised public link, which is Phase 3/6 work and deliberately not invented here — the
 * same limitation the member half of the meeting engine already carries.
 *
 * SCOPE, stated so nobody later reads more into this class than it does: this is a CONFIRMATION
 * door, not a claim door. Three rungs of the ladder belong to the customer as the CLAIMANT —
 * `viewed`, `interested` and `meeting_confirmed` (SuchakCollaborationStageEvent::STAGE_CLAIMANTS) —
 * and none of them can be recorded by anyone today. They were deliberately not added here: a
 * candidate's own member login is not the customer, the customer is the family (section 2), and
 * `meeting_confirmed` already has a rightful owner in the meeting engine's `user_confirmation_status`,
 * which is waiting on the missing `confirmByUser` route (§5.1 B2). Building a second door for it
 * here would be a second confirmation engine, which §12 forbids outright.
 */
class MemberSuchakStageApiController extends Controller
{
    /**
     * POST /api/v1/suchak-engagements/{collaboration}/stages/confirm
     *
     * Body: `stage_key` (required, one of `marriage_settled` / `engagement` / `marriage`),
     *       `confirmation_note` (nullable, max 2000).
     * 200: `{ success, message, data: { stage_event_id, stage_key, stage_label, confirmed_at,
     *         is_settled, marketplace_stage } }`
     */
    public function confirm(
        Request $request,
        SuchakCollaborationRequest $collaboration,
        SuchakCollaborationService $collaborationService,
    ): JsonResponse {
        $viewer = $this->viewerContext($request, $collaboration);
        if ($viewer instanceof JsonResponse) {
            return $viewer;
        }

        $validated = $request->validate([
            'stage_key' => ['required', 'string', Rule::in(SuchakCollaborationStageEvent::CONFIRMABLE_STAGES)],
            'confirmation_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $event = $collaborationService->confirmStage(
                $collaboration,
                $viewer,
                (string) $validated['stage_key'],
                $validated['confirmation_note'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'तुमचा दुजोरा नोंदवला.',
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
     * Either candidate's own user may confirm: both families are customers of their own Suchak, and
     * D26 says the customer confirms. A stranger gets 404, not 403 — a member has no business
     * learning that someone else's engagement exists.
     */
    private function viewerContext(Request $request, SuchakCollaborationRequest $collaboration): User|JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        $viewerProfile = $user->matrimonyProfile;
        if (! $viewerProfile instanceof MatrimonyProfile) {
            return $this->error(__('profile.suchak_request_not_found'), 404);
        }

        $candidateProfileIds = [
            (int) $collaboration->requesting_matrimony_profile_id,
            (int) $collaboration->target_matrimony_profile_id,
        ];

        if (! in_array((int) $viewerProfile->id, $candidateProfileIds, true)) {
            return $this->error(__('profile.suchak_request_not_found'), 404);
        }

        return $user;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
