<?php

namespace Tests\Feature\Suchak;

use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPayment;
use App\Models\SuchakCustomerPlan;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPaymentRequest;
use App\Models\SuchakPolicy;
use App\Models\SuchakServicePackage;
use App\Models\SuchakSuccessFeeTranche;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAgreementService;
use App\Modules\Suchak\Services\SuchakCollaborationService;
use App\Modules\Suchak\Services\SuchakMarriageOutcomeService;
use App\Modules\Suchak\Services\SuchakPackageCatalogService;
use App\Modules\Suchak\Services\SuchakPolicyService;
use App\Modules\Suchak\Services\SuchakSuccessFeeTrancheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Blueprint §7.4, M9 and M10 — THE TRANCHE RELEASE.
 *
 * Phase 4's finding in one line: `suchak_success_fee_tranches` shipped with five ledger columns
 * and NOTHING originated them. The only assignment anywhere was the copy-forward in
 * `SuchakAgreementService::persistTranchePlan()`, which moves state a previous revision already
 * held — so `isReleased()`, `isSettled()` and `isCommitted()` could only ever return false, M10
 * had no mechanism of any kind, and M9's guard against re-cutting a committed split returned at
 * its first `if` on every call this system has ever made.
 *
 * Worked example throughout, straight from §7.4: a ₹1,00,000 success fee split
 * 10% at लग्न ठरले, 40% at साखरपुडा, and the remainder at the wedding.
 */
class SuchakTrancheReleaseTest extends TestCase
{
    use RefreshDatabase;

    // ── M10: a later stage releases every earlier unpaid tranche with it ─────────────────────

    public function test_a_wedding_recorded_without_a_sakharpuda_releases_every_earlier_tranche(): void
    {
        $fixture = $this->engagementWithSplit();

        // The whole of M10 in one fixture: the family never recorded a लग्न ठरले and never
        // recorded a साखरपुडा. They married. Both earlier instalments are still owed.
        $this->recordWedding($fixture);

        $payload = $this->trancheService()->release($fixture['collaboration']);
        $rows = $payload['tranches'];

        $this->assertCount(3, $rows);
        $this->assertSame(
            [true, true, true],
            array_column($rows, 'is_released'),
            'A wedding held without a साखरपुडा still owes the engagement tranche (M10).',
        );

        // T1 + T2 through the one arithmetic owner: percentages of the TOTAL, last row the
        // remainder, and the parts sum to the whole exactly.
        $this->assertSame(['10000.00', '40000.00', '50000.00'], array_column($rows, 'amount'));
        // FROZEN digit rule — Latin numerals, Indian grouping, one formatter.
        $this->assertSame(
            ['₹10,000', '₹40,000', '₹50,000'],
            array_column($rows, 'amount_display'),
        );
        $this->assertSame('₹1,00,000', $payload['success_fee_display']);
        $this->assertSame('₹1,00,000', $payload['released_total_display']);
        $this->assertSame('₹0', $payload['settled_total_display']);
        $this->assertSame('₹1,00,000', $payload['outstanding_total_display']);

        // The cascade is RECORDED, not implied: every row names the wedding as what released it,
        // and two of the three say out loud that it was not their own rung.
        foreach ($rows as $row) {
            $this->assertSame(SuchakCollaborationStageEvent::STAGE_MARRIAGE, $row['released_by_stage_key']);
            $this->assertSame((int) $fixture['collaboration']->id, $row['released_by_collaboration_request_id']);
        }
        $this->assertSame([true, true, false], array_column($rows, 'released_by_later_stage'));

        // …and it reached the columns, which is the whole point of this phase.
        $stored = $this->tranches($fixture['agreement']);
        foreach ($stored as $tranche) {
            $this->assertTrue($tranche->isReleased());
            $this->assertTrue($tranche->isCommitted());
            $this->assertFalse($tranche->isSettled());
            $this->assertNotNull($tranche->released_by_stage_event_id);
            $this->assertSame((int) $fixture['collaboration']->id, (int) $tranche->released_by_collaboration_request_id);
        }
    }

    public function test_a_ladder_walked_in_order_credits_each_tranche_to_its_own_rung(): void
    {
        $fixture = $this->engagementWithSplit();
        $service = $this->trancheService();

        $settled = $this->claimAndConfirm($fixture, SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED);
        $service->release($fixture['collaboration']);

        $rows = $service->ledgerPayload($fixture['collaboration'])['tranches'];
        $this->assertSame([true, false, false], array_column($rows, 'is_released'));
        $this->assertSame((int) $settled->id, $rows[0]['released_by_stage_event_id']);
        $this->assertFalse($rows[0]['released_by_later_stage']);
        $this->assertSame('₹10,000', $rows[0]['amount_display']);

        $engagementRung = $this->claimAndConfirm($fixture, SuchakCollaborationStageEvent::STAGE_ENGAGEMENT);
        $service->release($fixture['collaboration']);

        $rows = $service->ledgerPayload($fixture['collaboration'])['tranches'];
        $this->assertSame([true, true, false], array_column($rows, 'is_released'));
        // The first row did not move — its rung is history and history does not get re-credited.
        $this->assertSame((int) $settled->id, $rows[0]['released_by_stage_event_id']);
        $this->assertSame((int) $engagementRung->id, $rows[1]['released_by_stage_event_id']);
    }

    // ── The release is arithmetic, not a job ─────────────────────────────────────────────────

    public function test_released_at_is_the_instant_the_rung_settled_not_the_instant_the_writer_ran(): void
    {
        $fixture = $this->engagementWithSplit();

        $rung = $this->claimAndConfirm($fixture, SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED);
        // Backdate the confirmation: production may not run schedule:run and two queues have had
        // no worker since 2026-06-17, so a release recorded months late must still say WHEN the
        // money was earned — not when somebody finally pressed the button.
        SuchakCollaborationStageEvent::query()
            ->whereKey($rung->id)
            ->update(['confirmed_at' => now()->subDays(94)]);

        $this->trancheService()->release($fixture['collaboration']);

        $tranche = $this->tranches($fixture['agreement'])->first();
        $this->assertNotNull($tranche->released_at);
        $this->assertSame(
            now()->subDays(94)->toDateString(),
            $tranche->released_at->toDateString(),
            'released_at is the rung\'s own instant, so the ledger reads identically whenever it is written.',
        );
    }

