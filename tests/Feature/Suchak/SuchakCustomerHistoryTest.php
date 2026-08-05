<?php

namespace Tests\Feature\Suchak;

use App\Http\Controllers\Api\Suchak\SuchakCustomerHistoryApiController;
use App\Http\Controllers\Api\Suchak\SuchakReputationApiController;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakCommissionAgreement;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPortalLink;
use App\Models\SuchakPipeline;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileRequest;
use App\Models\SuchakServicePackage;
use App\Models\SuchakVisitConfirmation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCollaborationService;
use App\Modules\Suchak\Services\SuchakMarriageOutcomeService;
use App\Modules\Suchak\Services\SuchakVisitConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * READ 2 of blueprint §11 phase 5 — D20, the customer's own trail.
 *
 * *"8 meetings, 6 attended, 2 cancelled by the family, 1 marriage. Facts only, derived from
 * records, never typed by a Suchak. It stops at marriage."*
 *
 * Two of those four figures cannot be produced by this platform, and the tests below pin that the
 * read SAYS SO rather than printing a zero:
 *
 *  - the family CANNOT cancel a meeting (`cancelVisit()` admits the arranging Suchak or an admin
 *    and refuses the member), so `cancellations.by_family` is `null`, never `0`;
 *  - attendance is not recorded anywhere (§5.1 B4), so no key claims it and the nearest true fact
 *    is named for what it is — a meeting whose date has passed while the row is still `scheduled`.
 */
class SuchakCustomerHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureRoutes();
    }

    // ── scope: one family, one Suchak ────────────────────────────────────────────────────────

    public function test_another_suchak_cannot_read_a_familys_history(): void
    {
        $world = $this->world();

        // The HELPER on this family's own engagement — the closest any other Suchak ever gets, and
        // still not close enough. §9 admits customer history to a helping Suchak before accepting,
        // but that is a DIFFERENT, identity-free surface and is not this owner-scoped door.
        Sanctum::actingAs($world['helperUser']);

        // 404, not 403: "forbidden" would confirm the family exists, and a Suchak has no business
        // learning that another Suchak's customer is on this platform.
        $this->getJson('/api/v1/suchak/customer-contexts/'.$world['context']->id.'/history')
            ->assertStatus(404)
            ->assertJsonPath('success', false);

        // A Suchak with no relationship to this family at all gets the identical answer, so the
        // response cannot be used to tell "I helped on this one" from "I have never heard of it".
        [$strangerUser] = $this->verifiedSuchakActor();
        Sanctum::actingAs($strangerUser);
        $this->getJson('/api/v1/suchak/customer-contexts/'.$world['context']->id.'/history')
            ->assertStatus(404);

        // The owner reads the same id and is answered.
        Sanctum::actingAs($world['ownerUser']);
        $this->getJson('/api/v1/suchak/customer-contexts/'.$world['context']->id.'/history')
            ->assertOk()
            ->assertJsonPath('data.customer_context_id', (int) $world['context']->id);
    }

    public function test_the_history_read_needs_a_suchak_account(): void
    {
        $world = $this->world();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/suchak/customer-contexts/'.$world['context']->id.'/history')
            ->assertStatus(403);
    }

    // ── D20's own four figures ───────────────────────────────────────────────────────────────

    public function test_the_trail_counts_what_was_arranged_and_what_the_family_did_about_it(): void
    {
        $world = $this->world();

        // Two meetings the family confirmed, one they refused, one still waiting on them, one
        // scheduled for a date that has already passed with nobody marking it either way.
        $this->meeting($world, 1, $this->confirmedMeeting());
        $this->meeting($world, 2, $this->confirmedMeeting());
        $this->meeting($world, 3, [
            'suchak_completion_status' => SuchakVisitConfirmation::COMPLETION_SUCHAK_MARKED,
            'suchak_completed_at' => now()->subDays(9),
            'visit_status' => SuchakVisitConfirmation::STATUS_COMPLETED,
            'user_confirmation_status' => SuchakVisitConfirmation::CONFIRMATION_DISPUTED,
            'user_confirmed_at' => now()->subDays(8),
        ]);
        $this->meeting($world, 4, [
            'suchak_completion_status' => SuchakVisitConfirmation::COMPLETION_SUCHAK_MARKED,
            'suchak_completed_at' => now()->subDays(2),
            'visit_status' => SuchakVisitConfirmation::STATUS_COMPLETED,
        ]);
        $this->meeting($world, 5, ['scheduled_for' => now()->subDays(3)]);

        Sanctum::actingAs($world['ownerUser']);
        $meetings = $this->getJson('/api/v1/suchak/customer-contexts/'.$world['context']->id.'/history')
            ->assertOk()->json('data.meetings');

        $this->assertSame(5, $meetings['arranged']);
        $this->assertSame(4, $meetings['held'], 'Four claims were made; the fifth was never marked.');
        $this->assertSame(2, $meetings['confirmed_by_family']);
        $this->assertSame(1, $meetings['refused_by_family']);
        $this->assertSame(1, $meetings['awaiting_family']);
        $this->assertSame(1, $meetings['scheduled_open']);
        // Named for what it IS. See the class docblock: it is not attendance and must never be
        // rendered as "did not attend".
        $this->assertSame(1, $meetings['scheduled_past_date']);
        // One pipeline, five meetings — D24's re-visits, which `meeting_sequence > 1` is what marks.
        $this->assertSame(4, $meetings['repeat_meetings']);
    }

    public function test_a_cancellation_names_who_called_it_off_and_never_invents_the_family(): void
    {
        $world = $this->world();
        $visit = $this->meeting($world, 1);

        // The REAL writer, so the actor lands on the append-only trail exactly as production writes
        // it — §5.1 B4's "cheapest home: new event_type values on the existing events table".
        $this->app->make(SuchakVisitConfirmationService::class)->cancelVisit(
            $visit,
            $world['ownerUser'],
            [
                'cancellation_reason' => 'कुटुंबाने तारीख पुढे ढकलली.',
                'attendance' => SuchakVisitConfirmation::ATTENDANCE_NONE,
            ],
        );

        Sanctum::actingAs($world['ownerUser']);
        $data = $this->getJson('/api/v1/suchak/customer-contexts/'.$world['context']->id.'/history')
            ->assertOk()->json('data');

        $this->assertSame(1, $data['meetings']['cancelled']);
        $this->assertSame(1, $data['cancellations']['total']);
        $this->assertSame(1, $data['cancellations']['by_suchak']);
        $this->assertSame(0, $data['cancellations']['by_admin']);

        // THE FIGURE D20 ASKS FOR AND THIS PLATFORM CANNOT PRODUCE. `cancelVisit()` refuses the
        // member outright, so a `0` here would be a measured finding about a family this platform is
        // incapable of measuring. Null, plus a coverage flag that says why.
        $this->assertNull($data['cancellations']['by_family']);
        $this->assertFalse($data['coverage']['family_cancellation_recorded']);
        $this->assertFalse($data['coverage']['attendance_recorded']);
    }

    public function test_the_family_cannot_cancel_which_is_why_that_figure_is_null(): void
    {
        $world = $this->world();
        $visit = $this->meeting($world, 1);

        // The proof behind the null: the candidate's own user is refused by the writer. M5 gives
        // the family `dispute`; cancelling is a scheduling fact the arranging side owns.
        /** @var User $candidateUser */
        $candidateUser = User::query()->findOrFail($world['candidate']->user_id);

        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(SuchakVisitConfirmationService::class)->cancelVisit(
            $visit,
            $candidateUser,
            [
                'cancellation_reason' => 'आम्हाला जमणार नाही.',
                'attendance' => SuchakVisitConfirmation::ATTENDANCE_NONE,
            ],
        );
    }

    public function test_what_was_put_in_front_of_the_family_and_what_they_did_with_it(): void
    {
        $world = $this->world();
        $engagement = $world['engagements'][0];
        $collaborationService = $this->app->make(SuchakCollaborationService::class);

        // The HELPER names his candidate…
        $collaborationService->claimStage(
            $engagement,
            $world['helperAccount'],
            $world['helperUser'],
            SuchakCollaborationStageEvent::STAGE_PROFILE_SUGGESTED,
        );

        // …and the FAMILY opens it, over their own portal link, declaring that they already know
        // this family (A6's one-tap release of the 12-month clause). No Suchak can write either
        // rung — `assertClaimChannel()` refuses them — which is what makes this a record of the
        // family's behaviour rather than a Suchak's account of it (D20).
        $collaborationService->recordCustomerStage(
            $engagement,
            $world['portalLink'],
            SuchakCollaborationStageEvent::STAGE_VIEWED,
            null,
            '203.0.113.9',
            'phpunit',
            true,
        );
        $collaborationService->recordCustomerStage(
            $engagement,
            $world['portalLink'],
            SuchakCollaborationStageEvent::STAGE_INTERESTED,
            null,
            '203.0.113.9',
            'phpunit',
        );

        Sanctum::actingAs($world['ownerUser']);
        $response = $this->getJson('/api/v1/suchak/customer-contexts/'.$world['context']->id.'/history')
            ->assertOk();
        $data = $response->json('data');

        $this->assertSame(1, $data['profiles']['suggested']);
        $this->assertSame(1, $data['profiles']['viewed']);
        $this->assertSame(1, $data['profiles']['interested']);
        $this->assertSame(1, $data['profiles']['prior_acquaintance_declared']);

        // A12's screening figure in count form — how many Suchaks worked this case. No Suchak is
        // NAMED: a helper's identity is not this family's fact to carry.
        $this->assertSame(1, $data['engagements']['total']);
        $this->assertStringNotContainsString('suchak_name', $response->getContent());
        $this->assertStringNotContainsString('collaboration_request_id', $response->getContent());
    }

    public function test_the_trail_stops_at_a_confirmed_marriage_and_not_at_a_claim(): void
    {
        $world = $this->world();
        $engagement = $this->acceptedEngagementFor($world);

        $this->app->make(SuchakMarriageOutcomeService::class)->record(
            $engagement,
            $world['ownerAccount'],
            $world['ownerUser'],
            now()->subDays(5)->toDateString(),
            'लग्न पार पडले.',
        );

        Sanctum::actingAs($world['ownerUser']);
        $claimed = $this->getJson('/api/v1/suchak/customer-contexts/'.$world['context']->id.'/history')
            ->assertOk()->json('data');

        $this->assertTrue($claimed['marriage']['is_recorded']);
        $this->assertFalse($claimed['marriage']['is_confirmed']);
        // D20 stops at MARRIAGE, and one Suchak's claim is not a marriage. Closing a family's
        // record on an unconfirmed claim would let a claim nobody agreed to end their history.
        $this->assertFalse($claimed['is_closed_by_marriage']);
        $this->assertTrue($claimed['stops_at_marriage']);

        // The family confirms, and the trail closes.
        $this->app->make(SuchakCollaborationService::class)->confirmStage(
            $engagement,
            User::query()->findOrFail($world['candidate']->user_id),
            SuchakCollaborationStageEvent::STAGE_MARRIAGE,
        );

        $confirmed = $this->getJson('/api/v1/suchak/customer-contexts/'.$world['context']->id.'/history')
            ->assertOk()->json('data');

        $this->assertTrue($confirmed['marriage']['is_confirmed']);
        $this->assertTrue($confirmed['is_closed_by_marriage']);
        $this->assertSame(now()->subDays(5)->toDateString(), $confirmed['marriage']['married_on']);
    }

    // ── the empty case, and what the payload refuses to carry ────────────────────────────────

    public function test_a_family_with_no_trail_is_new_rather_than_a_wall_of_verdicts(): void
    {
        $world = $this->world();

        // A family registered yesterday: a context, and nothing else yet. `world()`'s own context
        // already carries an engagement, so it is deliberately NOT the one used here — a fixture
        // that quietly has history cannot prove the empty case.
        /** @var SuchakCustomerContext $fresh */
        $fresh = SuchakCustomerContext::query()->create([
            'suchak_account_id' => $world['ownerAccount']->id,
            'candidate_matrimony_profile_id' => MatrimonyProfile::factory()->create()->id,
            'service_context' => SuchakCustomerContext::SERVICE_PROFILE_REPRESENTATION,
            'source_owner' => SuchakCustomerContext::SOURCE_OWNER_SUCHAK,
            'source_type' => SuchakCustomerContext::SOURCE_TYPE_MANUAL,
            'customer_lifecycle_status' => SuchakCustomerContext::STATUS_ACTIVE_SERVICE,
            'created_by_user_id' => $world['ownerUser']->id,
            'opened_at' => now(),
        ]);

        Sanctum::actingAs($world['ownerUser']);
        $data = $this->getJson('/api/v1/suchak/customer-contexts/'.$fresh->id.'/history')
            ->assertOk()->json('data');

        // 200 with `is_new`, never 404: "nothing has happened yet" is a real and useful answer to
        // the Suchak who is about to make something happen.
        $this->assertTrue($data['is_new']);
        $this->assertSame(0, $data['recorded_event_count']);
        $this->assertSame(0, $data['meetings']['arranged']);
        $this->assertFalse($data['marriage']['is_recorded']);
        $this->assertNull($data['marriage']['married_on']);
        // Still null, not zero, even with nothing recorded at all.
        $this->assertNull($data['cancellations']['by_family']);
    }

    public function test_the_history_carries_no_money_at_all(): void
    {
        $world = $this->world();
        $this->meeting($world, 1, $this->confirmedMeeting());

        Sanctum::actingAs($world['ownerUser']);
        $body = $this->getJson('/api/v1/suchak/customer-contexts/'.$world['context']->id.'/history')
            ->assertOk()->getContent();

        // D17, and §15's record of the product owner rejecting the same instinct twice: the
        // cumulative figure belongs on the payments screen, "where a person has gone to look at
        // money, not on the screen where they are deciding about a person". No screen built from
        // this payload can become the regret ledger, because the payload has no rupee in it.
        foreach (['₹', 'fee', 'amount', 'price', 'paid', 'currency'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, $forbidden.' is money; D17 puts it elsewhere.');
        }
    }

    public function test_the_history_surface_is_pinned(): void
    {
        $world = $this->world();

        Sanctum::actingAs($world['ownerUser']);
        $data = $this->getJson('/api/v1/suchak/customer-contexts/'.$world['context']->id.'/history')
            ->assertOk()->json('data');

        $this->assertSame([
            'customer_context_id',
            'suchak_account_id',
            'is_new',
            'recorded_event_count',
            'stops_at_marriage',
            'is_closed_by_marriage',
            'marriage',
            'meetings',
            'cancellations',
            'profiles',
            'engagements',
            'coverage',
        ], array_keys($data));

        $this->assertSame(['total', 'by_suchak', 'by_admin', 'by_family'], array_keys($data['cancellations']));
        $this->assertSame(
            ['family_cancellation_recorded', 'attendance_recorded'],
            array_keys($data['coverage']),
        );
        // Nothing here claims attendance. If a key ever does, it needs a column behind it first.
        foreach (['attended', 'no_show', 'attendance', 'actual_held_at'] as $absent) {
            $this->assertArrayNotHasKey($absent, $data['meetings'], $absent.' is not recorded anywhere.');
        }
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function confirmedMeeting(): array
    {
        return [
            'suchak_completion_status' => SuchakVisitConfirmation::COMPLETION_SUCHAK_MARKED,
            'suchak_completed_at' => now()->subDays(9),
            'user_confirmation_status' => SuchakVisitConfirmation::CONFIRMATION_CONFIRMED,
            'user_confirmed_at' => now()->subDays(8),
            'visit_status' => SuchakVisitConfirmation::STATUS_CONFIRMED,
        ];
    }

    /**
     * ONE family: a customer context, an accepted agreement revision, one pending marketplace
     * engagement linked to it, a portal link the family acts through, and a pipeline for meetings
     * to hang off.
     *
     * The helper answered the challenge, so he is the REQUESTING side and the customer-owning
     * Suchak is the target (§5.2's responder-is-requester note).
     *
     * @return array<string, mixed>
     */
    private function world(): array
    {
        [$ownerUser, $ownerAccount] = $this->verifiedSuchakActor();
        [$helperUser, $helperAccount] = $this->verifiedSuchakActor();

        /** @var MatrimonyProfile $candidate */
        $candidate = MatrimonyProfile::factory()->create([
            'full_name' => 'History fixture candidate',
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        /** @var MatrimonyProfile $other */
        $other = MatrimonyProfile::factory()->create(['lifecycle_state' => 'draft', 'is_suspended' => false]);

        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $ownerAccount->id,
            'matrimony_profile_id' => $candidate->id,
        ]);

        /** @var SuchakCustomerContext $context */
        $context = SuchakCustomerContext::query()->create([
            'suchak_account_id' => $ownerAccount->id,
            'candidate_matrimony_profile_id' => $candidate->id,
            'representation_id' => $representation->id,
            'service_context' => SuchakCustomerContext::SERVICE_PROFILE_REPRESENTATION,
            'source_owner' => SuchakCustomerContext::SOURCE_OWNER_SUCHAK,
            'source_type' => SuchakCustomerContext::SOURCE_TYPE_MANUAL,
            'customer_lifecycle_status' => SuchakCustomerContext::STATUS_ACTIVE_SERVICE,
            'created_by_user_id' => $ownerUser->id,
            'opened_at' => now(),
        ]);

        $agreement = $this->customerAgreement($ownerAccount, $ownerUser, $context);

        /** @var SuchakCollaborationRequest $engagement */
        $engagement = SuchakCollaborationRequest::factory()->create([
            'requesting_suchak_account_id' => $helperAccount->id,
            'target_suchak_account_id' => $ownerAccount->id,
            'target_matrimony_profile_id' => $candidate->id,
            'target_representation_id' => $representation->id,
            'status' => SuchakCollaborationRequest::STATUS_PENDING,
            'requested_at' => now()->subMonths(6),
            'responded_at' => null,
        ]);
        $this->app->make(SuchakCollaborationService::class)
            ->linkCustomerAgreement($engagement, $ownerAccount, $ownerUser, $agreement);

        /** @var SuchakProfileRequest $request */
        $request = SuchakProfileRequest::factory()->create([
            'requesting_matrimony_profile_id' => $other->id,
            'target_matrimony_profile_id' => $candidate->id,
            'selected_suchak_account_id' => $ownerAccount->id,
            'representation_id' => $representation->id,
        ]);

        /** @var SuchakPipeline $pipeline */
        $pipeline = SuchakPipeline::factory()->create([
            'request_id' => $request->id,
            'target_matrimony_profile_id' => $candidate->id,
            'requesting_matrimony_profile_id' => $other->id,
            'selected_suchak_account_id' => $ownerAccount->id,
            'representation_id' => $representation->id,
        ]);

        $plainToken = Str::random(64);
        /** @var SuchakCustomerPortalLink $portalLink */
        $portalLink = SuchakCustomerPortalLink::query()->create([
            'suchak_account_id' => $ownerAccount->id,
            'customer_context_id' => $context->id,
            'issued_by_user_id' => $ownerUser->id,
            'token_hash' => hash('sha256', $plainToken),
            'portal_status' => SuchakCustomerPortalLink::STATUS_ACTIVE,
            'recipient_role' => SuchakCustomerPortalLink::RECIPIENT_FAMILY,
            'recipient_label' => 'Customer family',
            'claimed_name' => 'सुनीता पवार',
            'claimed_relationship_to_candidate' => 'आई',
            'expires_at' => now()->addDays(365),
        ]);

        return [
            'ownerUser' => $ownerUser,
            'ownerAccount' => $ownerAccount,
            'helperUser' => $helperUser,
            'helperAccount' => $helperAccount,
            'candidate' => $candidate,
            'other' => $other,
            'representation' => $representation,
            'context' => $context,
            'agreement' => $agreement,
            'engagements' => [$engagement->fresh(['commissionAgreement'])],
            'portalLink' => $portalLink,
            'request' => $request,
            'pipeline' => $pipeline,
        ];
    }

    /**
     * A SECOND engagement on the same family, accepted with both commission acknowledgements — what
     * `assertAcceptedParticipant()` needs before a marriage may be recorded on it.
     *
     * @param  array<string, mixed>  $world
     */
    private function acceptedEngagementFor(array $world): SuchakCollaborationRequest
    {
        /** @var SuchakCollaborationRequest $engagement */
        $engagement = SuchakCollaborationRequest::factory()->create([
            'requesting_suchak_account_id' => $world['helperAccount']->id,
            'target_suchak_account_id' => $world['ownerAccount']->id,
            'target_matrimony_profile_id' => $world['candidate']->id,
            'target_representation_id' => $world['representation']->id,
            'status' => SuchakCollaborationRequest::STATUS_ACCEPTED,
            'requested_at' => now()->subMonths(6),
            'responded_at' => now()->subMonths(6),
        ]);

        $linked = $this->app->make(SuchakCollaborationService::class)
            ->linkCustomerAgreement($engagement, $world['ownerAccount'], $world['ownerUser'], $world['agreement']);

        SuchakCommissionAgreement::query()
            ->where('collaboration_request_id', $linked->id)
            ->update([
                'agreement_status' => SuchakCommissionAgreement::STATUS_ACCEPTED,
                'accepted_by_groom_suchak_at' => now(),
                'accepted_by_bride_suchak_at' => now(),
            ]);

        return $linked->fresh(['commissionAgreement']);
    }

    /**
     * @param  array<string, mixed>  $world
     * @param  array<string, mixed>  $overrides
     */
    private function meeting(array $world, int $sequence, array $overrides = []): SuchakVisitConfirmation
    {
        /** @var SuchakVisitConfirmation $visit */
        $visit = SuchakVisitConfirmation::query()->create(array_merge([
            'pipeline_id' => $world['pipeline']->id,
            'suchak_account_id' => $world['ownerAccount']->id,
            'request_id' => $world['request']->id,
            'representation_id' => $world['representation']->id,
            'target_matrimony_profile_id' => $world['candidate']->id,
            'requesting_matrimony_profile_id' => $world['other']->id,
            'customer_context_id' => $world['context']->id,
            'customer_agreement_id' => $world['agreement']->id,
            'visit_status' => SuchakVisitConfirmation::STATUS_SCHEDULED,
            'confirmation_policy_mode' => SuchakVisitConfirmation::POLICY_USER_ONLY,
            'meeting_sequence' => $sequence,
            'meeting_mode' => SuchakVisitConfirmation::MODE_OFFLINE,
            'fee_amount' => '3000.00',
            'fee_currency' => 'INR',
            'scheduled_by_user_id' => $world['ownerUser']->id,
            'scheduled_at' => now()->subDays(12),
            'scheduled_for' => now()->subDays(11),
        ], $overrides));

        return $visit;
    }

    private function customerAgreement(
        SuchakAccount $account,
        User $user,
        SuchakCustomerContext $context,
    ): SuchakCustomerAgreement {
        /** @var SuchakServicePackage $package */
        $package = SuchakServicePackage::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $context->id,
            'package_name' => 'Customer history fixture',
            'price_amount' => '25000',
            'currency' => 'INR',
            'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
            'requires_admin_approval' => false,
            'customized_by_user_id' => $user->id,
            'published_at' => now(),
        ]);

        /** @var SuchakCustomerAgreement $agreement */
        $agreement = SuchakCustomerAgreement::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $context->id,
            'service_package_id' => $package->id,
            'agreement_revision' => 1,
            'terms_status' => SuchakCustomerAgreement::TERMS_ACCEPTED,
            'terms_policy_mode' => SuchakCustomerAgreement::POLICY_STRICT,
            'agreement_snapshot_hash' => hash('sha256', 'customer-history-'.$package->id),
            'package_name' => $package->package_name,
            'price_amount' => '25000',
            'currency' => 'INR',
            'agreement_title' => 'Accepted terms revision 1',
            'created_by_user_id' => $user->id,
            'accepted_by_user_id' => $user->id,
            'accepted_at' => now(),
        ]);

        return $agreement;
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
            'registration_completed_at' => now(),
        ]);

        return [$user, $account];
    }

    /**
     * See {@see SuchakReputationReadTest::ensureRoutes()} — the same three lines, registered here
     * only while `routes/api/suchak.php` is being edited elsewhere.
     */
    private function ensureRoutes(): void
    {
        $existing = collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($route): string => $route->uri())
            ->all();

        if (in_array('api/v1/suchak/customer-contexts/{customerContext}/history', $existing, true)) {
            return;
        }

        Route::middleware([\Illuminate\Routing\Middleware\SubstituteBindings::class, 'auth:sanctum', 'suchak.account'])
            ->prefix('api/v1/suchak')
            ->group(function (): void {
                Route::get('/reputation', [SuchakReputationApiController::class, 'own']);
                Route::get('/reputation/{suchakAccount}', [SuchakReputationApiController::class, 'show'])
                    ->whereNumber('suchakAccount');
                Route::get('/customer-contexts/{customerContext}/history', [SuchakCustomerHistoryApiController::class, 'show'])
                    ->whereNumber('customerContext');
            });

        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }
}
