<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCommissionAgreement;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakPipeline;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileRequest;
use App\Models\SuchakServicePackage;
use App\Models\SuchakVisitConfirmation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakRequestPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * THE FIRST MEETING HAS A DOOR — and the meeting it opens is worth money.
 *
 * Two defects, both fatal to the feature and both invisible from either side alone:
 *
 *  1. NO `pipeline_id` REACHED THE APP for a pipeline with no meeting. `POST /suchak/meetings`
 *     needs one and nothing handed one out: `visits[]` carries it only for pairs that already met,
 *     and the profile-requests payload publishes the pipeline's SLA fields but not its id. So the
 *     Suchak app could schedule a pair's SECOND meeting and never its first, and its meetings list
 *     could never become non-empty from the meetings screen. Production: 20 pipelines, zero visit
 *     confirmations, ever, by anybody.
 *
 *  2. THE MEETING IT WOULD HAVE CREATED EARNED NOTHING. `scheduleVisit()` priced a meeting from a
 *     `suchak_payment_contexts` row keyed on the pipeline, and NOTHING in production writes one —
 *     not either pipeline creator, and not `SuchakLeadAllocationService`, whose row carries a NULL
 *     `pipeline_id` a pipeline-keyed lookup can never see. So `fee_amount` froze NULL: schedulable,
 *     completable, confirmable by the family, and then no figure on D17's approval screen and no
 *     fee due under M4/M5. A meeting that silently earns nothing is worse than no meeting, so the
 *     door and the fee had to land together.
 */
class SuchakFirstMeetingDoorTest extends TestCase
{
    use RefreshDatabase;

    // ── The door: a pipeline with no meeting is now reachable ─────────────────────────────────

    public function test_the_meetings_read_hands_out_a_pipeline_id_for_a_pair_with_no_meeting(): void
    {
        $fixture = $this->bootPair();
        Sanctum::actingAs($fixture['suchakUser']);

        $response = $this->getJson('/api/v1/suchak/meetings')->assertOk();

        // The list this screen has always read is still empty — that is the whole problem.
        $response->assertJsonPath('data.visits', []);

        $awaiting = $response->json('data.awaiting_first_meeting');
        $this->assertCount(1, $awaiting);
        $this->assertSame((int) $fixture['pipeline']->id, $awaiting[0]['pipeline_id']);
        // The id the "which agreed plan prices this meeting" read is keyed on.
        $this->assertSame((int) $fixture['representation']->id, $awaiting[0]['representation_id']);
        $this->assertSame('request', $awaiting[0]['source']);
        $this->assertSame('First Door Candidate', $awaiting[0]['customer_name']);
        $this->assertSame('First Door Member', $awaiting[0]['member_name']);
        $this->assertNull($awaiting[0]['helper_suchak_name']);
    }

    public function test_an_engagement_born_pair_reaches_the_same_door_and_names_no_foreign_candidate(): void
    {
        $fixture = $this->bootPair();
        $engagement = $this->bootEngagementOn($fixture);
        Sanctum::actingAs($fixture['suchakUser']);

        $awaiting = $this->getJson('/api/v1/suchak/meetings')
            ->assertOk()
            ->json('data.awaiting_first_meeting');

        $this->assertCount(2, $awaiting);
        $row = collect($awaiting)->firstWhere('source', 'engagement');
        $this->assertNotNull($row);
        $this->assertSame((int) $engagement['pipeline']->id, $row['pipeline_id']);
        $this->assertSame('First Door Candidate', $row['customer_name']);
        // D19a — the other side of an engagement is ANOTHER SUCHAK'S CANDIDATE. The counterparty
        // is named by the Suchak the collaborations payload already names to both parties, and the
        // candidate is not named at all.
        $this->assertNull($row['member_name']);
        $this->assertSame('Helper Suchak Office', $row['helper_suchak_name']);
        $this->assertStringNotContainsString(
            'First Door Helper Candidate',
            json_encode($awaiting, JSON_UNESCAPED_UNICODE) ?: '',
        );
    }