    public function test_the_release_is_idempotent(): void
    {
        $fixture = $this->engagementWithSplit();
        $this->recordWedding($fixture);

        $this->trancheService()->release($fixture['collaboration']);
        $first = $this->tranches($fixture['agreement'])
            ->map(fn (SuchakSuccessFeeTranche $t): string => $t->released_at->toIso8601String())
            ->all();

        $this->travel(3)->days();
        $this->trancheService()->release($fixture['collaboration']);
        $second = $this->tranches($fixture['agreement'])
            ->map(fn (SuchakSuccessFeeTranche $t): string => $t->released_at->toIso8601String())
            ->all();

        $this->assertSame($first, $second, 'Running the writer again must change nothing.');
    }

    public function test_the_read_door_is_honest_before_the_release_was_ever_recorded(): void
    {
        $fixture = $this->engagementWithSplit();
        $this->recordWedding($fixture);

        // Nobody has called release(). The arithmetic fallback still answers correctly.
        $rows = $this->trancheService()->ledgerPayload($fixture['collaboration'])['tranches'];

        $this->assertSame([true, true, true], array_column($rows, 'is_released'));
        $this->assertSame([false, false, false], array_column($rows, 'is_recorded'));
        $this->assertSame(
            0,
            SuchakSuccessFeeTranche::query()->whereNotNull('released_at')->count(),
            'The read must not write.',
        );
    }

    // ── What settles a rung, and what does not ───────────────────────────────────────────────

    public function test_a_terminal_rung_that_is_only_claimed_releases_nothing(): void
    {
        $fixture = $this->engagementWithSplit();

        // D26: the last three rungs are claimed, THEN confirmed. A claim alone is a Suchak's word.
        $this->collaborationService()->claimStage(
            $fixture['collaboration'],
            $fixture['helperAccount'],
            $fixture['helperUser'],
            SuchakCollaborationStageEvent::STAGE_MARRIAGE,
        );

        $rows = $this->trancheService()->release($fixture['collaboration'])['tranches'];
        $this->assertSame([false, false, false], array_column($rows, 'is_released'));
    }

