<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakCommissionAgreement;
use App\Models\SuchakCrossSuchakObligation;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPlan;
use App\Models\SuchakMarketplaceChallenge;
use App\Models\SuchakMarriageOutcome;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakServicePackage;
use App\Models\SuchakSuccessFeeTranche;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCollaborationService;
use App\Modules\Suchak\Services\SuchakCrossSuchakObligationService;
use App\Modules\Suchak\Services\SuchakMarketplaceChallengeService;
use App\Modules\Suchak\Services\SuchakMarriageOutcomeService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * "SUCHAK A OWES SUCHAK B" — blueprint §7 M2, M3, M9, M10 and §9a A7.
 *
 * Before `suchak_cross_suchak_obligations` nothing in this schema could say that sentence. Every
 * money object named exactly ONE Suchak account and none had a payer, and this class pins that
 * finding as an assertion rather than a comment (`test_no_existing_money_table_can_name_a_payer`),
 * so a later "just add a column to the ledger entry" has something to trip over.
 *
 * The two rules under test are the ones that were unsatisfiable in both halves:
 *
 *   M2   the only cross-Suchak obligation is the share the declarer DECLARED IN ADVANCE
 *   M3   it falls due when the customer has PAID, or a fixed number of days after a RECORDED
 *        MARRIAGE, whichever is earlier — and suppressing the record must ACCELERATE it
 */
class SuchakCrossSuchakObligationTest extends TestCase
{
    use RefreshDatabase;

    // ── The object that did not exist ────────────────────────────────────────────────────────

    public function test_no_existing_money_table_can_name_a_payer(): void
    {
        // Platform → one Suchak. Customer → one Suchak. Suchak's receivable against a PERSON.
        $this->assertFalse(Schema::hasColumn('suchak_platform_payouts', 'payer_suchak_account_id'));
        $this->assertFalse(Schema::hasColumn('suchak_customer_payments', 'payer_suchak_account_id'));
        $this->assertFalse(Schema::hasColumn('suchak_ledger_entries', 'payer_suchak_account_id'));

        // The ledger entry's shape is why a payer column could not simply be bolted on: it is keyed
        // to a CANDIDATE, NOT NULL, and a cross-Suchak share is not owed by a family.
        $this->assertTrue(Schema::hasColumn('suchak_ledger_entries', 'matrimony_profile_id'));

        // And the customer payment still cannot say WHICH fee it paid — deliberately. That fact has
        // one owner, `suchak_success_fee_tranches.customer_payment_id`, and a `fee_type` column here
        // would be a second home for it.
        $this->assertFalse(Schema::hasColumn('suchak_customer_payments', 'fee_type'));
        $this->assertTrue(Schema::hasColumn('suchak_success_fee_tranches', 'customer_payment_id'));

        // The acceptance-only commission agreement has no settlement vocabulary at all.
        $this->assertFalse(Schema::hasColumn('suchak_commission_agreements', 'settled_at'));
        $this->assertFalse(Schema::hasColumn('suchak_commission_agreements', 'due_date'));
    }

