<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPortalLink;
use App\Modules\Suchak\Services\SuchakCollaborationService;
use App\Modules\Suchak\Services\SuchakCustomerPortalService;
use App\Modules\Suchak\Services\SuchakTwelveMonthClauseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * THE CUSTOMER'S DOOR onto the marketplace stage ladder (blueprint 6a, D11, D23).
 *
 * Three rungs belong to the family and to nobody else — `viewed` (स्थळ पाहिले), `interested`
 * (पसंती दर्शवली) and `meeting_confirmed` (भेटीला दुजोरा). Every Suchak is refused them, correctly:
 * a Suchak writing "the family looked at this" is the forged record 9a A2/A3 exist to stop. The
 * result was that no row could exist for any of the three, and D11's 12-month anti-circumvention
 * clause — which binds at `viewed` — had no trigger it could ever fire.
 *
 * ── WHY THIS IS A PUBLIC, TOKENISED ROUTE ────────────────────────────────────────────────────
 *
 * Blueprint section 2: the customer is the candidate's FAMILY, and often has no login at all —
 * `users.mobile` is null whenever the number on file is a household number. Both existing
 * member-side doors (MemberSuchakMeetingApiController, MemberSuchakStageApiController) require
 * `$request->user()` plus a matrimony profile whose `user_id` matches, so they are unreachable for
 * exactly the families the blueprint describes.
 *
 * ── WHICH TOKEN, AND WHY NOT A FIFTH ─────────────────────────────────────────────────────────
 *
 * This route hangs off the EXISTING customer portal link (`suchak_customer_portal_links`), the same
 * token the family already received with their payment request. It is the only one of the four
 * tokenised links in this codebase that survives more than one visit and can therefore carry three
 * acts weeks apart: the agreement-acceptance token and the consent token are both single use, and a
 * payment-request token is a money artifact rather than an identity. It is also the only one that
 * records WHO in the family is holding it (`claimed_name`, `claimed_relationship_to_candidate`).
 *
 * Opening the token is SuchakCustomerPortalService::openPortalLink()'s job and is not re-implemented
 * here: it owns revoked / expired / issuing-Suchak-can-operate and writes the open event.
 *
 * ── WHAT A SUBMISSION HERE PROVES, AND WHAT IT DOES NOT (D23) ────────────────────────────────
 *
 * It proves that somebody holding this link pressed this button, at this time, from this IP and
 * user agent. It does NOT prove who they were. OTP does not exist on production (section 10 S4), so
 * nothing on this path writes a `mobile_match`, a `*_verified` flag or an acceptance tier — section
 * 8 names one such unchecked claim already in the codebase and it is not repeated here. The page
 * says this to the family in plain Marathi rather than only in a comment.
 */
class CustomerStageDoorController extends Controller
{
    /**
     * GET /suchak/customer-portal/{token}/stages
     *
     * The proposals made for this family, and which rungs they have already recorded.
     */
    public function index(
        Request $request,
        string $token,
        SuchakCustomerPortalService $portalService,
        SuchakCollaborationService $collaborationService,
        SuchakTwelveMonthClauseService $clauseService,
    ): View {
        $portalLink = $this->openLink($request, $token, $portalService);
        $customerContext = $portalLink->customerContext;

        // D11 / D21, shown to the party it binds. The family is the only one who can be surprised by
        // this clause a year later, and D27's test ("does the reader do something differently?")
        // passes here plainly: knowing a marriage to this candidate before a given date still owes
        // the success fee is the whole basis on which they decide what to do next.
        $clauses = $customerContext instanceof SuchakCustomerContext
            ? $clauseService->bindingsByEngagement($customerContext)
            : [];

        return view('suchak.customer-portal.stages', [
            'token' => $token,
            'portalLink' => $portalLink,
            'customerContext' => $customerContext,
            'engagements' => $this->engagementRows(
                $collaborationService->customerEngagementsForPortalLink($portalLink),
                $portalLink,
                $clauses,
            ),
            'stageKeys' => SuchakCollaborationStageEvent::customerClaimedStages(),
            'clauseAnchorStage' => SuchakCollaborationStageEvent::CLAUSE_ANCHOR_STAGE,
            'clauseTerms' => $clauseService->terms(),
        ]);
    }

