<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakCommissionAgreement;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCollaborationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Three structural defects in the marketplace stage ladder (blueprint 6a), each of which made a
 * capability unreachable rather than merely wrong:
 *
 *  1. `collaboration_request_id` was NOT NULL, so the four PRE-ENGAGEMENT stages could not be
 *     recorded at all — `published_to_marketplace` least of all, since publication is the act that
 *     invites the counterparty.
 *  2. `claimStage()` required an ACCEPTED engagement, but a marketplace proposal is created
 *     `pending` and D11 attaches the 12-month clause at `viewed` — before acceptance. The clause's
 *     own trigger was unreachable at the moment it is supposed to bind.
 *  3. `linkCustomerAgreement()`, `claimStage()` and `confirmStage()` had no controller and no
 *     route. A method no route calls is the same defect as a column no writer writes.
 */
class SuchakStageLadderReachabilityTest extends TestCase
{
    use RefreshDatabase;

    // ── Defect 1: the ladder can record its own first four rungs ──────────────────────────────

    public function test_the_stage_event_owner_is_nullable_engagement_plus_customer_agreement(): void
    {
        $this->assertTrue(Schema::hasColumn('suchak_collaboration_stage_events', 'customer_agreement_id'));

        $this->assertSame([
            'collaboration_request_id',
            'customer_agreement_id',
        ], SuchakCollaborationStageEvent::OWNER_COLUMNS);

        // Both owners keep a real foreign key. That is the half of "a stage event never belongs to
        // nothing" the database owns; the model owns the exactly-one half. It is also why the shape
        // is two typed columns and not a polymorphic (owner_type, owner_id) pair, which could not
        // carry either key. The engagement key had to survive the rebuild that making its column
        // nullable required.
        $foreignKeys = collect(Schema::getForeignKeys('suchak_collaboration_stage_events'))
            ->mapWithKeys(fn (array $key): array => [implode(',', $key['columns']) => $key['foreign_table']]);

        $this->assertSame('suchak_collaboration_requests', $foreignKeys->get('collaboration_request_id'));
        $this->assertSame('suchak_customer_agreements', $foreignKeys->get('customer_agreement_id'));

        // The split is a POSITION on the one ordered ladder, not a second hand-written list.
        $this->assertSame([
            SuchakCollaborationStageEvent::STAGE_REGISTRATION,
            SuchakCollaborationStageEvent::STAGE_AGREEMENT_PROPOSED,
            SuchakCollaborationStageEvent::STAGE_AGREEMENT_ACCEPTED,
            SuchakCollaborationStageEvent::STAGE_PUBLISHED_TO_MARKETPLACE,
        ], SuchakCollaborationStageEvent::preEngagementStages());

        $this->assertSame(
            array_slice(SuchakCollaborationStageEvent::STAGE_LADDER, 4),
            SuchakCollaborationStageEvent::engagementStages(),
        );
        $this->assertSame(
            SuchakCollaborationStageEvent::STAGE_LADDER,
            array_merge(
                SuchakCollaborationStageEvent::preEngagementStages(),
                SuchakCollaborationStageEvent::engagementStages(),
            ),
        );
    }

    public function test_all_four_pre_engagement_stages_are_recordable_on_the_customer_agreement(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        $agreement = $this->customerAgreement($account, $user, revision: 1);
        $service = $this->service();

        foreach (SuchakCollaborationStageEvent::preEngagementStages() as $stageKey) {
            $event = $service->claimCustomerStage($agreement, $account, $user, $stageKey);

            $this->assertSame($stageKey, $event->stage_key);
            $this->assertSame((int) $agreement->id, (int) $event->customer_agreement_id);
            $this->assertNull($event->collaboration_request_id);
            $this->assertTrue($event->isSettled());
        }

        $this->assertSame(4, SuchakCollaborationStageEvent::query()
            ->where('customer_agreement_id', $agreement->id)
            ->count());

        // The sharp one: publication invites the counterparty, so it can never have an engagement.
        $this->assertDatabaseHas('suchak_collaboration_stage_events', [
            'customer_agreement_id' => $agreement->id,
            'stage_key' => SuchakCollaborationStageEvent::STAGE_PUBLISHED_TO_MARKETPLACE,
            'collaboration_request_id' => null,
        ]);
    }

