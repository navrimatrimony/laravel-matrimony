<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakPipeline;
use App\Models\SuchakVisitConfirmation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin mobile adapter: list visit confirmations for the owning Suchak account.
 * Read-only projection of existing suchak_visit_confirmations rows (payments-ledger pattern).
 *
 * ── WHY THE PAIRS WITH NO MEETING YET RIDE ON THIS PAYLOAD ────────────────────────────────────
 *
 * `POST /suchak/meetings` needs a `pipeline_id`, and until now NO read this app makes handed one
 * out for a pipeline that has no meeting: `visits[]` below carries `pipeline_id` only for pairs
 * that already met, and the profile-requests payload publishes the pipeline's SLA fields but not
 * its id. So the app could schedule the SECOND meeting of a pair and never the first, and the
 * meetings list could not become non-empty from the meetings screen — production has 20 pipelines
 * and zero visit confirmations, ever, by anyone.
 *
 * `awaiting_first_meeting[]` closes that, on the read the screen already makes, for the same
 * reason `stage_events` rides on the collaborations row: a second route would be a second round
 * trip and a second read path for rows this screen already needs in hand. Nothing here is a new
 * fact — every key is a column, or a name some other Suchak door already publishes to this same
 * account.
 *
 * ── AUTHORISATION IS THE SERVER'S OWN COLUMN, NOT A SECOND RULE ───────────────────────────────
 *
 * The list is scoped on `selected_suchak_account_id`, which is the EXACT column
 * {@see \App\Modules\Suchak\Services\SuchakVisitConfirmationService::assertOwnerCanManagePipeline()}
 * checks, and the statuses are the ones `assertOpenPipeline()` admits. The app therefore cannot be
 * shown a pair the schedule endpoint would refuse, and the endpoint re-checks anyway — this
 * narrows what is offered, it never grants anything.
 *
 * ── MASKING (D19a) ───────────────────────────────────────────────────────────────────────────
 *
 * `customer_name` is the caller's OWN represented candidate: on both pipeline sources
 * `target_matrimony_profile_id` is the customer-owning side's candidate, and the caller IS that
 * side (that is what `selected_suchak_account_id` means). `member_name` is published only on a
 * request-born pair, where it is the member who approached — already published unmasked to this
 * same account as `from_profile.name` on the requests screen, because the Suchak must judge the
 * suitor. On an engagement-born pair the other side is ANOTHER SUCHAK'S CANDIDATE and is not
 * published at all; what is published instead is that Suchak's NAME, which the collaborations
 * payload already gives both parties. No candidate identity crosses the pair here.
 */
