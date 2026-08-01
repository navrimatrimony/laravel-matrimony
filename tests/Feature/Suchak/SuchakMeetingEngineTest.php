<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPipeline;
use App\Models\SuchakPlatformPayout;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileRequest;
use App\Models\SuchakServicePackage;
use App\Models\SuchakVisitConfirmation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakPolicyService;
use App\Modules\Suchak\Services\SuchakVisitConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 1a of the matchmaker marketplace blueprint (section 11 row "1a").
 *
 * Two defects, both narrow and both fatal to the feature:
 *
 *  B1 — `unique(pipeline_id)` meant a pair could hold exactly ONE meeting,
 *       ever. D24's re-visit fee could not exist, because `meeting_sequence > 1`
 *       is what marks a charge as a re-visit.
 *  B2 — four of the seven things the engine can do (confirm by user, confirm by
 *       admin, dispute, qualify payout) had no route, so in production a row
 *       could only ever be `scheduled` or `completed` and D9's "the customer
 *       confirms" was unreachable.
 */
class SuchakMeetingEngineTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- schema

    public function test_the_one_meeting_per_pair_unique_is_gone_and_the_sequence_unique_replaced_it(): void
    {
        foreach ([
            'meeting_sequence',
            'meeting_mode',
            'fee_amount',
            'fee_currency',
            'customer_agreement_id',
            'helper_suchak_account_id',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_visit_confirmations', $column), $column);
        }

        $indexes = collect(Schema::getIndexes('suchak_visit_confirmations'));

        $this->assertNull(
            $indexes->firstWhere('name', 'sk_visit_confirmations_pipeline_unique'),
            'The one-meeting-per-pair unique must be dropped, not merely worked around.',
        );

        $sequenceUnique = $indexes->firstWhere('name', 'sk_visit_confirmations_pipeline_seq_unique');
        $this->assertNotNull($sequenceUnique, 'unique(pipeline_id, meeting_sequence) is the replacement guarantee.');
        $this->assertTrue((bool) $sequenceUnique['unique']);
        $this->assertSame(['pipeline_id', 'meeting_sequence'], $sequenceUnique['columns']);

        // The foreign key still has to have an index to stand on.
        $this->assertNotNull($indexes->firstWhere('name', 'sk_visit_confirmations_pipeline_idx'));
    }

    public function test_the_database_refuses_a_second_meeting_with_the_same_sequence(): void
    {
        // The application decides the next number; this is the backstop that
        // stops two concurrent schedules both becoming meeting 2.
        $fixture = $this->meetingFixture();
        $service = app(SuchakVisitConfirmationService::class);

        $first = $service->scheduleVisit($fixture['pipeline'], $fixture['suchakUser'], [
            'payment_context_id' => $fixture['paymentContext']->id,
            'schedule_note' => 'First introduction meeting.',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('suchak_visit_confirmations')->insert(
            collect($first->getAttributes())
                ->except(['id', 'created_at', 'updated_at'])
                ->all()
        );
    }

    // --------------------------------------------------------------- service

    public function test_a_second_meeting_is_refused_while_the_first_is_still_open(): void
    {
        $fixture = $this->meetingFixture();
        $service = app(SuchakVisitConfirmationService::class);

        $first = $service->scheduleVisit($fixture['pipeline'], $fixture['suchakUser'], [
            'payment_context_id' => $fixture['paymentContext']->id,
            'schedule_note' => 'First meeting, still scheduled.',
        ]);
        $this->assertSame(1, $first->meeting_sequence);

        // scheduled
        $this->assertScheduleRefused($fixture);

        // completed-but-unconfirmed: M4 says no fee falls due without the
        // customer's confirmation, so stacking the next meeting on top of an
        // unanswered one is exactly what must not be possible.
        $completed = $service->markSuchakCompleted($first, $fixture['suchakUser'], [
            'completion_note' => 'Suchak marked the first meeting complete.',
        ]);
        $this->assertSame(SuchakVisitConfirmation::STATUS_COMPLETED, $completed->visit_status);
        $this->assertScheduleRefused($fixture);

        // disputed
        $disputed = $service->disputeVisit($completed, $fixture['requestingUser'], [
            'dispute_reason' => 'The family says this meeting never took place.',
        ]);
        $this->assertSame(SuchakVisitConfirmation::STATUS_DISPUTED, $disputed->visit_status);
        $this->assertScheduleRefused($fixture);
    }

    public function test_a_confirmed_meeting_opens_the_way_for_the_next_one_at_the_same_rate(): void
    {
        // D24 — a re-visit is charged at the same rate as a first visit. No
        // discount, no escalation, no dependence on the sequence number.
        DB::table('suchak_policies')
            ->where('policy_key', SuchakPolicyService::KEY_SUCHAK_VISIT_CONFIRMATION_POLICY_MODE)
            ->update(['policy_value' => SuchakVisitConfirmation::POLICY_USER_ONLY]);

        $fixture = $this->meetingFixture();
        $service = app(SuchakVisitConfirmationService::class);

        $first = $service->scheduleVisit($fixture['pipeline'], $fixture['suchakUser'], [
            'payment_context_id' => $fixture['paymentContext']->id,
            'schedule_note' => 'First arranged meeting.',
        ]);
        $this->assertSame('3000.00', $first->fee_amount);
        $this->assertSame(SuchakVisitConfirmation::MODE_OFFLINE, $first->meeting_mode);

        $completed = $service->markSuchakCompleted($first, $fixture['suchakUser'], [
            'completion_note' => 'First meeting held at the family home.',
        ]);
        $confirmed = $service->confirmByUser($completed, $fixture['requestingUser'], [
            'confirmation_note' => 'The family confirms the first meeting happened.',
        ]);
        $this->assertSame(SuchakVisitConfirmation::STATUS_CONFIRMED, $confirmed->visit_status);

        $second = $service->scheduleVisit($fixture['pipeline']->fresh(['selectedSuchakAccount']), $fixture['suchakUser'], [
            'payment_context_id' => $fixture['paymentContext']->id,
            'schedule_note' => 'Re-visit arranged by the Suchak.',
        ]);

        $this->assertSame(2, $second->meeting_sequence);
        $this->assertSame('3000.00', $second->fee_amount, 'D24: a re-visit costs exactly what the first visit cost.');
        $this->assertSame(2, SuchakVisitConfirmation::query()->where('pipeline_id', $fixture['pipeline']->id)->count());
    }

    public function test_an_online_meeting_freezes_the_online_rate_not_the_offline_one(): void
    {
        // D2 — the two per-meeting fees are fully independent amounts, so the
        // mode has to pick the rate rather than derive one from the other.
        $fixture = $this->meetingFixture();

        $visit = app(SuchakVisitConfirmationService::class)->scheduleVisit(
            $fixture['pipeline'],
            $fixture['suchakUser'],
            [
                'payment_context_id' => $fixture['paymentContext']->id,
                'meeting_mode' => SuchakVisitConfirmation::MODE_ONLINE,
                'schedule_note' => 'Video counselling session arranged.',
            ],
        );

        $this->assertSame(SuchakVisitConfirmation::MODE_ONLINE, $visit->meeting_mode);
        $this->assertSame('5000.00', $visit->fee_amount);
    }

    public function test_a_meeting_records_whose_candidate_was_met_and_refuses_to_name_the_arranging_suchak(): void
    {
        $fixture = $this->meetingFixture();
        $helper = SuchakAccount::factory()->create([
            'user_id' => User::factory()->create()->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
        ]);
        $service = app(SuchakVisitConfirmationService::class);

        $visit = $service->scheduleVisit($fixture['pipeline'], $fixture['suchakUser'], [
            'payment_context_id' => $fixture['paymentContext']->id,
            'helper_suchak_account_id' => $helper->id,
            'schedule_note' => 'Marketplace meeting with a helper candidate.',
        ]);

        $this->assertSame($helper->id, $visit->helper_suchak_account_id);

        // Naming the arranging account would make the column say something
        // untrue: there is no helper in that meeting. Fresh pipeline, because
        // the open-meeting guard would otherwise answer first.
        $other = $this->meetingFixture();
        try {
            $service->scheduleVisit($other['pipeline'], $other['suchakUser'], [
                'payment_context_id' => $other['paymentContext']->id,
                'helper_suchak_account_id' => $other['account']->id,
            ]);
            $this->fail('The arranging Suchak must not be recordable as the helper.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('different account', $exception->getMessage());
        }
    }

    public function test_the_fee_is_frozen_at_schedule_time_and_a_later_rate_change_does_not_reach_back(): void
    {
        // Section 4 / M6 — a rate change is a new agreement the customer must
        // accept; a meeting already scheduled is not re-priced by it.
        $fixture = $this->meetingFixture();

        $visit = app(SuchakVisitConfirmationService::class)->scheduleVisit(
            $fixture['pipeline'],
            $fixture['suchakUser'],
            [
                'payment_context_id' => $fixture['paymentContext']->id,
                'schedule_note' => 'Meeting priced under the accepted agreement.',
            ],
        );
        $this->assertSame('3000.00', $visit->fee_amount);

        $fixture['package']->forceFill(['per_meeting_fee_amount' => '9000.00'])->save();

        $this->assertSame('3000.00', $visit->fresh()->fee_amount, 'A held meeting must not silently re-price.');
    }

    public function test_the_fee_comes_from_the_plan_the_meeting_is_on_and_two_plans_are_refused_not_guessed(): void
    {
        // The upgrade that was being mis-billed: the family moved to the dearer
        // plan, but `agreement_revision` is numbered PER service_package_id
        // (unique(service_package_id, agreement_revision)), so sorting by it
        // across packages compared two unrelated counters and answered with the
        // plan they had left.
        $fixture = $this->meetingFixture();
        $cheaperButNewerRevision = $this->addAgreedPlan($fixture, 'Basic follow-up plan', '500.00', '750.00', 2);

        $this->assertGreaterThan(
            (int) $fixture['agreement']->agreement_revision,
            (int) $cheaperButNewerRevision->agreement_revision,
            'The probe only means anything while the cheaper plan sorts FIRST by revision.',
        );

        $service = app(SuchakVisitConfirmationService::class);

        try {
            $service->scheduleVisit($fixture['pipeline'], $fixture['suchakUser'], [
                'payment_context_id' => $fixture['paymentContext']->id,
                'schedule_note' => 'Two agreed plans and no plan named.',
            ]);
            $this->fail('Two agreed plans must be refused, never silently priced off one of them.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('more than one agreed', $exception->getMessage());
        }

        $this->assertSame(0, SuchakVisitConfirmation::query()->count(), 'A refused schedule must leave no row behind.');

        $visit = $service->scheduleVisit($fixture['pipeline'], $fixture['suchakUser'], [
            'payment_context_id' => $fixture['paymentContext']->id,
            'customer_agreement_id' => $fixture['agreement']->id,
            'schedule_note' => 'Meeting priced under the plan the family is actually on.',
        ]);

        $this->assertSame('3000.00', $visit->fee_amount, 'The named plan governs, not whichever revision number happens to be larger.');
        $this->assertSame((int) $fixture['agreement']->id, (int) $visit->customer_agreement_id, 'The frozen figure must name the agreement that asserted it.');
    }

    public function test_a_plan_belonging_to_someone_else_or_not_yet_accepted_cannot_price_a_meeting(): void
    {
        $fixture = $this->meetingFixture();
        $stranger = $this->meetingFixture();
        $service = app(SuchakVisitConfirmationService::class);

        try {
            $service->scheduleVisit($fixture['pipeline'], $fixture['suchakUser'], [
                'payment_context_id' => $fixture['paymentContext']->id,
                'customer_agreement_id' => $stranger['agreement']->id,
            ]);
            $this->fail('Another customer\'s agreement must never price this meeting.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('must belong to this Suchak account and customer', $exception->getMessage());
        }

        $pending = $this->addAgreedPlan($fixture, 'Unaccepted plan', '9999.00', '9999.00', 2, 'INR', SuchakCustomerAgreement::TERMS_PENDING);

        try {
            $service->scheduleVisit($fixture['pipeline'], $fixture['suchakUser'], [
                'payment_context_id' => $fixture['paymentContext']->id,
                'customer_agreement_id' => $pending->id,
            ]);
            $this->fail('Terms the customer has not accepted must not set a price.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('not in force', $exception->getMessage());
        }

        // The pending plan is not an agreed plan, so the single accepted one is
        // still unambiguous and the meeting prices itself without being named.
        $visit = $service->scheduleVisit($fixture['pipeline'], $fixture['suchakUser'], [
            'payment_context_id' => $fixture['paymentContext']->id,
        ]);
        $this->assertSame('3000.00', $visit->fee_amount);
    }

    public function test_the_currency_freezes_with_the_amount_so_a_dollar_plan_is_not_rendered_in_rupees(): void
    {
        // A frozen quote carries its own unit. Reading "the currency is the
        // agreement's" at render time is what turned a USD package into '₹'.
        $fixture = $this->meetingFixture(['package' => ['currency' => 'USD'], 'agreement' => ['currency' => 'USD']]);

        $visit = app(SuchakVisitConfirmationService::class)->scheduleVisit($fixture['pipeline'], $fixture['suchakUser'], [
            'payment_context_id' => $fixture['paymentContext']->id,
            'schedule_note' => 'Meeting quoted under a dollar-priced agreement.',
        ]);

        $this->assertSame('3000.00', $visit->fee_amount);
        $this->assertSame('USD', $visit->fee_currency);
        $this->assertSame('USD 3,000', $visit->fee_display, 'The exposed display must not default to a rupee sign.');

        // Latin digits, and the unit travels onto the append-only trail too.
        $this->assertDatabaseHas('suchak_visit_confirmations', [
            'id' => $visit->id,
            'fee_currency' => 'USD',
        ]);
        $scheduled = $visit->events->firstWhere('event_type', 'scheduled');
        $this->assertSame('USD', $scheduled?->metadata_json['fee_currency']);
        $this->assertSame((int) $fixture['agreement']->id, (int) $scheduled?->metadata_json['customer_agreement_id']);
    }

    public function test_a_meeting_that_costs_nothing_records_no_currency_at_all(): void
    {
        // Null in, null out: ₹0 must never be printed for "nothing was agreed".
        $fixture = $this->meetingFixture(['package' => [
            'per_meeting_fee_amount' => null,
            'per_meeting_online_fee_amount' => null,
        ]]);

        $visit = app(SuchakVisitConfirmationService::class)->scheduleVisit($fixture['pipeline'], $fixture['suchakUser'], [
            'payment_context_id' => $fixture['paymentContext']->id,
        ]);

        $this->assertNull($visit->fee_amount);
        $this->assertNull($visit->fee_currency);
        $this->assertNull($visit->fee_display);
    }

    // -------------------------------------------------------------------- M4

    public function test_admin_only_policy_cannot_waive_the_family_out_of_a_meeting_they_are_billed_for(): void
    {
        // M4 — no fee falls due without the customer's confirmation. Before this,
        // POLICY_ADMIN_ONLY wrote user_confirmation_status = not_required, one
        // admin click flipped the row to `confirmed`, and the platform visit
        // payout qualified with the family never asked.
        DB::table('suchak_policies')
            ->where('policy_key', SuchakPolicyService::KEY_SUCHAK_VISIT_CONFIRMATION_POLICY_MODE)
            ->update(['policy_value' => SuchakVisitConfirmation::POLICY_ADMIN_ONLY]);

        $fixture = $this->meetingFixture();
        $service = app(SuchakVisitConfirmationService::class);

        $visit = $service->scheduleVisit($fixture['pipeline'], $fixture['suchakUser'], [
            'payment_context_id' => $fixture['paymentContext']->id,
            'schedule_note' => 'Charged meeting arranged under admin-only policy.',
        ]);

        $this->assertSame('3000.00', $visit->fee_amount);
        $this->assertSame(
            SuchakVisitConfirmation::CONFIRMATION_PENDING,
            $visit->user_confirmation_status,
            'A meeting somebody is billed for always carries the family\'s confirmation.',
        );

        $completed = $service->markSuchakCompleted($visit, $fixture['suchakUser'], [
            'completion_note' => 'Suchak marked the charged meeting complete.',
        ]);
        $adminConfirmed = $service->confirmByAdmin($completed, $fixture['admin'], [
            'confirmation_note' => 'Admin confirmed under the admin-only policy.',
        ]);

        $this->assertSame(
            SuchakVisitConfirmation::STATUS_COMPLETED,
            $adminConfirmed->visit_status,
            'The admin alone must not be able to declare a charged meeting confirmed.',
        );

        try {
            $service->qualifyPayoutForVisit($adminConfirmed, $fixture['admin'], [
                'amount' => '3000',
                'qualification_note' => 'Attempt to pay out on the admin confirmation alone.',
            ]);
            $this->fail('Money must not move on a charged meeting the family was never asked about.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Suchak visit confirmation policy is not yet satisfied.', $exception->getMessage());
        }

        $this->assertSame(0, SuchakPlatformPayout::query()->count());

        // The family answers, and only now does the meeting settle. The admin's
        // confirmation is still required — the mode is honoured, not discarded.
        $confirmed = $service->confirmByUser($adminConfirmed, $fixture['requestingUser'], [
            'confirmation_note' => 'The family confirms the meeting took place.',
        ]);
        $this->assertSame(SuchakVisitConfirmation::STATUS_CONFIRMED, $confirmed->visit_status);

        $qualified = $service->qualifyPayoutForVisit($confirmed, $fixture['admin'], [
            'amount' => '3000',
            'qualification_note' => 'Both sides confirmed; the visit reward qualifies.',
        ]);
        $this->assertSame(SuchakVisitConfirmation::STATUS_PAYOUT_QUALIFIED, $qualified->visit_status);
    }

    // ---------------------------------------------------------------- cancel

    public function test_a_scheduled_meeting_can_be_cancelled_so_a_no_show_does_not_strand_the_pair_forever(): void
    {
        // `scheduled` is an OPEN status, so without this the first meeting nobody
        // attends blocks that pair for good: it can never be completed, so never
        // confirmed, so every later meeting is refused.
        $fixture = $this->meetingFixture();
        $service = app(SuchakVisitConfirmationService::class);

        $first = $service->scheduleVisit($fixture['pipeline'], $fixture['suchakUser'], [
            'payment_context_id' => $fixture['paymentContext']->id,
            'schedule_note' => 'Meeting the family never turned up to.',
        ]);
        $this->assertScheduleRefused($fixture);

        $cancelled = $service->cancelVisit($first, $fixture['suchakUser'], [
            'cancellation_reason' => 'Neither family reached the meeting point; calling it off.',
        ]);

        $this->assertSame(SuchakVisitConfirmation::STATUS_CANCELLED, $cancelled->visit_status);
        $this->assertNotContains(SuchakVisitConfirmation::STATUS_CANCELLED, SuchakVisitConfirmation::OPEN_STATUSES);

        // The reason and the actor live on the append-only trail, not in a new
        // pair of columns beside every other lifecycle step.
        $event = $cancelled->events->firstWhere('event_type', 'cancelled');
        $this->assertNotNull($event);
        $this->assertSame(SuchakVisitConfirmation::STATUS_SCHEDULED, $event->from_status);
        $this->assertSame((int) $fixture['suchakUser']->id, (int) $event->actor_user_id);
        $this->assertStringContainsString('never turned up', (string) $cancelled->events->firstWhere('event_type', 'scheduled')?->event_note);

        $next = $service->scheduleVisit(
            $fixture['pipeline']->fresh(['selectedSuchakAccount', 'request', 'representation']),
            $fixture['suchakUser'],
            [
                'payment_context_id' => $fixture['paymentContext']->id,
                'schedule_note' => 'Second attempt after the no-show.',
            ],
        );
        $this->assertSame(2, $next->meeting_sequence);
    }

    public function test_only_the_arranging_suchak_or_an_admin_may_cancel_and_only_before_completion(): void
    {
        $fixture = $this->meetingFixture();
        $service = app(SuchakVisitConfirmationService::class);
        $visit = $service->scheduleVisit($fixture['pipeline'], $fixture['suchakUser'], [
            'payment_context_id' => $fixture['paymentContext']->id,
        ]);

        // M5 gives the family `dispute`, not a quiet cancellation of a meeting
        // the Suchak arranged.
        try {
            $service->cancelVisit($visit, $fixture['requestingUser'], [
                'cancellation_reason' => 'The member tries to cancel the Suchak\'s meeting.',
            ]);
            $this->fail('The member must not be able to cancel an arranged meeting.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Only the selected Suchak', $exception->getMessage());
        }

        $completed = $service->markSuchakCompleted($visit, $fixture['suchakUser'], [
            'completion_note' => 'Suchak marked this meeting complete.',
        ]);

        // Once a fee is claimed, the answer to a disagreement is a dispute — not
        // the claiming party deleting its own claim.
        try {
            $service->cancelVisit($completed, $fixture['suchakUser'], [
                'cancellation_reason' => 'Suchak tries to withdraw a completed meeting.',
            ]);
            $this->fail('A completed meeting must not be cancellable.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('has not been marked completed', $exception->getMessage());
        }

        $this->assertSame(SuchakVisitConfirmation::STATUS_COMPLETED, $completed->fresh()->visit_status);
    }

    // ------------------------------------------------------------ lock order

    public function test_every_meeting_transaction_locks_the_pipeline_before_the_visit(): void
    {
        // The reproduced MySQL 1213: scheduleVisit took the pipeline row then the
        // visit rows, while markSuchakCompleted took the visit row and then
        // reached the same pipeline row through the `suchak_pipeline_events`
        // foreign key. Opposed order on two rows is an ABBA deadlock, and a
        // Suchak double-tapping "complete" and "schedule next" got a 500.
        //
        // SQLite compiles `for update` away, so what this asserts is the ORDER of
        // the two statements — which is exactly the property the deadlock turns
        // on, and the only one that survives the driver difference.
        $fixture = $this->meetingFixture();
        $service = app(SuchakVisitConfirmationService::class);
        $visit = $service->scheduleVisit($fixture['pipeline'], $fixture['suchakUser'], [
            'payment_context_id' => $fixture['paymentContext']->id,
        ]);

        DB::enableQueryLog();
        $service->markSuchakCompleted($visit, $fixture['suchakUser'], [
            'completion_note' => 'Completion under the single lock order.',
        ]);
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $pipelineAt = $queries->search(fn (string $sql): bool => str_contains($sql, 'from "suchak_pipelines"'));
        $visitAt = $queries->search(fn (string $sql): bool => str_contains($sql, 'from "suchak_visit_confirmations"'));

        $this->assertNotFalse($pipelineAt, 'markSuchakCompleted must take the pipeline row before anything else.');
        $this->assertNotFalse($visitAt);
        $this->assertLessThan($visitAt, $pipelineAt, 'Pipeline first, visit second — in EVERY transaction, or the ABBA cycle comes back.');
    }

    // ---------------------------------------------------------------- routes

    public function test_the_member_can_confirm_their_own_meeting_over_the_member_api(): void
    {
        DB::table('suchak_policies')
            ->where('policy_key', SuchakPolicyService::KEY_SUCHAK_VISIT_CONFIRMATION_POLICY_MODE)
            ->update(['policy_value' => SuchakVisitConfirmation::POLICY_USER_ONLY]);

        $fixture = $this->meetingFixture();
        $service = app(SuchakVisitConfirmationService::class);
        $visit = $service->markSuchakCompleted(
            $service->scheduleVisit($fixture['pipeline'], $fixture['suchakUser'], [
                'payment_context_id' => $fixture['paymentContext']->id,
            ]),
            $fixture['suchakUser'],
            ['completion_note' => 'Suchak marked this meeting complete.'],
        );

        Sanctum::actingAs($fixture['requestingUser']);

        $response = $this->postJson("/api/v1/suchak-meetings/{$visit->id}/confirm", [
            'confirmation_note' => 'We met the family on Sunday as arranged.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.visit_status', SuchakVisitConfirmation::STATUS_CONFIRMED)
            ->assertJsonPath('data.user_confirmation_status', SuchakVisitConfirmation::CONFIRMATION_CONFIRMED)
            ->assertJsonPath('data.meeting_sequence', 1)
            // D17 — this meeting's fee alone, and Latin digits with Indian
            // grouping. No accumulated total travels with an approval.
            ->assertJsonPath('data.fee_display', '₹3,000');

        $this->assertNull($response->json('data.total_fee_amount'));
        $this->assertNull($response->json('data.meetings_so_far'));
    }

    public function test_the_member_can_dispute_and_a_stranger_cannot_reach_the_meeting_at_all(): void
    {
        $fixture = $this->meetingFixture();
        $service = app(SuchakVisitConfirmationService::class);
        $visit = $service->markSuchakCompleted(
            $service->scheduleVisit($fixture['pipeline'], $fixture['suchakUser'], [
                'payment_context_id' => $fixture['paymentContext']->id,
            ]),
            $fixture['suchakUser'],
            ['completion_note' => 'Suchak marked this meeting complete.'],
        );

        $stranger = User::factory()->create();
        MatrimonyProfile::factory()->create([
            'user_id' => $stranger->id,
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        Sanctum::actingAs($stranger);
        $this->postJson("/api/v1/suchak-meetings/{$visit->id}/dispute", [
            'dispute_reason' => 'A stranger should never learn this meeting exists.',
        ])->assertNotFound();

        Sanctum::actingAs($fixture['requestingUser']);
        $this->postJson("/api/v1/suchak-meetings/{$visit->id}/dispute", [
            'dispute_reason' => 'No such meeting was ever arranged with our family.',
        ])->assertOk()->assertJsonPath('data.visit_status', SuchakVisitConfirmation::STATUS_DISPUTED);

        // Blueprint 7.2 — the dispute and the payout hold already existed and
        // were already wired to each other; only the route was missing.
        $fresh = $visit->fresh();
        $this->assertNotNull($fresh->dispute_id);
        $this->assertNotNull($fresh->payout_hold_id);
    }

    public function test_the_admin_can_confirm_and_qualify_a_visit_payout_over_the_admin_surface(): void
    {
        $fixture = $this->meetingFixture();
        $service = app(SuchakVisitConfirmationService::class);
        $visit = $service->markSuchakCompleted(
            $service->scheduleVisit($fixture['pipeline'], $fixture['suchakUser'], [
                'payment_context_id' => $fixture['paymentContext']->id,
            ]),
            $fixture['suchakUser'],
            ['completion_note' => 'Suchak marked this meeting complete.'],
        );
        $service->confirmByUser($visit, $fixture['requestingUser'], [
            'confirmation_note' => 'The family confirms the meeting happened.',
        ]);

        $this->actingAs($fixture['admin']);

        // The screen has to render, or the four routes are still unreachable in
        // practice — which was the whole of blocker B2.
        $this->get('/admin/suchak/visits')->assertOk()->assertSee('Suchak Meetings');

        $this->post("/admin/suchak/visits/{$visit->id}/confirm", [
            'confirmation_note' => 'Admin verified both sides before payout.',
        ])->assertRedirect(route('admin.suchak.visits.index'));

        $this->assertSame(SuchakVisitConfirmation::STATUS_CONFIRMED, $visit->fresh()->visit_status);

        $this->post("/admin/suchak/visits/{$visit->id}/qualify-payout", [
            'amount' => '3000',
            'qualification_note' => 'Confirmed meeting qualifies the platform visit reward.',
        ])->assertRedirect(route('admin.suchak.visits.index'));

        $qualified = $visit->fresh();
        $this->assertSame(SuchakVisitConfirmation::STATUS_PAYOUT_QUALIFIED, $qualified->visit_status);
        $this->assertNotNull($qualified->platform_payout_id);
    }

    public function test_a_non_admin_cannot_reach_the_admin_meeting_actions(): void
    {
        $fixture = $this->meetingFixture();
        $visit = app(SuchakVisitConfirmationService::class)->scheduleVisit(
            $fixture['pipeline'],
            $fixture['suchakUser'],
            ['payment_context_id' => $fixture['paymentContext']->id],
        );

        $this->actingAs($fixture['suchakUser']);
        $this->post("/admin/suchak/visits/{$visit->id}/confirm", [
            'confirmation_note' => 'A Suchak must not be able to confirm as admin.',
        ])->assertForbidden();
    }

    // --------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function assertScheduleRefused(array $fixture): void
    {
        try {
            app(SuchakVisitConfirmationService::class)->scheduleVisit(
                $fixture['pipeline']->fresh(['selectedSuchakAccount', 'request', 'representation']),
                $fixture['suchakUser'],
                ['payment_context_id' => $fixture['paymentContext']->id],
            );
            $this->fail('A second meeting must be refused while one is still open.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('still open', $exception->getMessage());
        }
    }

    /**
     * A SECOND published plan for the same customer, with its own accepted
     * agreement — what a family that upgrades (or downgrades) actually looks
     * like in the data, and the shape that used to be mis-priced.
     *
     * @param  array<string, mixed>  $fixture
     */
    private function addAgreedPlan(
        array $fixture,
        string $name,
        ?string $offlineRate,
        ?string $onlineRate,
        int $revision,
        string $currency = 'INR',
        string $termsStatus = SuchakCustomerAgreement::TERMS_ACCEPTED,
    ): SuchakCustomerAgreement {
        $package = SuchakServicePackage::query()->create([
            'suchak_account_id' => $fixture['account']->id,
            'customer_context_id' => $fixture['customerContext']->id,
            'package_name' => $name,
            'price_amount' => '10000.00',
            'currency' => $currency,
            'per_meeting_fee_amount' => $offlineRate,
            'per_meeting_online_fee_amount' => $onlineRate,
            'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
            'requires_admin_approval' => false,
            'customized_by_user_id' => $fixture['suchakUser']->id,
            'published_at' => now(),
        ]);

        return SuchakCustomerAgreement::query()->create([
            'suchak_account_id' => $fixture['account']->id,
            'customer_context_id' => $fixture['customerContext']->id,
            'service_package_id' => $package->id,
            'agreement_revision' => $revision,
            'terms_status' => $termsStatus,
            'terms_policy_mode' => SuchakCustomerAgreement::POLICY_STRICT,
            'agreement_snapshot_hash' => str_repeat('b', 64),
            'package_name' => $name,
            'price_amount' => '10000.00',
            'currency' => $currency,
            'agreement_title' => $name.' agreement',
            'created_by_user_id' => $fixture['suchakUser']->id,
            'accepted_at' => $termsStatus === SuchakCustomerAgreement::TERMS_ACCEPTED ? now() : null,
        ]);
    }

    /**
     * A pipeline whose customer has an ACCEPTED agreement quoting ₹3,000 offline
     * and ₹5,000 online, so the fee actually has somewhere to resolve from.
     *
     * @param  array<string, array<string, mixed>>  $overrides
     * @return array<string, mixed>
     */
    private function meetingFixture(array $overrides = []): array
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);
        $suchakUser = User::factory()->create();
        $requestingUser = User::factory()->create();

        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);
        $requestingProfile = MatrimonyProfile::factory()->create([
            'user_id' => $requestingUser->id,
            'full_name' => 'Meeting Engine Requester',
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        $targetProfile = MatrimonyProfile::factory()->create([
            'full_name' => 'Meeting Engine Candidate',
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
            'requesting_user_id' => $requestingUser->id,
            'requesting_matrimony_profile_id' => $requestingProfile->id,
            'target_matrimony_profile_id' => $targetProfile->id,
            'selected_suchak_account_id' => $account->id,
            'representation_id' => $representation->id,
            'request_status' => SuchakProfileRequest::STATUS_PENDING,
            'request_reason' => 'intro_visit',
            'message' => 'Please arrange an introduction.',
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
        $customerContext = SuchakCustomerContext::query()->create([
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $targetProfile->id,
            'payer_name' => 'Candidate family',
            'payer_relationship_to_candidate' => 'Parent',
            'service_context' => SuchakCustomerContext::SERVICE_PACKAGE_LEAD,
            'source_owner' => SuchakPaymentContext::SOURCE_SUCHAK,
            'source_type' => SuchakCustomerContext::SOURCE_TYPE_MANUAL,
            'customer_lifecycle_status' => SuchakCustomerContext::STATUS_ACTIVE_SERVICE,
            'created_by_user_id' => $suchakUser->id,
            'classified_by_user_id' => $suchakUser->id,
            'classified_at' => now(),
            'opened_at' => now(),
        ]);
        $package = SuchakServicePackage::query()->create(array_merge([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'package_name' => 'Meeting engine package',
            'price_amount' => '20000.00',
            'currency' => 'INR',
            'per_meeting_fee_amount' => '3000.00',
            'per_meeting_online_fee_amount' => '5000.00',
            'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
            'requires_admin_approval' => false,
            'customized_by_user_id' => $suchakUser->id,
            'published_at' => now(),
        ], $overrides['package'] ?? []));
        $agreement = SuchakCustomerAgreement::query()->create(array_merge([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'service_package_id' => $package->id,
            'agreement_revision' => 1,
            'terms_status' => SuchakCustomerAgreement::TERMS_ACCEPTED,
            'terms_policy_mode' => SuchakCustomerAgreement::POLICY_STRICT,
            'agreement_snapshot_hash' => str_repeat('a', 64),
            'package_name' => 'Meeting engine package',
            'price_amount' => '20000.00',
            'currency' => 'INR',
            'agreement_title' => 'Meeting engine agreement',
            'created_by_user_id' => $suchakUser->id,
            'accepted_at' => now(),
        ], $overrides['agreement'] ?? []));
        $paymentContext = SuchakPaymentContext::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'matrimony_profile_id' => $targetProfile->id,
            'pipeline_id' => $pipeline->id,
            'source_owner' => SuchakPaymentContext::SOURCE_PLATFORM,
            'payment_collector' => SuchakPaymentContext::COLLECTOR_PLATFORM,
            'context_status' => SuchakPaymentContext::STATUS_ACTIVE,
            'resolved_by_user_id' => $admin->id,
            'resolution_note' => 'Meeting engine platform context.',
        ]);

        return [
            'admin' => $admin,
            'suchakUser' => $suchakUser,
            'requestingUser' => $requestingUser,
            'account' => $account,
            'package' => $package,
            'agreement' => $agreement,
            'customerContext' => $customerContext,
            'pipeline' => $pipeline->fresh(['selectedSuchakAccount', 'request', 'representation']),
            'paymentContext' => $paymentContext->fresh(['suchakAccount', 'pipeline']),
        ];
    }
}
