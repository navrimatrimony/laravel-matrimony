<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
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
use App\Modules\Suchak\Services\SuchakTwelveMonthClauseService;
use App\Modules\Suchak\Services\SuchakVisitConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * THE CUSTOMER'S DOOR (blueprint 6a, D11, D23, M4) and the confirm guard it sits beside.
 *
 * Three defects, each of which made a rule in the frozen blueprint unimplementable rather than
 * merely awkward:
 *
 *  1. `viewed`, `interested` and `meeting_confirmed` are claimed by the CUSTOMER
 *     (SuchakCollaborationStageEvent::STAGE_CLAIMANTS) and assertStageClaimant() refused that
 *     claimant unconditionally. Every Suchak was refused — correctly — and the customer had no door,
 *     so ZERO rows could exist for any of the three.
 *  2. D11 attaches the 12-month anti-circumvention clause at `viewed`. Its anchor timestamp is
 *     `suchak_collaboration_stage_events.claimed_at`, a column that was declared, indexed and
 *     unwritable.
 *  3. M4 says no fee falls due without the customer's confirmation, yet both member doors need
 *     `$request->user()` and a matrimony profile whose `user_id` matches — and section 2 says the
 *     customer is the FAMILY and often has no login at all.
 *
 * Plus the confirm guard: assertRequestingUserCanConfirm() hard-coded
 * `requesting_matrimony_profile_id`, which is a DIRECTION. In a marketplace meeting the responder
 * is the requester (section 5.2), so that column names the HELPER's candidate — the wrong family
 * could confirm and the fee-bearing family could not.
 */
class SuchakCustomerDoorTest extends TestCase
{
    use RefreshDatabase;

    // ── The door exists, and it is the family's alone ────────────────────────────────────────

    public function test_the_family_records_all_three_of_its_own_rungs_over_the_portal_link(): void
    {
        $world = $this->marketplaceWorld();

        // `viewed` and `interested` sit BEFORE acceptance on the ladder — section 6a runs
        // profile_suggested -> viewed -> interested, and a marketplace proposal is created pending.
        foreach ([
            SuchakCollaborationStageEvent::STAGE_VIEWED,
            SuchakCollaborationStageEvent::STAGE_INTERESTED,
        ] as $stageKey) {
            $this->post($this->recordUrl($world['token'], $world['collaboration']), ['stage_key' => $stageKey])
                ->assertRedirect(route('suchak.customer-portal.stages.index', ['token' => $world['token']]));
        }

        // `meeting_confirmed` is gated on an ACCEPTED engagement, exactly like the Suchak path.
        $world['collaboration']->forceFill([
            'status' => SuchakCollaborationRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ])->save();

        $this->post($this->recordUrl($world['token'], $world['collaboration']), [
            'stage_key' => SuchakCollaborationStageEvent::STAGE_MEETING_CONFIRMED,
        ])->assertRedirect(route('suchak.customer-portal.stages.index', ['token' => $world['token']]));

        $this->assertSame(
            SuchakCollaborationStageEvent::customerClaimedStages(),
            SuchakCollaborationStageEvent::query()
                ->where('collaboration_request_id', $world['collaboration']->id)
                ->orderBy('id')
                ->pluck('stage_key')
                ->all(),
        );

        // The ladder moved to the last rung the family recorded.
        $this->assertSame(
            SuchakCollaborationStageEvent::STAGE_MEETING_CONFIRMED,
            $world['collaboration']->fresh()->marketplace_stage,
        );
    }