    public function test_a_stage_event_can_never_belong_to_nothing_or_to_two_owners(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        $agreement = $this->customerAgreement($account, $user, revision: 1);
        $collaboration = $this->engagement($account, SuchakCollaborationRequest::STATUS_ACCEPTED);

        $base = [
            'stage_key' => SuchakCollaborationStageEvent::STAGE_VIEWED,
            'claimed_by_actor_type' => 'suchak',
            'claimed_by_suchak_account_id' => $account->id,
            'claimed_by_user_id' => $user->id,
            'claimed_at' => now(),
        ];

        try {
            SuchakCollaborationStageEvent::query()->create($base);
            $this->fail('A stage event with no owner must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('must name an owner', $exception->getMessage());
        }

        try {
            SuchakCollaborationStageEvent::query()->create($base + [
                'collaboration_request_id' => $collaboration->id,
                'customer_agreement_id' => $agreement->id,
            ]);
            $this->fail('A stage event with two owners must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('exactly one owner', $exception->getMessage());
        }

        // Filed under the wrong owner is its own defect: a published_to_marketplace row hanging off
        // an engagement would claim a counterparty existed before the invitation was sent.
        try {
            SuchakCollaborationStageEvent::query()->create(array_merge($base, [
                'stage_key' => SuchakCollaborationStageEvent::STAGE_PUBLISHED_TO_MARKETPLACE,
                'collaboration_request_id' => $collaboration->id,
            ]));
            $this->fail('A pre-engagement stage on an engagement must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('belongs to customer_agreement_id', $exception->getMessage());
        }

        $this->assertSame(0, SuchakCollaborationStageEvent::query()->count());
    }

    /**
     * The service's own duplicate pre-check is not the guarantee — two concurrent claims would both
     * pass it. The guarantee is the pair of unique indexes, and the engagement one had to survive
     * the table rebuild that making its column nullable required. Written through the query builder
     * on purpose, so this tests the database and not the model guard.
     */
    public function test_both_unique_indexes_refuse_a_second_row_for_the_same_owner_and_stage(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        $agreement = $this->customerAgreement($account, $user, revision: 1);
        $collaboration = $this->engagement($account, SuchakCollaborationRequest::STATUS_ACCEPTED);

        $row = [
            'stage_key' => SuchakCollaborationStageEvent::STAGE_VIEWED,
            'claimed_by_actor_type' => 'suchak',
            'claimed_by_suchak_account_id' => $account->id,
            'claimed_by_user_id' => $user->id,
            'claimed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('suchak_collaboration_stage_events')
            ->insert($row + ['collaboration_request_id' => $collaboration->id]);
        try {
            DB::table('suchak_collaboration_stage_events')
                ->insert($row + ['collaboration_request_id' => $collaboration->id]);
            $this->fail('unique(collaboration_request_id, stage_key) did not survive.');
        } catch (QueryException) {
            // expected
        }

        $agreementRow = array_merge($row, [
            'stage_key' => SuchakCollaborationStageEvent::STAGE_REGISTRATION,
            'customer_agreement_id' => $agreement->id,
        ]);
        DB::table('suchak_collaboration_stage_events')->insert($agreementRow);
        try {
            DB::table('suchak_collaboration_stage_events')->insert($agreementRow);
            $this->fail('unique(customer_agreement_id, stage_key) is missing.');
        } catch (QueryException) {
            // expected
        }

        // NULLs compare distinct in a unique index, which is exactly what lets one table carry two
        // owners: the agreement-owned row is not policed by the engagement index, and vice versa.
        $this->assertSame(2, SuchakCollaborationStageEvent::query()->count());
    }

    public function test_a_pre_engagement_stage_is_recorded_once_per_agreement_revision(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        $first = $this->customerAgreement($account, $user, revision: 1);
        $second = $this->customerAgreement($account, $user, revision: 2);
        $service = $this->service();

        $service->claimCustomerStage($first, $account, $user, SuchakCollaborationStageEvent::STAGE_PUBLISHED_TO_MARKETPLACE);

        // Section 4: a rate change is a NEW agreement row, so re-publishing under new terms is a
        // new ladder row automatically. Re-publishing under the SAME terms is not counted here.
        $service->claimCustomerStage($second, $account, $user, SuchakCollaborationStageEvent::STAGE_PUBLISHED_TO_MARKETPLACE);
        $this->assertSame(2, SuchakCollaborationStageEvent::query()
            ->where('stage_key', SuchakCollaborationStageEvent::STAGE_PUBLISHED_TO_MARKETPLACE)
            ->count());

        $this->expectException(InvalidArgumentException::class);
        $service->claimCustomerStage($first, $account, $user, SuchakCollaborationStageEvent::STAGE_PUBLISHED_TO_MARKETPLACE);
    }

    public function test_each_claim_path_refuses_the_other_owner_stages(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        $agreement = $this->customerAgreement($account, $user, revision: 1);
        $collaboration = $this->engagement($account, SuchakCollaborationRequest::STATUS_ACCEPTED);
        $service = $this->service();

        try {
            $service->claimStage(
                $collaboration,
                $account,
                $user,
                SuchakCollaborationStageEvent::STAGE_PUBLISHED_TO_MARKETPLACE,
            );
            $this->fail('A pre-engagement stage must not be claimable on an engagement.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('before any engagement exists', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $service->claimCustomerStage($agreement, $account, $user, SuchakCollaborationStageEvent::STAGE_VIEWED);
    }

    public function test_another_suchaks_customer_agreement_cannot_be_claimed_against(): void
    {
        [$owner, $ownerAccount] = $this->verifiedSuchakActor();
        [$stranger, $strangerAccount] = $this->verifiedSuchakActor();
        $agreement = $this->customerAgreement($ownerAccount, $owner, revision: 1);

        $this->expectException(InvalidArgumentException::class);
        $this->service()->claimCustomerStage(
            $agreement,
            $strangerAccount,
            $stranger,
            SuchakCollaborationStageEvent::STAGE_REGISTRATION,
        );
    }

    // ── Defect 2: the pre-acceptance rungs are reachable — by the actor section 6a names ──────

    public function test_profile_suggested_is_claimable_by_the_helper_before_acceptance(): void
    {
        // Marketplace direction (blueprint 6.1 note): the Suchak ANSWERING a challenge becomes the
        // requester, so the customer-owning side is the target. The owner establishes that by
        // linking HIS OWN customer agreement; a Suchak can never appoint himself the other role.
        [$helperUser, $helperAccount] = $this->verifiedSuchakActor();
        [$ownerUser, $ownerAccount] = $this->verifiedSuchakActor();
        $collaboration = $this->engagementBetween($helperAccount, $ownerAccount, SuchakCollaborationRequest::STATUS_PENDING);
        $this->linkOwnerAgreement($collaboration, $ownerAccount, $ownerUser);

        $this->assertFalse(SuchakCollaborationStageEvent::requiresAcceptedEngagement(
            SuchakCollaborationStageEvent::STAGE_PROFILE_SUGGESTED,
        ));

        $event = $this->service()->claimStage(
            $collaboration->fresh(),
            $helperAccount,
            $helperUser,
            SuchakCollaborationStageEvent::STAGE_PROFILE_SUGGESTED,
        );

        $this->assertSame(SuchakCollaborationStageEvent::STAGE_PROFILE_SUGGESTED, $event->stage_key);
        $this->assertSame((int) $collaboration->id, (int) $event->collaboration_request_id);
        $this->assertSame(
            SuchakCollaborationStageEvent::STAGE_PROFILE_SUGGESTED,
            $collaboration->fresh()->marketplace_stage,
        );
        $this->assertSame(SuchakCollaborationRequest::STATUS_PENDING, $collaboration->fresh()->status);

        // …and the owning Suchak may not name the helper's own candidate for him.
        try {
            $this->service()->claimStage(
                $collaboration->fresh(),
                $ownerAccount,
                $ownerUser,
                SuchakCollaborationStageEvent::STAGE_PROFILE_SUGGESTED,
            );
            $this->fail('Only the helping Suchak may claim profile_suggested.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('फक्त मदत करणारा सूचक', $exception->getMessage());
        }
    }

    /**
     * D11's 12-month clause binds at `viewed`, and `viewed` is the customer's own act. There is no
     * customer door (D23 defers OTP; §10 S4 records that the delivery channel does not exist), so
     * the rung is refused to BOTH Suchaks rather than handed to whichever one asks first. This test
     * pins the gap deliberately: the clause has no honest trigger today, and the fix is the
     * customer's door, never a Suchak-written record of what the family did.
     */
    public function test_the_three_family_owned_rungs_are_refused_to_both_suchaks(): void
    {
        [$helperUser, $helperAccount] = $this->verifiedSuchakActor();
        [$ownerUser, $ownerAccount] = $this->verifiedSuchakActor();
        $collaboration = $this->engagementBetween($helperAccount, $ownerAccount, SuchakCollaborationRequest::STATUS_ACCEPTED);
        $this->linkOwnerAgreement($collaboration, $ownerAccount, $ownerUser);
        $service = $this->service();

        foreach ([
            SuchakCollaborationStageEvent::STAGE_VIEWED,
            SuchakCollaborationStageEvent::STAGE_INTERESTED,
            SuchakCollaborationStageEvent::STAGE_MEETING_CONFIRMED,
        ] as $stageKey) {
            $this->assertTrue(SuchakCollaborationStageEvent::isCustomerClaimedStage($stageKey), $stageKey);

            foreach ([[$helperAccount, $helperUser], [$ownerAccount, $ownerUser]] as [$account, $user]) {
                try {
                    $service->claimStage($collaboration->fresh(), $account, $user, $stageKey);
                    $this->fail($stageKey.' must not be claimable by a Suchak.');
                } catch (InvalidArgumentException $exception) {
                    $this->assertStringContainsString('ग्राहक स्वतः नोंदवतो', $exception->getMessage());
                }
            }
        }

        $this->assertSame(0, SuchakCollaborationStageEvent::query()->count());
        $this->assertNull($collaboration->fresh()->marketplace_stage);
    }

    /**
     * The attack, exactly as it was run over HTTP: one verified Suchak on the requesting side of an
     * accepted collaboration POSTed meeting_scheduled -> meeting_completed -> meeting_confirmed ->
     * share_settled and got 201 four times, ending at `share_settled` with no act by anyone else.
     */
    public function test_one_suchak_can_no_longer_walk_the_ladder_alone_over_http(): void
    {
        [$attacker, $attackerAccount] = $this->verifiedSuchakActor();
        [, $otherAccount] = $this->verifiedSuchakActor();
        $collaboration = $this->engagementBetween($attackerAccount, $otherAccount, SuchakCollaborationRequest::STATUS_ACCEPTED);

        Sanctum::actingAs($attacker);

        // Arranging a meeting is joint work on an already-accepted engagement: either side may
        // record it, so this one still passes. It is the only rung of the four that does.
        $this->postJson('/api/v1/suchak/collaborations/'.$collaboration->id.'/stages', [
            'stage_key' => SuchakCollaborationStageEvent::STAGE_MEETING_SCHEDULED,
        ])->assertCreated()->assertJsonPath('data.claimant', SuchakCollaborationStageEvent::CLAIMANT_EITHER_SUCHAK);

        foreach ([
            SuchakCollaborationStageEvent::STAGE_MEETING_COMPLETED,
            SuchakCollaborationStageEvent::STAGE_MEETING_CONFIRMED,
            SuchakCollaborationStageEvent::STAGE_SHARE_SETTLED,
        ] as $stageKey) {
            $this->postJson('/api/v1/suchak/collaborations/'.$collaboration->id.'/stages', [
                'stage_key' => $stageKey,
            ])->assertStatus(422)->assertJsonPath('success', false);
        }

        $this->assertSame(
            SuchakCollaborationStageEvent::STAGE_MEETING_SCHEDULED,
            $collaboration->fresh()->marketplace_stage,
        );
        $this->assertSame(1, SuchakCollaborationStageEvent::query()
            ->where('collaboration_request_id', $collaboration->id)
            ->count());
    }

    /**
     * A7 in one test: the declarer may not mark his own share settled. That single record is the
     * whole basis of the published realized-vs-declared ratio, and the payer signing his own
     * receipt would destroy the one number that stops an inflated declaration.
     */
    public function test_only_the_helper_marks_the_share_settled(): void
    {
        [$helperUser, $helperAccount] = $this->verifiedSuchakActor();
        [$ownerUser, $ownerAccount] = $this->verifiedSuchakActor();
        $collaboration = $this->engagementBetween($helperAccount, $ownerAccount, SuchakCollaborationRequest::STATUS_ACCEPTED);
        $this->linkOwnerAgreement($collaboration, $ownerAccount, $ownerUser);

        Sanctum::actingAs($ownerUser);
        $this->postJson('/api/v1/suchak/collaborations/'.$collaboration->id.'/stages', [
            'stage_key' => SuchakCollaborationStageEvent::STAGE_SHARE_SETTLED,
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, SuchakCollaborationStageEvent::query()->count());

        Sanctum::actingAs($helperUser);
        $this->postJson('/api/v1/suchak/collaborations/'.$collaboration->id.'/stages', [
            'stage_key' => SuchakCollaborationStageEvent::STAGE_SHARE_SETTLED,
        ])
            ->assertCreated()
            ->assertJsonPath('data.claimant', SuchakCollaborationStageEvent::CLAIMANT_HELPER)
            ->assertJsonPath('data.stage_label', 'वाटा दिल्यावर');

        $this->assertSame(
            (int) $helperAccount->id,
            (int) SuchakCollaborationStageEvent::query()->value('claimed_by_suchak_account_id'),
        );
    }

    /**
     * `customer_owner_side` DEFAULTS to `target`. Until the owning Suchak links his own customer
     * agreement, "helper" is a column default and not a finding — and A7's money rule may not hang
     * off a default. A role-scoped rung is refused until the role is a recorded fact.
     */
    public function test_a_role_scoped_rung_is_refused_while_the_role_is_only_a_column_default(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        $collaboration = $this->engagement($account, SuchakCollaborationRequest::STATUS_ACCEPTED);

        // The default already labels this account the helper — that is exactly what must not count.
        $this->assertTrue($collaboration->isHelpingSuchak((int) $account->id));

        try {
            $this->service()->claimStage(
                $collaboration,
                $account,
                $user,
                SuchakCollaborationStageEvent::STAGE_SHARE_SETTLED,
            );
            $this->fail('A role-scoped rung must be refused while the role is only a default.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('ग्राहकाचा सूचक कोण हे अजून नोंदवलेले नाही', $exception->getMessage());
        }

        $this->assertSame(0, SuchakCollaborationStageEvent::query()->count());
    }

    public function test_every_rung_names_exactly_one_claimant_and_the_two_lists_cannot_drift(): void
    {
        // The actor half of the ladder covers the ladder exactly, in the same order. Adding a rung
        // without naming its actor is caught here and, at runtime, by claimantFor() failing closed.
        $this->assertSame(
            SuchakCollaborationStageEvent::STAGE_LADDER,
            array_keys(SuchakCollaborationStageEvent::STAGE_CLAIMANTS),
        );

        // Every pre-engagement rung belongs to the customer-owning Suchak — which is what the
        // agreement path enforces structurally, since the agreement is his by definition.
        foreach (SuchakCollaborationStageEvent::preEngagementStages() as $stageKey) {
            $this->assertSame(
                SuchakCollaborationStageEvent::CLAIMANT_CUSTOMER_OWNER,
                SuchakCollaborationStageEvent::claimantFor($stageKey),
                $stageKey,
            );
        }

        // D26: the three confirmable rungs are exactly the ones either Suchak may claim.
        foreach (SuchakCollaborationStageEvent::CONFIRMABLE_STAGES as $stageKey) {
            $this->assertSame(
                SuchakCollaborationStageEvent::CLAIMANT_EITHER_SUCHAK,
                SuchakCollaborationStageEvent::claimantFor($stageKey),
                $stageKey,
            );
        }

        // The terms gate is a POSITION on the ladder: only the two rungs before it are ungated.
        $ungated = array_values(array_filter(
            SuchakCollaborationStageEvent::STAGE_LADDER,
            static fn (string $stageKey): bool => ! SuchakCollaborationStageEvent::requiresSatisfiedCustomerTerms($stageKey),
        ));
        $this->assertSame([
            SuchakCollaborationStageEvent::STAGE_REGISTRATION,
            SuchakCollaborationStageEvent::STAGE_AGREEMENT_PROPOSED,
        ], $ungated);

        $this->expectException(InvalidArgumentException::class);
        SuchakCollaborationStageEvent::claimantFor('lagna_zale');
    }

    public function test_post_acceptance_stages_still_require_an_accepted_engagement(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        $pending = $this->engagement($account, SuchakCollaborationRequest::STATUS_PENDING);
        $service = $this->service();

        foreach (SuchakCollaborationStageEvent::engagementStages() as $stageKey) {
            $expectedGate = SuchakCollaborationStageEvent::stageIndex($stageKey)
                >= SuchakCollaborationStageEvent::stageIndex(
                    SuchakCollaborationStageEvent::FIRST_STAGE_REQUIRING_ACCEPTED_ENGAGEMENT,
                );
            $this->assertSame(
                $expectedGate,
                SuchakCollaborationStageEvent::requiresAcceptedEngagement($stageKey),
                $stageKey,
            );
        }

        try {
            $service->claimStage(
                $pending,
                $account,
                $user,
                SuchakCollaborationStageEvent::STAGE_MEETING_SCHEDULED,
            );
            $this->fail('A post-acceptance stage must be refused on a pending engagement.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('accepted collaboration', $exception->getMessage());
        }

        $accepted = $this->engagement($account, SuchakCollaborationRequest::STATUS_ACCEPTED);
        $event = $service->claimStage(
            $accepted,
            $account,
            $user,
            SuchakCollaborationStageEvent::STAGE_MEETING_SCHEDULED,
        );
        $this->assertSame(SuchakCollaborationStageEvent::STAGE_MEETING_SCHEDULED, $event->stage_key);
    }

    public function test_a_closed_engagement_takes_no_further_stages(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        $rejected = $this->engagement($account, SuchakCollaborationRequest::STATUS_REJECTED);

        $this->expectException(InvalidArgumentException::class);
        $this->service()->claimStage($rejected, $account, $user, SuchakCollaborationStageEvent::STAGE_VIEWED);
    }

    // ── Defect 3: every writer has a door ────────────────────────────────────────────────────

    public function test_every_phase_one_writer_has_a_route(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => strtoupper(implode('|', $route->methods())).' /'.ltrim($route->uri(), '/'))
            ->all();

        foreach ([
            'api/v1/suchak/collaborations/{collaboration}/customer-agreement',
            'api/v1/suchak/collaborations/{collaboration}/stages',
            'api/v1/suchak/customer-agreements/{agreement}/stages',
            'api/v1/suchak-engagements/{collaboration}/stages/confirm',
        ] as $uri) {
            $this->assertTrue(
                collect($routes)->contains(fn (string $row): bool => str_contains($row, ' /'.$uri)),
                $uri.' has no route',
            );
        }
    }

    public function test_link_customer_agreement_route_writes_the_owner_side_and_the_revision(): void
    {
        [$owner, $ownerAccount] = $this->verifiedSuchakActor();
        [, $helperAccount] = $this->verifiedSuchakActor();
        $collaboration = $this->engagementBetween($ownerAccount, $helperAccount, SuchakCollaborationRequest::STATUS_ACCEPTED);
        // The owner is the REQUESTING side here, so his customer's candidate is the requesting
        // profile — the fact the route now proves before it writes the role label.
        $agreement = $this->customerAgreement(
            $ownerAccount,
            $owner,
            revision: 1,
            candidateProfileId: (int) $collaboration->requesting_matrimony_profile_id,
        );

        Sanctum::actingAs($owner);
        $response = $this->postJson(
            '/api/v1/suchak/collaborations/'.$collaboration->id.'/customer-agreement',
            ['customer_agreement_id' => $agreement->id],
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.customer_owner_side', SuchakCollaborationRequest::SIDE_REQUESTING)
            ->assertJsonPath('data.customer_owner_suchak_account_id', (int) $ownerAccount->id)
            ->assertJsonPath('data.helping_suchak_account_id', (int) $helperAccount->id)
            ->assertJsonPath('data.customer_agreement_id', (int) $agreement->id);

        // The two columns that could previously only ever hold their default / NULL.
        $this->assertSame(SuchakCollaborationRequest::SIDE_REQUESTING, $collaboration->fresh()->customer_owner_side);
        $this->assertSame((int) $agreement->id, (int) SuchakCommissionAgreement::query()
            ->where('collaboration_request_id', $collaboration->id)
            ->value('customer_agreement_id'));
    }

    public function test_link_customer_agreement_route_hides_another_suchaks_agreement(): void
    {
        [$owner, $ownerAccount] = $this->verifiedSuchakActor();
        [$stranger, $strangerAccount] = $this->verifiedSuchakActor();
        $collaboration = $this->engagementBetween($ownerAccount, $strangerAccount, SuchakCollaborationRequest::STATUS_ACCEPTED);
        $agreement = $this->customerAgreement($ownerAccount, $owner, revision: 1);

        Sanctum::actingAs($stranger);
        $this->postJson(
            '/api/v1/suchak/collaborations/'.$collaboration->id.'/customer-agreement',
            ['customer_agreement_id' => $agreement->id],
        )->assertNotFound();
    }

    public function test_engagement_stage_route_claims_profile_suggested_on_a_pending_engagement(): void
    {
        [$helper, $helperAccount] = $this->verifiedSuchakActor();
        [$ownerUser, $ownerAccount] = $this->verifiedSuchakActor();
        $collaboration = $this->engagementBetween($helperAccount, $ownerAccount, SuchakCollaborationRequest::STATUS_PENDING);
        $this->linkOwnerAgreement($collaboration, $ownerAccount, $ownerUser);

        Sanctum::actingAs($helper);
        $response = $this->postJson(
            '/api/v1/suchak/collaborations/'.$collaboration->id.'/stages',
            [
                'stage_key' => SuchakCollaborationStageEvent::STAGE_PROFILE_SUGGESTED,
                'event_note' => 'स्थळ सुचवले.',
            ],
        );

        $response->assertCreated()
            ->assertJsonPath('data.stage_key', SuchakCollaborationStageEvent::STAGE_PROFILE_SUGGESTED)
            ->assertJsonPath('data.owner', 'collaboration_request_id')
            ->assertJsonPath('data.claimant', SuchakCollaborationStageEvent::CLAIMANT_HELPER)
            ->assertJsonPath('data.requires_confirmation', false)
            ->assertJsonPath('data.marketplace_stage', SuchakCollaborationStageEvent::STAGE_PROFILE_SUGGESTED);

        $this->assertSame(
            SuchakCollaborationStageEvent::STAGE_PROFILE_SUGGESTED,
            $collaboration->fresh()->marketplace_stage,
        );
    }

    /**
     * `viewed` stays in the validation list on purpose: it IS a real engagement stage, and the
     * reason it is refused is the actor, not the vocabulary. A Suchak reading "स्थळ पाहिले हा टप्पा
     * ग्राहक स्वतः नोंदवतो" learns something true; "the selected stage_key is invalid" would not.
     */
    public function test_engagement_stage_route_refuses_a_family_owned_rung_with_a_reason_not_a_validation_error(): void
    {
        [$owner, $ownerAccount] = $this->verifiedSuchakActor();
        $collaboration = $this->engagement($ownerAccount, SuchakCollaborationRequest::STATUS_PENDING);

        Sanctum::actingAs($owner);
        $response = $this->postJson(
            '/api/v1/suchak/collaborations/'.$collaboration->id.'/stages',
            ['stage_key' => SuchakCollaborationStageEvent::STAGE_VIEWED],
        );

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertNull($response->json('errors'), 'This must be a reasoned refusal, not a validation error.');
        $this->assertStringContainsString('ग्राहक स्वतः नोंदवतो', (string) $response->json('message'));

        $this->assertSame(0, SuchakCollaborationStageEvent::query()->count());
    }

    public function test_engagement_stage_route_refuses_a_pre_engagement_stage_key(): void
    {
        [$owner, $ownerAccount] = $this->verifiedSuchakActor();
        $collaboration = $this->engagement($ownerAccount, SuchakCollaborationRequest::STATUS_ACCEPTED);

        Sanctum::actingAs($owner);
        $this->postJson(
            '/api/v1/suchak/collaborations/'.$collaboration->id.'/stages',
            ['stage_key' => SuchakCollaborationStageEvent::STAGE_PUBLISHED_TO_MARKETPLACE],
        )->assertStatus(422)->assertJsonValidationErrors('stage_key');
    }

    public function test_customer_stage_route_records_publication(): void
    {
        [$owner, $ownerAccount] = $this->verifiedSuchakActor();
        $agreement = $this->customerAgreement($ownerAccount, $owner, revision: 1);

        Sanctum::actingAs($owner);
        $response = $this->postJson(
            '/api/v1/suchak/customer-agreements/'.$agreement->id.'/stages',
            ['stage_key' => SuchakCollaborationStageEvent::STAGE_PUBLISHED_TO_MARKETPLACE],
        );

        $response->assertCreated()
            ->assertJsonPath('data.owner', 'customer_agreement_id')
            ->assertJsonPath('data.customer_agreement_id', (int) $agreement->id)
            ->assertJsonPath('data.collaboration_id', null)
            ->assertJsonPath('data.stage_label', 'बाजारपेठेत प्रसिद्ध');

        $this->assertDatabaseHas('suchak_collaboration_stage_events', [
            'customer_agreement_id' => $agreement->id,
            'stage_key' => SuchakCollaborationStageEvent::STAGE_PUBLISHED_TO_MARKETPLACE,
        ]);
    }

    /**
     * The second proven attack, over HTTP: against an agreement whose `terms_status` is `declined`,
     * `agreement_accepted` returned 201 and `published_to_marketplace` returned 201 — two ladder
     * rows asserting the customer accepted an agreement they refused, written by the only party
     * with an interest in it, with no counter-signature (none of the four pre-engagement rungs is
     * in CONFIRMABLE_STAGES) and no correction path.
     */
    public function test_a_declined_agreement_cannot_be_recorded_as_accepted_or_published(): void
    {
        [$owner, $ownerAccount] = $this->verifiedSuchakActor();
        $agreement = $this->customerAgreement(
            $ownerAccount,
            $owner,
            revision: 1,
            termsStatus: SuchakCustomerAgreement::TERMS_DECLINED,
        );

        Sanctum::actingAs($owner);

        foreach ([
            SuchakCollaborationStageEvent::STAGE_AGREEMENT_ACCEPTED,
            SuchakCollaborationStageEvent::STAGE_PUBLISHED_TO_MARKETPLACE,
        ] as $stageKey) {
            $response = $this->postJson(
                '/api/v1/suchak/customer-agreements/'.$agreement->id.'/stages',
                ['stage_key' => $stageKey],
            );

            $response->assertStatus(422)->assertJsonPath('success', false);
            $this->assertStringContainsString('ग्राहकाने हा करार नाकारला आहे', (string) $response->json('message'));
        }

        $this->assertSame(0, SuchakCollaborationStageEvent::query()->count());
    }

    /**
     * The other half of the same rule, and the decision it rests on: `registration` and
     * `agreement_proposed` sit BEFORE FIRST_STAGE_REQUIRING_SATISFIED_TERMS and require nothing
     * beyond an agreement row that belongs to the claiming Suchak. Both record acts the SUCHAK
     * performed — he registered the customer, he put terms in front of them — and both stay true
     * after a decline. Gating them would make the ladder's first rungs depend on a later one.
     */
    public function test_registration_and_agreement_proposed_survive_a_decline(): void
    {
        [$owner, $ownerAccount] = $this->verifiedSuchakActor();
        $agreement = $this->customerAgreement(
            $ownerAccount,
            $owner,
            revision: 1,
            termsStatus: SuchakCustomerAgreement::TERMS_DECLINED,
        );

        Sanctum::actingAs($owner);

        foreach ([
            SuchakCollaborationStageEvent::STAGE_REGISTRATION,
            SuchakCollaborationStageEvent::STAGE_AGREEMENT_PROPOSED,
        ] as $stageKey) {
            $this->postJson(
                '/api/v1/suchak/customer-agreements/'.$agreement->id.'/stages',
                ['stage_key' => $stageKey],
            )->assertCreated()->assertJsonPath('data.stage_key', $stageKey);
        }

        $this->assertSame(2, SuchakCollaborationStageEvent::query()->count());
    }

    /**
     * Terms that were never accepted and never refused are not a licence either — a challenge
     * declares a share OF accepted terms (D4), so `published_to_marketplace` on a pending agreement
     * would put a price on something nobody has agreed to.
     */
    public function test_pending_terms_cannot_be_published_to_the_marketplace(): void
    {
        [$owner, $ownerAccount] = $this->verifiedSuchakActor();
        $agreement = $this->customerAgreement(
            $ownerAccount,
            $owner,
            revision: 1,
            termsStatus: SuchakCustomerAgreement::TERMS_PENDING,
        );

        Sanctum::actingAs($owner);
        $response = $this->postJson(
            '/api/v1/suchak/customer-agreements/'.$agreement->id.'/stages',
            ['stage_key' => SuchakCollaborationStageEvent::STAGE_PUBLISHED_TO_MARKETPLACE],
        );

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertStringContainsString('अजून स्वीकारलेला नाही', (string) $response->json('message'));
        $this->assertSame(0, SuchakCollaborationStageEvent::query()->count());
    }

    public function test_customer_stage_route_hides_another_suchaks_agreement(): void
    {
        [$owner, $ownerAccount] = $this->verifiedSuchakActor();
        [$stranger] = $this->verifiedSuchakActor();
        $agreement = $this->customerAgreement($ownerAccount, $owner, revision: 1);

        Sanctum::actingAs($stranger);
        $this->postJson(
            '/api/v1/suchak/customer-agreements/'.$agreement->id.'/stages',
            ['stage_key' => SuchakCollaborationStageEvent::STAGE_REGISTRATION],
        )->assertNotFound();
    }

    public function test_the_candidate_confirms_a_claimed_terminal_stage_over_the_member_route(): void
    {
        [$owner, $ownerAccount] = $this->verifiedSuchakActor();
        [, $helperAccount] = $this->verifiedSuchakActor();
        $collaboration = $this->engagementBetween($ownerAccount, $helperAccount, SuchakCollaborationRequest::STATUS_ACCEPTED);

        $this->service()->claimStage(
            $collaboration,
            $ownerAccount,
            $owner,
            SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED,
            'दोन्ही कुटुंबांनी होकार दिला.',
        );
        $this->assertNull($collaboration->fresh()->marketplace_stage);

        /** @var MatrimonyProfile $candidateProfile */
        $candidateProfile = MatrimonyProfile::query()
            ->findOrFail($collaboration->requesting_matrimony_profile_id);
        /** @var User $candidateUser */
        $candidateUser = User::query()->findOrFail($candidateProfile->user_id);

        Sanctum::actingAs($candidateUser);
        $this->postJson(
            '/api/v1/suchak-engagements/'.$collaboration->id.'/stages/confirm',
            ['stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED],
        )
            ->assertOk()
            ->assertJsonPath('data.is_settled', true)
            ->assertJsonPath('data.marketplace_stage', SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED);

        $this->assertSame(
            SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED,
            $collaboration->fresh()->marketplace_stage,
        );
    }

    public function test_a_stranger_cannot_confirm_someone_elses_terminal_stage(): void
    {
        [$owner, $ownerAccount] = $this->verifiedSuchakActor();
        [, $helperAccount] = $this->verifiedSuchakActor();
        $collaboration = $this->engagementBetween($ownerAccount, $helperAccount, SuchakCollaborationRequest::STATUS_ACCEPTED);

        $this->service()->claimStage(
            $collaboration,
            $ownerAccount,
            $owner,
            SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED,
        );

        /** @var MatrimonyProfile $strangerProfile */
        $strangerProfile = MatrimonyProfile::factory()->create();
        /** @var User $stranger */
        $stranger = User::query()->findOrFail($strangerProfile->user_id);

        Sanctum::actingAs($stranger);
        $this->postJson(
            '/api/v1/suchak-engagements/'.$collaboration->id.'/stages/confirm',
            ['stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED],
        )->assertNotFound();

        $this->assertNull($collaboration->fresh()->marketplace_stage);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────────

    private function service(): SuchakCollaborationService
    {
        return $this->app->make(SuchakCollaborationService::class);
    }

    private function engagement(SuchakAccount $requestingAccount, string $status): SuchakCollaborationRequest
    {
        return $this->engagementBetween($requestingAccount, SuchakAccount::factory()->create(), $status);
    }

    private function engagementBetween(
        SuchakAccount $requestingAccount,
        SuchakAccount $targetAccount,
        string $status,
    ): SuchakCollaborationRequest {
        /** @var SuchakCollaborationRequest $collaboration */
        $collaboration = SuchakCollaborationRequest::factory()->create([
            'requesting_suchak_account_id' => $requestingAccount->id,
            'target_suchak_account_id' => $targetAccount->id,
            'status' => $status,
            'responded_at' => $status === SuchakCollaborationRequest::STATUS_PENDING ? null : now(),
        ]);

        return $collaboration;
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
     * Makes the customer-owning side a RECORDED fact rather than the `customer_owner_side` column
     * default, the way production does it: the owner links his own customer agreement revision.
     *
     * The agreement's customer is the candidate SITTING ON THE OWNER'S SIDE of this engagement —
     * derived here from the side the owner's account actually occupies, because that is the fact
     * linkCustomerAgreement() now checks. An agreement about somebody who is on neither side is
     * refused, and that refusal is the subject of SuchakCustomerDoorTest.
     */
    private function linkOwnerAgreement(
        SuchakCollaborationRequest $collaboration,
        SuchakAccount $ownerAccount,
        User $ownerUser,
    ): SuchakCustomerAgreement {
        $ownerSide = $collaboration->sideForAccount((int) $ownerAccount->id);
        $this->assertNotNull($ownerSide, 'The owner account must be a party to this engagement.');

        $agreement = $this->customerAgreement(
            $ownerAccount,
            $ownerUser,
            revision: 1,
            candidateProfileId: $collaboration->matrimonyProfileIdForSide((string) $ownerSide),
        );

        $linked = $this->service()->linkCustomerAgreement($collaboration, $ownerAccount, $ownerUser, $agreement);
        $this->assertSame((int) $ownerAccount->id, $linked->customerOwnerSuchakAccountId());

        return $agreement;
    }

    /**
     * Every agreement here names a customer context, because a customer agreement that names no
     * customer cannot establish whose candidate an engagement is about. `$candidateProfileId` is
     * supplied whenever the agreement will be linked to an engagement; otherwise the context gets
     * a candidate of its own, which is what the pre-engagement ladder rungs run against.
     */
    private function customerAgreement(
        SuchakAccount $account,
        User $user,
        int $revision,
        string $termsStatus = SuchakCustomerAgreement::TERMS_ACCEPTED,
        ?int $candidateProfileId = null,
    ): SuchakCustomerAgreement {
        $package = SuchakServicePackage::query()->create([
            'suchak_account_id' => $account->id,
            'package_name' => 'Stage ladder fixture '.$revision,
            'price_amount' => '25000',
            'currency' => 'INR',
            'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
            'requires_admin_approval' => false,
            'customized_by_user_id' => $user->id,
            'published_at' => now(),
        ]);

        $accepted = $termsStatus === SuchakCustomerAgreement::TERMS_ACCEPTED;
        $declined = $termsStatus === SuchakCustomerAgreement::TERMS_DECLINED;

        $customerContext = SuchakCustomerContext::query()->create([
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $candidateProfileId ?? MatrimonyProfile::factory()->create()->id,
            'service_context' => SuchakCustomerContext::SERVICE_PROFILE_REPRESENTATION,
            'source_owner' => SuchakCustomerContext::SOURCE_OWNER_SUCHAK,
            'source_type' => SuchakCustomerContext::SOURCE_TYPE_MANUAL,
            'customer_lifecycle_status' => SuchakCustomerContext::STATUS_ACTIVE_SERVICE,
            'created_by_user_id' => $user->id,
            'opened_at' => now(),
        ]);

        return SuchakCustomerAgreement::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'service_package_id' => $package->id,
            'agreement_revision' => $revision,
            'terms_status' => $termsStatus,
            'terms_policy_mode' => SuchakCustomerAgreement::POLICY_STRICT,
            'agreement_snapshot_hash' => hash('sha256', 'stage-ladder-'.$package->id),
            'package_name' => $package->package_name,
            'price_amount' => '25000',
            'currency' => 'INR',
            'agreement_title' => 'Terms revision '.$revision.' ('.$termsStatus.')',
            'created_by_user_id' => $user->id,
            'accepted_by_user_id' => $accepted ? $user->id : null,
            'accepted_at' => $accepted ? now() : null,
            'declined_by_user_id' => $declined ? $user->id : null,
            'declined_at' => $declined ? now() : null,
            'decline_reason' => $declined ? 'दर मान्य नाहीत.' : null,
        ]);
    }
}
