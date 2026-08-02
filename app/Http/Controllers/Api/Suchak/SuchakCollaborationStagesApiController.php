<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakMarriageOutcome;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCollaborationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * The Suchak half of the marketplace stage ladder (blueprint 6a) and of the engagement's binding to
 * the customer agreement revision (blueprint 6.1).
 *
 * `linkCustomerAgreement()`, `claimStage()` and `confirmStage()` shipped complete, guarded and
 * tested — and unreachable. No controller and no route called any of them, so in a live database
 * `suchak_collaboration_requests.customer_owner_side` could only ever hold its default `target`,
 * and `marketplace_stage` / `suchak_commission_agreements.customer_agreement_id` could only ever be
 * NULL. A column no writer writes and a method no route calls are the same defect. This class is
 * their door, plus the door for the pre-engagement half of the ladder.
 *
 * The customer's own door — confirming a claimed terminal stage (D26) — is deliberately NOT here:
 * the actor is the member, so it lives on the member API beside the meeting engine's confirm route,
 * in MemberSuchakStageApiController. Routing it through this Suchak-authenticated group would have
 * let any verified Suchak confirm a stranger's marriage claim and release a success-fee tranche.
 *
 * Authorisation follows SuchakCollaborationsMutationsApiController exactly: this codebase has no
 * policy layer, so the rules are the service's own exceptions surfaced as 422, with a caller-must-
 * hold-a-Suchak-account check in front and a 404 for rows outside the caller's account (the shape
 * SuchakAgreementLinkApiController established — a Suchak has no business learning that another
 * Suchak's agreement exists).
 */