    /**
     * D11's anchor. The clause binds from `viewed`, and until this door existed the timestamp it
     * runs from could not be written by anybody.
     */
    public function test_the_viewed_rung_finally_carries_the_twelve_month_clause_anchor(): void
    {
        $world = $this->marketplaceWorld();

        $this->post($this->recordUrl($world['token'], $world['collaboration']), [
            'stage_key' => SuchakCollaborationStageEvent::STAGE_VIEWED,
        ])->assertRedirect();

        /** @var SuchakCollaborationStageEvent $event */
        $event = SuchakCollaborationStageEvent::query()
            ->where('stage_key', SuchakCollaborationStageEvent::STAGE_VIEWED)
            ->firstOrFail();

        $this->assertNotNull($event->claimed_at);
        $this->assertTrue($event->isSettled());

        // The claim carries no Suchak and no user — the family has neither — and names the channel
        // instead. A row with all three null would be a row written by nobody.
        $this->assertSame(SuchakActivityLog::ACTOR_USER, $event->claimed_by_actor_type);
        $this->assertNull($event->claimed_by_suchak_account_id);
        $this->assertNull($event->claimed_by_user_id);
        $this->assertSame((int) $world['portalLink']->id, (int) $event->claimed_via_customer_portal_link_id);
    }

    /**
     * D23 / section 8. The record may claim ONLY what happened: someone holding this link acted, at
     * this time, from this IP and user agent. It must not claim the customer was verified.
     */
    public function test_the_record_claims_only_that_a_link_holder_acted(): void
    {
        $world = $this->marketplaceWorld();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->post(
                $this->recordUrl($world['token'], $world['collaboration']),
                ['stage_key' => SuchakCollaborationStageEvent::STAGE_VIEWED],
                ['User-Agent' => 'CustomerDoorTest/1.0'],
            )->assertRedirect();

        /** @var SuchakActivityLog $log */
        $log = SuchakActivityLog::query()
            ->where('action_type', SuchakActivityLog::ACTION_COLLABORATION_STAGE_CUSTOMER_RECORDED)
            ->firstOrFail();

        // The IP and the user agent live where the Suchak domain already keeps them — they are NOT
        // duplicated onto the stage event, which names only the link.
        $this->assertSame(SuchakActivityLog::ACTOR_USER, $log->actor_type);
        $this->assertNull($log->actor_user_id);
        $this->assertSame('203.0.113.9', $log->ip_address);
        $this->assertSame('CustomerDoorTest/1.0', $log->user_agent);
        $this->assertSame(false, $log->metadata_json['identity_verified']);
        $this->assertSame('none', $log->metadata_json['verification_channel']);

        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('suchak_collaboration_stage_events', 'mobile_match'),
            'A stage event must not carry a verification flag it cannot prove.',
        );

