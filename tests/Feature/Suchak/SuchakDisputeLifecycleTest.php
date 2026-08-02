<?php

namespace Tests\Feature\Suchak;

use App\Models\AdminAuditLog;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakDispute;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPayoutHold;
use App\Models\SuchakPipeline;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileRequest;
use App\Models\SuchakVisitConfirmation;
use App\Models\SuchakVisitConfirmationEvent;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakSafetyService;
use App\Modules\Suchak\Services\SuchakVisitConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * THE THREE DEAD ENDS OF THE DISPUTE ENGINE.
 *
 * 1. A dispute froze a meeting permanently — the guards tested `dispute_id`
 *    (a trail marker that is never cleared) instead of whether the case was
 *    still open, and `refund_review_status` had no writer at all.
 * 2. `suchak_payout_holds` declared `released`/`cancelled` and carried three
 *    release columns that nothing in app/ ever wrote.
 * 3. A helping Suchak could not raise a dispute, which is the one party §7.2's
 *    stop-loss exists to protect.
 *
 * Every assertion below is about a MONEY lever that could previously only be
 * pulled one way.
 */
class SuchakDisputeLifecycleTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // DEAD END 1 — three closing statuses, three different money answers
    // ------------------------------------------------------------------

    /**
     * A REJECTED DISPUTE UNFREEZES THE MEETING. IT DOES NOT PAY FOR IT.
     *
     * The first version of this test asserted the opposite — that closing a dispute `rejected`
     * moved the meeting straight to `confirmed` and qualified the payout — and the code did
     * exactly that. Both were wrong against M4, which is absolute: *no fee falls due without the
     * customer's confirmation*. Closing a dispute is an admin deciding a dispute; it is not the
     * customer confirming, and the two must never be the same act.
     *
     * The correction is narrow and must not swing the other way. A dispute settled in the
     * Suchak's favour still has to STOP FREEZING the meeting — that dead end (`dispute_id !== null`
     * as the guard, and `refund_review_status` with no writer at all) was itself a bug. So the
     * dismissal releases the hold, reopens every ordinary door, and hands the meeting back to the
     * one person who can make the money move.
     */
    public function test_rejected_dispute_unfreezes_the_meeting_without_confirming_it_for_the_family(): void
    {
        [$admin, $suchakUser, $requestingUser, $pipeline, $paymentContext] = $this->fixture();
        $service = app(SuchakVisitConfirmationService::class);
        $disputed = $this->disputedVisit($service, $pipeline, $suchakUser, $requestingUser, $paymentContext);
        $dispute = SuchakDispute::query()->findOrFail($disputed->dispute_id);

        app(SuchakSafetyService::class)->closeDispute(
            $dispute,
            $admin,
            SuchakDispute::STATUS_REJECTED,
            'Meeting evidence checked; the complaint does not stand and the freeze is lifted.',
        );

        $settled = $disputed->fresh();

        $this->assertSame(SuchakVisitConfirmation::REFUND_DISMISSED, $settled->refund_review_status);
        // NOT `confirmed`. The case is over, so the row is no longer `disputed` — but nobody has
        // confirmed this meeting, and a status of `confirmed` would say somebody had.
        $this->assertSame(SuchakVisitConfirmation::STATUS_COMPLETED, $settled->visit_status);
        // The trail survives its case.
        $this->assertNotNull($settled->dispute_id);
        $this->assertNotNull($settled->payout_hold_id);
        // The customer's own column is NOT rewritten — an admin finding is not
        // the family's word, and stamping it there would invent a confirmation.
        $this->assertSame(SuchakVisitConfirmation::CONFIRMATION_DISPUTED, $settled->user_confirmation_status);

        // A dismissal RELEASES the hold: the Suchak's OTHER payouts are freed on a finding.
        $this->assertDatabaseHas('suchak_payout_holds', [
            'id' => $settled->payout_hold_id,
            'hold_status' => SuchakPayoutHold::STATUS_RELEASED,
            'released_by_user_id' => $admin->id,
        ]);

        // M4 — and the refusal must be the CONFIRMATION one, named, not some other guard.
        try {
            $service->qualifyPayoutForVisit($settled, $admin, [
                'amount' => '1500',
                'currency' => 'INR',
                'qualification_note' => 'Dispute rejected; trying to pay without the family ever answering.',
            ]);
            $this->fail('M4 is absolute: an admin rejecting a dispute is not the customer confirming.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Suchak visit confirmation policy is not yet satisfied.', $exception->getMessage());
        }

        // AND IT IS NOT A DEAD END. The family — whose contest was just found not to stand — can
        // still confirm, and their own act is what makes the fee due. That is the only door, and
        // it is open.
        $confirmed = $service->confirmByUser($settled, $requestingUser, [
            'confirmation_note' => 'The family accepts the finding and confirms the meeting happened.',
        ]);
        $confirmed = $service->confirmByAdmin($confirmed, $admin, [
            'confirmation_note' => 'Admin confirms the meeting after the complaint was dismissed.',
        ]);

        $this->assertSame(SuchakVisitConfirmation::STATUS_CONFIRMED, $confirmed->visit_status);

        $qualified = $service->qualifyPayoutForVisit($confirmed, $admin, [
            'amount' => '1500',
            'currency' => 'INR',
            'qualification_note' => 'Confirmed by the family after the dismissal; the visit payout qualifies.',
        ]);

        $this->assertSame(SuchakVisitConfirmation::STATUS_PAYOUT_QUALIFIED, $qualified->visit_status);
        $this->assertNotNull($qualified->platform_payout_id);
    }

    /**
     * THE SAME REFUSAL, THROUGH THE DOOR THE ADMIN ACTUALLY USES.
     *
     * `POST /admin/suchak/safety/disputes/{dispute}/close` with `resolution_status = rejected` is
     * the whole attack: one admin form post, no customer anywhere in it, and the fee fell due.
     * Testing only the service would leave the HTTP route free to grow its own answer.
     */
    public function test_the_admin_close_route_cannot_make_a_fee_due_without_the_family(): void
    {
        [$admin, $suchakUser, $requestingUser, $pipeline, $paymentContext] = $this->fixture();
        $service = app(SuchakVisitConfirmationService::class);
        $disputed = $this->disputedVisit($service, $pipeline, $suchakUser, $requestingUser, $paymentContext);
        $dispute = SuchakDispute::query()->findOrFail($disputed->dispute_id);

        $this->actingAs($admin)
            ->post(route('admin.suchak.safety.disputes.close', $dispute), [
                'resolution_status' => SuchakDispute::STATUS_REJECTED,
                'resolution_note' => 'Closing the dispute in the Suchak favour over the admin route.',
            ])
            ->assertRedirect();

        $settled = $disputed->fresh();
        $this->assertSame(SuchakVisitConfirmation::REFUND_DISMISSED, $settled->refund_review_status);
        $this->assertNotSame(SuchakVisitConfirmation::STATUS_CONFIRMED, $settled->visit_status);
        $this->assertNull($settled->user_confirmed_at);

        try {
            $service->qualifyPayoutForVisit($settled, $admin, [
                'amount' => '1500',
                'currency' => 'INR',
                'qualification_note' => 'Paying a meeting the family never confirmed, via the admin route.',
            ]);
            $this->fail('M4: the admin close route must never substitute for the customer.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Suchak visit confirmation policy is not yet satisfied.', $exception->getMessage());
        }
    }

    public function test_resolved_dispute_refuses_the_fee_permanently_and_cancels_the_hold(): void
    {
        [$admin, $suchakUser, $requestingUser, $pipeline, $paymentContext] = $this->fixture();
        $service = app(SuchakVisitConfirmationService::class);
        $disputed = $this->disputedVisit($service, $pipeline, $suchakUser, $requestingUser, $paymentContext);
        $dispute = SuchakDispute::query()->findOrFail($disputed->dispute_id);

        app(SuchakSafetyService::class)->closeDispute(
            $dispute,
            $admin,
            SuchakDispute::STATUS_RESOLVED,
            'The complaint stood: this meeting did not happen as claimed and carries no fee.',
        );

        $settled = $disputed->fresh();

        $this->assertSame(SuchakVisitConfirmation::REFUND_UPHELD, $settled->refund_review_status);
        // Terminal, and OUTSIDE `OPEN_STATUSES` on purpose — a settled case must
        // not go on blocking the pair from ever meeting again.
        $this->assertSame(SuchakVisitConfirmation::STATUS_CANCELLED, $settled->visit_status);
        $this->assertNotContains($settled->visit_status, SuchakVisitConfirmation::OPEN_STATUSES);

        // Not `released`: nothing was found FOR the Suchak. The block on his
        // other payouts goes; no money is declared cleared.
        $this->assertDatabaseHas('suchak_payout_holds', [
            'id' => $settled->payout_hold_id,
            'hold_status' => SuchakPayoutHold::STATUS_CANCELLED,
        ]);

        try {
            $service->qualifyPayoutForVisit($settled, $admin, [
                'amount' => '1500',
                'currency' => 'INR',
                'qualification_note' => 'Attempting to pay a meeting whose complaint was upheld.',
            ]);
            $this->fail('An upheld complaint must never become payable.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'This Suchak visit dispute was settled against the claim; its fee can never qualify for platform payout.',
                $exception->getMessage(),
            );
        }

        // And nothing can revive it — not a later confirmation, not a re-dispute.
        try {
            $service->confirmByUser($settled, $requestingUser, [
                'confirmation_note' => 'Late confirmation after the complaint was already upheld.',
            ]);
            $this->fail('A meeting settled against the claim must not be confirmable.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'This Suchak visit dispute was settled against the claim; the meeting is closed and cannot be changed.',
                $exception->getMessage(),
            );
        }
    }

    public function test_closed_without_finding_returns_the_meeting_to_its_own_confirmations(): void
    {
        [$admin, $suchakUser, $requestingUser, $pipeline, $paymentContext] = $this->fixture();
        $service = app(SuchakVisitConfirmationService::class);
        $disputed = $this->disputedVisit($service, $pipeline, $suchakUser, $requestingUser, $paymentContext);
        $dispute = SuchakDispute::query()->findOrFail($disputed->dispute_id);

        app(SuchakSafetyService::class)->closeDispute(
            $dispute,
            $admin,
            SuchakDispute::STATUS_CLOSED,
            'Case filed away with no adjudication; neither side answered within the window.',
        );

        $settled = $disputed->fresh();

        $this->assertSame(SuchakVisitConfirmation::REFUND_CLOSED_NO_FINDING, $settled->refund_review_status);
        // No finding means no money conclusion — back to `completed`, i.e. the
        // family still has to answer (M4) and nothing is owed until it does.
        $this->assertSame(SuchakVisitConfirmation::STATUS_COMPLETED, $settled->visit_status);
        $this->assertDatabaseHas('suchak_payout_holds', [
            'id' => $settled->payout_hold_id,
            'hold_status' => SuchakPayoutHold::STATUS_CANCELLED,
        ]);

        try {
            $service->qualifyPayoutForVisit($settled, $admin, [
                'amount' => '1500',
                'currency' => 'INR',
                'qualification_note' => 'A case closed with no finding is not a confirmation.',
            ]);
            $this->fail('A closed-without-finding case must not release money on its own.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Suchak visit confirmation policy is not yet satisfied.', $exception->getMessage());
        }

        // But the ordinary doors are open again — this is the whole point of the
        // fix: the row is no longer frozen, it is merely unanswered.
        $confirmed = $service->confirmByUser($settled, $requestingUser, [
            'confirmation_note' => 'The family confirms the meeting after the case was closed with no finding.',
        ]);
        $confirmed = $service->confirmByAdmin($confirmed, $admin, [
            'confirmation_note' => 'Admin confirms the meeting after the case was closed with no finding.',
        ]);

        $this->assertSame(SuchakVisitConfirmation::STATUS_CONFIRMED, $confirmed->visit_status);

        $qualified = $service->qualifyPayoutForVisit($confirmed, $admin, [
            'amount' => '1500',
            'currency' => 'INR',
            'qualification_note' => 'Confirmed after the case closed with no finding; the payout qualifies.',
        ]);
        $this->assertSame(SuchakVisitConfirmation::STATUS_PAYOUT_QUALIFIED, $qualified->visit_status);
    }

    public function test_a_settled_dispute_cannot_be_reopened_on_the_same_meeting(): void
    {
        [$admin, $suchakUser, $requestingUser, $pipeline, $paymentContext] = $this->fixture();
        $service = app(SuchakVisitConfirmationService::class);
        $disputed = $this->disputedVisit($service, $pipeline, $suchakUser, $requestingUser, $paymentContext);
        $dispute = SuchakDispute::query()->findOrFail($disputed->dispute_id);

        app(SuchakSafetyService::class)->closeDispute(
            $dispute,
            $admin,
            SuchakDispute::STATUS_REJECTED,
            'Complaint dismissed after reviewing the meeting evidence supplied by both sides.',
        );

        try {
            $service->disputeVisit($disputed->fresh(), $requestingUser, [
                'dispute_reason' => 'Trying to contest the same meeting a second time after losing.',
            ]);
            $this->fail('§7.2 says a closed dispute is never revivable.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'This Suchak visit was already disputed once and settled; a settled dispute cannot be reopened.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(1, SuchakDispute::query()->count());
    }

    public function test_settling_a_dispute_writes_an_immutable_event_and_an_audited_activity_row(): void
    {
        [$admin, $suchakUser, $requestingUser, $pipeline, $paymentContext] = $this->fixture();
        $service = app(SuchakVisitConfirmationService::class);
        $disputed = $this->disputedVisit($service, $pipeline, $suchakUser, $requestingUser, $paymentContext);
        $dispute = SuchakDispute::query()->findOrFail($disputed->dispute_id);

        app(SuchakSafetyService::class)->closeDispute(
            $dispute,
            $admin,
            SuchakDispute::STATUS_REJECTED,
            'Complaint dismissed; the settlement is recorded on the meeting row itself.',
        );

        $event = SuchakVisitConfirmationEvent::query()
            ->where('visit_confirmation_id', $disputed->id)
            ->where('event_type', SuchakVisitConfirmationEvent::EVENT_DISPUTE_SETTLED)
            ->firstOrFail();

        $this->assertSame(SuchakVisitConfirmationEvent::ACTOR_ADMIN, $event->actor_type);
        $this->assertSame(SuchakVisitConfirmation::STATUS_DISPUTED, $event->from_status);
        $this->assertSame(SuchakVisitConfirmation::STATUS_COMPLETED, $event->to_status);
        $this->assertSame(SuchakVisitConfirmation::REFUND_DISMISSED, $event->metadata_json['refund_review_status']);
        // The settlement itself never makes a fee due (M4), so the audit row must not claim it
        // did. `fee_payable` reports the money answer AFTER the settlement, and a family that has
        // not confirmed leaves it false however the case went.
        $this->assertFalse($event->metadata_json['fee_payable']);

        $activity = SuchakActivityLog::query()
            ->where('action_type', SuchakActivityLog::ACTION_VISIT_DISPUTE_SETTLED)
            ->firstOrFail();
        // The logger REFUSES an admin row without an audit id — this is what
        // makes the settlement an audited act rather than a silent update.
        $this->assertNotNull($activity->admin_audit_log_id);
    }

    // ------------------------------------------------------------------
    // DEAD END 2 — the payout hold release
    // ------------------------------------------------------------------

    public function test_admin_releases_a_payout_hold_over_the_route_and_the_release_columns_are_written(): void
    {
        [$admin, $suchakUser, $requestingUser, $pipeline, $paymentContext] = $this->fixture();
        $service = app(SuchakVisitConfirmationService::class);
        $disputed = $this->disputedVisit($service, $pipeline, $suchakUser, $requestingUser, $paymentContext);
        $hold = SuchakPayoutHold::query()->findOrFail($disputed->payout_hold_id);

        // The door is on the page, not only in the service.
        $this->actingAs($admin)
            ->get(route('admin.suchak.safety.index'))
            ->assertOk()
            ->assertSee(route('admin.suchak.safety.payout-holds.release', $hold), false);

        $this->actingAs($admin)
            ->post(route('admin.suchak.safety.payout-holds.release', $hold), [
                'release_status' => SuchakPayoutHold::STATUS_RELEASED,
                'release_reason' => 'Hold raised on facts that did not survive review; the payout is freed.',
            ])
            ->assertRedirect(route('admin.suchak.safety.index'))
            ->assertSessionHas('success', 'Suchak payout hold released.');

        $released = $hold->fresh();
        $this->assertSame(SuchakPayoutHold::STATUS_RELEASED, $released->hold_status);
        $this->assertSame($admin->id, $released->released_by_user_id);
        $this->assertNotNull($released->released_at);
        $this->assertStringContainsString('did not survive review', (string) $released->release_reason);

        $this->assertNotNull(
            AdminAuditLog::query()
                ->where('action_type', 'suchak_payout_hold_released')
                ->where('entity_type', 'SuchakPayoutHold')
                ->where('entity_id', $hold->id)
                ->first()
        );
        $this->assertNotNull(
            SuchakActivityLog::query()
                ->where('action_type', SuchakActivityLog::ACTION_PAYOUT_HOLD_RELEASED)
                ->where('target_id', $hold->id)
                ->whereNotNull('admin_audit_log_id')
                ->first()
        );
    }

    public function test_only_an_admin_may_release_a_hold_and_only_while_it_is_active(): void
    {
        [$admin, $suchakUser, $requestingUser, $pipeline, $paymentContext] = $this->fixture();
        $service = app(SuchakVisitConfirmationService::class);
        $disputed = $this->disputedVisit($service, $pipeline, $suchakUser, $requestingUser, $paymentContext);
        $hold = SuchakPayoutHold::query()->findOrFail($disputed->payout_hold_id);
        $safety = app(SuchakSafetyService::class);

        // The Suchak whose money it is is the LAST person who may free it.
        try {
            $safety->releasePayoutHold(
                $hold,
                $suchakUser,
                SuchakPayoutHold::STATUS_RELEASED,
                'The Suchak trying to lift the hold on his own payouts.',
            );
            $this->fail('A Suchak must not be able to release his own payout hold.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Only admins can release Suchak payout holds.', $exception->getMessage());
        }

        // Neither may the customer.
        try {
            $safety->releasePayoutHold(
                $hold,
                $requestingUser,
                SuchakPayoutHold::STATUS_CANCELLED,
                'The complaining family trying to lift a hold they have no standing over.',
            );
            $this->fail('A member must not be able to release a payout hold.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Only admins can release Suchak payout holds.', $exception->getMessage());
        }

        // A reason is not optional.
        try {
            $safety->releasePayoutHold($hold, $admin, SuchakPayoutHold::STATUS_RELEASED, '   ');
            $this->fail('Releasing a hold without a reason must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Suchak payout hold release reason is required.', $exception->getMessage());
        }

        // `active` is not a release status — a hold is never re-armed in place.
        try {
            $safety->releasePayoutHold(
                $hold,
                $admin,
                SuchakPayoutHold::STATUS_ACTIVE,
                'Trying to re-arm a hold in place instead of opening a new one.',
            );
            $this->fail('A hold must not be re-armed through the release door.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Invalid Suchak payout hold release status.', $exception->getMessage());
        }

        $safety->releasePayoutHold(
            $hold,
            $admin,
            SuchakPayoutHold::STATUS_RELEASED,
            'Reviewed and lifted: the reason for this hold did not stand.',
        );

        // The first decision and its author survive — no second write.
        try {
            $safety->releasePayoutHold(
                $hold->fresh(),
                $admin,
                SuchakPayoutHold::STATUS_CANCELLED,
                'Trying to overwrite an already-recorded release decision.',
            );
            $this->fail('An already-released hold must not be released again.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Only active Suchak payout holds can be released.', $exception->getMessage());
        }
    }

    public function test_an_active_hold_blocks_payout_and_releasing_it_unblocks_the_next_one(): void
    {
        [$admin, $suchakUser, $requestingUser, $pipeline, $paymentContext] = $this->fixture();
        $service = app(SuchakVisitConfirmationService::class);

        // A hold that belongs to some OTHER case, raised on this Suchak.
        $foreignDispute = SuchakDispute::query()->create([
            'suchak_account_id' => $pipeline->selected_suchak_account_id,
            'opened_by_user_id' => $admin->id,
            'dispute_type' => SuchakDispute::TYPE_ABUSE_REPORT,
            'status' => SuchakDispute::STATUS_OPEN,
            'priority' => SuchakDispute::PRIORITY_HIGH,
            'summary' => 'An unrelated open case against this Suchak.',
            'opened_at' => now(),
        ]);
        $foreignHold = SuchakPayoutHold::query()->create([
            'suchak_dispute_id' => $foreignDispute->id,
            'suchak_account_id' => $pipeline->selected_suchak_account_id,
            'payment_context_id' => $paymentContext->id,
            'hold_scope' => SuchakPayoutHold::SCOPE_DIRECT_PAYMENT_RISK,
            'hold_status' => SuchakPayoutHold::STATUS_ACTIVE,
            'hold_reason' => 'Direct payment risk hold from an unrelated case.',
            'created_by_user_id' => $admin->id,
        ]);

        $visit = $service->scheduleVisit($pipeline, $suchakUser, [
            'payment_context_id' => $paymentContext->id,
            'schedule_note' => 'Meeting arranged while an unrelated hold is active.',
        ]);
        $visit = $service->markSuchakCompleted($visit, $suchakUser, [
            'completion_note' => 'Suchak marked the meeting completed.',
        ]);
        $visit = $service->confirmByUser($visit, $requestingUser, [
            'confirmation_note' => 'The family confirms the meeting happened as arranged.',
        ]);
        $visit = $service->confirmByAdmin($visit, $admin, [
            'confirmation_note' => 'Admin confirms the meeting happened as arranged.',
        ]);

        try {
            $service->qualifyPayoutForVisit($visit, $admin, [
                'amount' => '900',
                'currency' => 'INR',
                'qualification_note' => 'Attempting payout while an unrelated hold is active.',
            ]);
            $this->fail('An active hold must block the payout.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Suchak visit payout is held because an active payout hold exists.', $exception->getMessage());
        }

        app(SuchakSafetyService::class)->releasePayoutHold(
            $foreignHold,
            $admin,
            SuchakPayoutHold::STATUS_RELEASED,
            'The unrelated risk was cleared; this Suchak may be paid again.',
        );

        $qualified = $service->qualifyPayoutForVisit($visit->fresh(), $admin, [
            'amount' => '900',
            'currency' => 'INR',
            'qualification_note' => 'Hold lifted; the confirmed meeting payout qualifies.',
        ]);
        $this->assertSame(SuchakVisitConfirmation::STATUS_PAYOUT_QUALIFIED, $qualified->visit_status);
        // The unrelated case is still open — closing a hold is not closing a case.
        $this->assertSame(SuchakDispute::STATUS_OPEN, $foreignDispute->fresh()->status);
    }

    // ------------------------------------------------------------------
    // DEAD END 3 — the helping Suchak's door
    // ------------------------------------------------------------------

    public function test_the_helping_suchak_can_dispute_the_meeting_and_the_hold_lands_on_the_arranging_suchak(): void
    {
        [$admin, $suchakUser, $requestingUser, $pipeline, $paymentContext] = $this->fixture();
        [$helperUser, $helperAccount] = $this->suchakAccount('Helper Suchak');
        $service = app(SuchakVisitConfirmationService::class);

        $visit = $service->scheduleVisit($pipeline, $suchakUser, [
            'payment_context_id' => $paymentContext->id,
            'helper_suchak_account_id' => $helperAccount->id,
            'schedule_note' => 'Marketplace meeting on the helping Suchak candidate.',
        ]);
        $visit = $service->markSuchakCompleted($visit, $suchakUser, [
            'completion_note' => 'Arranging Suchak marked the marketplace meeting completed.',
        ]);

        $disputed = $service->disputeVisit($visit, $helperUser, [
            'dispute_reason' => 'The helper says this meeting was never arranged with his candidate.',
        ]);

        $this->assertSame(SuchakVisitConfirmation::STATUS_DISPUTED, $disputed->visit_status);
        // The member's column is untouched — the family did not contest anything.
        $this->assertSame(SuchakVisitConfirmation::CONFIRMATION_PENDING, $disputed->user_confirmation_status);

        // §7.2's leverage: the hold sits on the ARRANGING Suchak, the party who
        // has to answer, not on the helper who raised the claim.
        $this->assertDatabaseHas('suchak_payout_holds', [
            'id' => $disputed->payout_hold_id,
            'suchak_account_id' => $pipeline->selected_suchak_account_id,
            'hold_scope' => SuchakPayoutHold::SCOPE_VISIT_CONFIRMATION_DISPUTE,
            'hold_status' => SuchakPayoutHold::STATUS_ACTIVE,
        ]);
        $this->assertNotSame((int) $helperAccount->id, (int) SuchakPayoutHold::query()->findOrFail($disputed->payout_hold_id)->suchak_account_id);

        // The claim is filed under `suchak`, which is the only place a stop-loss
        // counter could ever learn WHO claimed.
        $event = SuchakVisitConfirmationEvent::query()
            ->where('visit_confirmation_id', $disputed->id)
            ->where('event_type', SuchakVisitConfirmationEvent::EVENT_DISPUTED)
            ->firstOrFail();
        $this->assertSame(SuchakVisitConfirmationEvent::ACTOR_SUCHAK, $event->actor_type);
        $this->assertSame($helperUser->id, $event->actor_user_id);
    }

    public function test_the_helper_door_is_reachable_over_http_and_a_stranger_suchak_is_not(): void
    {
        [$admin, $suchakUser, $requestingUser, $pipeline, $paymentContext] = $this->fixture();
        [$helperUser, $helperAccount] = $this->suchakAccount('Helper Suchak Over Http');
        [$strangerUser] = $this->suchakAccount('Stranger Suchak');
        $service = app(SuchakVisitConfirmationService::class);

        $visit = $service->scheduleVisit($pipeline, $suchakUser, [
            'payment_context_id' => $paymentContext->id,
            'helper_suchak_account_id' => $helperAccount->id,
            'schedule_note' => 'Marketplace meeting scheduled for the HTTP dispute door.',
        ]);
        $visit = $service->markSuchakCompleted($visit, $suchakUser, [
            'completion_note' => 'Arranging Suchak marked the meeting completed.',
        ]);

        Sanctum::actingAs($strangerUser);
        $this->postJson('/api/v1/suchak/meetings/'.$visit->id.'/dispute', [
            'dispute_reason' => 'A Suchak with nothing to do with this meeting trying to freeze it.',
        ])->assertStatus(404);

        Sanctum::actingAs($suchakUser);
        $this->postJson('/api/v1/suchak/meetings/'.$visit->id.'/dispute', [
            'dispute_reason' => 'The arranging Suchak trying to contest his own fee claim.',
        ])->assertStatus(404);

        Sanctum::actingAs($helperUser);
        $this->postJson('/api/v1/suchak/meetings/'.$visit->id.'/dispute', [
            'dispute_reason' => 'The helper contests the arranging Suchak claim about this meeting.',
        ])->assertOk()->assertJsonPath('data.visit_status', SuchakVisitConfirmation::STATUS_DISPUTED);

        $this->assertSame(1, SuchakDispute::query()->where('dispute_type', SuchakDispute::TYPE_VISIT_CONFIRMATION)->count());
    }

    public function test_the_arranging_suchak_and_an_unrelated_suchak_are_both_refused_by_the_service(): void
    {
        [$admin, $suchakUser, $requestingUser, $pipeline, $paymentContext] = $this->fixture();
        [, $helperAccount] = $this->suchakAccount('Helper Suchak For Refusals');
        [$strangerUser] = $this->suchakAccount('Unrelated Suchak For Refusals');
        $service = app(SuchakVisitConfirmationService::class);

        $visit = $service->scheduleVisit($pipeline, $suchakUser, [
            'payment_context_id' => $paymentContext->id,
            'helper_suchak_account_id' => $helperAccount->id,
            'schedule_note' => 'Marketplace meeting used for the refusal cases.',
        ]);
        $visit = $service->markSuchakCompleted($visit, $suchakUser, [
            'completion_note' => 'Arranging Suchak marked the meeting completed.',
        ]);

        // The claimant may not contest his own claim.
        try {
            $service->disputeVisit($visit, $suchakUser, [
                'dispute_reason' => 'The arranging Suchak withdrawing his own claim through the dispute route.',
            ]);
            $this->fail('The arranging Suchak must not be able to dispute his own meeting.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Only the customer this meeting was arranged for can confirm it.', $exception->getMessage());
        }

        try {
            $service->disputeVisit($visit, $strangerUser, [
                'dispute_reason' => 'A Suchak with no link to this meeting trying to freeze a rival payout.',
            ]);
            $this->fail('An unrelated Suchak must not be able to dispute a meeting.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Only the customer this meeting was arranged for can confirm it.', $exception->getMessage());
        }

        $this->assertSame(0, SuchakDispute::query()->count());
        $this->assertSame(0, SuchakPayoutHold::query()->count());
    }

    // ----------------------------------------------------------------- helpers

    private function disputedVisit(
        SuchakVisitConfirmationService $service,
        SuchakPipeline $pipeline,
        User $suchakUser,
        User $requestingUser,
        SuchakPaymentContext $paymentContext,
    ): SuchakVisitConfirmation {
        $visit = $service->scheduleVisit($pipeline, $suchakUser, [
            'payment_context_id' => $paymentContext->id,
            'schedule_note' => 'Meeting scheduled for the dispute lifecycle.',
        ]);
        $visit = $service->markSuchakCompleted($visit, $suchakUser, [
            'completion_note' => 'Suchak marked the meeting completed and awaits the family answer.',
        ]);

        return $service->disputeVisit($visit, $requestingUser, [
            'dispute_reason' => 'The family says this meeting did not happen as the Suchak claims.',
        ]);
    }

    /**
     * @return array{0: User, 1: SuchakAccount}
     */
    private function suchakAccount(string $name): array
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'suchak_name' => $name,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        return [$user->fresh(), $account];
    }

    /**
     * @return array{0: User, 1: User, 2: User, 3: SuchakPipeline, 4: SuchakPaymentContext}
     */
    private function fixture(): array
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);
        [$suchakUser, $account] = $this->suchakAccount('Dispute Lifecycle Suchak');
        $requestingUser = User::factory()->create();

        $requestingProfile = MatrimonyProfile::factory()->create([
            'user_id' => $requestingUser->id,
            'full_name' => 'Dispute Lifecycle Requesting User',
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        $targetProfile = MatrimonyProfile::factory()->create([
            'full_name' => 'Dispute Lifecycle Target Candidate',
            'date_of_birth' => '1998-06-10',
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
            'requesting_user_id' => $requestingUser->id,
            'requesting_matrimony_profile_id' => $requestingProfile->id,
            'target_matrimony_profile_id' => $targetProfile->id,
            'selected_suchak_account_id' => $account->id,
            'representation_id' => $representation->id,
            'request_status' => SuchakProfileRequest::STATUS_PENDING,
            'request_reason' => 'intro_visit',
            'message' => 'Please coordinate introduction through Suchak.',
        ]);
        $pipeline = SuchakPipeline::query()->create([
            'request_id' => $request->id,
            'target_matrimony_profile_id' => $targetProfile->id,
            'requesting_matrimony_profile_id' => $requestingProfile->id,
            'selected_suchak_account_id' => $account->id,
            'representation_id' => $representation->id,
            'pipeline_status' => SuchakPipeline::STATUS_PENDING,
            'attribution_locked_at' => now(),
            'lock_expires_at' => now()->addDays(2),
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
            'resolution_note' => 'Dispute lifecycle platform payout context.',
        ]);

        return [
            $admin,
            $suchakUser,
            $requestingUser,
            $pipeline->fresh(['selectedSuchakAccount', 'request', 'representation']),
            $paymentContext->fresh(['suchakAccount', 'pipeline', 'matrimonyProfile']),
        ];
    }
}
