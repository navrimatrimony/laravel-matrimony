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
use App\Models\SuchakMarriageOutcome;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCollaborationService;
use App\Modules\Suchak\Services\SuchakMarriageOutcomeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Blueprint §6.2 — the marriage, the engagement credited with it, and the two fail-open gates that
 * let a marriage claim stand on nothing.
 *
 * Phase 4's finding in one line: NOTHING recorded that a candidate married, and no column anywhere
 * held WHEN THE WEDDING HAPPENED — only when it was reported.
 */
class SuchakMarriageOutcomeTest extends TestCase
{
    use RefreshDatabase;

    // ── The gap, pinned so it cannot silently re-open ─────────────────────────────────────────

    public function test_the_wedding_date_has_exactly_one_home_and_it_is_not_a_reporting_instant(): void
    {
        // The new owner of "a marriage this platform produced".
        foreach ([
            'collaboration_request_id',
            'customer_agreement_id',
            'stage_event_id',
            'married_matrimony_profile_id',
            'spouse_matrimony_profile_id',
            'married_on',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_marriage_outcomes', $column), $column);
        }

        // The ladder rung still carries only REPORTING instants. If a wedding-date column is ever
        // added here as well, one fact has two homes and the two are free to disagree.
        foreach (['married_on', 'marriage_date', 'event_occurred_on', 'occurred_on'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('suchak_collaboration_stage_events', $column),
                'The wedding date belongs to suchak_marriage_outcomes, not to the ladder rung.',
            );
        }

        // `lifecycle_state` stays what it is — a VISIBILITY state with no date and no counterparty.
        foreach (['married_on', 'marriage_date', 'married_to_profile_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('matrimony_profiles', $column), $column);
        }

        // `profile_marriages` is the candidate's PREVIOUS marriage and knows nothing of engagements.
        $this->assertFalse(Schema::hasColumn('profile_marriages', 'collaboration_request_id'));
    }

    // ── §6.2: the row, and what it names ──────────────────────────────────────────────────────

    public function test_recording_a_marriage_names_the_engagement_the_revision_the_evidence_and_the_day(): void
    {
        $fixture = $this->linkedEngagement();
        // 20 days, not 40. The HELPER records here, and the helper is the party M3 owes the share
        // TO — a 40-day-old date typed by him is the backdating this door now refuses, so the
        // original figure made this test green on a shape the service must never accept. The fact
        // being asserted is unchanged: the wedding day is not the day it was reported.
        $weddingDay = now()->subDays(20)->toDateString();

        $outcome = $this->outcomeService()->record(
            $fixture['collaboration'],
            $fixture['helperAccount'],
            $fixture['helperUser'],
            $weddingDay,
            'लग्न पार पडले.',
        );

        // 1. The engagement credited with the introduction.
        $this->assertSame((int) $fixture['collaboration']->id, (int) $outcome->collaboration_request_id);

        // 2. The agreement REVISION in force — the terms the success fee is a fee under.
        $this->assertSame((int) $fixture['agreement']->id, (int) $outcome->customer_agreement_id);
        $this->assertSame(
            (int) $fixture['agreement']->id,
            (int) SuchakCommissionAgreement::query()
                ->where('collaboration_request_id', $fixture['collaboration']->id)
                ->value('customer_agreement_id'),
        );

        // 3. The evidence: the `marriage` rung of this same engagement, written by the same act.
        $event = $outcome->stageEvent;
        $this->assertNotNull($event);
        $this->assertSame(SuchakCollaborationStageEvent::STAGE_MARRIAGE, $event->stage_key);
        $this->assertSame((int) $fixture['collaboration']->id, (int) $event->collaboration_request_id);
        $this->assertSame((int) $fixture['helperAccount']->id, (int) $event->claimed_by_suchak_account_id);

        // 4. The two people, by ROLE and never by direction.
        $this->assertSame(
            (int) $fixture['collaboration']->customerOwnerMatrimonyProfileId(),
            (int) $outcome->married_matrimony_profile_id,
        );
        $this->assertSame(
            (int) $fixture['collaboration']->helpingMatrimonyProfileId(),
            (int) $outcome->spouse_matrimony_profile_id,
        );

        // 5. THE DATE OF THE WEDDING, which is not the day it was reported.
        $this->assertSame($weddingDay, $outcome->married_on->toDateString());
        $this->assertSame(now()->toDateString(), $event->claimed_at->toDateString());
        $this->assertNotSame($outcome->married_on->toDateString(), $event->claimed_at->toDateString());

        // A claim is a claim: nobody has confirmed it, and the row says so rather than implying it.
        $this->assertNull($event->confirmed_at);
        $this->assertFalse($outcome->isConfirmed());

        $this->assertDatabaseHas('suchak_activity_logs', [
            'action_type' => SuchakActivityLog::ACTION_MARRIAGE_OUTCOME_RECORDED,
            'target_type' => 'suchak_marriage_outcome',
            'target_id' => $outcome->id,
            // Filed under the CUSTOMER-OWNING Suchak even though the helper pressed the button.
            'suchak_account_id' => $fixture['collaboration']->customerOwnerSuchakAccountId(),
        ]);
    }

    /**
     * M3 — "a share falls due … a fixed number of days after a recorded Marriage". The clock starts
     * at the WEDDING, and it is arithmetic: no `share_due_at` column, no scheduler, correct on a
     * production where `schedule:run` has never fired.
     */
    public function test_m3_clock_runs_from_the_wedding_day_and_not_from_the_report(): void
    {
        $old = $this->linkedEngagement();
        $recent = $this->linkedEngagement();

        $longAgo = $this->outcomeService()->record(
            $old['collaboration'],
            $old['ownerAccount'],
            $old['ownerUser'],
            now()->subDays(SuchakMarriageOutcome::SHARE_DUE_DAYS_AFTER_MARRIAGE + 5)->toDateString(),
        );
        $today = $this->outcomeService()->record(
            $recent['collaboration'],
            $recent['ownerAccount'],
            $recent['ownerUser'],
            now()->toDateString(),
        );

        $this->assertSame(
            $longAgo->married_on->copy()->addDays(SuchakMarriageOutcome::SHARE_DUE_DAYS_AFTER_MARRIAGE)->toDateString(),
            $longAgo->shareFallsDueAt()->toDateString(),
        );

        // Both rows were REPORTED in the same second. Only the wedding dates differ, and only the
        // wedding dates move the obligation.
        $this->assertTrue($longAgo->isShareDueByElapsedDays());
        $this->assertFalse($today->isShareDueByElapsedDays());
    }

    public function test_a_future_wedding_and_one_predating_the_engagement_are_both_refused(): void
    {
        $fixture = $this->linkedEngagement();
        $service = $this->outcomeService();

        try {
            $service->record(
                $fixture['collaboration'],
                $fixture['ownerAccount'],
                $fixture['ownerUser'],
                now()->addDay()->toDateString(),
            );
            $this->fail('A wedding that has not happened is not a recorded marriage.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('भविष्यातील', $exception->getMessage());
        }

        try {
            $service->record(
                $fixture['collaboration'],
                $fixture['ownerAccount'],
                $fixture['ownerUser'],
                // The engagement opened six months ago; this predates the introduction it credits.
                now()->subMonths(9)->toDateString(),
            );
            $this->fail('A wedding predating the engagement cannot be credited to it.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('सुरू होण्यापूर्वीची', $exception->getMessage());
        }

        $this->assertSame(0, SuchakMarriageOutcome::query()->count());
        // And no half-written rung was left behind by either refusal.
        $this->assertSame(0, SuchakCollaborationStageEvent::query()
            ->where('stage_key', SuchakCollaborationStageEvent::STAGE_MARRIAGE)
            ->count());
    }

    /**
     * §6.2's whole reason for existing: "Two Suchaks can hold simultaneously valid representations,
     * agreements and success-fee terms on the same candidate." Both may claim a marriage on their
     * own engagement — the ladder's unique index is per (engagement, stage) and refuses neither.
     * One candidate, one marriage, one credited engagement.
     */
    public function test_one_candidate_is_credited_to_exactly_one_engagement(): void
    {
        $first = $this->linkedEngagement();
        $service = $this->outcomeService();

        $service->record(
            $first['collaboration'],
            $first['ownerAccount'],
            $first['ownerUser'],
            now()->subDays(10)->toDateString(),
        );

        // A SECOND engagement, a different helper, the SAME two candidates.
        [$rivalUser, $rivalAccount] = $this->verifiedSuchakActor();
        $second = $this->linkedEngagement(
            ownerCandidateId: (int) $first['collaboration']->customerOwnerMatrimonyProfileId(),
            helperCandidateId: (int) $first['collaboration']->helpingMatrimonyProfileId(),
            helperUser: $rivalUser,
            helperAccount: $rivalAccount,
        );

        try {
            $service->record(
                $second['collaboration'],
                $second['ownerAccount'],
                $second['ownerUser'],
                now()->subDays(10)->toDateString(),
            );
            $this->fail('A second engagement must not be credited with the same marriage.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('विवाह आधीच नोंदवला आहे', $exception->getMessage());
        }

        $this->assertSame(1, SuchakMarriageOutcome::query()->count());
    }

    public function test_the_same_engagement_cannot_record_two_marriages(): void
    {
        $fixture = $this->linkedEngagement();
        $service = $this->outcomeService();

        $service->record(
            $fixture['collaboration'],
            $fixture['ownerAccount'],
            $fixture['ownerUser'],
            now()->subDays(3)->toDateString(),
        );

        $this->expectException(InvalidArgumentException::class);
        $service->record(
            $fixture['collaboration'],
            $fixture['helperAccount'],
            $fixture['helperUser'],
            now()->subDays(2)->toDateString(),
        );
    }

    public function test_an_outcome_row_cannot_name_another_engagements_candidates_or_terms(): void
    {
        $fixture = $this->linkedEngagement();
        $other = $this->linkedEngagement();

        $outcome = $this->outcomeService()->record(
            $fixture['collaboration'],
            $fixture['ownerAccount'],
            $fixture['ownerUser'],
            now()->subDay()->toDateString(),
        );

        // The `saving` guard, exercised through a raw model write — the shape a future second
        // writer would take if it forgot the service.
        $forged = new SuchakMarriageOutcome([
            'collaboration_request_id' => $other['collaboration']->id,
            'customer_agreement_id' => $fixture['agreement']->id,
            'stage_event_id' => $outcome->stage_event_id,
            'married_matrimony_profile_id' => $other['collaboration']->customerOwnerMatrimonyProfileId(),
            'spouse_matrimony_profile_id' => $other['collaboration']->helpingMatrimonyProfileId(),
            'married_on' => now()->subDay()->toDateString(),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $forged->save();
    }

    public function test_a_marriage_outcome_can_never_be_deleted(): void
    {
        $fixture = $this->linkedEngagement();
        $outcome = $this->outcomeService()->record(
            $fixture['collaboration'],
            $fixture['ownerAccount'],
            $fixture['ownerUser'],
            now()->subDay()->toDateString(),
        );

        $this->expectException(RuntimeException::class);
        $outcome->delete();
    }

    // ── Gate (a): a terminal rung on an engagement with no agreement revision ─────────────────

    /**
     * `assertStageClaimant()` returned EARLY for CLAIMANT_EITHER_SUCHAK, above the
     * "customer_agreement_id is null" refusal. The three rungs that release the largest tranches in
     * the system were therefore the only ones exempt from it, so a §6.2 attribution could be
     * demanded of an engagement whose terms nobody had ever named.
     */
    public function test_no_either_suchak_rung_may_be_claimed_while_the_engagement_names_no_agreement(): void
    {
        [$ownerUser, $ownerAccount] = $this->verifiedSuchakActor();
        [, $helperAccount] = $this->verifiedSuchakActor();
        $collaboration = $this->engagement($ownerAccount, $helperAccount);

        foreach ([
            SuchakCollaborationStageEvent::STAGE_MEETING_SCHEDULED,
            SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED,
            SuchakCollaborationStageEvent::STAGE_ENGAGEMENT,
            SuchakCollaborationStageEvent::STAGE_MARRIAGE,
        ] as $stageKey) {
            try {
                $this->collaborationService()->claimStage($collaboration, $ownerAccount, $ownerUser, $stageKey);
                $this->fail($stageKey.' must be refused while the engagement names no agreement revision.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('ग्राहकाचा सूचक कोण हे अजून नोंदवलेले नाही', $exception->getMessage());
            }
        }

        $this->assertSame(0, SuchakCollaborationStageEvent::query()->count());
    }

    public function test_the_marriage_door_refuses_an_engagement_with_no_agreement_revision(): void
    {
        [$ownerUser, $ownerAccount] = $this->verifiedSuchakActor();
        [, $helperAccount] = $this->verifiedSuchakActor();
        $collaboration = $this->engagement($ownerAccount, $helperAccount);

        $this->expectException(InvalidArgumentException::class);
        $this->outcomeService()->record(
            $collaboration,
            $ownerAccount,
            $ownerUser,
            now()->subDay()->toDateString(),
        );
    }

    // ── Gate (b): who may confirm a marriage claim ────────────────────────────────────────────

    /**
     * `confirmationActorType()` threw for a participating Suchak's user and let EVERY OTHER
     * authenticated user through as ACTOR_USER. The candidate check that actually protected it sat
     * in MemberSuchakStageApiController, so any second door onto confirmStage() would have let a
     * stranger with a login settle a marriage claim. The refusal is now in the service.
     */
    public function test_only_a_candidate_or_an_admin_can_confirm_a_terminal_claim(): void
    {
        $fixture = $this->linkedEngagement();
        $service = $this->collaborationService();

        $service->claimStage(
            $fixture['collaboration'],
            $fixture['helperAccount'],
            $fixture['helperUser'],
            SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED,
        );

        // A stranger who merely holds a login. This used to succeed — at the SERVICE level, with
        // only a controller between it and production.
        $stranger = User::factory()->create();
        try {
            $service->confirmStage(
                $fixture['collaboration'],
                $stranger,
                SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED,
            );
            $this->fail('A stranger with a login must not confirm somebody else\'s marriage claim.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('उमेदवार स्वतः', $exception->getMessage());
        }

        // A participating Suchak is still refused first, so a Suchak who is also a candidate on his
        // own engagement cannot slip through the candidate door.
        try {
            $service->confirmStage(
                $fixture['collaboration'],
                $fixture['helperUser'],
                SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED,
            );
            $this->fail('A participating Suchak must not confirm their own claim.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('participating Suchak', $exception->getMessage());
        }

        $this->assertNull($fixture['collaboration']->fresh()->marketplace_stage);

        // The candidate himself may.
        $candidateUser = $this->candidateUser($fixture['collaboration']);
        $confirmed = $service->confirmStage(
            $fixture['collaboration'],
            $candidateUser,
            SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED,
        );
        $this->assertTrue($confirmed->isSettled());
    }

    public function test_an_admin_may_still_confirm_in_the_customers_place(): void
    {
        $fixture = $this->linkedEngagement();
        $service = $this->collaborationService();

        $service->claimStage(
            $fixture['collaboration'],
            $fixture['helperAccount'],
            $fixture['helperUser'],
            SuchakCollaborationStageEvent::STAGE_ENGAGEMENT,
        );

        /** @var User $admin */
        $admin = User::factory()->create(['is_admin' => true]);
        $confirmed = $service->confirmStage(
            $fixture['collaboration'],
            $admin,
            SuchakCollaborationStageEvent::STAGE_ENGAGEMENT,
        );

        $this->assertSame(SuchakActivityLog::ACTOR_ADMIN, $confirmed->confirmed_by_actor_type);
    }

    // ── The door ─────────────────────────────────────────────────────────────────────────────

    public function test_the_marriage_has_a_route_and_the_generic_stage_route_no_longer_accepts_it(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => strtoupper(implode('|', $route->methods())).' /'.ltrim($route->uri(), '/'))
            ->all();

        foreach (['POST', 'GET'] as $method) {
            $this->assertTrue(
                collect($routes)->contains(fn (string $row): bool => str_starts_with($row, $method)
                    && str_contains($row, ' /api/v1/suchak/collaborations/{collaboration}/marriage')),
                $method.' marriage route is missing',
            );
        }

        $fixture = $this->linkedEngagement();
        Sanctum::actingAs($fixture['helperUser']);

        // The generic ladder door no longer knows this key — one rung, one door.
        $this->postJson('/api/v1/suchak/collaborations/'.$fixture['collaboration']->id.'/stages', [
            'stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE,
        ])->assertStatus(422)->assertJsonValidationErrors('stage_key');

        $this->assertSame(0, SuchakCollaborationStageEvent::query()->count());
    }

    public function test_the_marriage_route_records_and_reads_back_the_attribution(): void
    {
        $fixture = $this->linkedEngagement();
        $weddingDay = now()->subDays(45)->toDateString();

        // Posted by the CUSTOMER-OWNING Suchak, not the helper. A 45-day-old wedding is older than
        // M3's own window, so `share_due_by_elapsed_days` is true the moment it is recorded — and
        // the only party who may say that is the one who OWES the share, never the one owed it.
        // The original version of this test posted it as the helper and asserted the same overdue
        // flag, which is exactly the abuse the date rule now refuses.
        Sanctum::actingAs($fixture['ownerUser']);
        $this->postJson('/api/v1/suchak/collaborations/'.$fixture['collaboration']->id.'/marriage', [
            'married_on' => $weddingDay,
            'event_note' => 'विवाह पार पडला.',
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.married_on', $weddingDay)
            ->assertJsonPath('data.is_confirmed', false)
            ->assertJsonPath('data.customer_agreement_id', (int) $fixture['agreement']->id)
            ->assertJsonPath('data.credited_customer_owner_suchak_account_id', (int) $fixture['ownerAccount']->id)
            ->assertJsonPath('data.credited_helping_suchak_account_id', (int) $fixture['helperAccount']->id)
            ->assertJsonPath('data.evidence_stage_key', SuchakCollaborationStageEvent::STAGE_MARRIAGE)
            ->assertJsonPath('data.share_due_days_after_marriage', SuchakMarriageOutcome::SHARE_DUE_DAYS_AFTER_MARRIAGE)
            ->assertJsonPath('data.share_due_by_elapsed_days', true);

        $this->getJson('/api/v1/suchak/collaborations/'.$fixture['collaboration']->id.'/marriage')
            ->assertOk()
            ->assertJsonPath('data.married_on', $weddingDay);

        // The §6.2 row exists NOW — M3's clock is running — but the ladder has NOT advanced,
        // because `marriage` is confirmable (D26) and nobody has confirmed it. That split is the
        // point: M3's cross-Suchak share runs from the recorded marriage so silence cannot kill it,
        // while M4 keeps the customer's own fee waiting for the customer.
        $this->assertNull($fixture['collaboration']->fresh()->marketplace_stage);
        $this->assertTrue(SuchakMarriageOutcome::query()
            ->where('collaboration_request_id', $fixture['collaboration']->id)
            ->exists());
    }

    public function test_the_marriage_route_hides_another_suchaks_engagement(): void
    {
        $fixture = $this->linkedEngagement();
        [$stranger] = $this->verifiedSuchakActor();

        Sanctum::actingAs($stranger);
        $this->postJson('/api/v1/suchak/collaborations/'.$fixture['collaboration']->id.'/marriage', [
            'married_on' => now()->subDay()->toDateString(),
        ])->assertNotFound();

        $this->getJson('/api/v1/suchak/collaborations/'.$fixture['collaboration']->id.'/marriage')
            ->assertNotFound();

        $this->assertSame(0, SuchakMarriageOutcome::query()->count());
    }

    public function test_reading_a_marriage_that_was_never_recorded_is_a_404_not_a_row_of_nulls(): void
    {
        $fixture = $this->linkedEngagement();

        Sanctum::actingAs($fixture['ownerUser']);
        $this->getJson('/api/v1/suchak/collaborations/'.$fixture['collaboration']->id.'/marriage')
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    // ── BLOCKER 1: the rung and the row succeed or fail together ──────────────────────────────

    /**
     * `record()` claimed the `marriage` rung through `claimStage()` — which commits its OWN
     * transaction — and only then opened the transaction that writes the §6.2 row. A refusal in the
     * second left the first standing: a `marriage` rung on an engagement that can never carry an
     * attribution, and `SuchakSuccessFeeTrancheService` keys the whole release on settled RUNGS and
     * never reads this table, so one confirmation on that orphan would release the entire success
     * fee with nothing naming who earned it.
     */
    public function test_a_refused_recording_leaves_no_marriage_rung_standing(): void
    {
        $first = $this->linkedEngagement();
        $service = $this->outcomeService();

        $service->record(
            $first['collaboration'],
            $first['ownerAccount'],
            $first['ownerUser'],
            now()->subDays(10)->toDateString(),
        );

        [$rivalUser, $rivalAccount] = $this->verifiedSuchakActor();
        $second = $this->linkedEngagement(
            ownerCandidateId: (int) $first['collaboration']->customerOwnerMatrimonyProfileId(),
            helperCandidateId: (int) $first['collaboration']->helpingMatrimonyProfileId(),
            helperUser: $rivalUser,
            helperAccount: $rivalAccount,
        );

        try {
            $service->record(
                $second['collaboration'],
                $second['ownerAccount'],
                $second['ownerUser'],
                now()->subDays(10)->toDateString(),
            );
            $this->fail('The second engagement must not be credited with the same marriage.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(1, SuchakMarriageOutcome::query()->count());

        // THE POINT: the refusal must have taken the rung with it.
        $this->assertSame(
            0,
            SuchakCollaborationStageEvent::query()
                ->where('collaboration_request_id', $second['collaboration']->id)
                ->where('stage_key', SuchakCollaborationStageEvent::STAGE_MARRIAGE)
                ->count(),
            'A refused recording must leave no marriage rung behind — that rung releases the fee.',
        );
    }

    /**
     * The same invariant from the other end, and the guard that makes it hold for every path that
     * ever exists: a `marriage` rung with no LIVE §6.2 row behind it cannot be confirmed, so it can
     * never settle and can never release a tranche.
     */
    public function test_a_marriage_rung_with_no_live_attribution_cannot_be_confirmed(): void
    {
        $fixture = $this->linkedEngagement();

        // A rung claimed through the generic writer — the shape production data from before this
        // door existed still has.
        $this->collaborationService()->claimStage(
            $fixture['collaboration'],
            $fixture['helperAccount'],
            $fixture['helperUser'],
            SuchakCollaborationStageEvent::STAGE_MARRIAGE,
        );

        $candidateUser = $this->candidateUser($fixture['collaboration']);

        try {
            $this->collaborationService()->confirmStage(
                $fixture['collaboration'],
                $candidateUser,
                SuchakCollaborationStageEvent::STAGE_MARRIAGE,
            );
            $this->fail('A marriage rung with no attribution row must not be confirmable.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('विवाहाची नोंद', $exception->getMessage());
        }

        // Attaching an attribution to a rung SOMEBODY ELSE claimed takes the stronger gate —
        // `assertAcceptedParticipant()`, which needs both commission acknowledgements.
        $this->acknowledgeCommissionBothSides($fixture['collaboration']);

        // Attach the attribution the rung was missing, and the same confirmation now succeeds.
        $this->outcomeService()->record(
            $fixture['collaboration'],
            $fixture['ownerAccount'],
            $fixture['ownerUser'],
            now()->subDays(3)->toDateString(),
        );

        $confirmed = $this->collaborationService()->confirmStage(
            $fixture['collaboration'],
            $candidateUser,
            SuchakCollaborationStageEvent::STAGE_MARRIAGE,
        );
        $this->assertTrue($confirmed->isSettled());
    }

    // ── BLOCKER 2: the payee may not choose the date that starts his own clock ────────────────

    /**
     * `marriage` is CLAIMANT_EITHER_SUCHAK (D26), and M3 runs the cross-Suchak share clock from
     * `married_on + 30 days`. So the HELPER — the party the share is owed TO — could record the
     * wedding himself, type a date already older than his own deadline, and have his share overdue
     * the second it was written.
     */
    public function test_the_beneficiary_cannot_backdate_the_wedding_past_his_own_share_deadline(): void
    {
        $fixture = $this->linkedEngagement();
        $alreadyOverdue = now()->subDays(SuchakMarriageOutcome::SHARE_DUE_DAYS_AFTER_MARRIAGE + 5)->toDateString();

        try {
            $this->outcomeService()->record(
                $fixture['collaboration'],
                $fixture['helperAccount'],
                $fixture['helperUser'],
                $alreadyOverdue,
            );
            $this->fail('The payee must not type a date that makes his own share overdue on day one.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('स्वतःच्या वाट्याची मुदत', $exception->getMessage());
        }

        $this->assertSame(0, SuchakMarriageOutcome::query()->count());
        $this->assertSame(0, SuchakCollaborationStageEvent::query()
            ->where('stage_key', SuchakCollaborationStageEvent::STAGE_MARRIAGE)
            ->count());

        // The helper may still record a wedding whose clock has time left on it — M3's obligation
        // is never killed, only its head start is.
        $withinWindow = $this->outcomeService()->record(
            $fixture['collaboration'],
            $fixture['helperAccount'],
            $fixture['helperUser'],
            now()->subDays(SuchakMarriageOutcome::SHARE_DUE_DAYS_AFTER_MARRIAGE)->toDateString(),
        );
        $this->assertFalse($withinWindow->isShareDueByElapsedDays());
    }

    /**
     * The other side of the same rule. The CUSTOMER-OWNING Suchak is the party the share is owed BY,
     * so an old date he types runs his own clock out sooner — a statement against his own interest,
     * which is the only backdating this platform can believe. M3's "suppressing the record must
     * ACCELERATE the obligation" is exactly this door.
     */
    public function test_the_payer_may_still_record_a_wedding_older_than_the_share_deadline(): void
    {
        $fixture = $this->linkedEngagement();

        $outcome = $this->outcomeService()->record(
            $fixture['collaboration'],
            $fixture['ownerAccount'],
            $fixture['ownerUser'],
            now()->subDays(SuchakMarriageOutcome::SHARE_DUE_DAYS_AFTER_MARRIAGE + 5)->toDateString(),
        );

        $this->assertTrue($outcome->isShareDueByElapsedDays());
    }

    /**
     * The date rule lived only in the controller's `['required','date']`. The SERVICE took a blank
     * string, handed it to `Carbon::parse()` — which answers NOW for an empty string — and recorded
     * TODAY as the wedding day, a date nobody typed.
     */
    public function test_the_service_refuses_a_wedding_date_nobody_supplied(): void
    {
        $fixture = $this->linkedEngagement();

        foreach (['', '   ', null] as $empty) {
            try {
                $this->outcomeService()->record(
                    $fixture['collaboration'],
                    $fixture['ownerAccount'],
                    $fixture['ownerUser'],
                    $empty,
                );
                $this->fail('A marriage with no date is a marriage M3 cannot put a clock on.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('विवाहाची तारीख', $exception->getMessage());
            }
        }

        $this->assertSame(0, SuchakMarriageOutcome::query()->count());
        $this->assertSame(0, SuchakCollaborationStageEvent::query()->count());
    }

    // ── BLOCKER 3: a competing claim, and the door out of a wrong one ─────────────────────────

    /**
     * §6.2 opens with "two Suchaks can hold simultaneously valid representations … on the same
     * candidate", so a rival Suchak holding his own engagement on candidate X may claim a marriage
     * on it. The candidate UNIQUE indexes admit no second row and nothing could ever undo the
     * first — so an UNCONFIRMED claim by the wrong Suchak destroyed the real engagement's
     * attribution permanently, and the largest sum in the system was decided by who tapped first.
     */
    public function test_a_rival_unconfirmed_claim_does_not_own_the_candidate_forever(): void
    {
        $real = $this->linkedEngagement();
        $sharedCandidateId = (int) $real['collaboration']->customerOwnerMatrimonyProfileId();

        // A DIFFERENT Suchak, a different engagement, a different spouse — the same candidate.
        $rival = $this->linkedEngagement(ownerCandidateId: $sharedCandidateId);
        $service = $this->outcomeService();

        $rivalOutcome = $service->record(
            $rival['collaboration'],
            $rival['ownerAccount'],
            $rival['ownerUser'],
            now()->subDays(6)->toDateString(),
        );
        $this->assertFalse($rivalOutcome->isConfirmed());

        // The real engagement is refused, and the refusal now names the door instead of being final.
        try {
            $service->record(
                $real['collaboration'],
                $real['ownerAccount'],
                $real['ownerUser'],
                now()->subDays(6)->toDateString(),
            );
            $this->fail('One candidate, one live attribution.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('विवाह आधीच नोंदवला आहे', $exception->getMessage());
        }

        // Only an admin may open the correction door.
        try {
            $service->voidClaim($rivalOutcome, $rival['ownerUser'], 'माझी चूक झाली.');
            $this->fail('A Suchak must not be able to void an attribution.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('प्रशासक', $exception->getMessage());
        }

        /** @var User $admin */
        $admin = User::factory()->create(['is_admin' => true]);
        $voided = $service->voidClaim($rivalOutcome, $admin, 'चुकीच्या सहकार्यावर नोंद झाली होती.');

        $this->assertTrue($voided->isVoided());
        $this->assertSame((int) $admin->id, (int) $voided->voided_by_user_id);
        // Set aside, never erased — the wrong claim is still readable beside the right one.
        $this->assertSame(1, SuchakMarriageOutcome::includingVoided()->count());
        $this->assertSame(0, SuchakMarriageOutcome::query()->count());

        // And now the rightful engagement can record what actually happened.
        $correct = $service->record(
            $real['collaboration'],
            $real['ownerAccount'],
            $real['ownerUser'],
            now()->subDays(6)->toDateString(),
        );

        $this->assertSame((int) $real['collaboration']->id, (int) $correct->collaboration_request_id);
        $this->assertSame($sharedCandidateId, (int) $correct->married_matrimony_profile_id);
        $this->assertSame(1, SuchakMarriageOutcome::query()->count());
        $this->assertSame(2, SuchakMarriageOutcome::includingVoided()->count());

        // The rival's rung outlives the void — and cannot settle, because nothing live attributes it.
        try {
            $this->collaborationService()->confirmStage(
                $rival['collaboration'],
                $this->candidateUser($rival['collaboration']),
                SuchakCollaborationStageEvent::STAGE_MARRIAGE,
            );
            $this->fail('A voided claim must not be confirmable.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('विवाहाची नोंद', $exception->getMessage());
        }
    }

    /**
     * The door does NOT open on a claim the family already confirmed. D26's confirmation is what
     * turns a Suchak's word into an attribution; an admin who could set that aside would be able to
     * take a settled success fee off the Suchak who earned it.
     */
    public function test_a_confirmed_attribution_has_no_correction_door(): void
    {
        $fixture = $this->linkedEngagement();
        $service = $this->outcomeService();

        $outcome = $service->record(
            $fixture['collaboration'],
            $fixture['ownerAccount'],
            $fixture['ownerUser'],
            now()->subDays(4)->toDateString(),
        );

        $this->collaborationService()->confirmStage(
            $fixture['collaboration'],
            $this->candidateUser($fixture['collaboration']),
            SuchakCollaborationStageEvent::STAGE_MARRIAGE,
        );

        /** @var User $admin */
        $admin = User::factory()->create(['is_admin' => true]);

        try {
            $service->voidClaim($outcome->fresh(), $admin, 'नंतर वाद निघाला.');
            $this->fail('A confirmed attribution is not correctable by voiding it.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('दुजोरा', $exception->getMessage());
        }

        $this->assertFalse($outcome->fresh()->isVoided());
    }

    public function test_the_correction_door_has_an_admin_route_and_refuses_everyone_else(): void
    {
        $fixture = $this->linkedEngagement();
        $outcome = $this->outcomeService()->record(
            $fixture['collaboration'],
            $fixture['ownerAccount'],
            $fixture['ownerUser'],
            now()->subDays(2)->toDateString(),
        );

        // A Suchak with a login is not an admin.
        Sanctum::actingAs($fixture['ownerUser']);
        $this->postJson('/api/v1/admin/suchak/marriage-outcomes/'.$outcome->id.'/void', [
            'void_reason' => 'मलाच मागे घ्यायचे आहे.',
        ])->assertForbidden();

        /** @var User $admin */
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        // A reason is not optional: a correction with no stated reason is an erasure.
        $this->postJson('/api/v1/admin/suchak/marriage-outcomes/'.$outcome->id.'/void', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('void_reason');

        $this->postJson('/api/v1/admin/suchak/marriage-outcomes/'.$outcome->id.'/void', [
            'void_reason' => 'चुकीच्या सहकार्यावर नोंद झाली होती.',
        ])
            ->assertOk()
            ->assertJsonPath('data.is_voided', true)
            ->assertJsonPath('data.void_reason', 'चुकीच्या सहकार्यावर नोंद झाली होती.');

        $this->assertSame(0, SuchakMarriageOutcome::query()->count());
    }

    /**
     * `confirmStage()`'s docblock said "an admin may confirm in their place" and the ACTOR_ADMIN
     * branch existed — but the only door onto that method requires the caller's own matrimony
     * profile to be one of the two candidates, so no admin could ever reach it. §2's family with no
     * login had nobody who could confirm at all. The sentence is true now because this route exists.
     */
    public function test_an_admin_has_a_real_door_onto_confirming_a_terminal_claim(): void
    {
        $fixture = $this->linkedEngagement();

        $this->collaborationService()->claimStage(
            $fixture['collaboration'],
            $fixture['helperAccount'],
            $fixture['helperUser'],
            SuchakCollaborationStageEvent::STAGE_ENGAGEMENT,
        );

        Sanctum::actingAs($fixture['helperUser']);
        $this->postJson('/api/v1/admin/suchak-engagements/'.$fixture['collaboration']->id.'/stages/confirm', [
            'stage_key' => SuchakCollaborationStageEvent::STAGE_ENGAGEMENT,
        ])->assertForbidden();

        /** @var User $admin */
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/suchak-engagements/'.$fixture['collaboration']->id.'/stages/confirm', [
            'stage_key' => SuchakCollaborationStageEvent::STAGE_ENGAGEMENT,
        ])->assertOk()->assertJsonPath('data.is_settled', true);

        $this->assertDatabaseHas('suchak_collaboration_stage_events', [
            'collaboration_request_id' => $fixture['collaboration']->id,
            'stage_key' => SuchakCollaborationStageEvent::STAGE_ENGAGEMENT,
            'confirmed_by_actor_type' => SuchakActivityLog::ACTOR_ADMIN,
        ]);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────────

    private function collaborationService(): SuchakCollaborationService
    {
        return $this->app->make(SuchakCollaborationService::class);
    }

    private function outcomeService(): SuchakMarriageOutcomeService
    {
        return $this->app->make(SuchakMarriageOutcomeService::class);
    }

    /**
     * Both sides acknowledge the commission terms — what `canExchangeContact()` reads, and therefore
     * what `assertAcceptedParticipant()` needs before one Suchak may complete an act the other
     * started.
     */
    private function acknowledgeCommissionBothSides(SuchakCollaborationRequest $collaboration): void
    {
        SuchakCommissionAgreement::query()
            ->where('collaboration_request_id', $collaboration->id)
            ->update([
                'agreement_status' => SuchakCommissionAgreement::STATUS_ACCEPTED,
                'accepted_by_groom_suchak_at' => now(),
                'accepted_by_bride_suchak_at' => now(),
            ]);
    }

    private function candidateUser(SuchakCollaborationRequest $collaboration): User
    {
        /** @var MatrimonyProfile $profile */
        $profile = MatrimonyProfile::query()->findOrFail($collaboration->customerOwnerMatrimonyProfileId());

        return User::query()->findOrFail($profile->user_id);
    }

    private function engagement(
        SuchakAccount $requestingAccount,
        SuchakAccount $targetAccount,
        ?int $requestingCandidateId = null,
        ?int $targetCandidateId = null,
    ): SuchakCollaborationRequest {
        $attributes = [
            'requesting_suchak_account_id' => $requestingAccount->id,
            'target_suchak_account_id' => $targetAccount->id,
            'status' => SuchakCollaborationRequest::STATUS_ACCEPTED,
            // Six months back, so a wedding recorded "40 days ago" is inside the engagement's life
            // and the predates-the-introduction refusal is exercised deliberately, never by accident.
            'requested_at' => now()->subMonths(6),
            'responded_at' => now()->subMonths(6),
        ];

        if ($requestingCandidateId !== null) {
            $attributes['requesting_matrimony_profile_id'] = $requestingCandidateId;
        }

        if ($targetCandidateId !== null) {
            $attributes['target_matrimony_profile_id'] = $targetCandidateId;
        }

        /** @var SuchakCollaborationRequest $collaboration */
        $collaboration = SuchakCollaborationRequest::factory()->create($attributes);

        return $collaboration;
    }

    /**
     * An accepted engagement whose customer-owning side is a RECORDED FACT — the owner has linked
     * his own customer agreement revision, which is what every rung from here on requires.
     *
     * The owner is the REQUESTING side of the pair, so his customer's candidate is the requesting
     * profile; the helper's candidate is the target profile.
     *
     * @return array{
     *     ownerUser: User, ownerAccount: SuchakAccount,
     *     helperUser: User, helperAccount: SuchakAccount,
     *     collaboration: SuchakCollaborationRequest, agreement: SuchakCustomerAgreement
     * }
     */
    private function linkedEngagement(
        ?int $ownerCandidateId = null,
        ?int $helperCandidateId = null,
        ?User $helperUser = null,
        ?SuchakAccount $helperAccount = null,
    ): array {
        [$ownerUser, $ownerAccount] = $this->verifiedSuchakActor();
        if ($helperUser === null || $helperAccount === null) {
            [$helperUser, $helperAccount] = $this->verifiedSuchakActor();
        }

        $collaboration = $this->engagement(
            $ownerAccount,
            $helperAccount,
            requestingCandidateId: $ownerCandidateId,
            targetCandidateId: $helperCandidateId,
        );

        $agreement = $this->customerAgreement(
            $ownerAccount,
            $ownerUser,
            candidateProfileId: (int) $collaboration->requesting_matrimony_profile_id,
        );

        $linked = $this->collaborationService()->linkCustomerAgreement(
            $collaboration,
            $ownerAccount,
            $ownerUser,
            $agreement,
        );
        $this->assertSame((int) $ownerAccount->id, $linked->customerOwnerSuchakAccountId());

        return [
            'ownerUser' => $ownerUser,
            'ownerAccount' => $ownerAccount,
            'helperUser' => $helperUser,
            'helperAccount' => $helperAccount,
            'collaboration' => $linked,
            'agreement' => $agreement,
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

    private function customerAgreement(
        SuchakAccount $account,
        User $user,
        int $candidateProfileId,
        int $revision = 1,
    ): SuchakCustomerAgreement {
        $package = SuchakServicePackage::query()->create([
            'suchak_account_id' => $account->id,
            'package_name' => 'Marriage outcome fixture '.$revision,
            'price_amount' => '25000',
            'currency' => 'INR',
            'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
            'requires_admin_approval' => false,
            'customized_by_user_id' => $user->id,
            'published_at' => now(),
        ]);

        $customerContext = SuchakCustomerContext::query()->create([
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $candidateProfileId,
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
            'terms_status' => SuchakCustomerAgreement::TERMS_ACCEPTED,
            'terms_policy_mode' => 'strict',
            'agreement_snapshot_hash' => hash('sha256', 'marriage-outcome-'.$package->id),
            'package_name' => $package->package_name,
            'price_amount' => '25000',
            'currency' => 'INR',
            'agreement_title' => 'Accepted terms revision '.$revision,
            'created_by_user_id' => $user->id,
            'accepted_by_user_id' => $user->id,
            'accepted_at' => now(),
        ]);
    }
}