        // Nothing on this path pretended to verify a mobile the way recordPublicConsentDecision()
        // does (section 8 names that as the one fiction already in the codebase).
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('suchak_consents')->count());
    }

    /**
     * Unchanged and non-negotiable: the door is for the FAMILY. Handing these rungs to a Suchak is
     * the forged record 9a A2/A3 exist to stop, and the customer-owning Suchak is refused just as
     * flatly as the helper.
     */
    public function test_neither_suchak_can_record_a_family_owned_rung(): void
    {
        $world = $this->marketplaceWorld();
        $service = $this->collaborationService();

        // Accepted, so the engagement-state gate cannot be what refuses `meeting_confirmed` — the
        // refusal under test is the ACTOR rule and nothing else.
        $world['collaboration']->forceFill([
            'status' => SuchakCollaborationRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ])->save();

        foreach (SuchakCollaborationStageEvent::customerClaimedStages() as $stageKey) {
            foreach ([
                [$world['ownerAccount'], $world['ownerUser']],
                [$world['helperAccount'], $world['helperUser']],
            ] as [$account, $user]) {
                try {
                    $service->claimStage($world['collaboration']->fresh(), $account, $user, $stageKey);
                    $this->fail($stageKey.' must not be claimable by a Suchak.');
                } catch (InvalidArgumentException $exception) {
                    $this->assertStringContainsString('ग्राहक स्वतः नोंदवतो', $exception->getMessage());
                }
            }
        }

        $this->assertSame(0, SuchakCollaborationStageEvent::query()->count());
    }

    /**
     * The link must govern THIS engagement. Two independent conditions, and each closes a different
     * attack: a helper handing his own customer a link to record the other family's rungs, and one
     * Suchak's link reaching every engagement he holds.
     */
    public function test_another_familys_link_cannot_record_this_engagements_rungs(): void
    {
        $world = $this->marketplaceWorld();
        $other = $this->marketplaceWorld();

        $this->post($this->recordUrl($other['token'], $world['collaboration']), [
            'stage_key' => SuchakCollaborationStageEvent::STAGE_VIEWED,
        ])->assertSessionHasErrors('customer_stage');

        $this->assertSame(0, SuchakCollaborationStageEvent::query()->count());
    }

    /**
     * THE THIRD QUESTION. Ownership and context alone let a link govern an engagement about two
     * strangers: the owning Suchak links his own agreement to a pair neither of whose profiles is
     * this family's candidate, hands the family their existing portal link, and the `viewed` rung
     * lands — binding D11's twelve-month success fee to a person the family was never shown.
     *
     * The refusal has to hold at BOTH ends, so both are asserted here: the door refuses the rung,
     * and linkCustomerAgreement() refuses to create the mismatched engagement in the first place.
     */
    public function test_a_link_whose_candidate_is_on_neither_side_of_the_engagement_is_refused(): void
    {
        /** @var MatrimonyProfile $stranger */
        $stranger = MatrimonyProfile::factory()->create();

        $world = $this->marketplaceWorld(
            linkAgreement: false,
            customerCandidateProfileId: (int) $stranger->id,
        );

        // End one: the agreement may not be bound to an engagement its candidate is absent from.
        try {
            $this->collaborationService()->linkCustomerAgreement(
                $world['collaboration'],
                $world['ownerAccount'],
                $world['ownerUser'],
                $world['agreement'],
            );
            $this->fail('A customer agreement was bound to an engagement neither of whose profiles is its candidate.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('स्थळ', $exception->getMessage());
        }

        $this->assertNull(
            $world['collaboration']->fresh('commissionAgreement')?->commissionAgreement?->customer_agreement_id,
        );

        // End two: even with the link forced into place, the family's door refuses the rung.
        $this->forceCustomerAgreementLink($world['collaboration'], $world['agreement']);

        $this->assertNotContains(
            (int) $stranger->id,
            [
                (int) $world['collaboration']->requesting_matrimony_profile_id,
                (int) $world['collaboration']->target_matrimony_profile_id,
            ],
            'The fixture must put the family candidate on NEITHER side, or it proves nothing.',
        );

        $this->post($this->recordUrl($world['token'], $world['collaboration']), [
            'stage_key' => SuchakCollaborationStageEvent::STAGE_VIEWED,
        ])->assertSessionHasErrors('customer_stage');

        $this->assertSame(0, SuchakCollaborationStageEvent::query()->count());

        // And therefore nothing binds. Before the gate this recorded the rung and returned a live
        // binding naming the engagement's requesting profile — a candidate this family never saw,
        // owed for twelve months.
        $this->assertSame([], $this->app->make(SuchakTwelveMonthClauseService::class)
            ->bindingsForCustomer($world['customerContext']));
    }

    /**
     * The legitimate case, in BOTH role directions. Direction does not imply role in the
     * marketplace (section 5.2), so the gate is proved against the customer-owning Suchak sitting
     * in the requesting slot as well as the target slot — a gate that only worked one way would
     * refuse half the real engagements.
     */
    public function test_the_legitimate_case_records_in_both_role_directions(): void
    {
        foreach ([SuchakCollaborationRequest::SIDE_TARGET, SuchakCollaborationRequest::SIDE_REQUESTING] as $ownerSide) {
            $world = $this->marketplaceWorld(ownerSide: $ownerSide);

            $this->assertSame(
                $ownerSide,
                $world['collaboration']->fresh()->customer_owner_side,
                'linkCustomerAgreement must record the role side it was proved from.',
            );

            $this->post($this->recordUrl($world['token'], $world['collaboration']), [
                'stage_key' => SuchakCollaborationStageEvent::STAGE_VIEWED,
            ])->assertSessionHasNoErrors();

            $event = SuchakCollaborationStageEvent::query()
                ->where('collaboration_request_id', $world['collaboration']->id)
                ->where('stage_key', SuchakCollaborationStageEvent::STAGE_VIEWED)
                ->firstOrFail();
            $this->assertSame((int) $world['portalLink']->id, (int) $event->claimed_via_customer_portal_link_id);

            // The clause names the OTHER family's candidate — the helper's profile, whichever
            // directional slot it happens to sit in.
            $expectedCandidateId = (int) ($ownerSide === SuchakCollaborationRequest::SIDE_REQUESTING
                ? $world['collaboration']->target_matrimony_profile_id
                : $world['collaboration']->requesting_matrimony_profile_id);

            $bindings = $this->app->make(SuchakTwelveMonthClauseService::class)
                ->bindingsForCustomer($world['customerContext']);

            $this->assertCount(1, $bindings);
            $this->assertTrue($bindings[0]['binds']);
            $this->assertSame($expectedCandidateId, $bindings[0]['candidate_matrimony_profile_id']);
        }
    }

    /**
     * The proposal has to name whose customer this is before the family can act on it. Without the
     * customer-agreement link there is no recorded fact tying the engagement to this family, and
     * A2's manufactured-obligation attack is exactly somebody asserting that tie informally.
     */
    public function test_an_engagement_with_no_linked_customer_agreement_is_not_recordable(): void
    {
        $world = $this->marketplaceWorld(linkAgreement: false);

        $this->post($this->recordUrl($world['token'], $world['collaboration']), [
            'stage_key' => SuchakCollaborationStageEvent::STAGE_VIEWED,
        ])->assertSessionHasErrors('customer_stage');

        $this->assertSame(0, SuchakCollaborationStageEvent::query()->count());
    }

    public function test_terms_the_customer_never_accepted_carry_no_rungs(): void
    {
        $world = $this->marketplaceWorld(termsStatus: SuchakCustomerAgreement::TERMS_PENDING);

        $this->post($this->recordUrl($world['token'], $world['collaboration']), [
            'stage_key' => SuchakCollaborationStageEvent::STAGE_VIEWED,
        ])->assertSessionHasErrors('customer_stage');

        $this->assertSame(0, SuchakCollaborationStageEvent::query()->count());
    }

    public function test_a_rung_is_recorded_once_and_a_second_press_changes_nothing(): void
    {
        $world = $this->marketplaceWorld();

        $this->post($this->recordUrl($world['token'], $world['collaboration']), [
            'stage_key' => SuchakCollaborationStageEvent::STAGE_VIEWED,
        ])->assertRedirect();

        $this->post($this->recordUrl($world['token'], $world['collaboration']), [
            'stage_key' => SuchakCollaborationStageEvent::STAGE_VIEWED,
        ])->assertSessionHasErrors('customer_stage');

        $this->assertSame(1, SuchakCollaborationStageEvent::query()->count());
    }

    /** A revoked link is gone, not forbidden, and the family is not told which of the three it was. */
    public function test_a_revoked_link_is_gone_not_forbidden(): void
    {
        $world = $this->marketplaceWorld();
        $world['portalLink']->forceFill(['portal_status' => SuchakCustomerPortalLink::STATUS_REVOKED])->save();

        $this->get(route('suchak.customer-portal.stages.index', ['token' => $world['token']]))
            ->assertStatus(410);
        $this->post($this->recordUrl($world['token'], $world['collaboration']), [
            'stage_key' => SuchakCollaborationStageEvent::STAGE_VIEWED,
        ])->assertStatus(410);

        $this->assertSame(0, SuchakCollaborationStageEvent::query()->count());
    }

    /**
     * A capability with no door is the defect this whole slice exists to fix, so the door itself
     * gets a door: the portal page is the only thing the family is ever sent, and it must carry the
     * way in.
     */
    public function test_the_door_is_reachable_from_the_page_the_family_is_sent(): void
    {
        $world = $this->marketplaceWorld();
        $stagesUrl = route('suchak.customer-portal.stages.index', ['token' => $world['token']]);

        $this->get(route('suchak.customer-portal.show', ['token' => $world['token']]))
            ->assertOk()
            ->assertSee($stagesUrl);

        $this->get($stagesUrl)
            ->assertOk()
            ->assertSee(SuchakCollaborationStageEvent::stageLabel(SuchakCollaborationStageEvent::STAGE_VIEWED));

        $routes = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => strtoupper(implode('|', $route->methods())).' /'.ltrim($route->uri(), '/'))
            ->all();

        foreach ([
            'GET|HEAD /suchak/customer-portal/{token}/stages',
            'POST /suchak/customer-portal/{token}/stages/{collaboration}',
        ] as $expected) {
            $this->assertContains($expected, $routes, $expected.' has no route.');
        }
    }

    // ── The model refuses a dishonest row whatever writes it ─────────────────────────────────

    public function test_a_customer_rung_must_name_its_link_and_may_not_name_a_suchak(): void
    {
        $world = $this->marketplaceWorld();

        $base = [
            'collaboration_request_id' => $world['collaboration']->id,
            'stage_key' => SuchakCollaborationStageEvent::STAGE_VIEWED,
            'claimed_by_actor_type' => SuchakActivityLog::ACTOR_USER,
            'claimed_at' => now(),
        ];

        try {
            SuchakCollaborationStageEvent::query()->create($base);
            $this->fail('A customer rung with no claim channel must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('must name the customer portal link', $exception->getMessage());
        }

        try {
            SuchakCollaborationStageEvent::query()->create($base + [
                'claimed_via_customer_portal_link_id' => $world['portalLink']->id,
                'claimed_by_suchak_account_id' => $world['ownerAccount']->id,
            ]);
            $this->fail('A customer rung naming a Suchak claimer must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('no Suchak may be recorded as its claimer', $exception->getMessage());
        }

        $this->assertSame(0, SuchakCollaborationStageEvent::query()->count());
    }

    public function test_a_suchak_rung_may_not_be_stamped_with_a_customer_link(): void
    {
        $world = $this->marketplaceWorld();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/customer portal link/');

        SuchakCollaborationStageEvent::query()->create([
            'collaboration_request_id' => $world['collaboration']->id,
            'stage_key' => SuchakCollaborationStageEvent::STAGE_PROFILE_SUGGESTED,
            'claimed_by_actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'claimed_by_suchak_account_id' => $world['helperAccount']->id,
            'claimed_by_user_id' => $world['helperUser']->id,
            'claimed_via_customer_portal_link_id' => $world['portalLink']->id,
            'claimed_at' => now(),
        ]);
    }

    // ── The confirm guard: role, not direction ───────────────────────────────────────────────

    /**
     * The proven bug. On a marketplace meeting `requesting_matrimony_profile_id` is the HELPER's
     * candidate, so the old guard let the helper's family confirm a fee the customer's family owes,
     * and refused the family who owes it.
     */
    public function test_the_customer_confirms_a_marketplace_meeting_and_the_helper_family_cannot(): void
    {
        $meeting = $this->marketplaceMeeting();
        $service = $this->app->make(SuchakVisitConfirmationService::class);

        $this->assertSame(
            (int) $meeting['customerProfile']->id,
            $service->customerSideMatrimonyProfileId($meeting['visit']),
        );

        try {
            $service->confirmByUser($meeting['visit'], $meeting['helperUser'], [
                'confirmation_note' => 'भेट झाली.',
            ]);
            $this->fail('The helper candidate\'s family must not confirm the customer\'s meeting.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Only the customer this meeting was arranged for', $exception->getMessage());
        }

        $confirmed = $service->confirmByUser($meeting['visit'], $meeting['customerUser'], [
            'confirmation_note' => 'भेट झाली.',
        ]);

        $this->assertSame(SuchakVisitConfirmation::CONFIRMATION_CONFIRMED, $confirmed->user_confirmation_status);
        $this->assertSame((int) $meeting['customerUser']->id, (int) $confirmed->user_confirmed_by_user_id);
    }

    /** The same re-point at the HTTP door, so the fix is not reachable only from a test. */
    public function test_the_member_meeting_route_hides_the_meeting_from_the_helper_family(): void
    {
        $meeting = $this->marketplaceMeeting();

        Sanctum::actingAs($meeting['helperUser']);
        $this->postJson('/api/v1/suchak-meetings/'.$meeting['visit']->id.'/confirm', [
            'confirmation_note' => 'भेट झाली.',
        ])->assertNotFound();

        Sanctum::actingAs($meeting['customerUser']);
        $this->postJson('/api/v1/suchak-meetings/'.$meeting['visit']->id.'/confirm', [
            'confirmation_note' => 'भेट झाली.',
        ])->assertOk();

        $this->assertSame(
            SuchakVisitConfirmation::CONFIRMATION_CONFIRMED,
            $meeting['visit']->fresh()->user_confirmation_status,
        );
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────────

    private function collaborationService(): SuchakCollaborationService
    {
        return $this->app->make(SuchakCollaborationService::class);
    }

    private function recordUrl(string $token, SuchakCollaborationRequest $collaboration): string
    {
        return route('suchak.customer-portal.stages.record', [
            'token' => $token,
            'collaboration' => $collaboration->id,
        ]);
    }

    /**
     * One marketplace engagement in the direction the blueprint describes: the HELPER answered a
     * challenge, so he is the REQUESTING side, and the customer-owning Suchak is the target. The
     * owner proves that role the way production does — by linking his own customer agreement.
     *
     * @return array<string, mixed>
     */
    private function marketplaceWorld(
        bool $linkAgreement = true,
        string $termsStatus = SuchakCustomerAgreement::TERMS_ACCEPTED,
        string $ownerSide = SuchakCollaborationRequest::SIDE_TARGET,
        ?int $customerCandidateProfileId = null,
    ): array {
        [$ownerUser, $ownerAccount] = $this->verifiedSuchakActor();
        [$helperUser, $helperAccount] = $this->verifiedSuchakActor();

        $ownerIsRequester = $ownerSide === SuchakCollaborationRequest::SIDE_REQUESTING;

        /** @var SuchakCollaborationRequest $collaboration */
        $collaboration = SuchakCollaborationRequest::factory()->create([
            'requesting_suchak_account_id' => $ownerIsRequester ? $ownerAccount->id : $helperAccount->id,
            'target_suchak_account_id' => $ownerIsRequester ? $helperAccount->id : $ownerAccount->id,
            'status' => SuchakCollaborationRequest::STATUS_PENDING,
            'responded_at' => null,
        ]);

        $ownCandidateId = $customerCandidateProfileId ?? (int) ($ownerIsRequester
            ? $collaboration->requesting_matrimony_profile_id
            : $collaboration->target_matrimony_profile_id);

        $customerContext = SuchakCustomerContext::query()->create([
            'suchak_account_id' => $ownerAccount->id,
            'candidate_matrimony_profile_id' => $ownCandidateId,
            'service_context' => SuchakCustomerContext::SERVICE_PROFILE_REPRESENTATION,
            'source_owner' => SuchakCustomerContext::SOURCE_OWNER_SUCHAK,
            'source_type' => SuchakCustomerContext::SOURCE_TYPE_MANUAL,
            'customer_lifecycle_status' => SuchakCustomerContext::STATUS_ACTIVE_SERVICE,
            'created_by_user_id' => $ownerUser->id,
            'opened_at' => now(),
        ]);

        $agreement = $this->customerAgreement($ownerAccount, $ownerUser, $customerContext, $termsStatus);

        if ($linkAgreement) {
            $this->collaborationService()->linkCustomerAgreement($collaboration, $ownerAccount, $ownerUser, $agreement);
        }

        [$plainToken, $portalLink] = $this->portalLink($ownerAccount, $ownerUser, $customerContext);

        return [
            'ownerUser' => $ownerUser,
            'ownerAccount' => $ownerAccount,
            'helperUser' => $helperUser,
            'helperAccount' => $helperAccount,
            'collaboration' => $collaboration->fresh(['commissionAgreement']),
            'customerContext' => $customerContext,
            'agreement' => $agreement,
            'portalLink' => $portalLink,
            'token' => $plainToken,
        ];
    }

    /**
     * Bind the agreement to the engagement WITHOUT the service, the way a row written before this
     * gate existed already sits in the database. The door must refuse on its own evidence rather
     * than trusting that the writing end was guarded.
     */
    private function forceCustomerAgreementLink(
        SuchakCollaborationRequest $collaboration,
        SuchakCustomerAgreement $agreement,
    ): void {
        $collaboration->refresh()->loadMissing('commissionAgreement');

        $commission = $collaboration->commissionAgreement
            ?? SuchakCommissionAgreement::factory()->create(['collaboration_request_id' => $collaboration->id]);

        SuchakCommissionAgreement::query()
            ->whereKey($commission->id)
            ->update(['customer_agreement_id' => $agreement->id]);

        SuchakCollaborationRequest::query()
            ->whereKey($collaboration->id)
            ->update(['customer_owner_side' => SuchakCollaborationRequest::SIDE_TARGET]);

        $collaboration->refresh()->loadMissing('commissionAgreement.customerAgreement');
    }

    /**
     * @return array{0: string, 1: SuchakCustomerPortalLink}
     */
    private function portalLink(
        SuchakAccount $account,
        User $issuer,
        SuchakCustomerContext $customerContext,
    ): array {
        $plainToken = Str::random(64);

        /** @var SuchakCustomerPortalLink $link */
        $link = SuchakCustomerPortalLink::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'issued_by_user_id' => $issuer->id,
            'token_hash' => hash('sha256', $plainToken),
            'portal_status' => SuchakCustomerPortalLink::STATUS_ACTIVE,
            'recipient_role' => SuchakCustomerPortalLink::RECIPIENT_FAMILY,
            'recipient_label' => 'Customer family',
            'expires_at' => now()->addDays(30),
        ]);

        return [$plainToken, $link];
    }

    private function customerAgreement(
        SuchakAccount $account,
        User $user,
        SuchakCustomerContext $customerContext,
        string $termsStatus,
    ): SuchakCustomerAgreement {
        $package = SuchakServicePackage::query()->create([
            'suchak_account_id' => $account->id,
            'package_name' => 'Customer door fixture',
            'price_amount' => '25000',
            'currency' => 'INR',
            'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
            'requires_admin_approval' => false,
            'customized_by_user_id' => $user->id,
            'published_at' => now(),
        ]);

        $accepted = $termsStatus === SuchakCustomerAgreement::TERMS_ACCEPTED;

        return SuchakCustomerAgreement::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'service_package_id' => $package->id,
            'agreement_revision' => 1,
            'terms_status' => $termsStatus,
            'terms_policy_mode' => SuchakCustomerAgreement::POLICY_STRICT,
            'agreement_snapshot_hash' => hash('sha256', 'customer-door-'.$package->id),
            'package_name' => $package->package_name,
            'price_amount' => '25000',
            'currency' => 'INR',
            'agreement_title' => 'Customer door terms',
            'created_by_user_id' => $user->id,
            'accepted_by_user_id' => $accepted ? $user->id : null,
            'accepted_at' => $accepted ? now() : null,
        ]);
    }

    /**
     * A MARKETPLACE meeting: the helper's candidate sits in `requesting_matrimony_profile_id`
     * (responder-is-requester, section 5.2), and `customer_context_id` names the family whose Suchak
     * arranged it. Those two point at different people, which is the whole reason the guard had to
     * move off direction.
     *
     * @return array<string, mixed>
     */
    private function marketplaceMeeting(): array
    {
        [$ownerUser, $ownerAccount] = $this->verifiedSuchakActor();
        [, $helperAccount] = $this->verifiedSuchakActor();

        $helperUser = User::factory()->create();
        $customerUser = User::factory()->create();

        /** @var MatrimonyProfile $helperProfile */
        $helperProfile = MatrimonyProfile::factory()->create([
            'user_id' => $helperUser->id,
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        /** @var MatrimonyProfile $customerProfile */
        $customerProfile = MatrimonyProfile::factory()->create([
            'user_id' => $customerUser->id,
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);

        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $ownerAccount->id,
            'matrimony_profile_id' => $customerProfile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_verified_consent_at' => now(),
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);

        $request = SuchakProfileRequest::query()->create([
            'requesting_user_id' => $helperUser->id,
            'requesting_matrimony_profile_id' => $helperProfile->id,
            'target_matrimony_profile_id' => $customerProfile->id,
            'selected_suchak_account_id' => $ownerAccount->id,
            'representation_id' => $representation->id,
            'request_status' => SuchakProfileRequest::STATUS_PENDING,
            'request_reason' => 'intro_visit',
            'message' => 'Marketplace introduction.',
        ]);

        $pipeline = SuchakPipeline::query()->create([
            'request_id' => $request->id,
            'target_matrimony_profile_id' => $customerProfile->id,
            'requesting_matrimony_profile_id' => $helperProfile->id,
            'selected_suchak_account_id' => $ownerAccount->id,
            'representation_id' => $representation->id,
            'pipeline_status' => SuchakPipeline::STATUS_PENDING,
            'attribution_locked_at' => now(),
            'lock_expires_at' => now()->addDays(2),
            'sla_status' => SuchakPipeline::SLA_WITHIN,
        ]);

        $customerContext = SuchakCustomerContext::query()->create([
            'suchak_account_id' => $ownerAccount->id,
            'candidate_matrimony_profile_id' => $customerProfile->id,
            'service_context' => SuchakCustomerContext::SERVICE_PROFILE_REPRESENTATION,
            'source_owner' => SuchakCustomerContext::SOURCE_OWNER_SUCHAK,
            'source_type' => SuchakCustomerContext::SOURCE_TYPE_MANUAL,
            'customer_lifecycle_status' => SuchakCustomerContext::STATUS_ACTIVE_SERVICE,
            'created_by_user_id' => $ownerUser->id,
            'opened_at' => now(),
        ]);

        /** @var SuchakVisitConfirmation $visit */
        $visit = SuchakVisitConfirmation::query()->create([
            'pipeline_id' => $pipeline->id,
            'suchak_account_id' => $ownerAccount->id,
            'helper_suchak_account_id' => $helperAccount->id,
            'request_id' => $request->id,
            'representation_id' => $representation->id,
            'target_matrimony_profile_id' => $customerProfile->id,
            'requesting_matrimony_profile_id' => $helperProfile->id,
            'customer_context_id' => $customerContext->id,
            'visit_status' => SuchakVisitConfirmation::STATUS_COMPLETED,
            'confirmation_policy_mode' => SuchakVisitConfirmation::POLICY_USER_ONLY,
            'meeting_sequence' => 1,
            'meeting_mode' => SuchakVisitConfirmation::MODE_OFFLINE,
            'scheduled_by_user_id' => $ownerUser->id,
            'scheduled_at' => now()->subDay(),
            'suchak_completion_status' => SuchakVisitConfirmation::COMPLETION_SUCHAK_MARKED,
            'suchak_completed_by_user_id' => $ownerUser->id,
            'suchak_completed_at' => now()->subHours(2),
            'suchak_completion_note' => 'भेट पार पडली.',
            'user_confirmation_status' => SuchakVisitConfirmation::CONFIRMATION_PENDING,
            'admin_confirmation_status' => SuchakVisitConfirmation::CONFIRMATION_NOT_REQUIRED,
            'refund_review_status' => SuchakVisitConfirmation::REFUND_NOT_REQUESTED,
        ]);

        return [
            'visit' => $visit->fresh(),
            'helperUser' => $helperUser,
            'customerUser' => $customerUser,
            'customerProfile' => $customerProfile,
            'helperProfile' => $helperProfile,
        ];
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
}
