<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCommissionAgreement;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPlan;
use App\Models\SuchakDispute;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPayoutHold;
use App\Models\SuchakPipeline;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileRequest;
use App\Models\SuchakScheduledJobRun;
use App\Models\SuchakServicePackage;
use App\Models\SuchakVisitConfirmation;
use App\Models\SuchakVisitConfirmationEvent;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakClaimSilenceService;
use App\Modules\Suchak\Services\SuchakMarketplaceChallengeService;
use App\Modules\Suchak\Services\SuchakSafetyService;
use App\Modules\Suchak\Services\SuchakVisitConfirmationService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * THE §7.2 CLOCK — seven silent days, the stop-loss, and the ninety-day lapse.
 *
 * Three rules that only hold together, and each of them has an obvious wrong implementation this
 * class exists to refuse:
 *
 *  - SILENCE (M4/M5). The wrong versions are "no answer in 7 days = ₹0" and "no answer in 7 days =
 *    the fee is due". Both are refused: silence opens a DISPUTE and the fee stays exactly as
 *    undecided as it was.
 *  - STOP-LOSS (clause 3). The wrong version counts nothing until an adjudicator says so. The
 *    numbers are the blueprint's — 2 claims, or ₹5,000.
 *  - LAPSE (clause 4). The wrong version lets a Suchak clear his own counter by waiting 90 days.
 *    M3 forbids exactly that: doing nothing must never make an obligation disappear.
 */
class SuchakSilenceTimerTest extends TestCase
{
    use RefreshDatabase;

    // ── The clock itself ──────────────────────────────────────────────────────────────────────

    public function test_the_clock_column_exists_and_carries_the_blueprints_own_numbers(): void
    {
        $this->assertTrue(Schema::hasColumn('suchak_visit_confirmations', 'claim_unanswered_since'));

        // Read from §7.2, not invented. A change here is a blueprint change request.
        $this->assertSame(7, SuchakVisitConfirmation::CLAIM_SILENCE_WINDOW_DAYS);
        $this->assertSame(90, SuchakVisitConfirmation::CLAIM_LAPSE_DAYS);
        $this->assertSame(2, SuchakVisitConfirmation::STOP_LOSS_UNANSWERED_CLAIMS);
        $this->assertSame('5000.00', SuchakVisitConfirmation::STOP_LOSS_UNANSWERED_AMOUNT);
        $this->assertSame('INR', SuchakVisitConfirmation::STOP_LOSS_CURRENCY);

        // The ninth scheduled job is LAST. runTrackedJob() re-throws and aborts everything after
        // the failure, so a money sweep may have nothing behind it.
        $this->assertSame(
            SuchakScheduledJobRun::JOB_CLAIM_SILENCE_SWEEP,
            SuchakScheduledJobRun::JOBS[array_key_last(SuchakScheduledJobRun::JOBS)],
        );
    }

