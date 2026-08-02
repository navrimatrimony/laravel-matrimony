<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationRequest;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakStageLadderPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin mobile adapter for collaboration list (read path mirrors web CollaborationController::index filters).
 *
 * ── WHY THE ENGAGEMENT'S OWN STATE RIDES ON THIS ROW AND NOT ON A NEW ROUTE ───────────────────
 *
 * There is no detail GET for a collaboration and there must not become one: this list is already
 * fetched on every load of both the list screen AND the engagement detail screen, so a second
 * route would be a second round trip — and a second read path — for a row this query already has
 * in hand. Everything added below rides on relations `SuchakCollaborationRequest` already
 * declares (`commissionAgreement`, `stageEvents`) or on columns already selected with the row
 * (`customer_owner_side`, the two `*_representation_id`s), so the cost is two eager loads over a
 * page of at most 50 rows, and nothing here is a new fact — each is a column or a derivation of
 * one that some other Suchak door already publishes.
 *
 * It closes two real dead ends:
 *
 *  1. THE AMNESIAC LADDER. `advanceMarketplaceStage()` deliberately does not move
 *     `marketplace_stage` for the three CONFIRMABLE rungs, so a claimed-but-unconfirmed
 *     `marriage_settled` or `engagement` existed ONLY in the app's session state and was lost on
 *     restart — after which the ladder under-reported and re-offered a rung the server refuses.
 *     `stage_events` is the recorded truth, so nothing has to be remembered client-side.
 *  2. AN UNREACHABLE `linkCustomerAgreement`. It needs a `customer_agreement_id`, whose only
 *     lister is `GET /customers/{representation}/payment-request-options`, which needs a
 *     representation id — and this payload carried neither. `my_representation_id` is the
 *     caller's OWN side of the engagement, which is exactly the representation that route
 *     accepts and exactly the side `assertCustomerCandidateSitsOnSide()` will check the agreement
 *     against.
 *
 * ── MASKING (D19a) ───────────────────────────────────────────────────────────────────────────
 *
 * This payload has never carried a candidate and still does not: no name, no age, no village, no
 * photo, no contact, no `matrimony_profile_id`. `SuchakCandidateMaskingService` is therefore not
 * a party to it — there is no candidate here for it to present, masked or otherwise.
 *
 * The two keys that COULD have leaked across the pair, and what was done with each:
 *
 *  - `customer_owner_representation_id` — a representation id is a handle to a Suchak's own
 *    customer. It is published ONLY to the Suchak who holds that customer (where it is simply
 *    `my_representation_id` under the name the role gives it), and is null to the helper. Handing
 *    the helper the owner's representation id would have named the other Suchak's customer, and
 *    would have been useless to him anyway — `payment-request-options` scopes representations to
 *    the caller's account and answers 404.
 *  - `customer_agreement_id` — published to BOTH sides, because the success-fee ledger door
 *    (`GET …/success-fee-tranches`, open to either participant via `participatingSuchakUser()`)
 *    already publishes exactly this id to exactly these two accounts. It is an opaque integer to
 *    the helper: every route that consumes an agreement id re-scopes it to the caller's own
 *    account and 404s otherwise.
 *
 * `stage_events` carries ladder facts only — the rung, its timestamps, its actor RULE and whether
 * it settled. `SuchakStageLadderPresenter` withholds `event_note` (free text that can name a
 * family) and every claimed-by column.
 */
class SuchakCollaborationsApiController extends Controller
{
    public function __construct(private readonly SuchakStageLadderPresenter $ladder)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        /** @var SuchakAccount|null $account */
        $account = $user->suchakAccount;
        if ($account === null) {
            return response()->json([
                'success' => false,
                'message' => 'Suchak account is required to access this section.',
            ], 403);
        }

        $status = $request->query('status');
        $status = in_array($status, SuchakCollaborationRequest::STATUSES, true) ? $status : null;
        $direction = $request->query('direction');
        $direction = in_array($direction, ['incoming', 'outgoing'], true) ? $direction : null;