    public function test_the_declared_share_becomes_a_row_that_names_a_payer_and_a_payee(): void
    {
        $fixture = $this->marriedMarketplaceEngagement();

        $obligations = $this->obligationService()->raise(
            $fixture['collaboration'],
            $fixture['publisher'],
            $fixture['publisherUser'],
        );

        $this->assertCount(1, $obligations);
        $obligation = $obligations[0];

        // THE SENTENCE. A pays B, and both are named on one row.
        $this->assertSame((int) $fixture['publisher']->id, (int) $obligation->payer_suchak_account_id);
        $this->assertSame((int) $fixture['helper']->id, (int) $obligation->payee_suchak_account_id);

        // 30% of the ₹1,00,000 frozen success fee (§7.1's worked example).
        $this->assertSame('30000.00', (string) $obligation->amount);
        $this->assertSame('INR', (string) $obligation->currency);

        // THE ORIGIN — engagement, marriage, declaration. All three, none of them optional.
        $this->assertSame((int) $fixture['collaboration']->id, (int) $obligation->collaboration_request_id);
        $this->assertSame((int) $fixture['outcome']->id, (int) $obligation->marriage_outcome_id);
        $this->assertSame((int) $fixture['challenge']->id, (int) $obligation->marketplace_challenge_id);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $fixture['publisher']->id,
            'action_type' => SuchakActivityLog::ACTION_CROSS_SUCHAK_OBLIGATION_RAISED,
            'target_type' => 'suchak_cross_suchak_obligation',
        ]);
    }

    public function test_the_payer_and_payee_are_roles_not_directions(): void
    {
        $fixture = $this->marriedMarketplaceEngagement();

        // On a challenge answered by proposing, the HELPER is the REQUESTER. Reading the direction
        // as the role would point the debt backwards exactly when the money is largest.
        $this->assertSame((int) $fixture['helper']->id, (int) $fixture['collaboration']->requesting_suchak_account_id);
        $this->assertSame((int) $fixture['publisher']->id, (int) $fixture['collaboration']->target_suchak_account_id);

        $obligation = $this->obligationService()->raise(
            $fixture['collaboration'],
            $fixture['publisher'],
            $fixture['publisherUser'],
        )[0];

        $this->assertSame((int) $fixture['publisher']->id, (int) $obligation->payer_suchak_account_id);

        // And the row refuses to be re-pointed, for any writer that ever exists.
        $this->expectException(InvalidArgumentException::class);
        $obligation->forceFill([
            'payer_suchak_account_id' => $fixture['helper']->id,
            'payee_suchak_account_id' => $fixture['publisher']->id,
        ])->save();
    }

    // ── An obligation may not exist ahead of the thing it is a share of ─────────────────────

    public function test_an_unconfirmed_marriage_claim_raises_no_obligation_at_all(): void
    {
        // The payee records the wedding himself — `STAGE_MARRIAGE` is CLAIMANT_EITHER_SUCHAK, so he
        // can — types the date, and asks for his own share. Nobody has confirmed anything.
        $fixture = $this->marriedMarketplaceEngagement(marriedDaysAgo: 40, confirmMarriage: false);

        $this->assertFalse(
            $fixture['outcome']->isConfirmed(),
            'The fixture confirmed the rung it was asked to leave as a claim.',
        );

        try {
            $this->obligationService()->raise(
                $fixture['collaboration'],
                $fixture['helper'],
                $fixture['helperUser'],
            );
            $this->fail('An unconfirmed marriage claim manufactured a cross-Suchak debt.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('निश्चित झालेली नाही', $exception->getMessage());
        }

        // Nothing was written, so nothing has to be un-written — which matters here more than
        // usual, because SuchakCrossSuchakObligation::delete() throws.
        $this->assertSame(0, SuchakCrossSuchakObligation::query()->count());

        // …and A7 does not report the payer as a defaulter for a debt that does not exist.
        $ratio = $this->obligationService()->declarerRatio((int) $fixture['publisher']->id);
        $this->assertTrue($ratio['is_new']);
        $this->assertSame(0, $ratio['declared_obligation_count']);
    }

    public function test_the_same_marriage_confirmed_raises_the_share_immediately(): void
    {
        $fixture = $this->marriedMarketplaceEngagement(marriedDaysAgo: 40, confirmMarriage: false);

        // §2's family has no login, so an admin stands in — the only route until Phase 6's OTP.
        $this->collaborationService()->confirmStage(
            $fixture['collaboration'],
            $this->adminUser(),
            SuchakCollaborationStageEvent::STAGE_MARRIAGE,
        );

        $obligations = $this->obligationService()->raise(
            $fixture['collaboration'],
            $fixture['helper'],
            $fixture['helperUser'],
        );

        $this->assertCount(1, $obligations);
        $this->assertSame('30000.00', (string) $obligations[0]->amount);
        // M3 half B is untouched by the gate: the clock still runs from the WEDDING DAY, so a
        // marriage confirmed late is already overdue rather than granted a fresh thirty days.
        $this->assertTrue($obligations[0]->isOverdue());
    }

    public function test_a_tranche_whose_trigger_can_never_release_is_never_billed_as_a_share(): void
    {
        // `share_settled` sits ABOVE SuchakSuccessFeeTrancheService::LAST_RELEASING_STAGE, so the
        // ledger can never release this tranche — the customer will never owe it, and a share of it
        // is a debt for a fee nobody can collect.
        $fixture = $this->marriedMarketplaceEngagement(tranchePlan: [
            [SuchakCollaborationStageEvent::STAGE_ENGAGEMENT, '40.00', false],
            [SuchakCollaborationStageEvent::STAGE_SHARE_SETTLED, '60.00', true],
        ]);

        $obligations = $this->obligationService()->raise(
            $fixture['collaboration'],
            $fixture['helper'],
            $fixture['helperUser'],
        );

        // Only the साखरपुडा tranche, released by M10's cascade off the confirmed wedding.
        $this->assertCount(1, $obligations);
        $this->assertSame(
            SuchakCollaborationStageEvent::STAGE_ENGAGEMENT,
            $obligations[0]->successFeeTranche?->trigger_stage_key,
        );
        // 40% of the ₹30,000 declared share — a slice of the WHOLE plan (T1), never re-cut to make
        // the collectible rows add back up to the declared total.
        $this->assertSame('12000.00', (string) $obligations[0]->amount);
        $this->assertSame(1, SuchakCrossSuchakObligation::query()->count());
    }

    // ── The obligation and the ledger read the SAME agreement revision ──────────────────────

    public function test_the_obligation_lands_on_the_revision_the_ledger_actually_settles(): void
    {
        $fixture = $this->marriedMarketplaceEngagement(marriedDaysAgo: 2, tranchePlan: [
            [SuchakCollaborationStageEvent::STAGE_MARRIAGE, '100.00', true],
        ]);

        $boundTranche = SuchakSuccessFeeTranche::query()
            ->where('customer_agreement_id', $fixture['agreement']->id)
            ->firstOrFail();
        [$revisionTwo, $liveTranches] = $this->supersedingRevision($fixture['agreement']);

        $obligation = $this->obligationService()->raise(
            $fixture['collaboration'],
            $fixture['helper'],
            $fixture['helperUser'],
        )[0];

        // The §6.2 row is BOUND to revision 1 for attribution — that is right and unchanged.
        $this->assertSame((int) $fixture['agreement']->id, (int) $fixture['outcome']->customer_agreement_id);
        // The MONEY is on revision 2, because that is where release and settlement write.
        $this->assertSame((int) $liveTranches[0]->id, (int) $obligation->success_fee_tranche_id);
        $this->assertNotSame((int) $boundTranche->id, (int) $obligation->success_fee_tranche_id);
        $this->assertSame((int) $revisionTwo->id, (int) $obligation->successFeeTranche?->customer_agreement_id);

        // M3 half A is therefore ALIVE: the family pays the live tranche and the share falls due.
        $this->assertTrue($obligation->customerPaymentIsAnswerable());
        $this->assertNull($obligation->customerPaidAt());

        SuchakSuccessFeeTranche::query()
            ->whereKey($liveTranches[0]->id)
            ->update(['settled_at' => now()->subHour()]);

        $obligation = $obligation->fresh(['successFeeTranche', 'marriageOutcome']);
        $this->assertNotNull($obligation->customerPaidAt());
        $this->assertSame('customer_paid', $obligation->dueReason());

        // And the row can still be settled after the revision moved — a guard pinned to one
        // revision would have made an already-correct row unsaveable the moment revision 3 landed.
        $settled = $this->obligationService()->settle($obligation, $fixture['helper'], $fixture['helperUser']);
        $this->assertTrue($settled->isSettled());
    }

    public function test_the_per_tranche_attribution_guard_reads_the_live_rows_not_dead_ones(): void
    {
        $fixture = $this->marriedMarketplaceEngagement(tranchePlan: [
            [SuchakCollaborationStageEvent::STAGE_MARRIAGE, '100.00', true],
        ]);

        [, $liveTranches] = $this->supersedingRevision($fixture['agreement']);

        // §7.4: helper A's match released this tranche on ANOTHER engagement. Reading the bound
        // revision's copy — whose release columns are null — would hand helper B the fruit of it.
        $otherEngagement = SuchakCollaborationRequest::factory()->create();
        SuchakSuccessFeeTranche::query()
            ->whereKey($liveTranches[0]->id)
            ->update([
                'released_by_collaboration_request_id' => $otherEngagement->id,
                'released_at' => now()->subMonth(),
            ]);

        $obligations = $this->obligationService()->raise(
            $fixture['collaboration'],
            $fixture['helper'],
            $fixture['helperUser'],
        );

        $this->assertSame([], $obligations);
        $this->assertSame(0, SuchakCrossSuchakObligation::query()->count());
    }

    public function test_a_fee_the_customer_has_not_agreed_to_yet_raises_no_share(): void
    {
        // No installment plan, so there is no tranche row to be blocked — the case where the
        // release rules have nothing to hang on. The Suchak then revises his terms and the family
        // has not accepted the new revision, so today they owe nothing of the success fee.
        $fixture = $this->marriedMarketplaceEngagement(marriedDaysAgo: 40);
        $this->supersedingRevision($fixture['agreement'], accepted: false);

        $obligations = $this->obligationService()->raise(
            $fixture['collaboration'],
            $fixture['helper'],
            $fixture['helperUser'],
        );

        // A customer with no plan must never be treated as owing MORE than one with a plan, where
        // the same unaccepted terms block every tranche.
        $this->assertSame([], $obligations);
        $this->assertSame(0, SuchakCrossSuchakObligation::query()->count());
        $this->assertTrue($this->obligationService()->declarerRatio((int) $fixture['publisher']->id)['is_new']);
    }

    // ── M2 / D5 — nothing was declared, so nothing is owed ───────────────────────────────────

    public function test_a_direct_collaboration_declared_nothing_and_therefore_owes_nothing(): void
    {
        $fixture = $this->marriedDirectEngagement();

        try {
            $this->obligationService()->raise(
                $fixture['collaboration'],
                $fixture['ownerAccount'],
                $fixture['ownerUser'],
            );
            $this->fail('A direct collaboration produced a cross-Suchak obligation.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('जाहीर न केलेले काहीही देय नसते', $exception->getMessage());
        }

        $this->assertSame(0, SuchakCrossSuchakObligation::query()->count());
    }

    // ── §7.4 — the grain is the tranche, and the slices sum to the declared total ────────────

    public function test_the_grain_is_the_tranche_and_the_slices_sum_to_the_whole_share(): void
    {
        $fixture = $this->marriedMarketplaceEngagement(tranchePlan: [
            [SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED, '10.00', false],
            [SuchakCollaborationStageEvent::STAGE_ENGAGEMENT, '40.00', false],
            [SuchakCollaborationStageEvent::STAGE_MARRIAGE, '50.00', true],
        ]);

        $obligations = $this->obligationService()->raise(
            $fixture['collaboration'],
            $fixture['helper'],
            $fixture['helperUser'],
        );

        // M10: a wedding held without a साखरपुडा still owes the engagement tranche — every unpaid
        // tranche is released with the later stage, so all three are credited to this engagement.
        $this->assertCount(3, $obligations);
        $this->assertSame(
            ['3000.00', '12000.00', '15000.00'],
            array_map(static fn (SuchakCrossSuchakObligation $row): string => (string) $row->amount, $obligations),
        );

        // T2 as an assertion: the parts sum to the declared whole, to the paisa.
        $this->assertSame(
            30000.0,
            array_sum(array_map(static fn (SuchakCrossSuchakObligation $row): float => (float) $row->amount, $obligations)),
        );

        foreach ($obligations as $row) {
            $this->assertNotNull($row->success_fee_tranche_id);
        }
    }

    public function test_a_tranche_already_released_by_another_engagement_is_not_credited_here(): void
    {
        $fixture = $this->marriedMarketplaceEngagement(tranchePlan: [
            [SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED, '10.00', false],
            [SuchakCollaborationStageEvent::STAGE_ENGAGEMENT, '40.00', false],
            [SuchakCollaborationStageEvent::STAGE_MARRIAGE, '50.00', true],
        ]);

        // §7.4 / M9: helper A's match produced the first tranche. Its share is A's, not this one's.
        $otherEngagement = SuchakCollaborationRequest::factory()->create();
        SuchakSuccessFeeTranche::query()
            ->where('customer_agreement_id', $fixture['agreement']->id)
            ->where('trigger_stage_key', SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED)
            ->update([
                'released_by_collaboration_request_id' => $otherEngagement->id,
                'released_at' => now()->subMonth(),
            ]);

        $obligations = $this->obligationService()->raise(
            $fixture['collaboration'],
            $fixture['helper'],
            $fixture['helperUser'],
        );

        $this->assertCount(2, $obligations);
        // The remaining two keep their own shares of the TOTAL (T1) — 40% and the remainder.
        $this->assertSame(
            ['12000.00', '15000.00'],
            array_map(static fn (SuchakCrossSuchakObligation $row): string => (string) $row->amount, $obligations),
        );
    }

    public function test_an_agreement_with_no_installment_plan_raises_one_obligation_for_the_whole_share(): void
    {
        $fixture = $this->marriedMarketplaceEngagement();

        $first = $this->obligationService()->raise($fixture['collaboration'], $fixture['helper'], $fixture['helperUser']);
        $second = $this->obligationService()->raise($fixture['collaboration'], $fixture['helper'], $fixture['helperUser']);

        // The unique index cannot close the NULL-tranche case on either MySQL or SQLite; the row
        // lock in raise() does, and this is the assertion that says so.
        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertSame((int) $first[0]->id, (int) $second[0]->id);
        $this->assertNull($first[0]->success_fee_tranche_id);
        $this->assertSame(1, SuchakCrossSuchakObligation::query()->count());
    }

    // ── M3 — both halves ────────────────────────────────────────────────────────────────────

    public function test_m3_half_b_a_marriage_suppressed_and_recorded_late_is_already_overdue(): void
    {
        // The wedding was 40 days before it was recorded. `married_on` is the WEDDING DAY, so the
        // 30-day window has already run — suppressing the record ACCELERATED the obligation instead
        // of pushing it out, which is M3's own sentence.
        $fixture = $this->marriedMarketplaceEngagement(marriedDaysAgo: 40);

        $obligation = $this->obligationService()->raise(
            $fixture['collaboration'],
            $fixture['helper'],
            $fixture['helperUser'],
        )[0];

        $this->assertSame(30, SuchakMarriageOutcome::SHARE_DUE_DAYS_AFTER_MARRIAGE);
        $this->assertTrue($obligation->isDue());
        $this->assertTrue($obligation->isOverdue());
        $this->assertSame('days_after_recorded_marriage', $obligation->dueReason());
        $this->assertGreaterThanOrEqual(9, (int) $obligation->overdueDays());
    }

    public function test_m3_half_b_a_fresh_marriage_is_not_yet_due(): void
    {
        $fixture = $this->marriedMarketplaceEngagement(marriedDaysAgo: 5);

        $obligation = $this->obligationService()->raise(
            $fixture['collaboration'],
            $fixture['helper'],
            $fixture['helperUser'],
        )[0];

        $this->assertFalse($obligation->isDue());
        $this->assertNull($obligation->dueReason());
        $this->assertNull($obligation->overdueDays());
        // The clock still exists and is answerable — "not yet" is not "never".
        $this->assertNotNull($obligation->fallsDueAt());
    }

    public function test_m3_half_a_a_paid_tranche_makes_the_share_due_before_the_thirty_days(): void
    {
        $fixture = $this->marriedMarketplaceEngagement(marriedDaysAgo: 2, tranchePlan: [
            [SuchakCollaborationStageEvent::STAGE_MARRIAGE, '100.00', true],
        ]);

        $obligation = $this->obligationService()->raise(
            $fixture['collaboration'],
            $fixture['helper'],
            $fixture['helperUser'],
        )[0];

        $this->assertTrue($obligation->customerPaymentIsAnswerable());
        $this->assertFalse($obligation->isDue());

        // The family paid the tranche. Its one writer is
        // SuchakSuccessFeeTrancheService::settle(); this sets the column it writes, because what is
        // under test here is the READ that turns it into M3's half A.
        SuchakSuccessFeeTranche::query()
            ->whereKey($obligation->success_fee_tranche_id)
            ->update(['settled_at' => now()->subHour()]);

        $obligation = $obligation->fresh(['successFeeTranche', 'marriageOutcome']);
        $this->assertTrue($obligation->isDue());
        $this->assertSame('customer_paid', $obligation->dueReason());
        $this->assertNotNull($obligation->customerPaidAt());
    }

    public function test_without_an_installment_plan_half_a_is_reported_unanswerable_rather_than_guessed(): void
    {
        $fixture = $this->marriedMarketplaceEngagement(marriedDaysAgo: 2);

        $obligation = $this->obligationService()->raise(
            $fixture['collaboration'],
            $fixture['helper'],
            $fixture['helperUser'],
        )[0];

        // No tranche row means no per-fee payment pointer, and `suchak_customer_payments` cannot say
        // which fee it paid. M3's "whichever is earlier" degenerates to half B — stated, not guessed.
        $this->assertFalse($obligation->customerPaymentIsAnswerable());
        $this->assertNull($obligation->customerPaidAt());
        $this->assertEquals(
            $fixture['outcome']->shareFallsDueAt()->toIso8601String(),
            $obligation->fallsDueAt()->toIso8601String(),
        );
    }

    public function test_the_payee_can_raise_the_obligation_so_the_payer_cannot_suppress_it(): void
    {
        $fixture = $this->marriedMarketplaceEngagement();

        // The helper, unaided. M3 forbids the obligation being killable by the payer doing nothing.
        $obligations = $this->obligationService()->raise(
            $fixture['collaboration'],
            $fixture['helper'],
            $fixture['helperUser'],
        );

        $this->assertCount(1, $obligations);
        $this->assertSame((int) $fixture['publisher']->id, (int) $obligations[0]->payer_suchak_account_id);
    }

    // ── A7 — the share-settled rung, and the ratio ──────────────────────────────────────────

    public function test_only_the_payee_may_mark_the_share_received(): void
    {
        $fixture = $this->marriedMarketplaceEngagement();
        $obligation = $this->obligationService()->raise(
            $fixture['collaboration'],
            $fixture['helper'],
            $fixture['helperUser'],
        )[0];

        try {
            $this->obligationService()->settle($obligation, $fixture['publisher'], $fixture['publisherUser']);
            $this->fail('The payer settled his own obligation.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('ज्याला तो मिळायचा आहे', $exception->getMessage());
        }

        $this->assertFalse($obligation->fresh()->isSettled());
    }

    public function test_settling_the_last_obligation_claims_the_share_settled_rung(): void
    {
        $fixture = $this->marriedMarketplaceEngagement();
        $obligation = $this->obligationService()->raise(
            $fixture['collaboration'],
            $fixture['helper'],
            $fixture['helperUser'],
        )[0];

        // The rung shipped claimable and READ BY NOTHING. This is what finally writes it.
        $this->assertSame(
            SuchakCollaborationStageEvent::CLAIMANT_HELPER,
            SuchakCollaborationStageEvent::claimantFor(SuchakCollaborationStageEvent::STAGE_SHARE_SETTLED),
        );
        $this->assertDatabaseMissing('suchak_collaboration_stage_events', [
            'collaboration_request_id' => $fixture['collaboration']->id,
            'stage_key' => SuchakCollaborationStageEvent::STAGE_SHARE_SETTLED,
        ]);

        $settled = $this->obligationService()->settle(
            $obligation,
            $fixture['helper'],
            $fixture['helperUser'],
            'UPI/992211',
        );

        $this->assertTrue($settled->isSettled());
        $this->assertSame('UPI/992211', $settled->settlement_reference);
        $this->assertSame((int) $fixture['helperUser']->id, (int) $settled->settled_by_user_id);
        $this->assertDatabaseHas('suchak_collaboration_stage_events', [
            'collaboration_request_id' => $fixture['collaboration']->id,
            'stage_key' => SuchakCollaborationStageEvent::STAGE_SHARE_SETTLED,
            'claimed_by_suchak_account_id' => $fixture['helper']->id,
        ]);
    }

    public function test_the_rung_waits_until_every_obligation_on_the_engagement_is_settled(): void
    {
        $fixture = $this->marriedMarketplaceEngagement(tranchePlan: [
            [SuchakCollaborationStageEvent::STAGE_ENGAGEMENT, '40.00', false],
            [SuchakCollaborationStageEvent::STAGE_MARRIAGE, '60.00', true],
        ]);

        $obligations = $this->obligationService()->raise(
            $fixture['collaboration'],
            $fixture['helper'],
            $fixture['helperUser'],
        );
        $this->assertCount(2, $obligations);

        $this->obligationService()->settle($obligations[0], $fixture['helper'], $fixture['helperUser']);
        $this->assertDatabaseMissing('suchak_collaboration_stage_events', [
            'collaboration_request_id' => $fixture['collaboration']->id,
            'stage_key' => SuchakCollaborationStageEvent::STAGE_SHARE_SETTLED,
        ]);

        $this->obligationService()->settle($obligations[1], $fixture['helper'], $fixture['helperUser']);
        $this->assertDatabaseHas('suchak_collaboration_stage_events', [
            'collaboration_request_id' => $fixture['collaboration']->id,
            'stage_key' => SuchakCollaborationStageEvent::STAGE_SHARE_SETTLED,
        ]);
    }

    public function test_the_realized_versus_declared_ratio_is_answerable(): void
    {
        $fixture = $this->marriedMarketplaceEngagement(marriedDaysAgo: 40, tranchePlan: [
            [SuchakCollaborationStageEvent::STAGE_ENGAGEMENT, '40.00', false],
            [SuchakCollaborationStageEvent::STAGE_MARRIAGE, '60.00', true],
        ]);

        $obligations = $this->obligationService()->raise(
            $fixture['collaboration'],
            $fixture['helper'],
            $fixture['helperUser'],
        );
        $this->obligationService()->settle($obligations[0], $fixture['helper'], $fixture['helperUser']);

        $ratio = $this->obligationService()->declarerRatio((int) $fixture['publisher']->id);

        $this->assertFalse($ratio['is_new']);
        $this->assertSame(2, $ratio['declared_obligation_count']);
        $this->assertSame(1, $ratio['settled_obligation_count']);
        // ₹12,000 realized of ₹30,000 declared. Latin digits, Indian grouping, one formatter.
        $this->assertSame('40', $ratio['realized_ratio_percent']);
        $this->assertFalse($ratio['mixed_currency']);
        $this->assertSame('₹30,000', $ratio['by_currency'][0]['declared_amount_display']);
        $this->assertSame('₹12,000', $ratio['by_currency'][0]['settled_amount_display']);
        $this->assertSame('₹18,000', $ratio['by_currency'][0]['outstanding_amount_display']);
        // The unsettled one is past its 30 days, so the raw overdue counter §7.2 prefers is live.
        $this->assertSame(1, $ratio['overdue_obligation_count']);
        $this->assertNotNull($ratio['oldest_overdue_days']);
    }

    public function test_the_ratio_is_answerable_without_the_declarer_pressing_anything(): void
    {
        // Nobody calls raise(). The declarer has no reason to (it publishes his own debt) and the
        // helper who gave up chasing has none either. Before the arithmetic fallback this rendered
        // as the NEW badge on the card of a Suchak carrying an unpaid share.
        $fixture = $this->marriedMarketplaceEngagement(marriedDaysAgo: 40);
        $this->assertSame(0, SuchakCrossSuchakObligation::query()->count());

        $ratio = $this->obligationService()->declarerRatio((int) $fixture['publisher']->id);

        $this->assertFalse($ratio['is_new']);
        $this->assertSame(1, $ratio['declared_obligation_count']);
        $this->assertSame(0, $ratio['recorded_obligation_count']);
        $this->assertSame(1, $ratio['derived_obligation_count']);
        // Declared ₹30,000, realized nothing — and past its thirty days, so §7.2's raw counter runs.
        $this->assertSame('0', $ratio['realized_ratio_percent']);
        $this->assertSame('₹30,000', $ratio['by_currency'][0]['declared_amount_display']);
        $this->assertSame(1, $ratio['overdue_obligation_count']);

        // Deriving it wrote nothing: A7 is a READ, and a row is still the payer's or payee's act.
        $this->assertSame(0, SuchakCrossSuchakObligation::query()->count());

        // Once the row exists it OWNS its slice — the derived twin does not double-count it.
        $obligation = $this->obligationService()->raise(
            $fixture['collaboration'],
            $fixture['helper'],
            $fixture['helperUser'],
        )[0];

        $ratio = $this->obligationService()->declarerRatio((int) $fixture['publisher']->id);
        $this->assertSame(1, $ratio['declared_obligation_count']);
        $this->assertSame(1, $ratio['recorded_obligation_count']);
        $this->assertSame(0, $ratio['derived_obligation_count']);

        $this->obligationService()->settle($obligation, $fixture['helper'], $fixture['helperUser']);
        $this->assertSame('100', $this->obligationService()->declarerRatio((int) $fixture['publisher']->id)['realized_ratio_percent']);
    }

    public function test_a_derived_share_never_becomes_a_platform_lever(): void
    {
        $fixture = $this->marriedMarketplaceEngagement(marriedDaysAgo: 40);

        // The public ratio counts it…
        $this->assertSame(
            1,
            $this->obligationService()->declarerRatio((int) $fixture['publisher']->id)['derived_obligation_count'],
        );

        // …and §7.3's exposure figure, which is what a payout gate would read, does not. A number
        // may be derived; a lever over somebody's money may only act on a recorded debt.
        $exposure = $this->obligationService()->overdueExposureFor((int) $fixture['publisher']->id);
        $this->assertSame(0, $exposure['overdue_count']);
        $this->assertFalse($exposure['platform_enforces']);
    }

    // ── The account ledger and the ratio must describe the same money the same way ───────────

    /**
     * Confirmed by hand on a real device against production data: the cross-Suchak screen showed
     * "जाहीर: 3 · पूर्ण: 0 · उशीर: 2" in the ratio card and, an inch below it, "अजून एकही आंतर-सूचक
     * नोंद नाही" in the list. Two contradictory statements about one Suchak's money, with two of the
     * shares already overdue.
     *
     * It was never a rendering bug. `ledgerFor()` read `owedBy()` / `owedTo()` — stored rows only —
     * while `declarerRatio()` counted those PLUS `derivedUnraisedObligations()`. So a marriage that
     * is recorded and confirmed produced a real, overdue debt the ledger rendered as nothing: the
     * payer had no row to raise, the payee had no row to chase, and the judged party could not act
     * on the number judging him.
     */
    public function test_the_ledger_shows_the_derived_overdue_share_the_ratio_is_already_counting(): void
    {
        $fixture = $this->marriedMarketplaceEngagement(marriedDaysAgo: 40);

        // Nobody pressed raise. This is exactly the production state that produced the screenshot.
        $this->assertSame(0, SuchakCrossSuchakObligation::query()->count());

        $ratio = $this->obligationService()->declarerRatio((int) $fixture['publisher']->id);
        $ledger = $this->obligationService()->ledgerFor($fixture['publisher']);

        // The ratio counts one overdue share…
        $this->assertSame(1, $ratio['declared_obligation_count']);
        $this->assertSame(1, $ratio['overdue_obligation_count']);

        // …and the list no longer answers "nothing here". THE CONTRADICTION, closed.
        $this->assertCount(1, $ledger['owed_by_me']['obligations']);

        $row = $ledger['owed_by_me']['obligations'][0];
        $this->assertTrue($row['is_overdue']);
        $this->assertSame('₹30,000', $row['amount_display']);
        $this->assertSame('days_after_recorded_marriage', $row['due_reason']);

        // A read stays a read.
        $this->assertSame(0, SuchakCrossSuchakObligation::query()->count());
    }

    public function test_a_derived_ledger_row_is_unmistakably_not_a_raised_one(): void
    {
        $fixture = $this->marriedMarketplaceEngagement(marriedDaysAgo: 40);

        $derived = $this->obligationService()
            ->ledgerFor($fixture['publisher'])['owed_by_me']['obligations'][0];

        // They are not the same fact: one is a debt somebody committed to, the other is one the
        // platform inferred and nobody has yet made real. Same `is_derived` key, same shape, as the
        // per-engagement door already publishes — the client needs no second parser.
        $this->assertTrue($derived['is_derived']);
        // NO STORED ID, so nothing in this payload can address the settle route at all.
        $this->assertSame(0, $derived['obligation_id']);
        // Derived is unsettled by definition: settlement is a fact only the payee can record.
        $this->assertFalse($derived['is_settled']);
        $this->assertNull($derived['settled_at']);
        // RAISE is the verb it answers to, and it is keyed on an id that is real.
        $this->assertSame((int) $fixture['collaboration']->id, $derived['collaboration_request_id']);

        $this->obligationService()->raise($fixture['collaboration'], $fixture['helper'], $fixture['helperUser']);

        $raised = $this->obligationService()
            ->ledgerFor($fixture['publisher']->fresh())['owed_by_me']['obligations'][0];

        $this->assertFalse($raised['is_derived']);
        $this->assertGreaterThan(0, $raised['obligation_id']);
        // Same money, same slice — only its standing as a record changed.
        $this->assertSame($derived['amount'], $raised['amount']);
    }

    public function test_a_derived_ledger_row_is_not_settleable_and_the_route_refuses_it(): void
    {
        $fixture = $this->marriedMarketplaceEngagement(marriedDaysAgo: 40);

        // The PAYEE reads it — the only party who may ever settle anything — and still gets a row
        // with no id to settle.
        Sanctum::actingAs($fixture['helperUser']);
        $this->getJson('/api/v1/suchak/cross-suchak-obligations')
            ->assertOk()
            ->assertJsonPath('data.owed_to_me.obligations.0.is_derived', true)
            ->assertJsonPath('data.owed_to_me.obligations.0.obligation_id', 0)
            ->assertJsonPath('data.owed_to_me.obligations.0.is_settled', false);

        // The id a derived row carries addresses nothing — route-model binding cannot resolve 0.
        $this->postJson('/api/v1/suchak/cross-suchak-obligations/0/settle')->assertNotFound();
        $this->assertSame(0, SuchakCrossSuchakObligation::query()->count());

        // Raising IS open to him — M3, suppressing the record must accelerate the obligation and
        // never kill it — and it is what turns the derived row into one he can later settle.
        $this->postJson(
            '/api/v1/suchak/collaborations/'.$fixture['collaboration']->id.'/cross-suchak-obligations'
        )->assertOk();
        $this->assertSame(1, SuchakCrossSuchakObligation::query()->count());
    }

    public function test_the_payee_is_shown_the_share_he_is_owed_before_anybody_raises_it(): void
    {
        $fixture = $this->marriedMarketplaceEngagement(marriedDaysAgo: 40);

        $ledger = $this->obligationService()->ledgerFor($fixture['helper']);

        // The helper's own money, in the direction it belongs to. Deciding whether the marketplace
        // was worth entering is what this list is FOR, and a helper who had earned ₹30,000 and never
        // been paid was shown an empty screen.
        $this->assertCount(1, $ledger['owed_to_me']['obligations']);
        $this->assertSame([], $ledger['owed_by_me']['obligations']);

        $row = $ledger['owed_to_me']['obligations'][0];
        $this->assertTrue($row['is_derived']);
        $this->assertSame((int) $fixture['publisher']->id, $row['payer_suchak_account_id']);
        $this->assertSame((int) $fixture['helper']->id, $row['payee_suchak_account_id']);
    }

    public function test_the_ledger_totals_match_the_ratio_the_same_screen_prints(): void
    {
        // Two tranches, one released and one not, so the arithmetic has something to get wrong.
        $fixture = $this->marriedMarketplaceEngagement(marriedDaysAgo: 40, tranchePlan: [
            [SuchakCollaborationStageEvent::STAGE_ENGAGEMENT, '40.00', false],
            [SuchakCollaborationStageEvent::STAGE_MARRIAGE, '60.00', true],
        ]);

        $ratio = $this->obligationService()->declarerRatio((int) $fixture['publisher']->id);
        $totals = $this->obligationService()->ledgerFor($fixture['publisher'])['owed_by_me']['totals'];

        // Card and list are computed from one set, so they can no longer disagree in front of a user.
        $this->assertSame($ratio['declared_obligation_count'], $totals['declared_count']);
        $this->assertSame($ratio['settled_obligation_count'], $totals['settled_count']);
        $this->assertSame($ratio['overdue_obligation_count'], $totals['overdue_count']);
        $this->assertSame($ratio['oldest_overdue_days'], $totals['oldest_overdue_days']);
        $this->assertSame(
            $ratio['by_currency'][0]['declared_amount_display'],
            $totals['declared_amount_display'],
        );
    }

    public function test_a_raised_row_and_its_derived_twin_are_never_both_counted_on_the_ledger(): void
    {
        $fixture = $this->marriedMarketplaceEngagement(marriedDaysAgo: 40, tranchePlan: [
            [SuchakCollaborationStageEvent::STAGE_ENGAGEMENT, '40.00', false],
            [SuchakCollaborationStageEvent::STAGE_MARRIAGE, '60.00', true],
        ]);

        $before = $this->obligationService()->ledgerFor($fixture['publisher'])['owed_by_me']['totals'];
        $this->assertSame(2, $before['declared_count']);
        $this->assertSame('₹30,000', $before['declared_amount_display']);

        // Raising persists exactly the slices that were derived. If the twins survived beside them
        // the declared sum would double to ₹60,000 — the number two businesses argue about,
        // inflated by a read.
        $this->obligationService()->raise($fixture['collaboration'], $fixture['helper'], $fixture['helperUser']);
        $this->assertSame(2, SuchakCrossSuchakObligation::query()->count());

        $payer = $this->obligationService()->ledgerFor($fixture['publisher']->fresh());
        $this->assertSame(2, $payer['owed_by_me']['totals']['declared_count']);
        $this->assertSame('₹30,000', $payer['owed_by_me']['totals']['declared_amount_display']);
        foreach ($payer['owed_by_me']['obligations'] as $row) {
            $this->assertFalse($row['is_derived']);
        }

        // The same, one row at a time, on the OTHER side of the same engagement.
        $payee = $this->obligationService()->ledgerFor($fixture['helper']->fresh());
        $this->assertSame(2, $payee['owed_to_me']['totals']['declared_count']);
        $this->assertSame('₹30,000', $payee['owed_to_me']['totals']['declared_amount_display']);
        foreach ($payee['owed_to_me']['obligations'] as $row) {
            $this->assertFalse($row['is_derived']);
        }
    }

    public function test_an_empty_ledger_and_the_new_badge_are_the_same_answer(): void
    {
        // The other half of the contract. When the list really is empty the ratio must be saying
        // "new" — neither side may invent the other's story.
        [, $account] = $this->verifiedSuchakActor();
        $ledger = $this->obligationService()->ledgerFor($account);

        $this->assertSame([], $ledger['owed_by_me']['obligations']);
        $this->assertSame([], $ledger['owed_to_me']['obligations']);
        $this->assertTrue($this->obligationService()->declarerRatio((int) $account->id)['is_new']);

        // And an UNCONFIRMED marriage is the same honest empty, on both sides: the derivation runs
        // `raise()`'s own gates because it IS `raise()`'s own routine. A claim is not a wedding, and
        // a share of a fee that cannot be collected is a debt nobody owes.
        $unconfirmed = $this->marriedMarketplaceEngagement(marriedDaysAgo: 40, confirmMarriage: false);

        $this->assertSame(
            [],
            $this->obligationService()->ledgerFor($unconfirmed['publisher'])['owed_by_me']['obligations'],
        );
        $this->assertSame(
            [],
            $this->obligationService()->ledgerFor($unconfirmed['helper'])['owed_to_me']['obligations'],
        );
        $this->assertTrue(
            $this->obligationService()->declarerRatio((int) $unconfirmed['publisher']->id)['is_new'],
        );
    }

    public function test_every_settlement_column_is_read_back_out(): void
    {
        $fixture = $this->marriedMarketplaceEngagement();
        $obligation = $this->obligationService()->raise(
            $fixture['collaboration'],
            $fixture['helper'],
            $fixture['helperUser'],
        )[0];

        $this->obligationService()->settle(
            $obligation,
            $fixture['helper'],
            $fixture['helperUser'],
            'UPI/554433',
            'Paid in cash at the reception, counted by both of us.',
        );

        // `settled_by_user_id` and `settlement_note` had a writer and no reader anywhere — in a
        // dispute a year later they are the evidence, and unread they were a promise the row could
        // not keep.
        $card = $this->obligationService()->forEngagement($fixture['collaboration']);
        $row = $card['obligations'][0];

        $this->assertSame((int) $fixture['helperUser']->id, $row['settled_by_user_id']);
        $this->assertSame('Paid in cash at the reception, counted by both of us.', $row['settlement_note']);
        $this->assertSame('UPI/554433', $row['settlement_reference']);
        $this->assertFalse($row['is_derived']);

        // The other party reads the same evidence through the door.
        Sanctum::actingAs($fixture['publisherUser']);
        $this->getJson('/api/v1/suchak/collaborations/'.$fixture['collaboration']->id.'/cross-suchak-obligations')
            ->assertOk()
            ->assertJsonPath('data.obligations.0.settled_by_user_id', (int) $fixture['helperUser']->id)
            ->assertJsonPath('data.obligations.0.settlement_note', 'Paid in cash at the reception, counted by both of us.');
    }

    public function test_a_declarer_with_no_obligations_is_new_and_never_zero_percent(): void
    {
        [, $account] = $this->verifiedSuchakActor();

        $ratio = $this->obligationService()->declarerRatio((int) $account->id);

        // D13 — "a new Suchak shows a New badge, never 0 marriages". Zero out of zero is not 0%.
        $this->assertTrue($ratio['is_new']);
        $this->assertNull($ratio['realized_ratio_percent']);
        $this->assertSame(0, $ratio['declared_obligation_count']);
    }

    // ── §7.3 — what the platform can and cannot do ───────────────────────────────────────────

    public function test_the_exposure_read_states_that_the_platform_enforces_nothing(): void
    {
        $fixture = $this->marriedMarketplaceEngagement(marriedDaysAgo: 40);
        $this->obligationService()->raise($fixture['collaboration'], $fixture['helper'], $fixture['helperUser']);

        $exposure = $this->obligationService()->overdueExposureFor((int) $fixture['publisher']->id);

        $this->assertSame(1, $exposure['overdue_count']);
        $this->assertSame('₹30,000', $exposure['overdue_amount_display']);
        // M1: no shared pot, and the platform does not stand between a customer and a Suchak. The
        // read publishes a number; it creates no hold and freezes nothing.
        $this->assertFalse($exposure['platform_enforces']);
        $this->assertSame(0, DB::table('suchak_payout_holds')->count());
    }

    // ── The row is evidence ──────────────────────────────────────────────────────────────────

    public function test_an_obligation_cannot_be_deleted(): void
    {
        $fixture = $this->marriedMarketplaceEngagement();
        $obligation = $this->obligationService()->raise(
            $fixture['collaboration'],
            $fixture['helper'],
            $fixture['helperUser'],
        )[0];

        $this->expectException(RuntimeException::class);
        $obligation->delete();
    }

    // ── The doors ────────────────────────────────────────────────────────────────────────────

    public function test_the_routes_raise_read_and_settle_end_to_end(): void
    {
        $fixture = $this->marriedMarketplaceEngagement(marriedDaysAgo: 40);
        $uri = '/api/v1/suchak/collaborations/'.$fixture['collaboration']->id.'/cross-suchak-obligations';

        Sanctum::actingAs($fixture['helperUser']);
        $raised = $this->postJson($uri)
            ->assertOk()
            ->assertJsonPath('data.payer_suchak_account_id', (int) $fixture['publisher']->id)
            ->assertJsonPath('data.payee_suchak_account_id', (int) $fixture['helper']->id)
            ->assertJsonPath('data.totals.declared_amount_display', '₹30,000')
            ->assertJsonPath('data.obligations.0.is_overdue', true)
            ->assertJsonPath('data.obligations.0.due_reason', 'days_after_recorded_marriage')
            ->json('data.obligations.0.obligation_id');

        // The publisher reads the same engagement card.
        Sanctum::actingAs($fixture['publisherUser']);
        $this->getJson($uri)->assertOk()->assertJsonPath('data.totals.outstanding_count', 1);

        // …and cannot settle it: A7 is helper-only.
        $this->postJson('/api/v1/suchak/cross-suchak-obligations/'.$raised.'/settle')->assertStatus(422);

        Sanctum::actingAs($fixture['helperUser']);
        $this->postJson('/api/v1/suchak/cross-suchak-obligations/'.$raised.'/settle', [
            'settlement_reference' => 'UPI/771122',
        ])->assertOk()->assertJsonPath('data.totals.settled_count', 1);

        // Both directions of one account's ledger.
        $this->getJson('/api/v1/suchak/cross-suchak-obligations')
            ->assertOk()
            ->assertJsonPath('data.owed_to_me.totals.declared_count', 1)
            ->assertJsonPath('data.owed_by_me.totals.declared_count', 0);

        // A7's card, read by the helper about the declarer he just worked for.
        $this->getJson('/api/v1/suchak/cross-suchak-obligations/ratio/'.$fixture['publisher']->id)
            ->assertOk()
            ->assertJsonPath('data.realized_ratio_percent', '100')
            ->assertJsonPath('data.is_new', false);
    }

    public function test_a_stranger_never_learns_the_engagement_or_the_obligation_exists(): void
    {
        $fixture = $this->marriedMarketplaceEngagement();
        $obligation = $this->obligationService()->raise(
            $fixture['collaboration'],
            $fixture['helper'],
            $fixture['helperUser'],
        )[0];

        [$strangerUser] = $this->verifiedSuchakActor();
        Sanctum::actingAs($strangerUser);

        $this->getJson('/api/v1/suchak/collaborations/'.$fixture['collaboration']->id.'/cross-suchak-obligations')
            ->assertNotFound();
        $this->postJson('/api/v1/suchak/collaborations/'.$fixture['collaboration']->id.'/cross-suchak-obligations')
            ->assertNotFound();
        $this->postJson('/api/v1/suchak/cross-suchak-obligations/'.$obligation->id.'/settle')
            ->assertNotFound();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────────

    private function obligationService(): SuchakCrossSuchakObligationService
    {
        return $this->app->make(SuchakCrossSuchakObligationService::class);
    }

    private function challengeService(): SuchakMarketplaceChallengeService
    {
        return $this->app->make(SuchakMarketplaceChallengeService::class);
    }

    private function collaborationService(): SuchakCollaborationService
    {
        return $this->app->make(SuchakCollaborationService::class);
    }

    private function outcomeService(): SuchakMarriageOutcomeService
    {
        return $this->app->make(SuchakMarriageOutcomeService::class);
    }

    /**
     * The whole marketplace path, ending in a recorded marriage: a publisher opens his customer with
     * a declared 30% share, a helper answers by naming his own candidate, the publisher accepts, and
     * the two candidates marry.
     *
     * TIME: the engagement opens today and the clock then travels forward, so `married_on` can sit
     * safely between "after the introduction" (§6.2's refusal) and "N days ago" without ever being
     * a future date (§7.4/D25's refusal).
     *
     * CONFIRMATION IS PART OF "THEY MARRY" AND IS ON BY DEFAULT. `marriage` is one of D26's
     * CONFIRMABLE_STAGES, so a recorded-but-unconfirmed rung is a CLAIM, not a wedding — and a
     * claim releases no tranche, therefore earns no fee, therefore owes no share. An admin stands
     * in for the family here because §2's customer usually has no login; that is the same door
     * production has (`CONFIRM_ACTOR_TYPES` admits `ACTOR_ADMIN`).
     *
     * @param  list<array{0: string, 1: string, 2: bool}>  $tranchePlan  stage key, share percent, is-final
     * @return array{
     *     publisherUser: User, publisher: SuchakAccount,
     *     helperUser: User, helper: SuchakAccount,
     *     challenge: SuchakMarketplaceChallenge, collaboration: SuchakCollaborationRequest,
     *     agreement: SuchakCustomerAgreement, outcome: SuchakMarriageOutcome
     * }
     */
    private function marriedMarketplaceEngagement(
        int $marriedDaysAgo = 10,
        array $tranchePlan = [],
        bool $confirmMarriage = true,
    ): array {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        [$publisherRepresentation, $agreement] = $this->publishableCandidate($publisher, $publisherUser);

        $challenge = $this->challengeService()->publish(
            $publisher,
            $publisherUser,
            $publisherRepresentation,
            [
                'declared_share_type' => SuchakCommissionAgreement::SPLIT_CUSTOM_PERCENT,
                'declared_share_percent' => 30,
            ],
        );

        $proposed = $this->challengeService()->proposeCandidate(
            $challenge,
            $helper,
            $helperUser,
            $this->helperCandidate($helper),
        );

        /** @var SuchakCollaborationRequest $collaboration */
        $collaboration = $proposed['request'];
        $collaboration = $this->collaborationService()->acceptRequest($collaboration, $publisher, $publisherUser);

        foreach ($tranchePlan as $index => [$stageKey, $percent, $isFinal]) {
            SuchakSuccessFeeTranche::query()->create([
                'customer_agreement_id' => $agreement->id,
                'sort_order' => ((int) $index + 1) * 10,
                'trigger_stage_key' => $stageKey,
                'share_percent' => $percent,
                'is_final_tranche' => $isFinal,
            ]);
        }

        // Move the clock past the wedding so the date can be in the past without predating the
        // introduction. `travel` is the suite's own clock, not a stored value.
        $this->travel($marriedDaysAgo + 1)->days();

        $outcome = $this->outcomeService()->record(
            $collaboration,
            $publisher,
            $publisherUser,
            now()->subDays($marriedDaysAgo),
        );

        if ($confirmMarriage) {
            $this->collaborationService()->confirmStage(
                $collaboration,
                $this->adminUser(),
                SuchakCollaborationStageEvent::STAGE_MARRIAGE,
            );
            $outcome = $outcome->fresh(['stageEvent']);
        }

        return [
            'publisherUser' => $publisherUser,
            'publisher' => $publisher,
            'helperUser' => $helperUser,
            'helper' => $helper,
            'challenge' => $challenge->fresh(),
            'collaboration' => $collaboration->fresh(['commissionAgreement', 'marketplaceChallenge']),
            'agreement' => $agreement,
            'outcome' => $outcome,
        ];
    }

    /**
     * A NON-marketplace engagement that also ends in a marriage — D5's case: work was done, a
     * wedding happened, and no share was ever declared.
     *
     * @return array{
     *     ownerUser: User, ownerAccount: SuchakAccount,
     *     collaboration: SuchakCollaborationRequest, outcome: SuchakMarriageOutcome
     * }
     */
    private function marriedDirectEngagement(): array
    {
        [$ownerUser, $ownerAccount] = $this->verifiedSuchakActor();
        [$helperUser, $helperAccount] = $this->verifiedSuchakActor();

        /** @var SuchakCollaborationRequest $collaboration */
        $collaboration = SuchakCollaborationRequest::factory()->create([
            'requesting_suchak_account_id' => $ownerAccount->id,
            'target_suchak_account_id' => $helperAccount->id,
            'status' => SuchakCollaborationRequest::STATUS_PENDING,
            'requested_at' => now(),
        ]);

        $collaboration = $this->collaborationService()->acceptRequest($collaboration, $helperAccount, $helperUser);

        // Both commission acknowledgements, so this engagement is as complete as the marketplace one
        // and the D5 refusal under test is the FIRST thing that stops it — not an unrelated gate.
        SuchakCommissionAgreement::query()
            ->where('collaboration_request_id', $collaboration->id)
            ->update([
                'accepted_by_groom_suchak_at' => now(),
                'accepted_by_bride_suchak_at' => now(),
                'agreement_status' => SuchakCommissionAgreement::STATUS_ACCEPTED,
            ]);
        $collaboration = $collaboration->fresh(['commissionAgreement']);

        $agreement = $this->standaloneAgreement(
            $ownerAccount,
            $ownerUser,
            (int) $collaboration->requesting_matrimony_profile_id,
        );
        $collaboration = $this->collaborationService()->linkCustomerAgreement(
            $collaboration,
            $ownerAccount,
            $ownerUser,
            $agreement,
        );

        $this->travel(11)->days();
        $outcome = $this->outcomeService()->record($collaboration, $ownerAccount, $ownerUser, now()->subDays(10));

        return [
            'ownerUser' => $ownerUser,
            'ownerAccount' => $ownerAccount,
            'collaboration' => $collaboration->fresh(['commissionAgreement', 'marketplaceChallenge']),
            'outcome' => $outcome,
        ];
    }

    /**
     * The admin who stands in for a family with no login (§2). `CONFIRM_ACTOR_TYPES` admits
     * `ACTOR_ADMIN` and `confirmationActorType()` checks the admin flag first, so this is the same
     * door production has — not a test-only shortcut around the confirmation rule.
     */
    private function adminUser(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /**
     * Revision 2 of the same agreement chain, with the installment plan carried forward — what
     * `SuchakAgreementService::persistTranchePlan()` does when a customer accepts revised terms.
     *
     * `SuchakSuccessFeeTrancheService::ledgerAgreementFor()` resolves the LIVE ledger as the highest
     * revision on the same `service_package_id`, so after this the money moves on revision 2 while
     * the §6.2 attribution row still names revision 1.
     *
     * @return array{0: SuchakCustomerAgreement, 1: list<SuchakSuccessFeeTranche>}
     */
    private function supersedingRevision(SuchakCustomerAgreement $agreement, bool $accepted = true): array
    {
        /** @var SuchakCustomerAgreement $next */
        $next = SuchakCustomerAgreement::query()->create([
            'suchak_account_id' => $agreement->suchak_account_id,
            'customer_context_id' => $agreement->customer_context_id,
            'service_package_id' => $agreement->service_package_id,
            'agreement_revision' => ((int) $agreement->agreement_revision) + 1,
            'terms_status' => $accepted
                ? SuchakCustomerAgreement::TERMS_ACCEPTED
                : SuchakCustomerAgreement::TERMS_PENDING,
            'terms_policy_mode' => SuchakCustomerAgreement::POLICY_STRICT,
            'agreement_snapshot_hash' => hash('sha256', 'obligation-revision-2-'.$agreement->id),
            'package_name' => $agreement->package_name,
            'price_amount' => $agreement->price_amount,
            'currency' => $agreement->currency,
            'agreement_title' => 'Terms revision 2',
            'created_by_user_id' => $agreement->created_by_user_id,
            'accepted_by_user_id' => $accepted ? $agreement->accepted_by_user_id : null,
            'accepted_at' => $accepted ? now() : null,
        ]);

        $carried = [];
        $previous = SuchakSuccessFeeTranche::query()
            ->where('customer_agreement_id', $agreement->id)
            ->orderBy('sort_order')
            ->get();

        foreach ($previous as $tranche) {
            $carried[] = SuchakSuccessFeeTranche::query()->create([
                'customer_agreement_id' => $next->id,
                'sort_order' => $tranche->sort_order,
                'trigger_stage_key' => $tranche->trigger_stage_key,
                'share_percent' => $tranche->share_percent,
                'is_final_tranche' => $tranche->is_final_tranche,
            ]);
        }

        return [$next->fresh(), $carried];
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
     * A candidate the publishing Suchak may open to the marketplace, with a package carrying the
     * fixed ₹1,00,000 success fee of §7.1's worked example.
     *
     * @return array{0: SuchakProfileRepresentation, 1: SuchakCustomerAgreement}
     */
    private function publishableCandidate(SuchakAccount $account, User $user): array
    {
        $profile = $this->activeProfile('Sunita Gaikwad');

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

        $agreement = $this->agreementFor($account, $user, $context, withSuccessFee: true);

        return [$representation->fresh(), $agreement->fresh(['customerContext'])];
    }

    private function standaloneAgreement(SuchakAccount $account, User $user, int $candidateProfileId): SuchakCustomerAgreement
    {
        /** @var SuchakCustomerContext $context */
        $context = SuchakCustomerContext::query()->create([
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $candidateProfileId,
            'created_by_user_id' => $user->id,
            'opened_at' => now(),
        ]);

        return $this->agreementFor($account, $user, $context, withSuccessFee: true);
    }

    private function agreementFor(
        SuchakAccount $account,
        User $user,
        SuchakCustomerContext $context,
        bool $withSuccessFee,
    ): SuchakCustomerAgreement {
        /** @var SuchakServicePackage $package */
        $package = SuchakServicePackage::query()->create([
            'suchak_account_id' => $account->id,
            'package_name' => 'Obligation fixture '.$context->id,
            'price_amount' => '25000',
            'currency' => 'INR',
            'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
            'requires_admin_approval' => false,
            'customized_by_user_id' => $user->id,
            'published_at' => now(),
            'post_marriage_fee_mode' => $withSuccessFee ? SuchakCustomerPlan::MODE_FIXED : null,
            'post_marriage_fee_amount' => $withSuccessFee ? '100000' : null,
        ]);

        /** @var SuchakCustomerAgreement $agreement */
        $agreement = SuchakCustomerAgreement::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $context->id,
            'service_package_id' => $package->id,
            'agreement_revision' => 1,
            'terms_status' => SuchakCustomerAgreement::TERMS_ACCEPTED,
            'terms_policy_mode' => SuchakCustomerAgreement::POLICY_STRICT,
            'agreement_snapshot_hash' => hash('sha256', 'obligation-'.$package->id),
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

    private function helperCandidate(SuchakAccount $account): SuchakProfileRepresentation
    {
        $profile = $this->activeProfile('Rahul Kadam');

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
     * A live profile with a canonical residence leaf — the two-step shape the other collaboration
     * suites use, because the residence SSOT observer refuses to let a profile leave draft without
     * one.
     */
    private function activeProfile(string $fullName): MatrimonyProfile
    {
        $state = $this->address('Maharashtra', 'state', 1, null);
        $district = $this->address('Pune', 'district', 2, $state);
        $taluka = $this->address('Shirur', 'taluka', 3, $district);
        $village = $this->address('Ranjangaon', 'village', 4, $taluka, 'rural');

        $profile = MatrimonyProfile::factory()->create([
            'full_name' => $fullName,
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);

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
        ], static fn ($value): bool => $value !== null));
    }
}