    public function test_a_pair_leaves_the_awaiting_list_the_moment_it_holds_a_meeting(): void
    {
        $fixture = $this->bootPair();
        Sanctum::actingAs($fixture['suchakUser']);

        $this->postJson('/api/v1/suchak/meetings', [
            'pipeline_id' => $fixture['pipeline']->id,
            'meeting_mode' => SuchakVisitConfirmation::MODE_OFFLINE,
        ])->assertCreated();

        $data = $this->getJson('/api/v1/suchak/meetings')->assertOk()->json('data');

        // One pair, one door: the meeting card owns the "next meeting" action from here on, so the
        // awaiting list must not go on offering the same pair a first meeting beside it.
        $this->assertSame([], $data['awaiting_first_meeting']);
        $this->assertCount(1, $data['visits']);
        $this->assertSame((int) $fixture['pipeline']->id, $data['visits'][0]['pipeline_id']);
    }

    // ── The money: a first meeting carries the agreed fee ─────────────────────────────────────

    public function test_a_first_meeting_on_a_member_born_pipeline_freezes_the_agreed_offline_fee(): void
    {
        $fixture = $this->bootPair();
        Sanctum::actingAs($fixture['suchakUser']);

        $data = $this->postJson('/api/v1/suchak/meetings', [
            'pipeline_id' => $fixture['pipeline']->id,
            'meeting_mode' => SuchakVisitConfirmation::MODE_OFFLINE,
            'schedule_note' => 'Sunday morning at the candidate home.',
        ])->assertCreated()->json('data');

        $this->assertSame(1, $data['meeting_sequence']);
        $this->assertSame('3000.00', $data['fee_amount']);
        $this->assertSame('INR', $data['fee_currency']);

        /** @var SuchakVisitConfirmation $visit */
        $visit = SuchakVisitConfirmation::query()->sole();
        // Resolved off the pipeline's representation — no payment context exists, and none ever
        // would have: nothing in either pipeline flow creates one.
        $this->assertNull($visit->payment_context_id);
        $this->assertSame((int) $fixture['customerContext']->id, (int) $visit->customer_context_id);
        $this->assertSame((int) $fixture['agreement']->id, (int) $visit->customer_agreement_id);
    }

    public function test_the_online_rate_is_its_own_agreed_figure_and_not_the_offline_one(): void
    {
        $fixture = $this->bootPair();
        Sanctum::actingAs($fixture['suchakUser']);

        $data = $this->postJson('/api/v1/suchak/meetings', [
            'pipeline_id' => $fixture['pipeline']->id,
            'meeting_mode' => SuchakVisitConfirmation::MODE_ONLINE,
        ])->assertCreated()->json('data');

        $this->assertSame(SuchakVisitConfirmation::MODE_ONLINE, $data['meeting_mode']);
        $this->assertSame('5000.00', $data['fee_amount']);
    }

    public function test_a_first_meeting_on_an_engagement_born_pipeline_freezes_the_same_agreed_fee(): void
    {
        $fixture = $this->bootPair();
        $engagement = $this->bootEngagementOn($fixture);
        Sanctum::actingAs($fixture['suchakUser']);

        // EXACTLY THE BODY THE APP SENDS — pipeline and mode, and nothing else. It has no helper
        // account id to send, and must not need one.
        $data = $this->postJson('/api/v1/suchak/meetings', [
            'pipeline_id' => $engagement['pipeline']->id,
            'meeting_mode' => SuchakVisitConfirmation::MODE_OFFLINE,
        ])->assertCreated()->json('data');

        // THE TRAP THAT WAS FOUND AND IS NOW CLOSED: this used to be NULL, and everything
        // downstream of it — D17's figure, M4/M5's fee — was NULL with it.
        $this->assertSame('3000.00', $data['fee_amount']);

        /** @var SuchakVisitConfirmation $visit */
        $visit = SuchakVisitConfirmation::query()->sole();
        $this->assertSame((int) $fixture['customerContext']->id, (int) $visit->customer_context_id);
        // Derived from the engagement, not sent. Without it the HELPER matches no column on this
        // row and §7.2's stop-loss — which exists for unanswered helper claims — would attach to
        // no marketplace meeting the app can create.
        $this->assertSame((int) $engagement['helperAccount']->id, (int) $visit->helper_suchak_account_id);
    }