    public function test_seven_silent_days_open_a_dispute_and_never_a_zero_or_a_payment(): void
    {
        $this->travelTo(Carbon::parse('2026-07-01 09:00:00'));
        [$admin, , , $account, $visit] = $this->unansweredClaim('3000.00');

        $this->travelTo(Carbon::parse('2026-07-08 09:01:00'));
        $swept = app(SuchakClaimSilenceService::class)->sweepSilenceDue($account);

        $this->assertSame(1, $swept['disputes_opened']);
        $fresh = $visit->fresh();

        // A DISPUTE — the blueprint's answer, and neither of the two shortcuts.
        $this->assertSame(SuchakVisitConfirmation::STATUS_DISPUTED, $fresh->visit_status);
        $this->assertNotNull($fresh->dispute_id);
        $this->assertNotNull($fresh->payout_hold_id);
        $this->assertNotNull($fresh->claim_unanswered_since);
        $this->assertSame(
            '2026-07-08 09:00:00',
            $fresh->claim_unanswered_since->format('Y-m-d H:i:s'),
            'clause 5 — the window runs from delivery, so the stamp is delivery + 7 days, not sweep time',
        );

        // NOT an automatic zero: the review is open, and `pending_review` is not a finding.
        $this->assertSame(SuchakVisitConfirmation::REFUND_PENDING_REVIEW, $fresh->refund_review_status);
        $this->assertFalse($fresh->isFeeRefusedByReview());

        // NOT an automatic payment, and NOT a refusal put in the family's mouth: their own column
        // is untouched. Silence is not a "no".
        $this->assertSame(SuchakVisitConfirmation::CONFIRMATION_PENDING, $fresh->user_confirmation_status);
        $this->assertNull($fresh->user_confirmed_at);

        // The money is frozen on the ARRANGING Suchak — §7.3's real leverage.
        $hold = SuchakPayoutHold::query()->findOrFail($fresh->payout_hold_id);
        $this->assertSame(SuchakPayoutHold::STATUS_ACTIVE, $hold->hold_status);
        $this->assertSame((int) $account->id, (int) $hold->suchak_account_id);
        $this->assertSame(SuchakPayoutHold::SCOPE_VISIT_CONFIRMATION_DISPUTE, $hold->hold_scope);

        // Nobody acted; a date arrived. No user is fabricated anywhere on the trail.
        $dispute = SuchakDispute::query()->findOrFail($fresh->dispute_id);
        $this->assertSame(SuchakDispute::STATUS_OPEN, $dispute->status);
        $this->assertNull($dispute->opened_by_user_id);
        $this->assertNull($dispute->assigned_admin_user_id);
        $this->assertNull($hold->created_by_user_id);
        $this->assertDatabaseHas('suchak_visit_confirmation_events', [
            'visit_confirmation_id' => $visit->id,
            'event_type' => SuchakVisitConfirmationEvent::EVENT_DISPUTED,
            'actor_type' => SuchakVisitConfirmationEvent::ACTOR_SYSTEM,
            'actor_user_id' => null,
        ]);

        // And no payout can qualify off a silence.
        $this->expectException(InvalidArgumentException::class);
        app(SuchakVisitConfirmationService::class)->qualifyPayoutForVisit($fresh, $admin, [
            'qualification_note' => 'Trying to pay a meeting nobody answered.',
        ]);
    }

    public function test_the_clock_does_not_fire_before_the_window_closes(): void
    {
        $this->travelTo(Carbon::parse('2026-07-01 09:00:00'));
        [, , , $account, $visit] = $this->unansweredClaim('3000.00');

        $this->travelTo(Carbon::parse('2026-07-07 23:59:00'));
        $swept = app(SuchakClaimSilenceService::class)->sweepSilenceDue($account);

        $this->assertSame(0, $swept['due_total']);
        $this->assertSame(0, $swept['disputes_opened']);
        $this->assertNull($visit->fresh()->claim_unanswered_since);
        $this->assertSame(SuchakVisitConfirmation::STATUS_COMPLETED, $visit->fresh()->visit_status);
    }

    public function test_a_confirmation_inside_the_window_stops_the_clock_for_good(): void
    {
        $this->travelTo(Carbon::parse('2026-07-01 09:00:00'));
        [, , $customerUser, $account, $visit] = $this->unansweredClaim('3000.00');

        $this->travelTo(Carbon::parse('2026-07-04 09:00:00'));
        app(SuchakVisitConfirmationService::class)->confirmByUser($visit, $customerUser, [
            'confirmation_note' => 'भेट झाली, दोन्ही कुटुंबे भेटली.',
        ]);

        $this->travelTo(Carbon::parse('2026-07-20 09:00:00'));
        $swept = app(SuchakClaimSilenceService::class)->sweepSilenceDue($account);

        $this->assertSame(0, $swept['disputes_opened']);
        $this->assertNull($visit->fresh()->claim_unanswered_since);
        $this->assertSame(SuchakVisitConfirmation::CONFIRMATION_CONFIRMED, $visit->fresh()->user_confirmation_status);
    }

