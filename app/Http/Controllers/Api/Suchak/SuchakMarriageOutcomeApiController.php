<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakMarriageOutcome;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakMarriageOutcomeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * THE DOOR onto blueprint §6.2 — recording a marriage and naming the engagement credited with it.
 *
 * Two verbs, and both of them are the reason this class exists rather than a stage key on the
 * generic ladder route:
 *
 *   POST …/collaborations/{collaboration}/marriage   record the wedding + its DATE, and attribute it
 *   GET  …/collaborations/{collaboration}/marriage   read the attribution back
 *
 * `SuchakCollaborationStagesApiController` no longer accepts `marriage` as a `stage_key`. That is
 * not tidiness: a `marriage` rung claimed through the generic route carries a report instant and no
 * wedding date, so M3's clock ("a fixed number of days after a recorded Marriage") has no start and
 * the §6.2 attribution row does not exist. One rung, one door, one place the date is required.
 *
 * AUTHORISATION follows the shape the Suchak API already uses (this codebase has no policy layer):
 * a caller-must-hold-a-Suchak-account check in front, the service's own exceptions surfaced as 422
 * with their Marathi text intact, and a 404 for an engagement the caller is not a party to — a
 * Suchak has no business learning that another Suchak's engagement, let alone another family's
 * marriage, exists.
 *
 * WHAT THIS DOOR DOES NOT CLAIM (D23, §8). A Suchak recording a marriage here is a Suchak's word.
 * Nothing on this path verifies that a wedding took place: no OTP exists on production (§10 S4), no
 * `*_verified` flag is written, and the family's confirmation — when a family with a login exists
 * to give it — stays on the stage event, read back through `is_confirmed` below and never copied.
 */
class SuchakMarriageOutcomeApiController extends Controller
{
    /**
     * POST /api/v1/suchak/collaborations/{collaboration}/marriage
     *
     * Body: `married_on` (required date — the WEDDING DAY, not today),
     *       `event_note` (nullable, max 2000).
     * 201: `{ success, message, data: <attribution card> }`
     *
     * `married_on` is validated only as a SHAPE here — a parsable date. Every rule about WHICH dates
     * are acceptable is the service's, and it owns all four of them: no date at all, a future date,
     * a date predating the engagement, and a backdated one chosen by the Suchak the share is owed
     * TO. A second copy of any of them in this validator would be a second place for it to drift.
     *
     * `required` used to be the ONLY place "a marriage needs a date" was written down: the service
     * took a blank string to `Carbon::parse('')`, which answers NOW, so any caller that omitted the
     * field silently recorded TODAY as the wedding day — the day M3's clock starts from. The rule
     * now lives in the service too, and this line is the cheap early refusal rather than the rule.
     */
    public function store(
        Request $request,
        SuchakCollaborationRequest $collaboration,
        SuchakMarriageOutcomeService $marriageOutcomeService,
    ): JsonResponse {
        $user = $this->participatingSuchakUser($request, $collaboration);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $validated = $request->validate([
            'married_on' => ['required', 'date'],
            'event_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $outcome = $marriageOutcomeService->record(
                $collaboration,
                $user->suchakAccount,
                $user,
                (string) $validated['married_on'],
                $validated['event_note'] ?? null,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('suchak.api.marriage.recorded'),
            'data' => $marriageOutcomeService->attribution($outcome),
        ], 201);
    }

    /**
     * GET /api/v1/suchak/collaborations/{collaboration}/marriage
     *
     * 200 with the attribution card, or 404 while no marriage has been recorded on this engagement.
     * A 404 rather than a 200 with nulls: "we have no record" is not "they did not marry", and a
     * payload of nulls invites a client to render the second sentence.
     */
    public function show(
        Request $request,
        SuchakCollaborationRequest $collaboration,
        SuchakMarriageOutcomeService $marriageOutcomeService,
    ): JsonResponse {
        $user = $this->participatingSuchakUser($request, $collaboration);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $outcome = $marriageOutcomeService->outcomeFor($collaboration);
        if (! $outcome instanceof SuchakMarriageOutcome) {
            return $this->error(__('suchak.api.errors.marriage_outcome_not_found'), 404);
        }

        return response()->json([
            'success' => true,
            'data' => $marriageOutcomeService->attribution($outcome),
        ]);
    }

    /**
     * A Suchak account is required (403), and it must be one of the two on this engagement (404).
     *
     * 404 and not 403 for the second: the fact that a given engagement exists is itself information
     * about two other Suchaks and two families, and telling a stranger "forbidden" confirms it.
     * Whether the caller may claim THIS rung is a further question and is the service's — this only
     * decides whether the row is visible at all.
     */
    private function participatingSuchakUser(
        Request $request,
        SuchakCollaborationRequest $collaboration,
    ): User|JsonResponse {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return $this->error(__('suchak.api.errors.suchak_account_required'), 403);
        }

        if ($collaboration->sideForAccount((int) $user->suchakAccount->id) === null) {
            return $this->error(__('suchak.api.errors.engagement_not_found'), 404);
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