    /**
     * POST /suchak/customer-portal/{token}/stages/{collaboration}
     *
     * `stage_key` is validated against the rungs the customer OWNS, derived from STAGE_CLAIMANTS —
     * never a hand-written list here, and never the whole ladder. Everything else the record needs
     * (which engagement, whether this link governs it, whether the terms are in force) is the
     * service's to refuse, so a wrong answer comes back as a sentence the family can read rather
     * than as a validation code.
     */
    public function record(
        Request $request,
        string $token,
        SuchakCollaborationRequest $collaboration,
        SuchakCustomerPortalService $portalService,
        SuchakCollaborationService $collaborationService,
    ): RedirectResponse {
        $portalLink = $this->openLink($request, $token, $portalService);

        $validated = $request->validate([
            'stage_key' => ['required', 'string', Rule::in(SuchakCollaborationStageEvent::customerClaimedStages())],
            'event_note' => ['nullable', 'string', 'max:2000'],
            // 9a A6 — the family's one tap that releases the 12-month clause for this profile. Sent
            // only on the `viewed` form; the service refuses it on any other rung rather than
            // ignoring it, because a release the family believed they made and that silently did
            // nothing is worse than a refusal they can see.
            'prior_acquaintance' => ['nullable', 'boolean'],
        ]);

        try {
            $collaborationService->recordCustomerStage(
                $collaboration,
                $portalLink,
                (string) $validated['stage_key'],
                $validated['event_note'] ?? null,
                $request->ip(),
                $request->userAgent(),
                (bool) ($validated['prior_acquaintance'] ?? false),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['customer_stage' => $exception->getMessage()]);
        }

        return redirect()
            ->route('suchak.customer-portal.stages.index', ['token' => $token])
            /*
             * The stage name is locale-aware, so the sentence around it has to
             * be too. As a Marathi literal this printed "…: Profile viewed" at
             * an English-reading family — the same half-translated splice the
             * agreement page's installment rows had.
             */
            ->with('success', __('suchak.customer_portal.stages.recorded', [
                'stage' => SuchakCollaborationStageEvent::stageLabel((string) $validated['stage_key']),
            ]));
    }

    /**
     * 410 on a dead link, matching CustomerPortalController — a token that is revoked, expired or
     * simply wrong is gone, not forbidden, and the family should not be told which of the three.
     */
    private function openLink(
        Request $request,
        string $token,
        SuchakCustomerPortalService $portalService,
    ): SuchakCustomerPortalLink {
        try {
            return $portalService->openPortalLink($token, $request->ip(), $request->userAgent());
        } catch (InvalidArgumentException $exception) {
            abort(410, $exception->getMessage());
        }
    }

    /**
     * One row per proposal, carrying the OTHER family's candidate — the one this family is being
     * asked about — and the rungs already recorded.
     *
     * "The other side" is read from the engagement's ROLE label (`customer_owner_side`), never
     * guessed from a position: on a marketplace engagement the responder is the requester, and the
     * old form here — "not the requesting one, unless it is" — printed the REQUESTING profile's
     * name for any engagement the family's candidate was absent from, which is the same stranger
     * the twelve-month clause used to bind to. A row whose customer is not on the pair now shows
     * no name at all rather than a confident wrong one; the service refuses to create such a row,
     * and this is what a pre-existing one looks like.
     *
     * No masking is applied: blueprint section 9 gives the CUSTOMER the full profile, and D19a's
     * four hidden defaults are a cross-SUCHAK rule.
     *
     * @param  Collection<int, SuchakCollaborationRequest>  $engagements
     * @param  array<int, array<string, mixed>>  $clauses  keyed by collaboration request id
     * @return list<array<string, mixed>>
     */
    private function engagementRows(
        Collection $engagements,
        SuchakCustomerPortalLink $portalLink,
        array $clauses = [],
    ): array {
        $ownCandidateId = $portalLink->customerContext?->candidate_matrimony_profile_id;

        return $engagements
            ->map(function (SuchakCollaborationRequest $collaboration) use ($ownCandidateId, $clauses): array {
                $proposed = $ownCandidateId !== null
                    && $collaboration->customerOwnerMatrimonyProfileId() === (int) $ownCandidateId
                        ? ($collaboration->helpingMatrimonyProfileId() === (int) $collaboration->requesting_matrimony_profile_id
                            ? $collaboration->requestingMatrimonyProfile
                            : $collaboration->targetMatrimonyProfile)
                        : null;

                $recorded = $collaboration->stageEvents
                    ->whereNotNull('claimed_at')
                    ->pluck('claimed_at', 'stage_key')
                    ->all();

                return [
                    'collaboration' => $collaboration,
                    'proposed_name' => $proposed?->full_name,
                    'recorded' => $recorded,
                    'clause' => $clauses[(int) $collaboration->id] ?? null,
                ];
            })
            ->values()
            ->all();
    }
}