    public function test_a_contest_inside_the_window_is_an_answer_and_is_never_swept_again(): void
    {
        $this->travelTo(Carbon::parse('2026-07-01 09:00:00'));
        [, , $customerUser, $account, $visit] = $this->unansweredClaim('3000.00');

        $this->travelTo(Carbon::parse('2026-07-03 09:00:00'));
        app(SuchakVisitConfirmationService::class)->disputeVisit($visit, $customerUser, [
            'dispute_reason' => 'ही भेट झालीच नाही.',
        ]);

        $this->travelTo(Carbon::parse('2026-07-30 09:00:00'));
        $swept = app(SuchakClaimSilenceService::class)->sweepSilenceDue($account);

        $this->assertSame(0, $swept['disputes_opened']);
        // A family that answered is not an unanswered claim, so it never touches the stop-loss.
        $this->assertNull($visit->fresh()->claim_unanswered_since);
        $this->assertFalse($visit->fresh()->hasUnansweredClaim());
        $this->assertSame(
            0,
            app(SuchakClaimSilenceService::class)->unansweredClaimSummary($account)['claims'],
        );
    }

    public function test_an_unpriced_meeting_is_never_frozen_over_silence(): void
    {
        $this->travelTo(Carbon::parse('2026-07-01 09:00:00'));
        [, , , $account, $visit] = $this->unansweredClaim(null);

        $this->travelTo(Carbon::parse('2026-07-15 09:00:00'));
        $swept = app(SuchakClaimSilenceService::class)->sweepSilenceDue($account);

        // M4 is a rule about FEES. Freezing a Suchak's payouts over a ₹0 row is leverage applied
        // to nothing.
        $this->assertSame(0, $swept['disputes_opened']);
        $this->assertNull($visit->fresh()->dispute_id);
    }

    public function test_the_sweep_is_idempotent_and_opens_one_dispute_per_claim(): void
    {
        $this->travelTo(Carbon::parse('2026-07-01 09:00:00'));
        [, , , $account, $visit] = $this->unansweredClaim('3000.00');

        $this->travelTo(Carbon::parse('2026-07-09 09:00:00'));
        $service = app(SuchakClaimSilenceService::class);
        $service->sweepSilenceDue($account);
        $second = $service->sweepSilenceDue($account);

        $this->assertSame(0, $second['due_total']);
        $this->assertSame(1, SuchakDispute::query()->where('dispute_type', SuchakDispute::TYPE_VISIT_CONFIRMATION)->count());
        $this->assertSame(1, SuchakPayoutHold::query()->count());
        $this->assertSame(
            1,
            SuchakVisitConfirmationEvent::query()
                ->where('visit_confirmation_id', $visit->id)
                ->where('event_type', SuchakVisitConfirmationEvent::EVENT_DISPUTED)
                ->count(),
        );
    }

    public function test_the_consolidated_timer_runs_the_sweep_synchronously_and_reports_its_backlog(): void
    {
        $this->travelTo(Carbon::parse('2026-07-01 09:00:00'));
        [$admin, , , $account, $visit] = $this->unansweredClaim('3000.00');

        $now = Carbon::parse('2026-07-10 04:00:00');
        $this->travelTo($now);

        $this->artisan('suchak:scheduled-jobs', [
            '--account-id' => $account->id,
            '--admin-id' => $admin->id,
            '--at' => $now->toDateTimeString(),
        ])->assertExitCode(0);

        $this->assertSame(SuchakVisitConfirmation::STATUS_DISPUTED, $visit->fresh()->visit_status);

        $run = SuchakScheduledJobRun::query()
            ->where('job_key', SuchakScheduledJobRun::JOB_CLAIM_SILENCE_SWEEP)
            ->firstOrFail();

        $this->assertSame(SuchakScheduledJobRun::STATUS_COMPLETED, $run->job_status);
        $this->assertSame(1, $run->metrics_json['silence']['disputes_opened']);
        // The cap must never hide a backlog on a money job.
        $this->assertSame(0, $run->metrics_json['silence']['deferred_backlog']);
        $this->assertArrayHasKey('due_total', $run->metrics_json['silence']);
        $this->assertArrayHasKey('lapse', $run->metrics_json);
    }