class SuchakMeetingsApiController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        /** @var SuchakAccount|null $account */
        $account = $user->suchakAccount;
        if ($account === null) {
            return response()->json([
                'success' => false,
                'message' => 'Suchak account is required to access this section.',
            ], 403);
        }

        // No hand-maintained column projection. The response shape is decided by
        // the mapping below, not by the SELECT, and a hand-listed projection
        // silently drops every column a later migration adds — which is exactly
        // how a frozen fee would keep rendering in the wrong currency after
        // `fee_currency` lands. Fifty rows of one table is not a query worth
        // buying that failure mode.
        $visits = SuchakVisitConfirmation::query()
            ->where('suchak_account_id', $account->id)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(static fn (SuchakVisitConfirmation $visit): array => [
                'id' => $visit->id,
                'pipeline_id' => $visit->pipeline_id,
                'representation_id' => $visit->representation_id,
                'target_matrimony_profile_id' => $visit->target_matrimony_profile_id,
                'requesting_matrimony_profile_id' => $visit->requesting_matrimony_profile_id,
                'visit_status' => $visit->visit_status,
                'confirmation_policy_mode' => $visit->confirmation_policy_mode,
                // Which meeting of this pair, how it was held, and what it cost.
                // Per meeting only — D17 forbids a running total on any screen
                // where a family is deciding about a person.
                'meeting_sequence' => $visit->meeting_sequence,
                'meeting_mode' => $visit->meeting_mode,
                'fee_amount' => $visit->fee_amount,
                // The unit the quote froze in travels with the figure. A frozen
                // fee carries its own currency; formatting it against the INR
                // default would print ₹ over a USD agreement's number. The
                // pairing lives on the model accessor — one place decides how a
                // frozen quote reads, so no surface can drift to the default.
                'fee_currency' => $visit->fee_currency,
                'fee_display' => $visit->fee_display,
                'helper_suchak_account_id' => $visit->helper_suchak_account_id,
                'scheduled_for' => $visit->scheduled_for?->toIso8601String(),
                'schedule_note' => $visit->schedule_note,
                'suchak_completion_status' => $visit->suchak_completion_status,
                'user_confirmation_status' => $visit->user_confirmation_status,
                'admin_confirmation_status' => $visit->admin_confirmation_status,
                'created_at' => $visit->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Suchak visit confirmations loaded.',
            'data' => [
                'account_id' => $account->id,
                'visits' => $visits,
                'awaiting_first_meeting' => $this->awaitingFirstMeeting($account),
            ],
        ]);
    }

    /**
     * The pairs this Suchak may schedule a FIRST meeting for.
     *
     * `whereDoesntHave('visitConfirmations')` is what makes it the first one, and it is also what
     * keeps this list and the "next meeting" action on a `visits[]` card from ever offering the
     * same pair twice: the moment a pipeline holds any meeting row — including a cancelled one,
     * whose card offers the next meeting — it leaves this list for good. One pair, one door.
     *
     * @return array<int, array<string, mixed>>
     */
    private function awaitingFirstMeeting(SuchakAccount $account): array
    {
        return SuchakPipeline::query()
            ->where('selected_suchak_account_id', $account->id)
            // The statuses `assertOpenPipeline()` admits, and nothing else — an expired, closed or
            // cancelled pair is not one a meeting can be scheduled against.
            ->whereIn('pipeline_status', [SuchakPipeline::STATUS_PENDING, SuchakPipeline::STATUS_CONVERTED])
            ->whereDoesntHave('visitConfirmations')
            ->with([
                'targetMatrimonyProfile:id,full_name',
                'requestingMatrimonyProfile:id,full_name',
                'collaborationRequest:id,requesting_suchak_account_id,target_suchak_account_id',
                'collaborationRequest.requestingSuchakAccount:id,suchak_name',
                'collaborationRequest.targetSuchakAccount:id,suchak_name',
            ])
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(static function (SuchakPipeline $pipeline) use ($account): array {
                $engagement = $pipeline->collaborationRequest;
                $engagementBorn = $pipeline->isEngagementBorn();

                // The OTHER Suchak on the engagement — whichever side the caller is not.
                $helper = $engagement === null
                    ? null
                    : ((int) $engagement->requesting_suchak_account_id === (int) $account->id
                        ? $engagement->targetSuchakAccount
                        : $engagement->requestingSuchakAccount);

                return [
                    'pipeline_id' => (int) $pipeline->id,
                    // The schedule flow asks which agreed plan prices the meeting, and the read it
                    // asks with (`payment-request-options`) is keyed on the representation.
                    'representation_id' => $pipeline->representation_id === null
                        ? null
                        : (int) $pipeline->representation_id,
                    // Where the pair came from. An enum, never a sentence: the app owns the words
                    // in its own ARB, in the reader's own language.
                    'source' => $engagementBorn ? 'engagement' : 'request',
                    'customer_name' => $pipeline->targetMatrimonyProfile?->full_name,
                    'member_name' => $engagementBorn
                        ? null
                        : $pipeline->requestingMatrimonyProfile?->full_name,
                    'helper_suchak_name' => $engagementBorn ? $helper?->suchak_name : null,
                    'opened_at' => $pipeline->created_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }
}