    public function test_a_member_born_meeting_still_names_no_helper(): void
    {
        $fixture = $this->bootPair();
        Sanctum::actingAs($fixture['suchakUser']);

        $this->postJson('/api/v1/suchak/meetings', [
            'pipeline_id' => $fixture['pipeline']->id,
        ])->assertCreated();

        // Nothing to derive from, and nothing invented: the member who approached is not a Suchak.
        $this->assertNull(SuchakVisitConfirmation::query()->sole()->helper_suchak_account_id);
    }

    public function test_a_customer_who_agreed_no_meeting_rate_owes_nothing_rather_than_zero(): void
    {
        $fixture = $this->bootPair();
        // The plan quotes a registration price and no meeting rate at all — an ordinary,
        // deliberate shape. "Not agreed" is a real answer and must never be printed as ₹0.
        $fixture['package']->forceFill([
            'per_meeting_fee_amount' => null,
            'per_meeting_online_fee_amount' => null,
        ])->save();
        Sanctum::actingAs($fixture['suchakUser']);

        $data = $this->postJson('/api/v1/suchak/meetings', [
            'pipeline_id' => $fixture['pipeline']->id,
            'meeting_mode' => SuchakVisitConfirmation::MODE_OFFLINE,
        ])->assertCreated()->json('data');

        $this->assertNull($data['fee_amount']);
        $this->assertNull($data['fee_currency']);
        $this->assertNull($data['fee_display']);
        // The agreement is still recorded: "priced under plan X, which charges nothing for a
        // meeting" is a fact, and a bare null would lose it.
        $this->assertSame(
            (int) $fixture['agreement']->id,
            (int) SuchakVisitConfirmation::query()->sole()->customer_agreement_id,
        );
    }

    public function test_a_customer_relationship_that_has_ended_prices_nothing(): void
    {
        $fixture = $this->bootPair();
        $fixture['customerContext']->forceFill([
            'customer_lifecycle_status' => SuchakCustomerContext::STATUS_CLOSED,
        ])->save();
        Sanctum::actingAs($fixture['suchakUser']);

        $data = $this->postJson('/api/v1/suchak/meetings', [
            'pipeline_id' => $fixture['pipeline']->id,
        ])->assertCreated()->json('data');

        // Same refusal SuchakPaymentCollectorResolver already makes before collecting money from a
        // closed customer — one definition of it, on the model.
        $this->assertNull($data['fee_amount']);
        $this->assertNull(SuchakVisitConfirmation::query()->sole()->customer_context_id);
    }

    // ── Authorisation ─────────────────────────────────────────────────────────────────────────

    public function test_another_suchak_cannot_schedule_the_first_meeting_on_this_pair(): void
    {
        $fixture = $this->bootPair();
        [$otherUser] = $this->verifiedSuchak('Outsider Suchak Office');
        Sanctum::actingAs($otherUser);

        $this->postJson('/api/v1/suchak/meetings', [
            'pipeline_id' => $fixture['pipeline']->id,
        ])->assertStatus(403);

        $this->assertSame(0, SuchakVisitConfirmation::query()->count());
    }

    public function test_another_suchak_is_never_offered_this_pair_in_the_first_place(): void
    {
        $this->bootPair();
        [$otherUser] = $this->verifiedSuchak('Outsider Suchak Office');
        Sanctum::actingAs($otherUser);

        $this->getJson('/api/v1/suchak/meetings')
            ->assertOk()
            ->assertJsonPath('data.awaiting_first_meeting', []);
    }