    // ── The stop-loss (clause 3) ──────────────────────────────────────────────────────────────

    public function test_one_small_unanswered_claim_does_not_stop_a_suchak(): void
    {
        $this->travelTo(Carbon::parse('2026-07-01 09:00:00'));
        [, , , $account] = $this->unansweredClaim('1000.00');

        $this->travelTo(Carbon::parse('2026-07-09 09:00:00'));
        $summary = app(SuchakClaimSilenceService::class)->unansweredClaimSummaryAfterSweep($account);

        $this->assertSame(1, $summary['claims']);
        $this->assertSame('₹1,000', $summary['amount_display']);
        $this->assertFalse($summary['blocked']);
    }

    public function test_two_unanswered_claims_stop_the_suchak_whatever_they_cost(): void
    {
        $this->travelTo(Carbon::parse('2026-07-01 09:00:00'));
        [, , , $account] = $this->unansweredClaim('500.00');
        $this->unansweredClaim('500.00', $account);

        $this->travelTo(Carbon::parse('2026-07-09 09:00:00'));
        $service = app(SuchakClaimSilenceService::class);
        $summary = $service->unansweredClaimSummaryAfterSweep($account);

        $this->assertSame(2, $summary['claims']);
        $this->assertSame('₹1,000', $summary['amount_display']);
        $this->assertTrue($summary['blocked']);
        $this->assertContains('claim_count', $summary['reasons']);
        $this->assertNotContains('amount', $summary['reasons']);
        $this->assertSame(1, $summary['oldest_days']);

        $this->expectException(InvalidArgumentException::class);
        $service->assertHelperMayAcceptChallengeFrom($account);
    }

    public function test_one_large_unanswered_claim_stops_the_suchak_on_the_money_leg(): void
    {
        $this->travelTo(Carbon::parse('2026-07-01 09:00:00'));
        [, , , $account] = $this->unansweredClaim('6000.00');

        $this->travelTo(Carbon::parse('2026-07-09 09:00:00'));
        $summary = app(SuchakClaimSilenceService::class)->unansweredClaimSummaryAfterSweep($account);

        $this->assertSame(1, $summary['claims']);
        // Latin digits, Indian grouping, one formatter.
        $this->assertSame('₹6,000', $summary['amount_display']);
        $this->assertTrue($summary['blocked']);
        $this->assertContains('amount', $summary['reasons']);
        $this->assertNotContains('claim_count', $summary['reasons']);
    }