    /**
     * REPLACES a test that asserted the exact defect below.
     *
     * The version this replaces built a plan of `meeting_completed` 20% / `marriage` 80% and
     * asserted that the helper's own unconfirmed claim on `meeting_completed` RELEASED ₹20,000 —
     * "a rung that settles on the claim releases without a confirmation". It was green, and it was
     * green about the wrong shape: §7.4 names three releasing events, all three are
     * CONFIRMABLE_STAGES (D26), and M4 says no fee falls due without the customer's confirmation.
     * A rung that settles on one Suchak's tap is not one of them.
     */
    public function test_a_rung_that_settles_on_the_claim_may_not_carry_a_tranche_at_all(): void
    {
        try {
            $this->engagementWithSplit([
                ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MEETING_COMPLETED, 'share_percent' => '20'],
                ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE, 'share_percent' => '80'],
            ]);
            $this->fail('A rung that settles on a Suchak\'s own claim may not release a success-fee tranche.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('भेट झाली', $exception->getMessage());
            $this->assertStringContainsString('ग्राहकाच्या दुजोऱ्यावर', $exception->getMessage());
        }
    }

    // ── BLOCKER 1: only §7.4's three releasing events may trigger a tranche ──────────────────

    public function test_no_tranche_may_be_planned_on_a_rung_outside_the_three_releasing_events(): void
    {
        // PROVEN before this guard, on the §7.4 worked figure: a plan of [meeting_scheduled 100%]
        // on a ₹1,00,000 success fee, claimed by the customer-owning Suchak ALONE, released the
        // whole ₹1,00,000. Same with [meeting_completed 100%] claimed by the helper. No wedding,
        // no साखरपुडा, nobody confirmed anything — M4, D25 and §7.4's refund argument at once,
        // and assertPlanChangeAllowed() then froze the row permanently because it was committed.
        foreach ([
            SuchakCollaborationStageEvent::STAGE_REGISTRATION,
            SuchakCollaborationStageEvent::STAGE_INTERESTED,
            SuchakCollaborationStageEvent::STAGE_MEETING_SCHEDULED,
            SuchakCollaborationStageEvent::STAGE_MEETING_COMPLETED,
            SuchakCollaborationStageEvent::STAGE_MEETING_CONFIRMED,
            // Above the cap: `share_settled` is the HELPER'S OWN receipt for the cross-Suchak
            // share. Planned there a tranche could never fire, and the ledger gave no reason.
            SuchakCollaborationStageEvent::STAGE_SHARE_SETTLED,
        ] as $stageKey) {
            try {
                $this->trancheService()->normalizePlan([
                    ['trigger_stage_key' => $stageKey, 'share_percent' => '100'],
                ]);
                $this->fail('"'.$stageKey.'" may not carry a success-fee tranche.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString(
                    SuchakCollaborationStageEvent::stageLabel($stageKey),
                    $exception->getMessage(),
                );
            }
        }

        // …and the three that may, do.
        $plan = $this->trancheService()->normalizePlan($this->workedExampleSplit());
        $this->assertSame(
            SuchakCollaborationStageEvent::CONFIRMABLE_STAGES,
            array_column($plan, 'trigger_stage_key'),
        );
    }

    public function test_the_releasing_window_is_derived_from_the_ladder_and_is_exactly_d26s_three(): void
    {
        // Not a second hand-written list: a window between two positions on the ONE ladder. It has
        // to come out as CONFIRMABLE_STAGES, because §7.4's three releasing events and D26's three
        // claim-then-confirm rungs are the same three, and if they ever drift this fails loudly.
        $this->assertSame(
            SuchakCollaborationStageEvent::CONFIRMABLE_STAGES,
            SuchakSuccessFeeTrancheService::releasingStages(),
        );
        $this->assertSame(
            SuchakCollaborationStageEvent::STAGE_MARRIAGE,
            SuchakSuccessFeeTrancheService::LAST_RELEASING_STAGE,
        );
        $this->assertSame(
            SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED,
            SuchakSuccessFeeTrancheService::FIRST_RELEASING_STAGE,
        );
    }

    public function test_a_tranche_already_stored_on_a_non_releasing_rung_never_fires_and_says_why(): void
    {
        $fixture = $this->engagementWithSplit();

        // A row the plan door now refuses, written straight to the table the way every plan
        // written before this guard could be. Legacy rows must not release either — the guard
        // that matters is the one in front of the money.
        SuchakSuccessFeeTranche::query()
            ->where('customer_agreement_id', $fixture['agreement']->id)
            ->delete();
        SuchakSuccessFeeTranche::query()->create([
            'customer_agreement_id' => $fixture['agreement']->id,
            'sort_order' => 10,
            'trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MEETING_COMPLETED,
            'share_percent' => '100.00',
            'is_final_tranche' => true,
        ]);

        $this->collaborationService()->claimStage(
            $fixture['collaboration'],
            $fixture['helperAccount'],
            $fixture['helperUser'],
            SuchakCollaborationStageEvent::STAGE_MEETING_COMPLETED,
        );
        // Even a confirmed wedding must not cascade onto it: M10 moves money onto EARLIER
        // instalments, and a rung outside the releasing window is not an instalment at all.
        $this->recordWedding($fixture);

        $payload = $this->trancheService()->release($fixture['collaboration']);

        $this->assertSame([false], array_column($payload['tranches'], 'is_released'));
        $this->assertSame('₹0', $payload['released_total_display']);
        $this->assertStringContainsString(
            'ग्राहकाच्या दुजोऱ्यावर',
            (string) $payload['tranches'][0]['blocked_reason'],
        );
    }

    public function test_share_settled_may_not_release_the_plan(): void
    {
        $fixture = $this->engagementWithSplit();

        // `share_settled` sits AFTER `marriage` on the ladder, settles on the claim, needs no
        // confirmation, and is markable by the HELPER alone — the party being paid. The ladder
        // does not enforce monotonic progress, so nothing stops him claiming it on an engagement
        // that never reached a wedding. If it could release, M10's cascade would fire the entire
        // plan on one unconfirmed tap by the payee (9a A7).
        $this->collaborationService()->claimStage(
            $fixture['collaboration'],
            $fixture['helperAccount'],
            $fixture['helperUser'],
            SuchakCollaborationStageEvent::STAGE_SHARE_SETTLED,
        );

        $rows = $this->trancheService()->release($fixture['collaboration'])['tranches'];
        $this->assertSame([false, false, false], array_column($rows, 'is_released'));
        $this->assertSame(
            SuchakCollaborationStageEvent::STAGE_MARRIAGE,
            SuchakSuccessFeeTrancheService::LAST_RELEASING_STAGE,
        );
    }

    public function test_no_tranche_is_released_under_a_revision_the_customer_has_not_accepted(): void
    {
        // Not a second copy of the ladder's gate. claimStage() checks the terms of the revision
        // the ENGAGEMENT is bound to, when the rung is written; the ledger lives on the LATEST
        // revision, and a fresh one starts `pending`. So a lawfully recorded wedding can end up
        // pointing at terms the family has never seen — which is where money must stop.
        $fixture = $this->engagementWithSplit();
        $this->recordWedding($fixture);

        $revision = app(SuchakAgreementService::class)->createRevisionForPackageChange(
            $fixture['agreement'],
            $fixture['ownerUser'],
            [
                'agreement_title' => 'Re-quoted, not yet accepted',
                'terms_policy_mode' => SuchakCustomerAgreement::POLICY_STRICT,
            ],
        );
        $this->assertSame(SuchakCustomerAgreement::TERMS_PENDING, $revision->fresh()->terms_status);

        $payload = $this->trancheService()->ledgerPayload($fixture['collaboration']);
        $this->assertSame((int) $revision->id, $payload['customer_agreement_id']);
        $this->assertFalse($payload['terms_satisfied']);
        $this->assertSame([false, false, false], array_column($payload['tranches'], 'is_released'));
        $this->assertSame(
            'ग्राहकाने करार स्वीकारेपर्यंत हा हप्ता लागू होत नाही.',
            $payload['tranches'][0]['blocked_reason'],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ग्राहकाने करार स्वीकारेपर्यंत यशस्वी विवाह शुल्काचा कोणताही हप्ता लागू होत नाही.');
        $this->trancheService()->release($fixture['collaboration']);
    }

    // ── M9 hole (ii): the revision guard, in BOTH directions ─────────────────────────────────

    public function test_a_revision_carries_release_state_forward_even_when_the_rest_of_the_split_changed(): void
    {
        $fixture = $this->engagementWithSplit();
        $this->claimAndConfirm($fixture, SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED);
        $this->trancheService()->release($fixture['collaboration']);

        // The settlement broke. The family and the Suchak re-shape the two instalments that have
        // NOT happened and leave the one that has. Before this phase, persistTranchePlan() carried
        // state only when the plan was byte-identical, so this revision wrote three rows with all
        // five ledger columns null — a clean slate, and the family exposed to the whole fee twice.
        $revision = app(SuchakAgreementService::class)->createRevisionForPackageChange(
            $fixture['agreement'],
            $fixture['ownerUser'],
            ['success_fee_tranches' => [
                ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED, 'share_percent' => '10'],
                ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_ENGAGEMENT, 'share_percent' => '20'],
                ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE, 'share_percent' => '70'],
            ]],
        );

        $carried = $this->tranches($revision);
        $this->assertCount(3, $carried);
        $this->assertTrue($carried[0]->isReleased(), 'A released instalment stays released across a revision.');
        $this->assertNotNull($carried[0]->released_by_stage_event_id);
        $this->assertFalse($carried[1]->isReleased());
        $this->assertFalse($carried[2]->isReleased());
        $this->assertSame(['10.00', '20.00', '70.00'], $carried->pluck('share_percent')->map(strval(...))->all());
    }

    public function test_a_revision_that_recuts_a_released_tranche_is_refused_by_name(): void
    {
        $fixture = $this->engagementWithSplit();
        $this->claimAndConfirm($fixture, SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED);
        $this->trancheService()->release($fixture['collaboration']);

        try {
            app(SuchakAgreementService::class)->createRevisionForPackageChange(
                $fixture['agreement'],
                $fixture['ownerUser'],
                ['success_fee_tranches' => [
                    ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED, 'share_percent' => '30'],
                    ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_ENGAGEMENT, 'share_percent' => '20'],
                    ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE, 'share_percent' => '50'],
                ]],
            );
            $this->fail('A released instalment may not be re-cut.');
        } catch (InvalidArgumentException $exception) {
            // Named, because "the split may not be changed" leaves a Suchak staring at three rows.
            $this->assertStringContainsString('लग्न ठरल्यावर', $exception->getMessage());
        }
    }

    public function test_a_released_tranche_may_not_be_dropped_from_the_plan(): void
    {
        $fixture = $this->engagementWithSplit();
        $this->claimAndConfirm($fixture, SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED);
        $this->trancheService()->release($fixture['collaboration']);

        try {
            app(SuchakAgreementService::class)->createRevisionForPackageChange(
                $fixture['agreement'],
                $fixture['ownerUser'],
                ['success_fee_tranches' => [
                    ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_ENGAGEMENT, 'share_percent' => '40'],
                    ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE, 'share_percent' => '60'],
                ]],
            );
            $this->fail('A released instalment may not be deleted by a revision.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('काढून टाकता येणार नाही', $exception->getMessage());
        }
    }

    public function test_the_release_writes_to_the_latest_revision_not_the_one_the_engagement_was_bound_to(): void
    {
        $fixture = $this->engagementWithSplit();

        // The engagement's binding is write-once (blueprint 6.1). Revisions after it carry the
        // ledger forward, so the LIVE rows are the latest revision's — releasing onto the bound
        // revision would write to a ledger nothing reads.
        $revision = app(SuchakAgreementService::class)->createRevisionForPackageChange(
            $fixture['agreement'],
            $fixture['ownerUser'],
            [
                'agreement_title' => 'Same terms, second revision',
                'terms_policy_mode' => SuchakCustomerAgreement::POLICY_OPTIONAL,
            ],
        );
        $this->assertNotSame((int) $fixture['agreement']->id, (int) $revision->id);

        $this->recordWedding($fixture);
        $payload = $this->trancheService()->release($fixture['collaboration']);

        $this->assertSame((int) $revision->id, $payload['customer_agreement_id']);
        $this->assertSame(2, $payload['agreement_revision']);
        $this->assertSame(3, $this->tranches($revision)->whereNotNull('released_at')->count());
        $this->assertSame(0, $this->tranches($fixture['agreement'])->whereNotNull('released_at')->count());
    }

    // ── M9 hole (i): two plans sent to one family ────────────────────────────────────────────

    public function test_one_rung_is_not_charged_twice_when_a_family_was_sent_two_plans(): void
    {
        $basic = $this->engagementWithSplit();

        // `suchak_service_packages.customer_context_id` has an index and no unique, and
        // payment-setup matches an existing package by `package_name`, so "Basic" then "Premium"
        // to one family builds two packages, two agreement chains and two tranche ledgers, EACH
        // carrying a full 100%. That is the shape M9 had no expression against at all.
        $premium = $this->secondPlanForSameFamily($basic);

        $this->claimAndConfirm($basic, SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED);
        $this->trancheService()->release($basic['collaboration']);
        $this->assertTrue($this->tranches($basic['agreement'])->first()->isReleased());

        $this->claimAndConfirm($premium, SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED);
        $payload = $this->trancheService()->release($premium['collaboration']);

        $this->assertFalse(
            $payload['tranches'][0]['is_released'],
            'लग्न ठरले is owed once by this family, whichever plan the Suchak sent (M9).',
        );
        $this->assertStringContainsString(
            'दुसऱ्या योजनेत',
            (string) $payload['tranches'][0]['blocked_reason'],
        );

        // Published rather than hidden: the second chain says which agreement already holds it.
        $this->assertSame(
            [[
                'trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED,
                'trigger_stage_label' => 'लग्न ठरल्यावर',
                'customer_agreement_id' => (int) $basic['agreement']->id,
            ]],
            $payload['other_chain_commitments'],
        );
    }

    // ── BLOCKER 2: M9's unit is the family's MONEY, not the stage key ────────────────────────

    public function test_two_plans_with_disjoint_triggers_may_not_charge_one_family_two_success_fees(): void
    {
        // PROVEN before this guard: one customer context, Basic [लग्न ठरले 10%, साखरपुडा 90%] and
        // Premium [विवाह 100%], each quoting ₹1,00,000. The stage-key guard blocks a tranche only
        // when its TRIGGER is already committed on a sibling chain, and these two plans share no
        // trigger at all — so they collided with nothing and the family was charged ₹2,00,000.
        // M9: the success fee is paid once per customer, IN TOTAL.
        $basic = $this->engagementWithSplit([
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED, 'share_percent' => '10'],
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_ENGAGEMENT, 'share_percent' => '90'],
        ]);
        $premium = $this->secondPlanForSameFamily($basic, [
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE, 'share_percent' => '100'],
        ]);

        $this->claimAndConfirm($basic, SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED);
        $this->claimAndConfirm($basic, SuchakCollaborationStageEvent::STAGE_ENGAGEMENT);
        $basicPayload = $this->trancheService()->release($basic['collaboration']);
        $this->assertSame('₹1,00,000', $basicPayload['released_total_display']);

        $this->recordWedding($premium);
        $premiumPayload = $this->trancheService()->release($premium['collaboration']);

        $this->assertSame(
            [false],
            array_column($premiumPayload['tranches'], 'is_released'),
            'The whole of this family\'s success fee is already committed on the other plan (M9).',
        );
        $this->assertSame('₹0', $premiumPayload['released_total_display']);
        // Named in money, because "already charged" on a screen with one row on it says nothing.
        $this->assertStringContainsString('₹1,00,000', (string) $premiumPayload['tranches'][0]['blocked_reason']);

        // The family's exposure across BOTH chains is the one agreed figure.
        $this->assertSame(
            '100000.00',
            number_format($this->releasedRupeesForFamily($basic['context']->id), 2, '.', ''),
        );
    }

    public function test_the_family_cap_leaves_room_for_what_the_other_plan_did_not_take(): void
    {
        // The guard is a BUDGET, not a veto: Basic committed ₹10,000 of the family's ₹1,00,000, so
        // Premium may still release an instalment that fits in the ₹90,000 that is left.
        $basic = $this->engagementWithSplit([
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED, 'share_percent' => '10'],
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_ENGAGEMENT, 'share_percent' => '90'],
        ]);
        $premium = $this->secondPlanForSameFamily($basic, [
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_ENGAGEMENT, 'share_percent' => '40'],
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE, 'share_percent' => '60'],
        ]);

        $this->claimAndConfirm($basic, SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED);
        $this->trancheService()->release($basic['collaboration']);

        $this->recordWedding($premium);
        $premiumPayload = $this->trancheService()->release($premium['collaboration']);

        // साखरपुडा is refused by the STAGE guard? No — Basic has not committed it. It is refused
        // by neither: ₹40,000 fits in the ₹90,000 left. The wedding's ₹60,000 does not.
        $this->assertSame([true, false], array_column($premiumPayload['tranches'], 'is_released'));
        $this->assertSame('₹40,000', $premiumPayload['released_total_display']);
        $this->assertSame(
            '50000.00',
            number_format($this->releasedRupeesForFamily($basic['context']->id), 2, '.', ''),
        );
    }

    // ── The other two columns: the family actually paid ──────────────────────────────────────

    public function test_a_tranche_is_settled_by_a_recorded_payment_and_never_before_it_is_released(): void
    {
        $fixture = $this->engagementWithSplit();
        $service = $this->trancheService();
        $tranche = $this->tranches($fixture['agreement'])->first();
        $payment = $this->paidCustomerPayment($fixture, '10000');

        // D25: every rupee is taken for an event that ALREADY HAPPENED. Money against a tranche
        // that has not fired is money taken for a future that may not arrive.
        try {
            $service->settle($tranche, $payment);
            $this->fail('An unreleased tranche may not be settled.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('लागू न झालेल्या', $exception->getMessage());
        }

        $this->claimAndConfirm($fixture, SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED);
        $service->release($fixture['collaboration']);

        $settled = $service->settle($tranche->fresh(), $payment);
        $this->assertTrue($settled->isSettled());
        $this->assertSame((int) $payment->id, (int) $settled->customer_payment_id);
        $this->assertSame(
            $payment->payment_received_at->toDateString(),
            $settled->settled_at->toDateString(),
        );

        $payload = $service->ledgerPayload($fixture['collaboration']);
        $this->assertSame('₹10,000', $payload['settled_total_display']);
        $this->assertSame('₹0', $payload['outstanding_total_display']);
    }

    // ── BLOCKER 3: a receipt settles what it covers, and nothing it does not ─────────────────

    /**
     * REPLACES `test_one_payment_settles_one_tranche`, which asserted the wrong shape.
     *
     * That test pinned "one payment, one tranche" as an absolute — and that rule is precisely what
     * made M10's headline case unsettleable: a wedding cascades three instalments, the family pays
     * the whole ₹1,00,000 in ONE receipt, and only one instalment could ever be marked paid while
     * the other two stayed unsettled forever. The real rule is a budget, and it is asserted below
     * in both directions.
     */
    public function test_a_receipt_may_not_settle_more_than_it_carries(): void
    {
        $fixture = $this->engagementWithSplit();
        $service = $this->trancheService();
        $this->recordWedding($fixture);
        $service->release($fixture['collaboration']);

        $tranches = $this->tranches($fixture['agreement']);
        $payment = $this->paidCustomerPayment($fixture, '10000');
        $service->settle($tranches[0], $payment);

        // ₹10,000 received, ₹10,000 already accounted for. Nothing is left for the ₹40,000 row.
        try {
            $service->settle($tranches[1]->fresh(), $payment);
            $this->fail('A ₹10,000 receipt may not also settle a ₹40,000 instalment.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('₹10,000', $exception->getMessage());
        }

        $this->assertSame('₹10,000', $service->ledgerPayload($fixture['collaboration'])['settled_total_display']);
    }

    public function test_a_one_rupee_receipt_does_not_settle_a_fifty_thousand_rupee_instalment(): void
    {
        // PROVEN before this guard: settle() checked released-first, same agreement, payment_status
        // and one-payment-one-tranche — and never once compared the RECEIPT to the AMOUNT. A ₹1
        // payment set settled_at on the ₹50,000 wedding instalment and settled_total_display then
        // read ₹50,000. `settled_at` is M9's own paid predicate AND M3's half A, so a one-rupee
        // receipt made the family read as paid and made the cross-Suchak share fall due at once.
        $fixture = $this->engagementWithSplit();
        $service = $this->trancheService();
        $this->recordWedding($fixture);
        $service->release($fixture['collaboration']);

        $tranches = $this->tranches($fixture['agreement']);
        $rupee = $this->paidCustomerPayment($fixture, '1');

        try {
            $service->settle($tranches[2], $rupee);
            $this->fail('A ₹1 receipt may not settle a ₹50,000 instalment.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('₹50,000', $exception->getMessage());
            $this->assertStringContainsString('₹1', $exception->getMessage());
        }

        $this->assertFalse($tranches[2]->fresh()->isSettled());
        $payload = $service->ledgerPayload($fixture['collaboration']);
        $this->assertSame('₹0', $payload['settled_total_display']);
        $this->assertSame('₹1,00,000', $payload['outstanding_total_display']);
    }

    public function test_one_receipt_settles_the_whole_cascade_it_paid_for(): void
    {
        // M10's headline case: a wedding held without a साखरपुडा cascades all three instalments,
        // and the family pays the agreed ₹1,00,000 in ONE receipt. Under "one payment, one
        // tranche" two of the three could never be settled by any receipt at all.
        $fixture = $this->engagementWithSplit();
        $service = $this->trancheService();
        $this->recordWedding($fixture);
        $service->release($fixture['collaboration']);

        $whole = $this->paidCustomerPayment($fixture, '100000');
        foreach ($this->tranches($fixture['agreement']) as $tranche) {
            $service->settle($tranche->fresh(), $whole);
        }

        $payload = $service->ledgerPayload($fixture['collaboration']);
        $this->assertSame([true, true, true], array_column($payload['tranches'], 'is_settled'));
        $this->assertSame('₹1,00,000', $payload['settled_total_display']);
        $this->assertSame('₹0', $payload['outstanding_total_display']);
        $this->assertSame(
            [(int) $whole->id, (int) $whole->id, (int) $whole->id],
            array_column($payload['tranches'], 'customer_payment_id'),
        );
    }

    public function test_an_unpaid_payment_row_does_not_settle_a_tranche(): void
    {
        $fixture = $this->engagementWithSplit();
        $service = $this->trancheService();
        $this->claimAndConfirm($fixture, SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED);
        $service->release($fixture['collaboration']);

        $pending = $this->paidCustomerPayment($fixture, '10000');
        SuchakCustomerPayment::query()
            ->whereKey($pending->id)
            ->update(['payment_status' => SuchakCustomerPayment::STATUS_PENDING]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('भरणा पूर्ण झाल्याची नोंद नसताना हप्ता भरला असे नोंदवता येणार नाही.');
        $service->settle($this->tranches($fixture['agreement'])->first(), $pending->fresh());
    }

    // ── The door ─────────────────────────────────────────────────────────────────────────────

    public function test_the_ledger_has_routes_and_the_release_runs_through_them(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => $route->methods()[0].' '.$route->uri())
            ->all();

        foreach ([
            'GET api/v1/suchak/collaborations/{collaboration}/success-fee-tranches',
            'POST api/v1/suchak/collaborations/{collaboration}/success-fee-tranches/release',
            'POST api/v1/suchak/collaborations/{collaboration}/success-fee-tranches/{tranche}/settlement',
        ] as $expected) {
            $this->assertContains($expected, $uris, $expected.' must exist — a capability with no door is unreachable.');
        }

        $fixture = $this->engagementWithSplit();
        $this->recordWedding($fixture);

        Sanctum::actingAs($fixture['ownerUser']);
        $collaborationId = $fixture['collaboration']->id;

        // The GET is honest before anything is recorded.
        $read = $this->getJson("/api/v1/suchak/collaborations/{$collaborationId}/success-fee-tranches");
        $read->assertOk()
            ->assertJsonPath('data.tranches.0.is_released', true)
            ->assertJsonPath('data.tranches.0.is_recorded', false);

        $written = $this->postJson("/api/v1/suchak/collaborations/{$collaborationId}/success-fee-tranches/release");
        $written->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tranches.0.is_recorded', true)
            ->assertJsonPath('data.released_total_display', '₹1,00,000');

        $this->assertSame(3, $this->tranches($fixture['agreement'])->whereNotNull('released_at')->count());
    }

    public function test_a_stranger_suchak_is_told_nothing_and_the_helper_may_not_record_the_payment(): void
    {
        $fixture = $this->engagementWithSplit();
        $this->recordWedding($fixture);
        $this->trancheService()->release($fixture['collaboration']);
        $collaborationId = $fixture['collaboration']->id;
        $trancheId = $this->tranches($fixture['agreement'])->first()->id;

        // The existence of an engagement is information about two other Suchaks and two families.
        [$strangerUser] = $this->verifiedSuchakActor();
        Sanctum::actingAs($strangerUser);
        $this->getJson("/api/v1/suchak/collaborations/{$collaborationId}/success-fee-tranches")
            ->assertStatus(404);

        // M1: each customer pays only their OWN Suchak. A helper marking another family's money
        // as received is the forgery 9a A7 exists to stop.
        Sanctum::actingAs($fixture['helperUser']);
        $this->postJson(
            "/api/v1/suchak/collaborations/{$collaborationId}/success-fee-tranches/{$trancheId}/settlement",
            ['customer_payment_id' => 1],
        )->assertStatus(403);
    }

    // ────────────────────────────────────────────────────────── fixtures ────────────────────

    /**
     * An accepted engagement whose customer-owning side holds a real, frozen agreement with the
     * §7.4 worked-example split on a ₹1,00,000 success fee.
     *
     * Built through the real services end to end — package catalog, agreement snapshot, engagement
     * binding — so the ledger under test sits on rows the product actually produces.
     *
     * @param  ?list<array<string, mixed>>  $plan
     * @return array{
     *     ownerUser: User, ownerAccount: SuchakAccount, helperUser: User, helperAccount: SuchakAccount,
     *     collaboration: SuchakCollaborationRequest, agreement: SuchakCustomerAgreement,
     *     context: SuchakCustomerContext
     * }
     */
    private function engagementWithSplit(
        ?array $plan = null,
        string $policyMode = SuchakCustomerAgreement::POLICY_OPTIONAL,
    ): array {
        [$ownerUser, $ownerAccount] = $this->verifiedSuchakActor();
        [$helperUser, $helperAccount] = $this->verifiedSuchakActor();

        /** @var SuchakCollaborationRequest $collaboration */
        $collaboration = SuchakCollaborationRequest::factory()->create([
            'requesting_suchak_account_id' => $ownerAccount->id,
            'target_suchak_account_id' => $helperAccount->id,
            'status' => SuchakCollaborationRequest::STATUS_ACCEPTED,
            'requested_at' => now()->subMonths(6),
            'responded_at' => now()->subMonths(6),
        ]);

        $context = $this->customerContext($ownerAccount, $ownerUser, (int) $collaboration->requesting_matrimony_profile_id);
        $package = $this->publishedPackage($ownerAccount, $ownerUser, $context, 'Basic');

        $agreement = app(SuchakAgreementService::class)->createAgreementForPackage(
            $package,
            $ownerUser,
            [
                'agreement_title' => 'Tranche release fixture',
                'terms_policy_mode' => $policyMode,
                'success_fee_tranches' => $plan ?? $this->workedExampleSplit(),
            ],
        );

        $linked = app(SuchakCollaborationService::class)->linkCustomerAgreement(
            $collaboration,
            $ownerAccount,
            $ownerUser,
            $agreement,
        );

        return [
            'ownerUser' => $ownerUser,
            'ownerAccount' => $ownerAccount,
            'helperUser' => $helperUser,
            'helperAccount' => $helperAccount,
            'collaboration' => $linked,
            'agreement' => $agreement,
            'context' => $context,
        ];
    }

    /**
     * A SECOND package, agreement chain and engagement for the SAME family — the shape M9 hole (i)
     * produces in production the moment a Suchak sends "Basic" and later "Premium".
     *
     * @param  array<string, mixed>  $basic
     * @param  ?list<array<string, mixed>>  $plan
     * @return array<string, mixed>
     */
    private function secondPlanForSameFamily(array $basic, ?array $plan = null): array
    {
        [$otherHelperUser, $otherHelperAccount] = $this->verifiedSuchakActor();

        /** @var SuchakCollaborationRequest $collaboration */
        $collaboration = SuchakCollaborationRequest::factory()->create([
            'requesting_suchak_account_id' => $basic['ownerAccount']->id,
            'target_suchak_account_id' => $otherHelperAccount->id,
            // Same family, different candidate proposed by a different helper.
            'requesting_matrimony_profile_id' => $basic['collaboration']->requesting_matrimony_profile_id,
            'status' => SuchakCollaborationRequest::STATUS_ACCEPTED,
            'requested_at' => now()->subMonths(6),
            'responded_at' => now()->subMonths(6),
        ]);

        $package = $this->publishedPackage(
            $basic['ownerAccount'],
            $basic['ownerUser'],
            $basic['context'],
            'Premium',
        );

        $agreement = app(SuchakAgreementService::class)->createAgreementForPackage(
            $package,
            $basic['ownerUser'],
            [
                'agreement_title' => 'Second plan to the same family',
                'terms_policy_mode' => SuchakCustomerAgreement::POLICY_OPTIONAL,
                'success_fee_tranches' => $plan ?? $this->workedExampleSplit(),
            ],
        );

        $linked = app(SuchakCollaborationService::class)->linkCustomerAgreement(
            $collaboration,
            $basic['ownerAccount'],
            $basic['ownerUser'],
            $agreement,
        );

        return [
            'ownerUser' => $basic['ownerUser'],
            'ownerAccount' => $basic['ownerAccount'],
            'helperUser' => $otherHelperUser,
            'helperAccount' => $otherHelperAccount,
            'collaboration' => $linked,
            'agreement' => $agreement,
            'context' => $basic['context'],
        ];
    }

    /**
     * @return list<array<string, string>> §7.4's worked example: 10% / 40% / the remainder.
     */
    private function workedExampleSplit(): array
    {
        return [
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED, 'share_percent' => '10'],
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_ENGAGEMENT, 'share_percent' => '40'],
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE, 'share_percent' => '50'],
        ];
    }

    /**
     * The wedding, through the §6.2 door another agent built, then confirmed. Phase 4's two halves
     * meeting: the marriage outcome names the engagement credited, and this ledger prices it.
     *
     * @param  array<string, mixed>  $fixture
     */
    private function recordWedding(array $fixture): void
    {
        app(SuchakMarriageOutcomeService::class)->record(
            $fixture['collaboration'],
            $fixture['helperAccount'],
            $fixture['helperUser'],
            now()->subDays(20)->toDateString(),
        );

        app(SuchakCollaborationService::class)->confirmStage(
            $fixture['collaboration'],
            $this->admin(),
            SuchakCollaborationStageEvent::STAGE_MARRIAGE,
        );
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function claimAndConfirm(array $fixture, string $stageKey): SuchakCollaborationStageEvent
    {
        $service = app(SuchakCollaborationService::class);
        $service->claimStage(
            $fixture['collaboration'],
            $fixture['helperAccount'],
            $fixture['helperUser'],
            $stageKey,
        );

        return $service->confirmStage($fixture['collaboration'], $this->admin(), $stageKey);
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function paidCustomerPayment(array $fixture, string $amount): SuchakCustomerPayment
    {
        /** @var SuchakPaymentContext $paymentContext */
        $paymentContext = SuchakPaymentContext::query()->create([
            'suchak_account_id' => $fixture['ownerAccount']->id,
            'customer_context_id' => $fixture['context']->id,
            'matrimony_profile_id' => $fixture['context']->candidate_matrimony_profile_id,
            'source_owner' => SuchakPaymentContext::SOURCE_SUCHAK,
            'payment_collector' => SuchakPaymentContext::COLLECTOR_SUCHAK,
            'context_status' => SuchakPaymentContext::STATUS_ACTIVE,
            'resolved_by_user_id' => $fixture['ownerUser']->id,
            'resolution_note' => 'Tranche settlement fixture.',
        ]);

        /** @var SuchakPaymentRequest $paymentRequest */
        $paymentRequest = SuchakPaymentRequest::query()->create([
            'suchak_account_id' => $fixture['ownerAccount']->id,
            'customer_context_id' => $fixture['context']->id,
            'service_package_id' => $fixture['agreement']->service_package_id,
            'customer_agreement_id' => $fixture['agreement']->id,
            'payment_context_id' => $paymentContext->id,
            'requested_by_user_id' => $fixture['ownerUser']->id,
            'request_token_hash' => hash('sha256', 'tranche-settlement-'.uniqid('', true)),
            'payment_status' => SuchakPaymentRequest::STATUS_PAID,
            'request_title' => 'Success fee instalment',
            'amount_due' => $amount,
            'currency' => 'INR',
            'collector_disclosure' => 'सूचक स्वतः रक्कम स्वीकारतील.',
            'sent_at' => now()->subDays(3),
        ]);

        /** @var SuchakCustomerPayment $payment */
        $payment = SuchakCustomerPayment::query()->create([
            'suchak_account_id' => $fixture['ownerAccount']->id,
            'customer_context_id' => $fixture['context']->id,
            'payment_context_id' => $paymentContext->id,
            'payment_request_id' => $paymentRequest->id,
            'service_package_id' => $fixture['agreement']->service_package_id,
            'customer_agreement_id' => $fixture['agreement']->id,
            'recorded_by_user_id' => $fixture['ownerUser']->id,
            'collection_channel' => SuchakCustomerPayment::CHANNEL_SUCHAK_DIRECT,
            'payment_mode' => SuchakCustomerPayment::MODE_UPI,
            'payment_status' => SuchakCustomerPayment::STATUS_PAID,
            'amount_due' => $amount,
            'amount_received' => $amount,
            'balance_amount' => '0',
            'currency' => 'INR',
            'payment_received_at' => now()->subDays(2),
        ]);

        return $payment;
    }

    private function customerContext(
        SuchakAccount $account,
        User $user,
        int $candidateProfileId,
    ): SuchakCustomerContext {
        /** @var SuchakCustomerContext $context */
        $context = SuchakCustomerContext::query()->create([
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $candidateProfileId,
            'service_context' => SuchakCustomerContext::SERVICE_PROFILE_REPRESENTATION,
            'source_owner' => SuchakCustomerContext::SOURCE_OWNER_SUCHAK,
            'source_type' => SuchakCustomerContext::SOURCE_TYPE_MANUAL,
            'customer_lifecycle_status' => SuchakCustomerContext::STATUS_ACTIVE_SERVICE,
            'created_by_user_id' => $user->id,
            'opened_at' => now(),
        ]);

        return $context;
    }

    private function publishedPackage(
        SuchakAccount $account,
        User $user,
        SuchakCustomerContext $context,
        string $packageName,
    ): SuchakServicePackage {
        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE],
            [
                'policy_value' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Auto publish packages for the tranche-release fixture.',
                'is_active' => true,
            ],
        );

        $package = app(SuchakPackageCatalogService::class)->createCustomPackage(
            $account,
            $user,
            [
                'package_name' => $packageName,
                'price_amount' => '5000',
                'currency' => 'INR',
                'post_marriage_fee_mode' => SuchakCustomerPlan::MODE_FIXED,
                'post_marriage_fee_amount' => '100000',
            ],
            [[
                'stage_key' => 'intake_and_shortlist',
                'stage_name' => 'Intake and shortlist',
                'sort_order' => 10,
                'expected_days' => 7,
            ]],
            [[
                'deliverable_key' => 'shortlist_pack',
                'deliverable_name' => 'Shortlist pack',
                'stage_key' => 'intake_and_shortlist',
                'sort_order' => 10,
            ]],
            $context,
            null,
            null,
            true,
        );

        return $package->fresh(['suchakAccount.user', 'stages', 'deliverables.servicePackageStage']);
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
     * D26/§7.2 keep an admin path beside the family's own confirmation, and a family with no login
     * (§2) cannot be the confirming actor in a fixture. A participating Suchak is refused by
     * confirmationActorType(), which is the point.
     */
    private function admin(): User
    {
        /** @var User $admin */
        $admin = User::factory()->create(['is_admin' => true]);

        return $admin;
    }

    /**
     * Every rupee this FAMILY has been charged of a success fee, across every plan the Suchak
     * sent them — M9's unit, computed here from the stored rows rather than from the service, so
     * the assertion does not lean on the thing it is checking.
     */
    private function releasedRupeesForFamily(int $customerContextId): float
    {
        $total = 0.0;

        $agreements = SuchakCustomerAgreement::query()
            ->where('customer_context_id', $customerContextId)
            ->with('servicePackage')
            ->orderByDesc('agreement_revision')
            ->get()
            // The ledger lives on the latest revision of each package chain; earlier revisions
            // hold carried-forward copies of the same money.
            ->unique('service_package_id');

        foreach ($agreements as $agreement) {
            $tranches = $this->tranches($agreement);
            if ($tranches->isEmpty()) {
                continue;
            }

            $amounts = app(SuchakSuccessFeeTrancheService::class)
                ->amounts($agreement->servicePackage?->post_marriage_fee_amount, $tranches);

            foreach ($tranches->values()->all() as $index => $tranche) {
                if ($tranche->isCommitted()) {
                    $total += (float) ($amounts[$index] ?? 0);
                }
            }
        }

        return $total;
    }

    /**
     * @return \Illuminate\Support\Collection<int, SuchakSuccessFeeTranche>
     */
    private function tranches(SuchakCustomerAgreement $agreement)
    {
        return SuchakSuccessFeeTranche::query()
            ->where('customer_agreement_id', $agreement->id)
            ->orderBy('sort_order')
            ->get();
    }

    private function trancheService(): SuchakSuccessFeeTrancheService
    {
        return app(SuchakSuccessFeeTrancheService::class);
    }

    private function collaborationService(): SuchakCollaborationService
    {
        return app(SuchakCollaborationService::class);
    }
}