    public function test_the_helping_suchak_on_an_engagement_is_not_the_one_who_schedules(): void
    {
        $fixture = $this->bootPair();
        $engagement = $this->bootEngagementOn($fixture);
        Sanctum::actingAs($engagement['helperUser']);

        // The arranging Suchak is `selected_suchak_account_id`, which on an engagement-born
        // pipeline is the CUSTOMER-OWNING side. The helper is a participant, never the arranger.
        $this->getJson('/api/v1/suchak/meetings')
            ->assertOk()
            ->assertJsonPath('data.awaiting_first_meeting', []);

        $this->postJson('/api/v1/suchak/meetings', [
            'pipeline_id' => $engagement['pipeline']->id,
        ])->assertStatus(403);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    /**
     * A member-born pair, with the customer record and the accepted agreement a real Suchak has,
     * and deliberately WITHOUT a payment context — because production has none.
     *
     * @return array<string, mixed>
     */
    private function bootPair(): array
    {
        [$suchakUser, $account] = $this->verifiedSuchak('First Door Suchak Office');

        $candidateProfile = $this->profile('First Door Candidate');
        $memberProfile = $this->profile('First Door Member');

        /** @var SuchakProfileRepresentation $representation */
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $candidateProfile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_verified_consent_at' => now(),
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);

        /** @var SuchakCustomerContext $customerContext */
        $customerContext = SuchakCustomerContext::query()->create([
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $candidateProfile->id,
            'representation_id' => $representation->id,
            'payer_name' => 'Candidate family',
            'service_context' => SuchakCustomerContext::SERVICE_PACKAGE_LEAD,
            'customer_lifecycle_status' => SuchakCustomerContext::STATUS_ACTIVE_SERVICE,
            'created_by_user_id' => $suchakUser->id,
            'opened_at' => now(),
        ]);

        /** @var SuchakServicePackage $package */
        $package = SuchakServicePackage::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'package_name' => 'First door package',
            'price_amount' => '20000.00',
            'currency' => 'INR',
            'per_meeting_fee_amount' => '3000.00',
            // Independently agreed, deliberately not a multiple of the offline rate.
            'per_meeting_online_fee_amount' => '5000.00',
            'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
            'requires_admin_approval' => false,
            'customized_by_user_id' => $suchakUser->id,
            'published_at' => now(),
        ]);

        /** @var SuchakCustomerAgreement $agreement */
        $agreement = SuchakCustomerAgreement::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'service_package_id' => $package->id,
            'agreement_revision' => 1,
            'terms_status' => SuchakCustomerAgreement::TERMS_ACCEPTED,
            'terms_policy_mode' => SuchakCustomerAgreement::POLICY_STRICT,
            'agreement_snapshot_hash' => hash('sha256', 'first-door-'.$package->id),
            'package_name' => $package->package_name,
            'price_amount' => '20000.00',
            'currency' => 'INR',
            'agreement_title' => 'Accepted terms revision 1',
            'created_by_user_id' => $suchakUser->id,
            'accepted_by_user_id' => $suchakUser->id,
            'accepted_at' => now(),
        ]);

        /** @var SuchakProfileRequest $request */
        $request = SuchakProfileRequest::query()->create([
            'requesting_user_id' => $memberProfile->user_id,
            'requesting_matrimony_profile_id' => $memberProfile->id,
            'target_matrimony_profile_id' => $candidateProfile->id,
            'selected_suchak_account_id' => $account->id,
            'representation_id' => $representation->id,
            'request_status' => SuchakProfileRequest::STATUS_PENDING,
            'request_reason' => 'intro_visit',
            'message' => 'Please arrange an introduction.',
        ]);

        /** @var SuchakPipeline $pipeline */
        $pipeline = SuchakPipeline::query()->create([
            'request_id' => $request->id,
            'target_matrimony_profile_id' => $candidateProfile->id,
            'requesting_matrimony_profile_id' => $memberProfile->id,
            'selected_suchak_account_id' => $account->id,
            'representation_id' => $representation->id,
            'pipeline_status' => SuchakPipeline::STATUS_PENDING,
            'attribution_locked_at' => now(),
            'lock_expires_at' => now()->addDays(2),
            'sla_status' => SuchakPipeline::SLA_WITHIN,
        ]);

        return [
            'suchakUser' => $suchakUser,
            'account' => $account,
            'representation' => $representation,
            'customerContext' => $customerContext,
            'package' => $package,
            'agreement' => $agreement,
            'candidateProfile' => $candidateProfile,
            'pipeline' => $pipeline->fresh(),
        ];
    }

    /**
     * A second pipeline on the same customer, born of an accepted engagement — the path that had
     * no meeting door at all until today, and no fee behind it either.
     *
     * The engagement rows are written directly rather than driven through the marketplace: what is
     * under test here is the pipeline's meeting door, and `openPipelineForEngagement()` is still
     * the one writer of the pipeline.
     *
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    private function bootEngagementOn(array $fixture): array
    {
        [$helperUser, $helperAccount] = $this->verifiedSuchak('Helper Suchak Office');
        $helperProfile = $this->profile('First Door Helper Candidate');

        /** @var SuchakProfileRepresentation $helperRepresentation */
        $helperRepresentation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $helperAccount->id,
            'matrimony_profile_id' => $helperProfile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_verified_consent_at' => now(),
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);

        /** @var SuchakCollaborationRequest $engagement */
        $engagement = SuchakCollaborationRequest::query()->create([
            // The marketplace direction: the helper answered, so the helper is the REQUESTER and
            // the customer-owning side is the target (blueprint 5.2's direction note).
            'requesting_suchak_account_id' => $helperAccount->id,
            'target_suchak_account_id' => $fixture['account']->id,
            'requesting_matrimony_profile_id' => $helperProfile->id,
            'target_matrimony_profile_id' => $fixture['candidateProfile']->id,
            'requesting_representation_id' => $helperRepresentation->id,
            'target_representation_id' => $fixture['representation']->id,
            'status' => SuchakCollaborationRequest::STATUS_ACCEPTED,
            'customer_owner_side' => SuchakCollaborationRequest::SIDE_TARGET,
            'requested_at' => now()->subDay(),
            'responded_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        // `customer_agreement_id` is what makes the role a RECORDED fact rather than the column
        // default — `hasRecordedCustomerOwner()` reads exactly this, and without it no pipeline
        // opens at all.
        SuchakCommissionAgreement::query()->create([
            'collaboration_request_id' => $engagement->id,
            'groom_side_suchak_account_id' => $fixture['account']->id,
            'bride_side_suchak_account_id' => $helperAccount->id,
            'agreement_text_snapshot' => 'First door engagement fixture.',
            'customer_agreement_id' => $fixture['agreement']->id,
        ]);

        $pipeline = $this->app->make(SuchakRequestPipelineService::class)->openPipelineForEngagement(
            $engagement->fresh(['commissionAgreement']),
            $fixture['suchakUser'],
        );

        $this->assertInstanceOf(SuchakPipeline::class, $pipeline);

        return [
            'helperUser' => $helperUser,
            'helperAccount' => $helperAccount,
            'engagement' => $engagement->fresh(['commissionAgreement']),
            'pipeline' => $pipeline,
        ];
    }

    /**
     * @return array{0: User, 1: SuchakAccount}
     */
    private function verifiedSuchak(string $name): array
    {
        $user = User::factory()->create();

        /** @var SuchakAccount $account */
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'suchak_name' => $name,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        return [$user->fresh(), $account];
    }

    private function profile(string $fullName): MatrimonyProfile
    {
        return MatrimonyProfile::factory()->create([
            'user_id' => User::factory()->create()->id,
            'full_name' => $fullName,
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
    }
}
