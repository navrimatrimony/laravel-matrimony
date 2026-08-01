<?php

namespace Tests\Feature\Suchak;

use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakCommissionAgreement;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPlan;
use App\Models\SuchakMarketplaceChallenge;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAccessService;
use App\Modules\Suchak\Services\SuchakCollaborationService;
use App\Modules\Suchak\Services\SuchakMarketplaceChallengeService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ACCEPT BY PROPOSING (blueprint D7 / D7a / section 6.1 / section 11 phase 2).
 *
 * A helping Suchak cannot press a bare "accept" — he NAMES one of his own candidates, and that act
 * creates the engagement. The engagement is not a new object: it is
 * `suchak_collaboration_requests` + `suchak_commission_agreements`, written in the REVERSED
 * direction (section 5.2's direction note — "the Suchak answering a challenge becomes the
 * requester").
 *
 * The reversal is the whole risk. Four things the original direction hard-wired change meaning the
 * moment the responder becomes the requester, and this class pins each of them by its blueprint
 * name: H1 the collector, H2 the account gates, H3 the open-request quota, H5 the re-quote route.
 */
class SuchakAcceptByProposingTest extends TestCase
{
    use RefreshDatabase;

    // ── The engagement is the existing pair, reversed ─────────────────────────────────────────

    public function test_proposing_creates_the_existing_engagement_pair_and_no_third_table(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        [$publisherRepresentation, $customerAgreement] = $this->publishableCandidate($publisher, $publisherUser);
        $helperCandidate = $this->helperCandidate($helper);

        $challenge = $this->challengeService()->publish(
            $publisher,
            $publisherUser,
            $publisherRepresentation,
            $this->percentTerms(30),
        );

        $proposed = $this->challengeService()->proposeCandidate(
            $challenge,
            $helper,
            $helperUser,
            $helperCandidate,
            ['message' => 'हे स्थळ जुळेल असे वाटते.'],
        );

        /** @var SuchakCollaborationRequest $request */
        $request = $proposed['request'];
        /** @var SuchakCommissionAgreement $agreement */
        $agreement = $proposed['agreement'];

        // Section 6.1 in one assertion: nothing new was built to hold one customer and two Suchaks.
        $this->assertFalse(Schema::hasTable('suchak_engagements'));
        $this->assertFalse(Schema::hasTable('suchak_marketplace_proposals'));

        // The REVERSAL: the answering Suchak is the requester, the publisher is the target.
        $this->assertSame((int) $helper->id, (int) $request->requesting_suchak_account_id);
        $this->assertSame((int) $publisher->id, (int) $request->target_suchak_account_id);
        $this->assertSame((int) $helperCandidate->id, (int) $request->requesting_representation_id);
        $this->assertSame((int) $publisherRepresentation->id, (int) $request->target_representation_id);
        $this->assertSame(SuchakCollaborationRequest::STATUS_PENDING, $request->status);
        $this->assertSame((int) $challenge->id, (int) $request->marketplace_challenge_id);
        $this->assertTrue($request->isMarketplaceProposal());

        // The role is a RECORDED fact from row one, not the `customer_owner_side` column default:
        // the challenge proved it, and the frozen revision is the challenge's, never the helper's.
        $this->assertSame(SuchakCollaborationRequest::SIDE_TARGET, $request->customer_owner_side);
        $this->assertSame((int) $customerAgreement->id, (int) $agreement->customer_agreement_id);
        $this->assertSame((int) $publisher->id, $request->customerOwnerSuchakAccountId());
        $this->assertSame((int) $helper->id, $request->helpingSuchakAccountId());

        // D18: what happened to a published candidate reaches the Suchak who published him. The
        // collaboration log row is filed under the REQUESTER, who here is the other party.
        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $publisher->id,
            'action_type' => SuchakActivityLog::ACTION_MARKETPLACE_PROPOSAL_RECEIVED,
            'target_type' => 'suchak_marketplace_challenge',
            'target_id' => $challenge->id,
        ]);
    }

    public function test_the_proposal_records_profile_suggested_through_the_one_stage_writer(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        $challenge = $this->openChallenge($publisher, $publisherUser);
        $helperCandidate = $this->helperCandidate($helper);

        $proposed = $this->challengeService()->proposeCandidate(
            $challenge,
            $helper,
            $helperUser,
            $helperCandidate,
            ['message' => 'स्थळ सुचवत आहे.'],
        );

        /** @var SuchakCollaborationRequest $request */
        $request = $proposed['request'];
        /** @var SuchakCollaborationStageEvent $event */
        $event = $proposed['stage_event'];

        $this->assertSame(SuchakCollaborationStageEvent::STAGE_PROFILE_SUGGESTED, $event->stage_key);
        // Owned by the ENGAGEMENT, not by the customer agreement: the counterparty now exists.
        $this->assertSame(
            SuchakCollaborationStageEvent::OWNER_COLUMN_COLLABORATION_REQUEST,
            $event->ownerColumn(),
        );
        $this->assertSame((int) $request->id, (int) $event->collaboration_request_id);
        // Section 6a's actor for this rung: "helper names their candidate."
        $this->assertSame(
            SuchakCollaborationStageEvent::CLAIMANT_HELPER,
            SuchakCollaborationStageEvent::claimantFor((string) $event->stage_key),
        );
        $this->assertSame((int) $helper->id, (int) $event->claimed_by_suchak_account_id);
        $this->assertSame((int) $helperUser->id, (int) $event->claimed_by_user_id);

        // Claimable on a PENDING engagement — the line sits at meeting_scheduled — and the ladder
        // position advanced with it.
        $this->assertFalse(SuchakCollaborationStageEvent::requiresAcceptedEngagement(
            SuchakCollaborationStageEvent::STAGE_PROFILE_SUGGESTED,
        ));
        $this->assertSame(
            SuchakCollaborationStageEvent::STAGE_PROFILE_SUGGESTED,
            $request->fresh()->marketplace_stage,
        );

        // Exactly one row, and the same rung cannot be claimed a second time through its own route.
        $this->assertSame(1, SuchakCollaborationStageEvent::query()
            ->where('collaboration_request_id', $request->id)
            ->where('stage_key', SuchakCollaborationStageEvent::STAGE_PROFILE_SUGGESTED)
            ->count());

        Sanctum::actingAs($helperUser);
        $this->postJson('/api/v1/suchak/collaborations/'.$request->id.'/stages', [
            'stage_key' => SuchakCollaborationStageEvent::STAGE_PROFILE_SUGGESTED,
        ])->assertStatus(422);
    }

    // ── H1: the collector is the publisher, and that is verified rather than assumed ──────────

    public function test_h1_the_pinned_collector_is_the_suchak_who_owns_the_customer(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        $challenge = $this->openChallenge($publisher, $publisherUser);

        $proposed = $this->challengeService()->proposeCandidate(
            $challenge,
            $helper,
            $helperUser,
            $this->helperCandidate($helper),
        );

        /** @var SuchakCollaborationRequest $request */
        $request = $proposed['request'];
        /** @var SuchakCommissionAgreement $agreement */
        $agreement = $proposed['agreement'];

        /*
         * createRequest() pins `collector_suchak_account_id` to the TARGET side, and has since
         * before the marketplace existed. Reversed, the target is the publisher — who holds the
         * customer, the agreement and the collection — so M1 ("each customer pays only their own
         * Suchak") lands correctly. It lands correctly BY ACCIDENT of the original wiring, which is
         * exactly why it is pinned here: the collector and the recorded customer-owning side must
         * be the same account, and nothing but this assertion says so.
         */
        $this->assertSame((int) $publisher->id, (int) $agreement->collector_suchak_account_id);
        $this->assertSame($request->customerOwnerSuchakAccountId(), (int) $agreement->collector_suchak_account_id);
        $this->assertNotSame((int) $helper->id, (int) $agreement->collector_suchak_account_id);
    }

    // ── H2: the badge gates both sides, and the direct path keeps its own gates ───────────────

    public function test_h2_an_unverified_helper_cannot_propose_even_though_he_may_operate(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        $challenge = $this->openChallenge($publisher, $publisherUser);
        $helperCandidate = $this->helperCandidate($helper);

        $helper->forceFill(['verification_status' => SuchakAccount::VERIFICATION_PENDING])->save();
        $helper->refresh();

        // The under-gate was REAL, not theoretical: the policy allows work before admin approval by
        // default, so createRequest()'s requester gate — canOperate() — still says yes here. D18 and
        // A10 say no, and A10's attack is precisely this cheap second account.
        $this->assertTrue($this->app->make(SuchakAccessService::class)->canOperate($helper));

        $this->assertProposalRefused($challenge, $helper, $helperUser, $helperCandidate, 'पडताळणी');
        $this->assertSame(0, SuchakCollaborationRequest::query()->count());
    }

    public function test_h2_a_publisher_who_is_not_in_the_public_directory_can_still_be_answered(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        [$publisherRepresentation] = $this->publishableCandidate($publisher, $publisherUser);

        $challenge = $this->challengeService()->publish(
            $publisher,
            $publisherUser,
            $publisherRepresentation,
            $this->percentTerms(30),
        );

        // Publishing needs the BADGE alone. If the target gate stayed canPubliclyRoute() —
        // VERIFIED *and* PUBLIC_ACTIVE — this legitimately published challenge would be one nobody
        // on earth could answer.
        $publisher->forceFill(['public_status' => SuchakAccount::PUBLIC_HIDDEN])->save();
        $this->assertFalse($this->app->make(SuchakAccessService::class)->canPubliclyRoute($publisher->fresh()));

        $proposed = $this->challengeService()->proposeCandidate(
            $challenge,
            $helper,
            $helperUser,
            $this->helperCandidate($helper),
        );

        $this->assertSame(SuchakCollaborationRequest::STATUS_PENDING, $proposed['request']->status);
    }

    public function test_h2_the_direct_collaboration_path_still_demands_a_publicly_routable_target(): void
    {
        [$ownerUser, $owner] = $this->verifiedSuchakActor();
        [$targetUser, $target] = $this->verifiedSuchakActor();
        $ownCandidate = $this->helperCandidate($owner);
        $targetCandidate = $this->helperCandidate($target);

        // Baseline: publicly routable, so the direct request goes through.
        $created = $this->collaborationService()->createRequest($owner, $ownerUser, $ownCandidate, $targetCandidate);
        $this->assertNull($created['request']->marketplace_challenge_id);
        $this->assertSame(SuchakCollaborationRequest::SIDE_TARGET, $created['request']->customer_owner_side);
        // No challenge, so nothing froze a revision — the direct path still binds its role later,
        // through linkCustomerAgreement(). Unchanged by this slice.
        $this->assertNull($created['agreement']->customer_agreement_id);

        // Now take the target out of the public directory. He is still VERIFIED, so the MARKETPLACE
        // gate would admit him — the direct gate must not, or this slice weakened the old path.
        $target->forceFill(['public_status' => SuchakAccount::PUBLIC_HIDDEN])->save();
        $secondOwnCandidate = $this->helperCandidate($owner);
        $secondTargetCandidate = $this->helperCandidate($target->fresh());

        try {
            $this->collaborationService()->createRequest(
                $owner,
                $ownerUser,
                $secondOwnCandidate,
                $secondTargetCandidate,
            );
            $this->fail('The direct collaboration path accepted a target that is not publicly routable.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Target representation must be publicly routable.', $exception->getMessage());
        }

        $this->assertTrue($target->fresh()->isVerified());
        $this->assertSame(1, SuchakCollaborationRequest::query()->count());
    }

    // ── H3: the quota lands on the side that initiated, deliberately ──────────────────────────

    public function test_h3_the_proposal_counts_against_the_helper_and_never_against_the_publisher(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$firstHelperUser, $firstHelper] = $this->verifiedSuchakActor();
        [$secondHelperUser, $secondHelper] = $this->verifiedSuchakActor();
        $challenge = $this->openChallenge($publisher, $publisherUser);

        $this->challengeService()->proposeCandidate($challenge, $firstHelper, $firstHelperUser, $this->helperCandidate($firstHelper));
        $this->challengeService()->proposeCandidate($challenge->fresh(), $secondHelper, $secondHelperUser, $this->helperCandidate($secondHelper));

        /*
         * SuchakLimitService::assertCollaborationRequestAllowed() counts open rows by
         * `requesting_suchak_account_id`. Reversed, that is the HELPER, and it is LEFT THAT WAY on
         * purpose: the entitlement means "open work you initiated against other Suchaks", and
         * proposing is exactly that. Capping the RECEIVING side instead would be D14's forbidden
         * block — "may rank suggestions but may not block them" — wearing an entitlement's clothes,
         * and it would punish the publisher for the one behaviour the marketplace exists to produce.
         */
        $openFor = fn (SuchakAccount $account): int => SuchakCollaborationRequest::query()
            ->where('requesting_suchak_account_id', $account->id)
            ->whereIn('status', SuchakCollaborationRequest::OPEN_STATUSES)
            ->count();

        $this->assertSame(1, $openFor($firstHelper));
        $this->assertSame(1, $openFor($secondHelper));
        $this->assertSame(0, $openFor($publisher), 'The publisher burned quota for proposals he only received.');
    }

    // ── H5: the split cannot be moved, by the helper or by anybody ────────────────────────────

    public function test_h5_the_helper_cannot_re_quote_the_split_the_challenge_declared(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        $challenge = $this->openChallenge($publisher, $publisherUser);

        $proposed = $this->challengeService()->proposeCandidate(
            $challenge,
            $helper,
            $helperUser,
            $this->helperCandidate($helper),
        );
        /** @var SuchakCollaborationRequest $request */
        $request = $proposed['request'];

        // This is a LIVE door, not a hypothetical one: the web route exists today and
        // updateCommissionTerms() is requester-only — which, reversed, is the helper.
        $this->assertTrue(Route::has('suchak.collaborations.commission.update'));

        try {
            $this->collaborationService()->updateCommissionTerms($request, $helper, $helperUser, [
                'split_type' => SuchakCommissionAgreement::SPLIT_CUSTOM_PERCENT,
                'groom_side_share' => 90,
                'bride_side_share' => 10,
            ]);
            $this->fail('The helper re-quoted a share D4 says is not negotiable.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('बदलता येत नाही', $exception->getMessage());
        }

        // Untouched: the declared 30/70 still stands, and no acceptance was reset.
        $agreement = $request->fresh(['commissionAgreement'])->commissionAgreement;
        $this->assertSame('30.00', (string) $agreement->groom_side_share);
        $this->assertSame('70.00', (string) $agreement->bride_side_share);
        $this->assertNotNull($agreement->accepted_by_groom_suchak_at);

        // And the publisher cannot move it either — he is the TARGET, and the method was always
        // requester-only. A marketplace split is republished, never re-quoted.
        try {
            $this->collaborationService()->updateCommissionTerms($request->fresh(), $publisher, $publisherUser, [
                'split_type' => SuchakCommissionAgreement::SPLIT_CUSTOM_PERCENT,
                'groom_side_share' => 10,
                'bride_side_share' => 90,
            ]);
            $this->fail('The publisher re-quoted a declared share.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Only the requesting Suchak account', $exception->getMessage());
        }
    }

    public function test_a_direct_collaboration_can_still_be_re_quoted_by_its_requester(): void
    {
        [$ownerUser, $owner] = $this->verifiedSuchakActor();
        [, $target] = $this->verifiedSuchakActor();

        $created = $this->collaborationService()->createRequest(
            $owner,
            $ownerUser,
            $this->helperCandidate($owner),
            $this->helperCandidate($target),
        );

        // H5 closed the marketplace door only. The direct path is where a split is genuinely
        // negotiated, and nothing about it changed.
        $updated = $this->collaborationService()->updateCommissionTerms(
            $created['request'],
            $owner,
            $ownerUser,
            [
                'split_type' => SuchakCommissionAgreement::SPLIT_CUSTOM_PERCENT,
                'groom_side_share' => 60,
                'bride_side_share' => 40,
            ],
        );

        $this->assertSame('60.00', (string) $updated->groom_side_share);
    }

    // ── The declared share is the challenge's, frozen at proposal time ────────────────────────

    public function test_the_declared_share_is_frozen_from_the_challenge_onto_the_engagement(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        $challenge = $this->openChallenge($publisher, $publisherUser, 30);

        $proposed = $this->challengeService()->proposeCandidate(
            $challenge,
            $helper,
            $helperUser,
            $this->helperCandidate($helper),
        );
        /** @var SuchakCommissionAgreement $agreement */
        $agreement = $proposed['agreement'];

        // One-directional declaration → two-sided agreement. With no gender on either candidate the
        // requester is the groom side, and the requester is the HELPER, so the helper's declared 30
        // lands on him and the publisher keeps the remainder. The two must total 100 exactly.
        $this->assertSame(SuchakCommissionAgreement::SPLIT_CUSTOM_PERCENT, $agreement->split_type);
        $this->assertSame((int) $helper->id, (int) $agreement->groom_side_suchak_account_id);
        $this->assertSame('30.00', (string) $agreement->groom_side_share);
        $this->assertSame('70.00', (string) $agreement->bride_side_share);
        $this->assertNull($agreement->fixed_amount);
        // The currency is READ from the agreement the challenge froze; nobody supplied it.
        $this->assertSame('INR', $agreement->currency);

        // D4: accepting the challenge IS accepting the share, so the helper's acknowledgement is
        // already on the row — there is no second act in which he could agree to something else.
        $this->assertNotNull($agreement->accepted_by_groom_suchak_at);
        $this->assertNull($agreement->accepted_by_bride_suchak_at);
        $this->assertSame(SuchakCommissionAgreement::STATUS_PENDING, $agreement->agreement_status);
    }

    public function test_the_declared_share_follows_the_helper_to_the_bride_side(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();

        $female = MasterGender::query()->firstOrCreate(['key' => 'female'], ['label' => 'Female', 'is_active' => true]);
        $male = MasterGender::query()->firstOrCreate(['key' => 'male'], ['label' => 'Male', 'is_active' => true]);

        $challenge = $this->openChallenge($publisher, $publisherUser, 30, genderId: (int) $male->id);
        $helperCandidate = $this->helperCandidate($helper, genderId: (int) $female->id);

        $proposed = $this->challengeService()->proposeCandidate($challenge, $helper, $helperUser, $helperCandidate);
        /** @var SuchakCommissionAgreement $agreement */
        $agreement = $proposed['agreement'];

        // The helper's candidate is female, so HE is the bride side now. The declared 30 must
        // follow the role, not the column name — otherwise the publisher would be paying himself
        // 30 and charging the helper 70.
        $this->assertSame((int) $helper->id, (int) $agreement->bride_side_suchak_account_id);
        $this->assertSame((int) $publisher->id, (int) $agreement->groom_side_suchak_account_id);
        $this->assertSame('30.00', (string) $agreement->bride_side_share);
        $this->assertSame('70.00', (string) $agreement->groom_side_share);
    }

    public function test_a_fixed_declared_amount_travels_with_the_agreements_currency(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        [$publisherRepresentation] = $this->publishableCandidate($publisher, $publisherUser);

        $challenge = $this->challengeService()->publish($publisher, $publisherUser, $publisherRepresentation, [
            'declared_share_type' => SuchakCommissionAgreement::SPLIT_FIXED_AMOUNT,
            'declared_share_amount' => 25000,
        ]);

        $proposed = $this->challengeService()->proposeCandidate(
            $challenge,
            $helper,
            $helperUser,
            $this->helperCandidate($helper),
        );
        /** @var SuchakCommissionAgreement $agreement */
        $agreement = $proposed['agreement'];

        $this->assertSame(SuchakCommissionAgreement::SPLIT_FIXED_AMOUNT, $agreement->split_type);
        $this->assertSame('25000.00', (string) $agreement->fixed_amount);
        $this->assertNull($agreement->groom_side_share);
        $this->assertSame('INR', $agreement->currency);
    }

    public function test_the_helper_cannot_type_a_share_or_a_currency(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        $challenge = $this->openChallenge($publisher, $publisherUser, 30);
        $helperCandidate = $this->helperCandidate($helper);

        Sanctum::actingAs($helperUser);

        foreach (['groom_side_share' => 90, 'fixed_amount' => 99999, 'currency' => 'USD'] as $field => $value) {
            $this->postJson('/api/v1/suchak/marketplace/challenges/'.$challenge->id.'/proposals', [
                'representation_id' => $helperCandidate->id,
                $field => $value,
            ])
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }

        // Refused, never quietly dropped — and nothing was created by the attempts.
        $this->assertSame(0, SuchakCollaborationRequest::query()->count());

        // The same refusal one layer down, so a caller bypassing the route gains nothing.
        try {
            $this->challengeService()->proposeCandidate($challenge, $helper, $helperUser, $helperCandidate, [
                'groom_side_share' => 90,
            ]);
            $this->fail('The service accepted a share the challenge already declared.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('आधीच जाहीर', $exception->getMessage());
        }
    }

    // ── The publisher answers, on the routes that already exist ───────────────────────────────

    public function test_the_publisher_accepts_on_the_existing_route_and_the_challenge_is_fulfilled(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        $challenge = $this->openChallenge($publisher, $publisherUser, 30);

        $proposed = $this->challengeService()->proposeCandidate(
            $challenge,
            $helper,
            $helperUser,
            $this->helperCandidate($helper),
        );
        /** @var SuchakCollaborationRequest $request */
        $request = $proposed['request'];

        // The helper is the requester, so acceptRequest()'s target-actor gate refuses him — he
        // cannot accept his own proposal into an engagement.
        Sanctum::actingAs($helperUser);
        $this->postJson('/api/v1/suchak/collaborations/'.$request->id.'/accept')->assertStatus(422);

        // The publisher IS the target in this direction, so no new accept verb was needed.
        Sanctum::actingAs($publisherUser);
        $this->postJson('/api/v1/suchak/collaborations/'.$request->id.'/accept')->assertOk();

        $accepted = $request->fresh(['commissionAgreement']);
        $this->assertSame(SuchakCollaborationRequest::STATUS_ACCEPTED, $accepted->status);
        $this->assertSame(SuchakCommissionAgreement::STATUS_ACCEPTED, $accepted->commissionAgreement->agreement_status);
        $this->assertTrue($this->collaborationService()->canExchangeContact($accepted));

        // STATUS_FULFILLED shipped with no writer. Acceptance is the honest moment, and this is it.
        $closed = $challenge->fresh();
        $this->assertSame(SuchakMarketplaceChallenge::STATUS_FULFILLED, $closed->status);
        $this->assertNotNull($closed->fulfilled_at);
    }

    public function test_rejecting_a_proposal_leaves_the_challenge_open_for_others(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        [$otherHelperUser, $otherHelper] = $this->verifiedSuchakActor();
        $challenge = $this->openChallenge($publisher, $publisherUser, 30);

        $proposed = $this->challengeService()->proposeCandidate(
            $challenge,
            $helper,
            $helperUser,
            $this->helperCandidate($helper),
        );

        Sanctum::actingAs($publisherUser);
        $this->postJson('/api/v1/suchak/collaborations/'.$proposed['request']->id.'/reject')->assertOk();

        $this->assertSame(SuchakCollaborationRequest::STATUS_REJECTED, $proposed['request']->fresh()->status);
        $this->assertSame(SuchakMarketplaceChallenge::STATUS_OPEN, $challenge->fresh()->status);

        // Still answerable by somebody else — a rejection is an answer to one proposal, not the end
        // of the search.
        $second = $this->challengeService()->proposeCandidate(
            $challenge->fresh(),
            $otherHelper,
            $otherHelperUser,
            $this->helperCandidate($otherHelper),
        );
        $this->assertSame(SuchakCollaborationRequest::STATUS_PENDING, $second['request']->status);
    }

    // ── A challenge that is not live takes no proposals ───────────────────────────────────────

    public function test_a_withdrawn_challenge_refuses_new_proposals_in_marathi(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        $challenge = $this->openChallenge($publisher, $publisherUser);

        $this->challengeService()->withdraw($challenge, $publisher, $publisherUser, 'कुटुंबाने शोध थांबवला.');

        $this->assertProposalRefused(
            $challenge->fresh(),
            $helper,
            $helperUser,
            $this->helperCandidate($helper),
            'मागे घेतले आहे',
        );
    }

    public function test_a_fulfilled_challenge_refuses_new_proposals_in_marathi(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$firstHelperUser, $firstHelper] = $this->verifiedSuchakActor();
        [$secondHelperUser, $secondHelper] = $this->verifiedSuchakActor();
        $challenge = $this->openChallenge($publisher, $publisherUser);

        $proposed = $this->challengeService()->proposeCandidate(
            $challenge,
            $firstHelper,
            $firstHelperUser,
            $this->helperCandidate($firstHelper),
        );
        $this->collaborationService()->acceptRequest($proposed['request'], $publisher, $publisherUser);

        $this->assertProposalRefused(
            $challenge->fresh(),
            $secondHelper,
            $secondHelperUser,
            $this->helperCandidate($secondHelper),
            'आधीच निश्चित झाले',
        );
    }

    public function test_an_expired_challenge_refuses_new_proposals_the_instant_its_day_passes(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        [$publisherRepresentation] = $this->publishableCandidate($publisher, $publisherUser);

        $challenge = $this->challengeService()->publish(
            $publisher,
            $publisherUser,
            $publisherRepresentation,
            $this->percentTerms(30) + ['expires_at' => now()->addDay()->toIso8601String()],
        );

        $this->travel(2)->days();

        // Still `open` in the column — expiry is evaluated live, so no sweep has to have run.
        $this->assertSame(SuchakMarketplaceChallenge::STATUS_OPEN, $challenge->fresh()->status);
        $this->assertProposalRefused(
            $challenge->fresh(),
            $helper,
            $helperUser,
            $this->helperCandidate($helper),
            'मुदत संपली',
        );
    }

    public function test_a_lapsed_consent_on_the_published_candidate_stops_the_proposal(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        [$publisherRepresentation] = $this->publishableCandidate($publisher, $publisherUser);

        $challenge = $this->challengeService()->publish(
            $publisher,
            $publisherUser,
            $publisherRepresentation,
            $this->percentTerms(30),
        );

        $publisherRepresentation->forceFill(['consent_valid_until' => now()->subDay()])->save();

        // The consent the candidate signed covers forwarding to suitable matches. A lapsed one
        // covers nothing, and the helper finds out here rather than after committing.
        $this->assertProposalRefused(
            $challenge->fresh(),
            $helper,
            $helperUser,
            $this->helperCandidate($helper),
            'संमती',
        );
    }

    public function test_a_helper_cannot_propose_a_candidate_whose_own_consent_has_lapsed(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        $challenge = $this->openChallenge($publisher, $publisherUser);

        $helperCandidate = $this->helperCandidate($helper);
        $helperCandidate->forceFill(['consent_valid_until' => now()->subDay()])->save();

        // D8: only registered, consented profiles may be proposed.
        $this->assertProposalRefused($challenge, $helper, $helperUser, $helperCandidate->fresh(), 'संमती');
    }

    // ── A2 and A10 ────────────────────────────────────────────────────────────────────────────

    public function test_a2_a_suchak_cannot_answer_his_own_challenge(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        $challenge = $this->openChallenge($publisher, $publisherUser);

        $this->assertProposalRefused(
            $challenge,
            $publisher,
            $publisherUser,
            $this->helperCandidate($publisher),
            'स्वतःच्या आव्हानाला',
        );
        $this->assertSame(0, SuchakCollaborationRequest::query()->count());
    }

    public function test_a10_the_same_candidate_cannot_be_proposed_to_the_same_challenge_twice(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        $challenge = $this->openChallenge($publisher, $publisherUser);
        $helperCandidate = $this->helperCandidate($helper);

        $proposed = $this->challengeService()->proposeCandidate($challenge, $helper, $helperUser, $helperCandidate);

        $this->assertProposalRefused(
            $challenge->fresh(),
            $helper,
            $helperUser,
            $helperCandidate->fresh(),
            'आधीच सुचवले',
        );

        // Status-BLIND: after a rejection the answer has been given, and asking again is pestering,
        // not retrying. This is the half assertNoDuplicateOpenRequest() does NOT cover.
        $this->collaborationService()->rejectRequest($proposed['request'], $publisher, $publisherUser);
        $this->assertProposalRefused(
            $challenge->fresh(),
            $helper,
            $helperUser,
            $helperCandidate->fresh(),
            'आधीच सुचवले',
        );

        $this->assertSame(1, SuchakCollaborationRequest::query()->count());

        // And the database carries the same rule, so a second entrance cannot reintroduce it.
        $unique = collect(Schema::getIndexes('suchak_collaboration_requests'))
            ->first(fn (array $index): bool => $index['columns'] === [
                'marketplace_challenge_id',
                'requesting_representation_id',
            ]);

        $this->assertNotNull($unique, 'The (challenge, proposed candidate) pair has no unique index.');
        $this->assertTrue((bool) $unique['unique']);
    }

    public function test_a_different_candidate_may_still_be_proposed_to_the_same_challenge(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        $challenge = $this->openChallenge($publisher, $publisherUser);

        $this->challengeService()->proposeCandidate($challenge, $helper, $helperUser, $this->helperCandidate($helper));
        $second = $this->challengeService()->proposeCandidate(
            $challenge->fresh(),
            $helper,
            $helperUser,
            $this->helperCandidate($helper),
        );

        // The rule is one candidate per challenge, not one helper per challenge: a Suchak holding
        // two hundred candidates may legitimately have two who fit.
        $this->assertSame(SuchakCollaborationRequest::STATUS_PENDING, $second['request']->status);
        $this->assertSame(2, SuchakCollaborationRequest::query()
            ->where('marketplace_challenge_id', $challenge->id)
            ->count());
    }

    // ── The frozen revision cannot be overwritten by the helper ───────────────────────────────

    public function test_the_helper_cannot_appoint_himself_the_customer_owning_side(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        [, $publisherAgreement] = $this->publishableCandidate($publisher, $publisherUser);
        $challenge = $this->challengeService()->publish(
            $publisher,
            $publisherUser,
            SuchakProfileRepresentation::query()->findOrFail($publisherAgreement->customerContext->representation_id),
            $this->percentTerms(30),
        );

        $proposed = $this->challengeService()->proposeCandidate(
            $challenge,
            $helper,
            $helperUser,
            $this->helperCandidate($helper),
        );
        /** @var SuchakCollaborationRequest $request */
        $request = $proposed['request'];

        // The role decides who may claim `share_settled` (A7's money rule) and who may claim
        // `meeting_completed`. Freezing the publisher's revision at proposal time is what makes
        // linkCustomerAgreement() — write-once — refuse the helper's own agreement afterwards.
        [$helperRepresentation, $helperOwnAgreement] = $this->publishableCandidate($helper, $helperUser);
        unset($helperRepresentation);

        try {
            $this->collaborationService()->linkCustomerAgreement($request, $helper, $helperUser, $helperOwnAgreement);
            $this->fail('The helper re-bound the engagement to his own customer agreement.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('already bound to another customer agreement', $exception->getMessage());
        }

        $this->assertSame(SuchakCollaborationRequest::SIDE_TARGET, $request->fresh()->customer_owner_side);
        $this->assertSame((int) $publisher->id, $request->fresh()->customerOwnerSuchakAccountId());
    }

    // ── The doors ─────────────────────────────────────────────────────────────────────────────

    public function test_the_proposal_routes_carry_the_whole_act_end_to_end(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        $challenge = $this->openChallenge($publisher, $publisherUser, 30);
        $helperCandidate = $this->helperCandidate($helper);

        Sanctum::actingAs($helperUser);

        $response = $this->postJson('/api/v1/suchak/marketplace/challenges/'.$challenge->id.'/proposals', [
            'representation_id' => $helperCandidate->id,
            'message' => 'पुण्यातील स्थळ आहे.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.challenge_id', (int) $challenge->id)
            ->assertJsonPath('data.status', SuchakCollaborationRequest::STATUS_PENDING)
            ->assertJsonPath('data.marketplace_stage', SuchakCollaborationStageEvent::STAGE_PROFILE_SUGGESTED)
            ->assertJsonPath('data.stage_event.stage_key', SuchakCollaborationStageEvent::STAGE_PROFILE_SUGGESTED)
            // The terms he has just accepted, read from the challenge and echoed back.
            ->assertJsonPath('data.declared_share.display', '30%')
            ->assertJsonPath('data.declared_share.currency', 'INR')
            ->assertJsonPath('data.declared_share.estimated_share_display', '₹30,000');

        $collaborationId = $response->json('data.collaboration_id');

        // The helper sees his own proposal on the collaborations list, marked as a marketplace one.
        $this->getJson('/api/v1/suchak/collaborations?direction=outgoing')
            ->assertOk()
            ->assertJsonPath('data.collaborations.0.id', $collaborationId)
            ->assertJsonPath('data.collaborations.0.marketplace_challenge_id', (int) $challenge->id);

        // The publisher reads the proposal before answering — masked like every cross-Suchak read.
        Sanctum::actingAs($publisherUser);
        $this->getJson('/api/v1/suchak/marketplace/challenges/'.$challenge->id.'/proposals')
            ->assertOk()
            ->assertJsonPath('data.0.collaboration_id', $collaborationId)
            ->assertJsonPath('data.0.proposing_suchak.suchak_account_id', (int) $helper->id)
            ->assertJsonPath('data.0.proposed_candidate.display_name', 'Rahul K.');

        $this->postJson('/api/v1/suchak/collaborations/'.$collaborationId.'/accept')->assertOk();
        $this->assertSame(SuchakMarketplaceChallenge::STATUS_FULFILLED, $challenge->fresh()->status);
    }

    public function test_the_proposal_routes_hide_what_is_not_the_callers(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        [$strangerUser] = $this->verifiedSuchakActor();
        $challenge = $this->openChallenge($publisher, $publisherUser);
        $helperCandidate = $this->helperCandidate($helper);

        // Another Suchak's representation is a 404, not a 422: the shape of the refusal must not
        // teach a Suchak that a stranger's candidate exists.
        Sanctum::actingAs($strangerUser);
        $this->postJson('/api/v1/suchak/marketplace/challenges/'.$challenge->id.'/proposals', [
            'representation_id' => $helperCandidate->id,
        ])->assertStatus(404);

        // The proposal inbox belongs to the publisher alone.
        $this->getJson('/api/v1/suchak/marketplace/challenges/'.$challenge->id.'/proposals')->assertStatus(404);

        Sanctum::actingAs($helperUser);
        $this->getJson('/api/v1/suchak/marketplace/challenges/'.$challenge->id.'/proposals')->assertStatus(404);

        $this->assertSame(0, SuchakCollaborationRequest::query()->count());
    }

    // ── The three attacks an adversarial reviewer proved over HTTP, each now refused ───────────

    /**
     * ATTACK 1 — THE DOUBLE ACCEPT. One declared share, owed twice.
     *
     * Proven over HTTP before the fix: the publisher accepted proposal A, the challenge became
     * `fulfilled`, and `POST /collaborations/{B}/accept` then returned 200 — a SECOND accepted
     * engagement, two helpers, both carrying `groom_side_share = 30.00` against one customer's
     * single ₹1,00,000 success fee. He declared 30% once and was bound for 60%.
     *
     * assertChallengeAcceptsProposals() guarded new PROPOSALS; nothing guarded acceptance.
     */
    public function test_attack_one_declared_share_cannot_be_owed_twice_by_a_second_acceptance(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$firstHelperUser, $firstHelper] = $this->verifiedSuchakActor();
        [$secondHelperUser, $secondHelper] = $this->verifiedSuchakActor();
        $challenge = $this->openChallenge($publisher, $publisherUser, 30);

        $first = $this->challengeService()->proposeCandidate(
            $challenge,
            $firstHelper,
            $firstHelperUser,
            $this->helperCandidate($firstHelper),
        )['request'];
        $second = $this->challengeService()->proposeCandidate(
            $challenge->fresh(),
            $secondHelper,
            $secondHelperUser,
            $this->helperCandidate($secondHelper),
        )['request'];

        Sanctum::actingAs($publisherUser);
        $this->postJson('/api/v1/suchak/collaborations/'.$first->id.'/accept')->assertOk();
        $this->assertSame(SuchakMarketplaceChallenge::STATUS_FULFILLED, $challenge->fresh()->status);

        // The 200 that cost ₹30,000.
        $this->postJson('/api/v1/suchak/collaborations/'.$second->id.'/accept')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'आधीच स्वीकारले'));

        $this->assertSame(SuchakCollaborationRequest::STATUS_PENDING, $second->fresh()->status);

        // The money, counted: exactly one accepted engagement, so exactly one declared share.
        $accepted = SuchakCollaborationRequest::query()
            ->where('marketplace_challenge_id', $challenge->id)
            ->where('status', SuchakCollaborationRequest::STATUS_ACCEPTED)
            ->get();
        $this->assertCount(1, $accepted);
        $this->assertSame((int) $first->id, (int) $accepted->first()->id);

        $owed = SuchakCommissionAgreement::query()
            ->whereIn('collaboration_request_id', $accepted->pluck('id'))
            ->where('agreement_status', SuchakCommissionAgreement::STATUS_ACCEPTED)
            ->get();
        $this->assertCount(1, $owed);
        $this->assertSame(30.0, (float) $owed->sum(fn (SuchakCommissionAgreement $a): float => (float) $a->groom_side_share));

        // The rival proposal is left PENDING, not auto-rejected: fulfilAnsweredChallenge()'s own
        // reasoning stands — answering on the publisher's behalf is not this code's job — and the
        // refusal above tells him the answer he can still give.
        $this->postJson('/api/v1/suchak/collaborations/'.$second->id.'/reject')->assertOk();
        $this->assertSame(SuchakCollaborationRequest::STATUS_REJECTED, $second->fresh()->status);
    }

    /**
     * The same attack on a WITHDRAWN challenge, which a status-only guard would miss.
     *
     * fulfilAnsweredChallenge() is silent when the challenge is not open — deliberately, so a
     * proposal the publisher himself invited is not stranded by his own withdrawal. That silence
     * means the row never reaches `fulfilled`, so "refuse when the challenge is fulfilled" would
     * have left every pre-withdrawal proposal acceptable one after another. The guard reads the
     * FACT (a sibling proposal is already accepted) instead.
     */
    public function test_attack_one_holds_on_a_withdrawn_challenge_that_never_reaches_fulfilled(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$firstHelperUser, $firstHelper] = $this->verifiedSuchakActor();
        [$secondHelperUser, $secondHelper] = $this->verifiedSuchakActor();
        $challenge = $this->openChallenge($publisher, $publisherUser, 30);

        $first = $this->challengeService()->proposeCandidate(
            $challenge,
            $firstHelper,
            $firstHelperUser,
            $this->helperCandidate($firstHelper),
        )['request'];
        $second = $this->challengeService()->proposeCandidate(
            $challenge->fresh(),
            $secondHelper,
            $secondHelperUser,
            $this->helperCandidate($secondHelper),
        )['request'];

        $this->challengeService()->withdraw($challenge, $publisher, $publisherUser, 'कुटुंबाने थांबवले.');

        Sanctum::actingAs($publisherUser);
        $this->postJson('/api/v1/suchak/collaborations/'.$first->id.'/accept')->assertOk();

        // Still `withdrawn`, never `fulfilled` — the exact hole a status check would leave open.
        $this->assertSame(SuchakMarketplaceChallenge::STATUS_WITHDRAWN, $challenge->fresh()->status);
        $this->assertNull($challenge->fresh()->fulfilled_at);

        $this->postJson('/api/v1/suchak/collaborations/'.$second->id.'/accept')->assertStatus(422);
        $this->assertSame(1, SuchakCollaborationRequest::query()
            ->where('marketplace_challenge_id', $challenge->id)
            ->where('status', SuchakCollaborationRequest::STATUS_ACCEPTED)
            ->count());
    }

    /**
     * ATTACK 2 — THE PENDING PUBLISHER. D18's badge was on the propose leg only.
     *
     * Proven over HTTP with the publisher's verification set back to `pending`:
     * `GET /marketplace/challenges` returned 422 (correct), while
     * `GET /marketplace/challenges/{id}/proposals` returned 200 with the full masked payload of
     * another Suchak's candidate, and `POST /collaborations/{id}/accept` returned 200 and formed
     * the engagement — acceptance falls through to canOperate(), which by design admits
     * VERIFICATION_PENDING.
     */
    public function test_attack_two_a_publisher_whose_badge_lapsed_can_neither_read_proposals_nor_accept(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        $challenge = $this->openChallenge($publisher, $publisherUser, 30);

        $request = $this->challengeService()->proposeCandidate(
            $challenge,
            $helper,
            $helperUser,
            $this->helperCandidate($helper),
        )['request'];

        $publisher->forceFill(['verification_status' => SuchakAccount::VERIFICATION_PENDING])->save();
        $publisher->refresh();

        // The gate that was already right, and the reason the other two were wrong: canOperate()
        // still says yes, because the policy allows work before admin approval.
        $this->assertTrue($this->app->make(SuchakAccessService::class)->canOperate($publisher));

        Sanctum::actingAs($publisherUser);
        $this->getJson('/api/v1/suchak/marketplace/challenges')->assertStatus(422);

        // Was 200 with another Suchak's candidate. Owning the challenge is not the badge.
        $this->getJson('/api/v1/suchak/marketplace/challenges/'.$challenge->id.'/proposals')
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'पडताळणी झालेल्या सूचकांना'));

        // Was 200 and formed the engagement.
        $this->postJson('/api/v1/suchak/collaborations/'.$request->id.'/accept')
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'पडताळणी'));

        $this->assertSame(SuchakCollaborationRequest::STATUS_PENDING, $request->fresh()->status);
        $this->assertSame(SuchakMarketplaceChallenge::STATUS_OPEN, $challenge->fresh()->status);

        // REJECTING is deliberately still allowed. Saying no reveals no candidate and creates no
        // obligation, and a publisher who could not say it would hold the helper's quota hostage.
        $this->postJson('/api/v1/suchak/collaborations/'.$request->id.'/reject')->assertOk();
        $this->assertSame(SuchakCollaborationRequest::STATUS_REJECTED, $request->fresh()->status);
    }

    /**
     * The other side of the same badge: a helper who lost his verification after proposing cannot
     * be accepted into a live engagement either (A10 — the cheap second account, one step later).
     * The direct collaboration path is untouched and still runs on canOperate().
     */
    public function test_attack_two_also_closes_on_the_helper_and_leaves_the_direct_path_alone(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        $challenge = $this->openChallenge($publisher, $publisherUser, 30);

        $request = $this->challengeService()->proposeCandidate(
            $challenge,
            $helper,
            $helperUser,
            $this->helperCandidate($helper),
        )['request'];

        $helper->forceFill(['verification_status' => SuchakAccount::VERIFICATION_PENDING])->save();

        Sanctum::actingAs($publisherUser);
        $this->postJson('/api/v1/suchak/collaborations/'.$request->id.'/accept')->assertStatus(422);
        $this->assertSame(SuchakCollaborationRequest::STATUS_PENDING, $request->fresh()->status);

        // The DIRECT path deliberately allows pending-with-policy, and this slice did not narrow it.
        [$ownerUser, $owner] = $this->verifiedSuchakActor();
        [$targetUser, $target] = $this->verifiedSuchakActor();
        $direct = $this->collaborationService()->createRequest(
            $owner,
            $ownerUser,
            $this->helperCandidate($owner),
            $this->helperCandidate($target),
        )['request'];

        $target->forceFill(['verification_status' => SuchakAccount::VERIFICATION_PENDING])->save();
        $target->refresh();

        $accepted = $this->collaborationService()->acceptRequest($direct, $target, $targetUser);
        $this->assertSame(SuchakCollaborationRequest::STATUS_ACCEPTED, $accepted->status);
    }

    /**
     * ATTACK 3 — THE MIRRORED PAIR. One candidate pair, two open engagements, two collectors.
     *
     * assertNoDuplicateOpenRequest() matched requesting==requesting AND target==target and never
     * looked at the mirror. Before the marketplace the reversed direction was an accident; accept-
     * by-proposing makes it the standard path, so A publishes X and B answers with Y, then B
     * publishes Y and A answers with X — two engagements on the same two candidates, two commission
     * agreements, two ladders, and two different `collector_suchak_account_id`, which is exactly
     * the M1 invariant "each customer pays only their own Suchak".
     */
    public function test_attack_three_one_candidate_pair_cannot_hold_two_engagements_with_two_collectors(): void
    {
        [$aUser, $a] = $this->verifiedSuchakActor();
        [$bUser, $b] = $this->verifiedSuchakActor();
        [$candidateX] = $this->publishableCandidate($a, $aUser);
        [$candidateY] = $this->publishableCandidate($b, $bUser);

        $challengeA = $this->challengeService()->publish($a, $aUser, $candidateX, $this->percentTerms(30));
        $first = $this->challengeService()->proposeCandidate($challengeA, $b, $bUser, $candidateY);

        // Engagement one: B's candidate answers A's challenge, so A is the locked collector.
        $this->assertSame((int) $a->id, (int) $first['agreement']->collector_suchak_account_id);

        // Now the mirror, through the standard marketplace path: B publishes his own candidate and
        // A answers with the very same person who is already engaged to him.
        $challengeB = $this->challengeService()->publish($b, $bUser, $candidateY->fresh(), $this->percentTerms(40));
        $this->assertProposalRefused($challengeB, $a, $aUser, $candidateX->fresh(), 'एकच जोडणी');

        // The direct path sees the same mirror, and its refusal is unchanged English.
        try {
            $this->collaborationService()->createRequest($a, $aUser, $candidateX->fresh(), $candidateY->fresh());
            $this->fail('The direct path formed a second engagement on a pair that already has one.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'An open collaboration request already exists for this Suchak/profile pair.',
                $exception->getMessage(),
            );
        }

        // One pair, one engagement, one commission agreement, ONE collector.
        $pair = SuchakCollaborationRequest::query()
            ->whereIn('requesting_matrimony_profile_id', [$candidateX->matrimony_profile_id, $candidateY->matrimony_profile_id])
            ->whereIn('target_matrimony_profile_id', [$candidateX->matrimony_profile_id, $candidateY->matrimony_profile_id])
            ->get();
        $this->assertCount(1, $pair);
        $this->assertSame(1, SuchakCommissionAgreement::query()
            ->whereIn('collaboration_request_id', $pair->pluck('id'))
            ->count());
        $this->assertSame([(int) $a->id], SuchakCommissionAgreement::query()
            ->whereIn('collaboration_request_id', $pair->pluck('id'))
            ->pluck('collector_suchak_account_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all());

        // A DIFFERENT pair is still a different engagement — the guard is about the pair, never
        // about the two Suchaks, who are expected to work together repeatedly.
        $secondCandidateForA = $this->helperCandidate($a);
        $second = $this->challengeService()->proposeCandidate(
            $challengeB->fresh(),
            $a,
            $aUser,
            $secondCandidateForA,
        );

        $this->assertSame(SuchakCollaborationRequest::STATUS_PENDING, $second['request']->status);
        $this->assertSame((int) $b->id, (int) $second['agreement']->collector_suchak_account_id);
        $this->assertSame(2, SuchakCollaborationRequest::query()->count());
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function challengeService(): SuchakMarketplaceChallengeService
    {
        return $this->app->make(SuchakMarketplaceChallengeService::class);
    }

    private function collaborationService(): SuchakCollaborationService
    {
        return $this->app->make(SuchakCollaborationService::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function percentTerms(float $percent): array
    {
        return [
            'declared_share_type' => SuchakCommissionAgreement::SPLIT_CUSTOM_PERCENT,
            'declared_share_percent' => $percent,
        ];
    }

    private function assertProposalRefused(
        SuchakMarketplaceChallenge $challenge,
        SuchakAccount $account,
        User $actor,
        SuchakProfileRepresentation $representation,
        string $expectedFragment,
    ): void {
        try {
            $this->challengeService()->proposeCandidate($challenge, $account, $actor, $representation);
            $this->fail('The proposal was allowed when it should have been refused ('.$expectedFragment.').');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($expectedFragment, $exception->getMessage());
        }
    }

    private function openChallenge(
        SuchakAccount $publisher,
        User $publisherUser,
        float $percent = 30,
        ?int $genderId = null,
    ): SuchakMarketplaceChallenge {
        [$representation] = $this->publishableCandidate($publisher, $publisherUser, $genderId);

        return $this->challengeService()->publish(
            $publisher,
            $publisherUser,
            $representation,
            $this->percentTerms($percent),
        );
    }

    /**
     * @return array{0: User, 1: SuchakAccount}
     */
    private function verifiedSuchakActor(): array
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            // SuchakAccountFactory does not set this, and canOperate() requires it.
            'registration_completed_at' => now(),
        ]);

        return [$user, $account];
    }

    /**
     * A candidate the publishing Suchak may open to the marketplace: active profile, active
     * representation, live consent, a customer context, and a customer agreement the customer has
     * ACCEPTED whose package carries a fixed ₹1,00,000 success fee (blueprint 7.1's worked example).
     *
     * @return array{0: SuchakProfileRepresentation, 1: SuchakCustomerAgreement}
     */
    private function publishableCandidate(
        SuchakAccount $account,
        User $user,
        ?int $genderId = null,
    ): array {
        $profile = $this->activeProfile('Sunita Gaikwad', $genderId);

        /** @var SuchakProfileRepresentation $representation */
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);

        /** @var SuchakCustomerContext $context */
        $context = SuchakCustomerContext::query()->create([
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $profile->id,
            'representation_id' => $representation->id,
            'created_by_user_id' => $user->id,
            'opened_at' => now(),
        ]);

        /** @var SuchakServicePackage $package */
        $package = SuchakServicePackage::query()->create([
            'suchak_account_id' => $account->id,
            'package_name' => 'Proposal fixture '.$representation->id,
            'price_amount' => '25000',
            'currency' => 'INR',
            'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
            'requires_admin_approval' => false,
            'customized_by_user_id' => $user->id,
            'published_at' => now(),
            'post_marriage_fee_mode' => SuchakCustomerPlan::MODE_FIXED,
            'post_marriage_fee_amount' => '100000',
        ]);

        /** @var SuchakCustomerAgreement $agreement */
        $agreement = SuchakCustomerAgreement::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $context->id,
            'service_package_id' => $package->id,
            'agreement_revision' => 1,
            'terms_status' => SuchakCustomerAgreement::TERMS_ACCEPTED,
            'terms_policy_mode' => SuchakCustomerAgreement::POLICY_STRICT,
            'agreement_snapshot_hash' => hash('sha256', 'proposal-'.$package->id),
            'package_name' => $package->package_name,
            'price_amount' => '25000',
            'currency' => 'INR',
            'agreement_title' => 'Accepted terms revision 1',
            'created_by_user_id' => $user->id,
            'accepted_by_user_id' => $user->id,
            'accepted_at' => now(),
        ]);

        return [$representation->fresh(), $agreement->fresh(['customerContext'])];
    }

    /**
     * One of the helper's own candidates — everything createRequest() needs on the REQUESTING side
     * and nothing more: no customer context and no agreement. That is the honest shape of a helper
     * answering a challenge, and it is what proves the frozen revision is the PUBLISHER's.
     */
    private function helperCandidate(SuchakAccount $account, ?int $genderId = null): SuchakProfileRepresentation
    {
        $profile = $this->activeProfile('Rahul Kadam', $genderId);

        /** @var SuchakProfileRepresentation $representation */
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);

        return $representation->fresh(['suchakAccount', 'matrimonyProfile.gender']);
    }

    /**
     * createRequest() requires a LIVE profile on both sides, and the residence SSOT observer
     * refuses to let a profile leave draft without a canonical leaf. Same two-step shape
     * SuchakCollaborationFoundationTest uses, for the same reason.
     */
    private function activeProfile(string $fullName, ?int $genderId): MatrimonyProfile
    {
        $state = $this->address('Maharashtra', 'state', 1, null);
        $district = $this->address('Pune', 'district', 2, $state);
        $taluka = $this->address('Shirur', 'taluka', 3, $district);
        $village = $this->address('Ranjangaon', 'village', 4, $taluka, 'rural');

        $profile = MatrimonyProfile::factory()->create(array_filter([
            'full_name' => $fullName,
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'gender_id' => $genderId,
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ], static fn ($value): bool => $value !== null));

        if (Schema::hasColumn($profile->getTable(), 'location_id')) {
            DB::table($profile->getTable())->where('id', $profile->id)->update(['location_id' => $village]);
            $profile->refresh();
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $profile->id, $village, null, true, false);
        }

        $profile->update([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        return $profile->fresh();
    }

    private function address(string $name, string $hierarchy, int $level, ?int $parent, ?string $tag = null): int
    {
        return DB::table('addresses')->insertGetId(array_filter([
            'name' => $name,
            'slug' => strtolower($name).'-'.$hierarchy.'-'.uniqid('', true),
            'hierarchy' => $hierarchy,
            'level' => $level,
            'parent_id' => $parent,
            'tag' => $tag,
            'created_at' => now(),
            'updated_at' => now(),
        ], static fn ($v): bool => $v !== null));
    }
}