    public function test_the_stop_loss_is_enforced_at_the_only_door_that_accepts_a_challenge(): void
    {
        $this->travelTo(Carbon::parse('2026-07-01 09:00:00'));

        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        [$publisherRepresentation] = $this->publishableCandidate($publisher, $publisherUser);
        $helperCandidate = $this->helperCandidate($helper);

        $challenge = app(SuchakMarketplaceChallengeService::class)->publish(
            $publisher,
            $publisherUser,
            $publisherRepresentation,
            [
                'declared_share_type' => SuchakCommissionAgreement::SPLIT_CUSTOM_PERCENT,
                'declared_share_percent' => 30,
            ],
        );

        // Two claims the publisher never answered — the window has closed but nothing has swept.
        $this->unansweredClaim('500.00', $publisher);
        $this->unansweredClaim('500.00', $publisher);
        $this->travelTo(Carbon::parse('2026-07-09 09:00:00'));

        try {
            app(SuchakMarketplaceChallengeService::class)->proposeCandidate(
                $challenge,
                $helper,
                $helperUser,
                $helperCandidate,
                ['message' => 'हे स्थळ जुळेल असे वाटते.'],
            );
            $this->fail('A helper must not be able to accept a challenge from a stopped-out Suchak.');
        } catch (InvalidArgumentException $exception) {
            // The gate swept first: with no scheduler on this production, the rule still holds at
            // the instant it matters.
            $this->assertStringContainsString('2 दावे', $exception->getMessage());
            $this->assertStringContainsString('₹1,000', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('suchak_collaboration_requests')->count());
    }

    // ── The lapse (clause 4) and M3 ───────────────────────────────────────────────────────────

    public function test_the_lapse_closes_the_case_but_never_clears_the_debt(): void
    {
        $this->travelTo(Carbon::parse('2026-07-01 09:00:00'));
        [$admin, , , $account, $visit] = $this->unansweredClaim('6000.00');

        $this->travelTo(Carbon::parse('2026-07-09 09:00:00'));
        $service = app(SuchakClaimSilenceService::class);
        $service->sweepSilenceDue($account);

        $this->travelTo(Carbon::parse('2026-10-10 09:00:00'));
        $lapse = $service->sweepLapsedClaims($admin, $account);

        $this->assertSame(1, $lapse['claims_lapsed']);
        $fresh = $visit->fresh();

        // "still visible" — nothing is deleted, and the case reads as closed WITHOUT a finding.
        $dispute = SuchakDispute::query()->findOrFail($fresh->dispute_id);
        $this->assertSame(SuchakDispute::STATUS_CLOSED, $dispute->status);
        $this->assertSame(SuchakVisitConfirmation::REFUND_CLOSED_NO_FINDING, $fresh->refund_review_status);
        $this->assertFalse($fresh->isComplaintDismissedByReview());
        $this->assertFalse($fresh->isFeeRefusedByReview());

        // The hold is CANCELLED, not released: cancelling ends the freeze, releasing would read as
        // a finding for the Suchak, and nobody found anything.
        $this->assertSame(
            SuchakPayoutHold::STATUS_CANCELLED,
            SuchakPayoutHold::query()->findOrFail($fresh->payout_hold_id)->hold_status,
        );

        // "still counted" — M3. Waiting out the 90 days does NOT clear the counter, so an
        // obligation cannot be made to disappear by doing nothing.
        $this->assertNotNull($fresh->claim_unanswered_since);
        $this->assertTrue($fresh->hasUnansweredClaim());
        $summary = $service->unansweredClaimSummary($account);
        $this->assertSame(1, $summary['claims']);
        $this->assertTrue($summary['blocked']);

        $this->expectException(InvalidArgumentException::class);
        $service->assertHelperMayAcceptChallengeFrom($account);
    }

    /**
     * THE TEST THAT WAS GREEN AGAINST A PROTECTION THAT DID NOT EXIST.
     *
     * Its first version drove the same three steps and then asserted only `expectException(
     * InvalidArgumentException::class)`. It passed — on the WRONG exception. The fixture's policy
     * mode is `user_and_admin`, so the admin's confirmation was still outstanding and
     * `qualifyPayoutForVisit()` refused with "confirmation policy is not yet satisfied" long
     * before the lapse was ever consulted. The lapse itself had already been erased by the late
     * confirmation, and nothing noticed.
     *
     * Two changes make it a real test, and both are load-bearing:
     *  - the meeting is put on `user_only`, which is what §7.2 clause 2 sets marketplace visits
     *    to, so the family's own answer is the ONLY confirmation outstanding and no second reason
     *    to refuse can stand in for the lapse;
     *  - the exception is asserted BY MESSAGE, so the lapse refusal cannot be impersonated.
     */
    public function test_a_lapsed_claim_is_never_due_even_if_the_family_answers_afterwards(): void
    {
        $this->travelTo(Carbon::parse('2026-07-01 09:00:00'));
        [$admin, , $customerUser, $account, $visit] = $this->unansweredClaim('6000.00');

        // §7.2 clause 2 — "set `confirmation_policy_mode = user_only` for marketplace visits so an
        // admin is not silently required".
        $visit->forceFill([
            'confirmation_policy_mode' => SuchakVisitConfirmation::POLICY_USER_ONLY,
            'admin_confirmation_status' => SuchakVisitConfirmation::CONFIRMATION_NOT_REQUIRED,
        ])->save();

        $this->travelTo(Carbon::parse('2026-07-09 09:00:00'));
        app(SuchakClaimSilenceService::class)->sweepSilenceDue($account);

        $this->travelTo(Carbon::parse('2026-10-10 09:00:00'));
        app(SuchakClaimSilenceService::class)->sweepLapsedClaims($admin, $account);
        $this->assertTrue($visit->fresh()->isClaimLapsed());

        // The family may still put their answer on the record — history is not falsified to
        // enforce a deadline...
        $confirmed = app(SuchakVisitConfirmationService::class)->confirmByUser($visit->fresh(), $customerUser, [
            'confirmation_note' => 'तीन महिन्यांनी उत्तर देत आहोत.',
        ]);
        $this->assertSame(SuchakVisitConfirmation::CONFIRMATION_CONFIRMED, $confirmed->user_confirmation_status);

        // ...and the answer does not unmake the lapse. Clause 4, all three words:
        //
        // NEVER REVIVABLE — the lapse is a RECORDED FACT on the row, not a predicate derived from
        // the answer columns that a late answer can flip back.
        $this->assertNotNull($confirmed->claim_lapsed_at);
        $this->assertSame('2026-10-06 09:00:00', $confirmed->claim_lapsed_at->format('Y-m-d H:i:s'));
        $this->assertTrue($confirmed->isClaimLapsed());

        // STILL COUNTED — M3. The stop-loss does not drop by one because an answer finally came
        // three months late; stonewalling must never clear a Suchak's own counter.
        $this->assertTrue($confirmed->hasUnansweredClaim());
        $summary = app(SuchakClaimSilenceService::class)->unansweredClaimSummary($account);
        $this->assertSame(1, $summary['claims']);
        $this->assertTrue($summary['blocked']);

        // NEVER DUE — and the refusal has to BE the lapse. Asserting the exception class alone is
        // exactly what let an unrelated refusal masquerade as this one: on the version of this
        // code the fix replaced, `assertEligibleForPayout()` passed COMPLETELY here and the only
        // thing that threw was `amount` being absent from the attributes below. A valid amount is
        // therefore supplied, so nothing but §7.2 clause 4 is left to refuse the money.
        try {
            app(SuchakVisitConfirmationService::class)->qualifyPayoutForVisit($confirmed, $admin, [
                'amount' => '6000',
                'currency' => 'INR',
                'qualification_note' => 'Trying to pay a lapsed claim.',
            ]);
            $this->fail('§7.2 clause 4 says a lapsed claim is never due, whenever the family answers.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'This Suchak meeting claim went unanswered past its lapse window; its fee can never qualify for platform payout.',
                $exception->getMessage(),
            );
        }
    }

    public function test_never_due_holds_even_when_the_lapse_sweep_never_runs(): void
    {
        $this->travelTo(Carbon::parse('2026-07-01 09:00:00'));
        [$admin, , , $account, $visit] = $this->unansweredClaim('6000.00');

        $this->travelTo(Carbon::parse('2026-07-09 09:00:00'));
        app(SuchakClaimSilenceService::class)->sweepSilenceDue($account);

        // No lapse sweep at all — this is the production where `schedule:run` never fires.
        $this->travelTo(Carbon::parse('2026-10-10 09:00:00'));
        $fresh = $visit->fresh();

        $this->assertTrue($fresh->isClaimLapsed(), 'the lapse is arithmetic, not a flag a job writes');
        $this->assertSame(SuchakDispute::STATUS_OPEN, SuchakDispute::query()->findOrFail($fresh->dispute_id)->status);

        $this->expectException(InvalidArgumentException::class);
        app(SuchakVisitConfirmationService::class)->qualifyPayoutForVisit($fresh, $admin, [
            'qualification_note' => 'Trying to pay a claim whose lapse nobody swept.',
        ]);
    }

    public function test_the_lapse_skips_without_an_admin_and_says_so_instead_of_pretending(): void
    {
        $this->travelTo(Carbon::parse('2026-07-01 09:00:00'));
        [, , , $account, $visit] = $this->unansweredClaim('6000.00');

        $this->travelTo(Carbon::parse('2026-07-09 09:00:00'));
        $service = app(SuchakClaimSilenceService::class);
        $service->sweepSilenceDue($account);

        $this->travelTo(Carbon::parse('2026-10-10 09:00:00'));
        $lapse = $service->sweepLapsedClaims(null, $account);

        $this->assertTrue($lapse['admin_required']);
        $this->assertSame(1, $lapse['due_total']);
        $this->assertSame(0, $lapse['claims_lapsed']);
        // Closing the case is an audited admin act, so it waits. The money answer does not.
        $this->assertTrue($visit->fresh()->isClaimLapsed());
        $this->assertSame(1, $service->unansweredClaimSummary($account)['claims']);
    }

    public function test_an_adjudication_beats_the_clock_in_both_directions(): void
    {
        $this->travelTo(Carbon::parse('2026-07-01 09:00:00'));
        [$admin, , , $account, $visit] = $this->unansweredClaim('6000.00');

        $this->travelTo(Carbon::parse('2026-07-09 09:00:00'));
        app(SuchakClaimSilenceService::class)->sweepSilenceDue($account);

        // An admin decides the case AGAINST the complaint, which settles the meeting.
        $dispute = SuchakDispute::query()->findOrFail($visit->fresh()->dispute_id);
        app(SuchakSafetyService::class)->closeDispute(
            $dispute,
            $admin,
            SuchakDispute::STATUS_REJECTED,
            'The Suchak produced the meeting evidence and the family confirmed by phone.',
        );

        $fresh = $visit->fresh();
        $this->assertSame(SuchakVisitConfirmation::REFUND_DISMISSED, $fresh->refund_review_status);

        // A finding is an ANSWER: it clears the stop-loss and stops the lapse clock, even long
        // after 90 days would otherwise have passed.
        $this->assertFalse($fresh->hasUnansweredClaim());
        $this->assertSame(0, app(SuchakClaimSilenceService::class)->unansweredClaimSummary($account)['claims']);

        $this->travelTo(Carbon::parse('2026-12-01 09:00:00'));
        $this->assertFalse($visit->fresh()->isClaimLapsed());
        app(SuchakClaimSilenceService::class)->assertHelperMayAcceptChallengeFrom($account);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    /**
     * A fee-bearing meeting the Suchak has marked complete and the family has not answered.
     *
     * `fee_amount` is stamped directly rather than quoted through an agreement: the quote path is
     * SuchakVisitConfirmationFlowTest's subject, and what this class needs is simply a meeting
     * somebody will be billed for.
     *
     * @return array{0: User, 1: User, 2: User, 3: SuchakAccount, 4: SuchakVisitConfirmation}
     */
    private function unansweredClaim(?string $feeAmount, ?SuchakAccount $reuseAccount = null): array
    {
        [$admin, $suchakUser, $customerUser, $account, $pipeline, $paymentContext] = $this->pipelineFixture($reuseAccount);

        $visit = app(SuchakVisitConfirmationService::class)->scheduleVisit($pipeline, $suchakUser, [
            'payment_context_id' => $paymentContext->id,
            'scheduled_for' => now()->addDay()->toDateTimeString(),
            'schedule_note' => 'Introduction meeting arranged by the Suchak.',
        ]);

        if ($feeAmount !== null) {
            $visit->forceFill(['fee_amount' => $feeAmount, 'fee_currency' => 'INR'])->save();
        }

        $completed = app(SuchakVisitConfirmationService::class)->markSuchakCompleted($visit->fresh(), $suchakUser, [
            'completion_note' => 'Suchak marked the introduction meeting completed.',
        ]);

        return [$admin, $suchakUser, $customerUser, $account, $completed];
    }

    /**
     * One member→Suchak pipeline with a platform payment context, optionally on an account that
     * already exists so several claims can stack against the same originating Suchak.
     *
     * @return array{0: User, 1: User, 2: User, 3: SuchakAccount, 4: SuchakPipeline, 5: SuchakPaymentContext}
     */
    private function pipelineFixture(?SuchakAccount $reuseAccount = null): array
    {
        $admin = User::query()->where('is_admin', true)->first()
            ?? User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);

        if ($reuseAccount instanceof SuchakAccount) {
            $account = $reuseAccount;
            $suchakUser = User::query()->findOrFail($account->user_id);
        } else {
            [$suchakUser, $account] = $this->verifiedSuchakActor();
        }

        $customerUser = User::factory()->create();
        $customerProfile = MatrimonyProfile::factory()->create([
            'user_id' => $customerUser->id,
            'full_name' => 'Silence Timer Customer '.uniqid('', true),
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        $targetProfile = MatrimonyProfile::factory()->create([
            'full_name' => 'Silence Timer Candidate '.uniqid('', true),
            'date_of_birth' => '1997-04-02',
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $targetProfile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_verified_consent_at' => now(),
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);
        $request = SuchakProfileRequest::query()->create([
            'requesting_user_id' => $customerUser->id,
            'requesting_matrimony_profile_id' => $customerProfile->id,
            'target_matrimony_profile_id' => $targetProfile->id,
            'selected_suchak_account_id' => $account->id,
            'representation_id' => $representation->id,
            'request_status' => SuchakProfileRequest::STATUS_PENDING,
            'request_reason' => 'intro_visit',
            'message' => 'Please coordinate the introduction.',
        ]);
        $pipeline = SuchakPipeline::query()->create([
            'request_id' => $request->id,
            'target_matrimony_profile_id' => $targetProfile->id,
            'requesting_matrimony_profile_id' => $customerProfile->id,
            'selected_suchak_account_id' => $account->id,
            'representation_id' => $representation->id,
            'pipeline_status' => SuchakPipeline::STATUS_PENDING,
            'attribution_locked_at' => now(),
            'lock_expires_at' => now()->addDays(30),
            'sla_status' => SuchakPipeline::SLA_WITHIN,
        ]);
        $paymentContext = SuchakPaymentContext::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => null,
            'matrimony_profile_id' => $targetProfile->id,
            'pipeline_id' => $pipeline->id,
            'source_owner' => SuchakPaymentContext::SOURCE_PLATFORM,
            'payment_collector' => SuchakPaymentContext::COLLECTOR_PLATFORM,
            'context_status' => SuchakPaymentContext::STATUS_ACTIVE,
            'resolved_by_user_id' => $admin->id,
            'resolution_note' => 'Silence-timer platform payout context.',
        ]);

        return [
            $admin,
            $suchakUser,
            $customerUser,
            $account->fresh(),
            $pipeline->fresh(['selectedSuchakAccount', 'request', 'representation']),
            $paymentContext->fresh(['suchakAccount', 'pipeline', 'matrimonyProfile']),
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

    /**
     * @return array{0: SuchakProfileRepresentation, 1: SuchakCustomerAgreement}
     */
    private function publishableCandidate(SuchakAccount $account, User $user): array
    {
        $profile = $this->activeProfile('Sunita Gaikwad');

        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);

        $context = SuchakCustomerContext::query()->create([
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $profile->id,
            'representation_id' => $representation->id,
            'created_by_user_id' => $user->id,
            'opened_at' => now(),
        ]);

        $package = SuchakServicePackage::query()->create([
            'suchak_account_id' => $account->id,
            'package_name' => 'Silence timer fixture '.$representation->id,
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

        $agreement = SuchakCustomerAgreement::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $context->id,
            'service_package_id' => $package->id,
            'agreement_revision' => 1,
            'terms_status' => SuchakCustomerAgreement::TERMS_ACCEPTED,
            'terms_policy_mode' => SuchakCustomerAgreement::POLICY_STRICT,
            'agreement_snapshot_hash' => hash('sha256', 'silence-'.$package->id),
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

    private function helperCandidate(SuchakAccount $account): SuchakProfileRepresentation
    {
        $profile = $this->activeProfile('Rahul Kadam');

        return SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ])->fresh();
    }

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
