<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Models\SuchakVisitConfirmation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakVisitConfirmationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The MEMBER half of the meeting engine (blueprint 5.1 blocker B2).
 *
 * `confirmByUser()` and `disputeVisit()` have existed in
 * SuchakVisitConfirmationService since day 44 and were reachable only from
 * tests, so in production a meeting could never move past `completed` and D9's
 * "the customer confirms" was unimplementable.
 *
 * This lives on the member API and not on the Suchak API because the actor is
 * the member: `assertCustomerSideUserCanConfirm()` accepts exactly the user who
 * owns the meeting's CUSTOMER-side candidate profile. It follows
 * MemberSuchakRequestApiController — the same surface the member app already
 * uses for every other member-side Suchak action, over the same service the web
 * routes use, so nothing here is a second engine.
 *
 * Authorisation is the service's, not this controller's. The ownership check
 * below is only about which HTTP status a stranger's meeting deserves: 404,
 * because a member has no business learning that someone else's meeting exists.
 *
 * WHO STILL CANNOT REACH THIS: a customer with no login — which blueprint
 * section 2 says is the common case, because `users.mobile` is null whenever the
 * number on file is a household number. Both routes need `$request->user()`.
 * Their tokenised door records the LADDER rung `meeting_confirmed`
 * (SuchakCollaborationService::recordCustomerStage) and deliberately does not
 * touch `suchak_visit_confirmations`: giving the visit engine a second, tokenised
 * confirmation path is a second confirmation engine, which section 12 forbids.
 * Closing that gap properly means the visit row learning to name a portal link,
 * and that is not this slice.
 */
class MemberSuchakMeetingApiController extends Controller
{
    /**
     * POST /api/v1/suchak-meetings/{visit}/confirm
     * The member confirms the meeting actually happened. No fee falls due
     * without this (M4).
     */
    public function confirm(
        Request $request,
        SuchakVisitConfirmation $visit,
        SuchakVisitConfirmationService $visitConfirmationService,
    ): JsonResponse {
        $context = $this->viewerContext($request, $visit, $visitConfirmationService);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $validated = $request->validate([
            'confirmation_note' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $updated = $visitConfirmationService->confirmByUser(
                $visit,
                $context,
                $validated,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'भेट झाल्याची पुष्टी नोंदवली.',
            'data' => $this->visitPayload($updated),
        ]);
    }

    /**
     * POST /api/v1/suchak-meetings/{visit}/dispute
     * The member says the meeting did not happen as claimed. Opens a
     * SuchakDispute and an active payout hold (blueprint 7.2) — silence is never
     * an automatic zero, and neither is a refusal.
     */
    public function dispute(
        Request $request,
        SuchakVisitConfirmation $visit,
        SuchakVisitConfirmationService $visitConfirmationService,
    ): JsonResponse {
        $context = $this->viewerContext($request, $visit, $visitConfirmationService);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $validated = $request->validate([
            'dispute_reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $updated = $visitConfirmationService->disputeVisit(
                $visit,
                $context,
                $validated,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'तक्रार नोंदवली. आढावा पूर्ण होईपर्यंत रक्कम रोखली आहे.',
            'data' => $this->visitPayload($updated),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function visitPayload(SuchakVisitConfirmation $visit): array
    {
        return [
            'visit_id' => $visit->id,
            'visit_status' => $visit->visit_status,
            'user_confirmation_status' => $visit->user_confirmation_status,
            'meeting_sequence' => $visit->meeting_sequence,
            'meeting_mode' => $visit->meeting_mode,
            // THIS meeting's fee and nothing else (D17). A cumulative figure
            // shown while a family is deciding about a person reads as a regret
            // ledger; the running total belongs on the payments screen.
            'fee_amount' => $visit->fee_amount,
            // The currency this fee was quoted in, frozen with the amount. The
            // customer is the person this string misleads if it is wrong: a USD
            // agreement rendered with the INR default reads as ₹ to the family
            // being asked to pay it.
            'fee_currency' => $visit->fee_currency,
            // The model's accessor, not a hand-paired MoneyFormat call here.
            // Amount and unit are two halves of one fact; pairing them at each
            // call site is how one surface ends up formatting against the INR
            // default while its neighbours do not.
            'fee_display' => $visit->fee_display,
        ];
    }

    /**
     * The customer side, resolved by the SERVICE — never re-derived here.
     *
     * This used to compare against `requesting_matrimony_profile_id`, which is a DIRECTION. On a
     * marketplace meeting the Suchak answering a challenge becomes the requester (blueprint 5.2),
     * so that column names the HELPER's candidate: the wrong family got the 200 and the fee-bearing
     * family got a 404 on its own meeting. `customerSideMatrimonyProfileId()` is the one owner of
     * "who is the customer on this meeting", and the service guard behind these two routes uses the
     * same call — two copies of that question is how they drift apart again.
     */
    private function viewerContext(
        Request $request,
        SuchakVisitConfirmation $visit,
        SuchakVisitConfirmationService $visitConfirmationService,
    ): User|JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        $viewerProfile = $user->matrimonyProfile;
        if (! $viewerProfile instanceof MatrimonyProfile) {
            return $this->error(__('profile.suchak_request_not_found'), 404);
        }

        if ($visitConfirmationService->customerSideMatrimonyProfileId($visit) !== (int) $viewerProfile->id) {
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