        $rows = SuchakCollaborationRequest::query()
            ->with([
                'requestingSuchakAccount:id,suchak_name',
                'targetSuchakAccount:id,suchak_name',
                // Blueprint 6.1 — the engagement IS SuchakCollaborationRequest +
                // SuchakCommissionAgreement, and the agreement is where the customer agreement
                // revision in force is named.
                'commissionAgreement:id,collaboration_request_id,customer_agreement_id',
                'stageEvents',
            ])
            ->where(function ($query) use ($account): void {
                $query
                    ->where('requesting_suchak_account_id', $account->id)
                    ->orWhere('target_suchak_account_id', $account->id);
            })
            ->when($direction === 'incoming', fn ($query) => $query->where('target_suchak_account_id', $account->id))
            ->when($direction === 'outgoing', fn ($query) => $query->where('requesting_suchak_account_id', $account->id))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (SuchakCollaborationRequest $row): array => $this->row($row, $account))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Suchak collaborations loaded.',
            'data' => [
                'account_id' => $account->id,
                'collaborations' => $rows,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SuchakCollaborationRequest $row, SuchakAccount $account): array
    {
        $incoming = (int) $row->target_suchak_account_id === (int) $account->id;

        // THE PROOF THE ROLE WAS RECORDED, and it is the service's own predicate rather than a
        // second one: `assertStageClaimant()` treats the role as a finding only when the
        // commission agreement NAMES a customer agreement, because `customer_owner_side` DEFAULTS
        // to `target` and `bindCustomerAgreement()` is the one writer of both columns, in the same
        // breath. Publishing the default as though it were a fact is how an app ends up telling a
        // helper he owns the customer.
        $customerAgreementId = $row->commissionAgreement?->customer_agreement_id === null
            ? null
            : (int) $row->commissionAgreement->customer_agreement_id;
        $roleRecorded = $customerAgreementId !== null;

        $mySide = $row->sideForAccount((int) $account->id);
        $myRepresentationId = $mySide === SuchakCollaborationRequest::SIDE_REQUESTING
            ? $row->requesting_representation_id
            : $row->target_representation_id;
        $myRepresentationId = $myRepresentationId === null ? null : (int) $myRepresentationId;

        $isCustomerOwner = $roleRecorded ? $row->isCustomerOwner((int) $account->id) : null;

        return [
            'id' => $row->id,
            'status' => $row->status,
            'direction' => $incoming ? 'incoming' : 'outgoing',
            'requesting_suchak_name' => $row->requestingSuchakAccount?->suchak_name,
            'target_suchak_name' => $row->targetSuchakAccount?->suchak_name,
            // A marketplace proposal arrives on this same list and is accepted with the same
            // two routes, but it is not the same thing as a direct request: the terms are
            // frozen from the challenge and cannot be re-quoted, and the incoming candidate
            // is one the caller invited by publishing. Without this the app cannot tell
            // them apart, and the challenge's own proposal inbox has no id to link to.
            'marketplace_challenge_id' => $row->marketplace_challenge_id === null
                ? null
                : (int) $row->marketplace_challenge_id,
            'marketplace_stage' => $row->marketplace_stage,
            'created_at' => $row->created_at?->toIso8601String(),
            'expires_at' => $row->expires_at?->toIso8601String(),

            // ── blueprint 6.1: the engagement's binding to the agreement revision in force ──
            //
            // Null means UNLINKED, and then no rung is claimable, no tranche can fire and the
            // success-fee ledger answers 422 — which is the whole reason the app needs to know
            // before it offers anything.
            'customer_agreement_id' => $customerAgreementId,
            // Null while the role is only a column default; `requesting` / `target` once recorded.
            'customer_owner_side' => $roleRecorded ? $row->customer_owner_side : null,
            // Null while unrecorded. True means the caller holds the customer, the agreement and
            // the collection — the side that alone may settle a success-fee tranche (§2, M1).
            'is_customer_owner' => $isCustomerOwner,
            // The caller's OWN representation on this engagement — the id
            // `GET /customers/{representation}/payment-request-options` accepts, and the side an
            // agreement offered to `linkCustomerAgreement` must be about.
            'my_representation_id' => $myRepresentationId,
            // The same id under the name the ROLE gives it, and only to the Suchak who holds that
            // customer. Never the counterparty's — see the class docblock.
            'customer_owner_representation_id' => $isCustomerOwner === true ? $myRepresentationId : null,

            // ── blueprint 6a: the rungs this engagement actually holds ──
            //
            // The recorded ladder, in ladder order. `marketplace_stage` above is only the furthest
            // SETTLED rung: `advanceMarketplaceStage()` does not move it for a rung still awaiting
            // the family's confirmation, so a claim on `marriage_settled` or `engagement` appears
            // ONLY here.
            'stage_events' => $this->ladder->rungs($row->stageEvents),
        ];
    }
}