class SuchakCollaborationStagesApiController extends Controller
{
    /**
     * POST /api/v1/suchak/collaborations/{collaboration}/customer-agreement
     *
     * Names the customer-owning side of the engagement and freezes the customer agreement REVISION
     * in force. Write-once: re-binding a different revision is refused by the service.
     *
     * Body: `customer_agreement_id` (required int — an agreement of the CALLER's account).
     * 200: `{ success, message, data: { collaboration_id, customer_owner_side,
     *         customer_owner_suchak_account_id, helping_suchak_account_id, customer_agreement_id } }`
     */
    public function linkCustomerAgreement(
        Request $request,
        SuchakCollaborationRequest $collaboration,
        SuchakCollaborationService $collaborationService,
    ): JsonResponse {
        $user = $this->suchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $validated = $request->validate([
            'customer_agreement_id' => ['required', 'integer'],
        ]);

        /** @var SuchakCustomerAgreement|null $agreement */
        $agreement = SuchakCustomerAgreement::query()
            ->whereKey((int) $validated['customer_agreement_id'])
            ->where('suchak_account_id', $user->suchakAccount->id)
            ->first();

        if ($agreement === null) {
            return $this->error('हा करार तुमच्या खात्यात सापडला नाही.', 404);
        }

        try {
            $linked = $collaborationService->linkCustomerAgreement(
                $collaboration,
                $user->suchakAccount,
                $user,
                $agreement,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        $linked->loadMissing('commissionAgreement');

        return response()->json([
            'success' => true,
            'message' => 'करार या सहकार्याशी जोडला.',
            'data' => [
                'collaboration_id' => (int) $linked->id,
                'customer_owner_side' => $linked->customer_owner_side,
                'customer_owner_suchak_account_id' => $linked->customerOwnerSuchakAccountId(),
                'helping_suchak_account_id' => $linked->helpingSuchakAccountId(),
                'customer_agreement_id' => $linked->commissionAgreement?->customer_agreement_id === null
                    ? null
                    : (int) $linked->commissionAgreement->customer_agreement_id,
            ],
        ]);
    }

    /**
     * POST /api/v1/suchak/collaborations/{collaboration}/stages
     *
     * Claims one engagement-owned ladder stage.
     *
     * Being a participant is not enough — section 6a names an ACTOR per rung and the service
     * enforces it (SuchakCollaborationStageEvent::STAGE_CLAIMANTS):
     *
     *   profile_suggested, meeting_completed, share_settled  → the HELPING Suchak only
     *   meeting_scheduled, marriage_settled, engagement, marriage → either Suchak (D26)
     *   viewed, interested, meeting_confirmed                → the CUSTOMER — refused to everyone
     *                                                          today, because the customer has no
     *                                                          door yet (D23, §10 S4)
     *
     * `viewed`, `interested` and `meeting_confirmed` stay in the validation list on purpose. They
     * ARE real engagement stages; the reason they are refused is the actor, not the vocabulary, and
     * a 422 that says so in Marathi tells the Suchak something true, where "the selected stage_key
     * is invalid" would not.
     *
     * `marriage` is the ONE exception and is removed from the list outright (blueprint 6.2, phase
     * 4). It is not an actor refusal — either Suchak may claim it — it is that this route cannot
     * carry the fact the rung is worthless without: THE DATE OF THE WEDDING. `claimed_at` and
     * `confirmed_at` are when it was REPORTED, and M3 keys the cross-Suchak share on "a fixed
     * number of days after a recorded Marriage", so a rung claimed here would start no clock and
     * produce no §6.2 attribution row. It is recorded through
     * POST /suchak/collaborations/{collaboration}/marriage instead
     * (SuchakMarriageOutcomeApiController), which requires the date and writes both rows together.
     * The exclusion is DERIVED from SuchakMarriageOutcome::EVIDENCE_STAGE rather than spelled here,
     * so moving that constant moves this list with it.
     *
     * `profile_suggested` is claimable on a PENDING engagement (a marketplace proposal is created
     * pending); everything from `meeting_scheduled` onward needs the engagement accepted.
     *
     * Body: `stage_key` (required, one of the engagement stages), `event_note` (nullable, max 2000).
     * 201: `{ success, message, data: { stage_event_id, stage_key, stage_label, owner,
     *         collaboration_id, claimed_at, requires_confirmation, marketplace_stage } }`
     */
    public function claimEngagementStage(
        Request $request,
        SuchakCollaborationRequest $collaboration,
        SuchakCollaborationService $collaborationService,
    ): JsonResponse {
        $user = $this->suchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $validated = $request->validate([
            'stage_key' => ['required', 'string', Rule::in($this->claimableEngagementStages())],
            'event_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $event = $collaborationService->claimStage(
                $collaboration,
                $user->suchakAccount,
                $user,
                (string) $validated['stage_key'],
                $validated['event_note'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'टप्पा नोंदवला.',
            'data' => $this->stagePayload($event) + [
                'marketplace_stage' => $collaboration->fresh()?->marketplace_stage,
            ],
        ], 201);
    }

    /**
     * POST /api/v1/suchak/customer-agreements/{agreement}/stages
     *
     * Claims one of the four PRE-ENGAGEMENT stages (registration, agreement proposed, agreement
     * accepted, published to marketplace). They have no engagement to hang off —
     * `published_to_marketplace` is the act that invites a counterparty — so they hang off the
     * customer agreement revision, which is what section 4 says publication attaches to.
     *
     * Body: `stage_key` (required, one of the pre-engagement stages), `event_note` (nullable).
     * 201: `{ success, message, data: { stage_event_id, stage_key, stage_label, owner,
     *         customer_agreement_id, claimed_at, requires_confirmation } }`
     */
    public function claimCustomerStage(
        Request $request,
        int $agreement,
        SuchakCollaborationService $collaborationService,
    ): JsonResponse {
        $user = $this->suchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $validated = $request->validate([
            'stage_key' => ['required', 'string', Rule::in(SuchakCollaborationStageEvent::preEngagementStages())],
            'event_note' => ['nullable', 'string', 'max:2000'],
        ]);

        /** @var SuchakCustomerAgreement|null $model */
        $model = SuchakCustomerAgreement::query()
            ->whereKey($agreement)
            ->where('suchak_account_id', $user->suchakAccount->id)
            ->first();

        if ($model === null) {
            return $this->error('हा करार तुमच्या खात्यात सापडला नाही.', 404);
        }

        try {
            $event = $collaborationService->claimCustomerStage(
                $model,
                $user->suchakAccount,
                $user,
                (string) $validated['stage_key'],
                $validated['event_note'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'टप्पा नोंदवला.',
            'data' => $this->stagePayload($event),
        ], 201);
    }

    /**
     * The engagement-owned rungs this route accepts: the ladder's engagement half MINUS the one
     * rung that has a door of its own.
     *
     * Derived, never hand-written. `SuchakMarriageOutcome::EVIDENCE_STAGE` is the single name of the
     * rung a marriage is evidenced by; if it ever moves, this list moves with it instead of silently
     * re-opening the door this exclusion exists to close.
     *
     * @return list<string>
     */
    private function claimableEngagementStages(): array
    {
        return array_values(array_diff(
            SuchakCollaborationStageEvent::engagementStages(),
            [SuchakMarriageOutcome::EVIDENCE_STAGE],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function stagePayload(SuchakCollaborationStageEvent $event): array
    {
        return [
            'stage_event_id' => (int) $event->id,
            'stage_key' => $event->stage_key,
            'stage_label' => SuchakCollaborationStageEvent::stageLabel((string) $event->stage_key),
            'owner' => $event->ownerColumn(),
            'collaboration_id' => $event->collaboration_request_id === null
                ? null
                : (int) $event->collaboration_request_id,
            'customer_agreement_id' => $event->customer_agreement_id === null
                ? null
                : (int) $event->customer_agreement_id,
            'claimed_at' => $event->claimed_at?->toIso8601String(),
            'confirmed_at' => $event->confirmed_at?->toIso8601String(),
            // Who was entitled to write this rung. The app can grey the button out instead of
            // guessing, and a stored row carries the rule it was written under.
            'claimant' => SuchakCollaborationStageEvent::claimantFor((string) $event->stage_key),
            'requires_confirmation' => SuchakCollaborationStageEvent::requiresConfirmation((string) $event->stage_key),
            'is_settled' => $event->isSettled(),
        ];
    }

    private function suchakUser(Request $request): User|JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return $this->error('सूचक खाते आवश्यक आहे.', 403);
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
