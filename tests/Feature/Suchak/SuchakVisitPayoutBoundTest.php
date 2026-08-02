<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakGrowthRewardRule;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPipeline;
use App\Models\SuchakPlatformPayout;
use App\Models\SuchakPolicy;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileRequest;
use App\Models\SuchakVisitConfirmation;
use App\Models\SuchakVisitConfirmationEvent;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakPolicyService;
use App\Modules\Suchak\Services\SuchakVisitConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * WHAT A MEETING PAYOUT IS WORTH, AND WHO MAY DECIDE IT.
 *
 * `qualifyPayoutForVisit()` mints a real SuchakPlatformPayout — the PLATFORM
 * paying the SUCHAK. Its amount validated as `[required, numeric, min:0.01]`:
 * no ceiling, no reference to any platform-owned figure, and no check that the
 * admin qualifying the payout was not the same admin who confirmed the meeting
 * an hour earlier. Whatever one person typed became platform money.
 *
 * The exposure today is one meeting fee. Blueprint 7.4 puts the identical shape
 * in front of an 80,000 - 1,00,000 success fee released in tranches, which is
 * why the bound is worth having before that arrives rather than after.
 *
 * The one thing this file must NOT drift into asserting: that the payout equals
 * `fee_amount`. That figure is the customer-side quote the Suchak set for
 * himself, and binding platform money to the payee's own price list is the
 * defect the empty amount box on `admin/suchak/visits` was guarding against.
 * {@see test_the_payout_is_never_bound_to_the_suchak_own_customer_fee} holds
 * that line.
 */
class SuchakVisitPayoutBoundTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_payout_amount_comes_from_the_platform_rule_not_from_a_typed_figure(): void
    {
        $fixture = $this->confirmedVisit();
        $rule = $this->publishVisitRewardRule($fixture['admin'], 'visit-reward-base', '1200.00');

        $qualified = app(SuchakVisitConfirmationService::class)->qualifyPayoutForVisit(
            $fixture['visit'],
            $fixture['admin'],
            ['qualification_note' => 'Confirmed meeting qualifies the published platform visit reward.'],
        );

        $payout = SuchakPlatformPayout::query()->firstOrFail();
        $this->assertSame('1200.00', $payout->amount, 'The payout did not take the platform rule figure.');
        $this->assertSame('INR', $payout->currency);
        $this->assertSame($qualified->platform_payout_id, $payout->id);

        // The source travels with the money. A payout that records an amount and
        // nothing about where it came from cannot be read back a year later.
        $event = SuchakVisitConfirmationEvent::query()
            ->where('visit_confirmation_id', $qualified->id)
            ->where('event_type', SuchakVisitConfirmationEvent::EVENT_PAYOUT_QUALIFIED)
            ->firstOrFail();

        $this->assertSame(
            SuchakVisitConfirmationService::PAYOUT_AMOUNT_SOURCE_PLATFORM_RULE,
            $event->metadata_json['amount_source'],
        );
        $this->assertSame('visit-reward-base', $event->metadata_json['reward_rule_key']);
        $this->assertSame($rule->id, $event->metadata_json['reward_rule_id']);
        $this->assertNull($event->metadata_json['typed_amount_ceiling']);
    }

    public function test_a_typed_amount_that_disagrees_with_the_rule_in_force_is_refused(): void
    {
        $fixture = $this->confirmedVisit();
        $this->publishVisitRewardRule($fixture['admin'], 'visit-reward-base', '1200.00');

        // Refused rather than silently ignored: a stale form and a deliberate
        // override are indistinguishable here, and silence would pass the second.
        try {
            app(SuchakVisitConfirmationService::class)->qualifyPayoutForVisit(
                $fixture['visit'],
                $fixture['admin'],
                ['amount' => '90000', 'qualification_note' => 'An admin trying to pay ninety thousand for one meeting.'],
            );
            $this->fail('A typed amount overrode the platform rule.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('visit-reward-base', $exception->getMessage());
        }

        $this->assertSame(0, SuchakPlatformPayout::query()->count());
        $this->assertNull($fixture['visit']->fresh()->platform_payout_id);
    }

    public function test_the_payout_is_never_bound_to_the_suchak_own_customer_fee(): void
    {
        $fixture = $this->confirmedVisit();

        // The Suchak quoted the CUSTOMER 25,000 for this meeting. That figure is
        // his to set and has nothing to do with what the platform owes him.
        DB::table('suchak_visit_confirmations')
            ->where('id', $fixture['visit']->id)
            ->update(['fee_amount' => '25000.00', 'fee_currency' => 'INR']);

        $this->publishVisitRewardRule($fixture['admin'], 'visit-reward-base', '1200.00');

        app(SuchakVisitConfirmationService::class)->qualifyPayoutForVisit(
            $fixture['visit']->fresh(),
            $fixture['admin'],
            ['qualification_note' => 'The platform reward is the platform figure, not the quoted fee.'],
        );

        $payout = SuchakPlatformPayout::query()->firstOrFail();
        $this->assertSame('1200.00', $payout->amount);
        $this->assertNotSame('25000.00', $payout->amount, 'The payee’s own price list became the platform obligation.');
    }

    public function test_without_a_rule_a_typed_amount_survives_but_is_capped_by_the_platform_ceiling(): void
    {
        $fixture = $this->confirmedVisit();
        $this->assertNull(SuchakGrowthRewardRule::visitRewardInForce(), 'A rule leaked into the no-rule case.');

        try {
            app(SuchakVisitConfirmationService::class)->qualifyPayoutForVisit(
                $fixture['visit'],
                $fixture['admin'],
                ['amount' => '80000', 'qualification_note' => 'A success-fee-sized figure typed into a meeting form.'],
            );
            $this->fail('An unbounded typed amount qualified a payout.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('7,500', $exception->getMessage());
        }

        $this->assertSame(0, SuchakPlatformPayout::query()->count());

        // Under the ceiling the interim still works — refusing outright would
        // deadlock every meeting payout on a production with no rule row.
        $qualified = app(SuchakVisitConfirmationService::class)->qualifyPayoutForVisit(
            $fixture['visit']->fresh(),
            $fixture['admin'],
            ['amount' => '1500', 'currency' => 'INR', 'qualification_note' => 'Meeting reward typed under the platform ceiling.'],
        );

        $this->assertSame('1500.00', SuchakPlatformPayout::query()->firstOrFail()->amount);

        $event = SuchakVisitConfirmationEvent::query()
            ->where('visit_confirmation_id', $qualified->id)
            ->where('event_type', SuchakVisitConfirmationEvent::EVENT_PAYOUT_QUALIFIED)
            ->firstOrFail();

        $this->assertSame(
            SuchakVisitConfirmationService::PAYOUT_AMOUNT_SOURCE_TYPED_UNDER_CEILING,
            $event->metadata_json['amount_source'],
        );
        $this->assertSame(
            SuchakPolicyService::DEFAULT_SUCHAK_VISIT_PAYOUT_MAX_AMOUNT,
            $event->metadata_json['typed_amount_ceiling'],
        );
        $this->assertNull($event->metadata_json['reward_rule_key']);
    }

    public function test_the_ceiling_is_platform_owned_and_a_published_rule_beats_it(): void
    {
        $fixture = $this->confirmedVisit();

        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_VISIT_PAYOUT_MAX_AMOUNT],
            ['policy_value' => '2000', 'value_type' => SuchakPolicy::TYPE_INTEGER, 'description' => 'Meeting payout ceiling.', 'is_active' => true],
        );

        // A rule may exceed the ceiling. The ceiling bounds an UNPUBLISHED figure;
        // applying it on top of a published price would let an old default
        // silently overrule a deliberate decision that carries its own audit row.
        $this->publishVisitRewardRule($fixture['admin'], 'visit-reward-premium', '5000.00');

        app(SuchakVisitConfirmationService::class)->qualifyPayoutForVisit(
            $fixture['visit'],
            $fixture['admin'],
            ['qualification_note' => 'A published platform price is not capped by the typed-figure ceiling.'],
        );

        $this->assertSame('5000.00', SuchakPlatformPayout::query()->firstOrFail()->amount);
    }

    public function test_the_newest_rule_in_force_supersedes_an_older_one_and_wrong_rules_are_ignored(): void
    {
        $fixture = $this->confirmedVisit();

        $this->publishVisitRewardRule($fixture['admin'], 'visit-reward-2026-06', '1000.00', now()->subMonths(2));
        $newest = $this->publishVisitRewardRule($fixture['admin'], 'visit-reward-2026-08', '1800.00', now()->subDay());
        $this->publishVisitRewardRule($fixture['admin'], 'visit-reward-2027-01', '9000.00', now()->addYear());
        $this->publishVisitRewardRule($fixture['admin'], 'visit-reward-expired', '7000.00', now()->subMonths(6), now()->subMonths(3));

        // Rules can never be edited or deleted, so a later price is a later row.
        $this->assertSame($newest->id, SuchakGrowthRewardRule::visitRewardInForce()?->id);

        // The referral engine's own rules are a different price list on the same
        // table. A payment-trigger rule must never price a meeting.
        SuchakGrowthRewardRule::query()->create([
            'rule_key' => 'referral-reward',
            'reward_trigger' => SuchakGrowthRewardRule::TRIGGER_PLATFORM_PAYMENT_CONFIRMED,
            'reward_type' => SuchakGrowthRewardRule::TYPE_CASH,
            'attribution_policy' => 'coupon_priority',
            'reward_amount' => '50000.00',
            'reward_currency' => 'INR',
            'is_active' => true,
            'starts_at' => now(),
            'created_by_admin_user_id' => $fixture['admin']->id,
        ]);

        $this->assertSame($newest->id, SuchakGrowthRewardRule::visitRewardInForce()?->id);

        app(SuchakVisitConfirmationService::class)->qualifyPayoutForVisit(
            $fixture['visit'],
            $fixture['admin'],
            ['qualification_note' => 'The newest in-force platform visit rule prices this meeting.'],
        );

        $this->assertSame('1800.00', SuchakPlatformPayout::query()->firstOrFail()->amount);
    }

    public function test_a_single_actor_payout_is_allowed_by_default_but_recorded_and_shown_on_the_screen(): void
    {
        $fixture = $this->confirmedVisit();
        $this->publishVisitRewardRule($fixture['admin'], 'visit-reward-base', '1200.00');

        $qualified = app(SuchakVisitConfirmationService::class)->qualifyPayoutForVisit(
            $fixture['visit'],
            $fixture['admin'],
            ['qualification_note' => 'The confirming admin also qualified this payout.'],
        );

        $this->assertSame(SuchakVisitConfirmation::STATUS_PAYOUT_QUALIFIED, $qualified->visit_status);

        $event = SuchakVisitConfirmationEvent::query()
            ->where('visit_confirmation_id', $qualified->id)
            ->where('event_type', SuchakVisitConfirmationEvent::EVENT_PAYOUT_QUALIFIED)
            ->firstOrFail();

        $this->assertTrue($event->metadata_json['single_actor_qualification']);
        $this->assertSame($fixture['admin']->id, $event->metadata_json['admin_confirmed_by_user_id']);
        $this->assertSame($fixture['admin']->id, $event->metadata_json['payout_qualified_by_user_id']);

        $this->assertDatabaseHas('admin_audit_logs', ['action_type' => 'suchak_visit_payout_qualified']);
        $this->assertStringContainsString(
            'single_actor_qualification',
            (string) DB::table('admin_audit_logs')->where('action_type', 'suchak_visit_payout_qualified')->value('reason'),
        );

        // A fact that only lives in a log is a fact nobody reads.
        $this->actingAs($fixture['admin'])
            ->get(route('admin.suchak.visits.index'))
            ->assertOk()
            ->assertSee('Single-actor payout');
    }

    public function test_four_eyes_refuses_the_confirming_admin_once_platform_policy_requires_a_second(): void
    {
        $fixture = $this->confirmedVisit();
        $this->publishVisitRewardRule($fixture['admin'], 'visit-reward-base', '1200.00');

        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_VISIT_PAYOUT_REQUIRES_SECOND_ADMIN],
            ['policy_value' => 'true', 'value_type' => SuchakPolicy::TYPE_BOOLEAN, 'description' => 'Four-eyes on meeting payouts.', 'is_active' => true],
        );

        try {
            app(SuchakVisitConfirmationService::class)->qualifyPayoutForVisit(
                $fixture['visit'],
                $fixture['admin'],
                ['qualification_note' => 'The same admin who confirmed is trying to qualify the payout.'],
            );
            $this->fail('Four-eyes did not refuse the confirming admin.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('different admin', $exception->getMessage());
        }

        $this->assertSame(0, SuchakPlatformPayout::query()->count());

        // And a second admin is not blocked — the control must refuse the actor,
        // not the action.
        $secondAdmin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);

        app(SuchakVisitConfirmationService::class)->qualifyPayoutForVisit(
            $fixture['visit']->fresh(),
            $secondAdmin,
            ['qualification_note' => 'A second admin qualified the payout the first one confirmed.'],
        );

        $this->assertSame('1200.00', SuchakPlatformPayout::query()->firstOrFail()->amount);

        $event = SuchakVisitConfirmationEvent::query()
            ->where('event_type', SuchakVisitConfirmationEvent::EVENT_PAYOUT_QUALIFIED)
            ->firstOrFail();

        // `false` is written too. It is the proof the check ran, not an absence.
        $this->assertFalse($event->metadata_json['single_actor_qualification']);
    }

    public function test_the_admin_surface_can_publish_a_visit_reward_rule_and_cannot_mint_a_referral_one(): void
    {
        $fixture = $this->confirmedVisit();

        // createRewardRule() had no caller outside tests — no route, no
        // controller, no seeder. Binding the payout to a rule nobody could
        // create would have swapped an unbounded amount for an unreachable one.
        $this->actingAs($fixture['admin'])
            ->post(route('admin.suchak.visits.reward-rule.store'), [
                'rule_key' => 'visit-reward-from-screen',
                'reward_amount' => '1350',
                // The form cannot choose the trigger; the controller fixes it.
                'reward_trigger' => SuchakGrowthRewardRule::TRIGGER_PLATFORM_PAYMENT_CONFIRMED,
            ])
            ->assertRedirect(route('admin.suchak.visits.index'));

        $rule = SuchakGrowthRewardRule::query()->where('rule_key', 'visit-reward-from-screen')->firstOrFail();
        $this->assertSame(SuchakGrowthRewardRule::TRIGGER_PLATFORM_VISIT_CONFIRMED, $rule->reward_trigger);
        $this->assertSame(SuchakGrowthRewardRule::TYPE_CASH, $rule->reward_type);
        $this->assertSame('1350.00', $rule->reward_amount);
        $this->assertSame($rule->id, SuchakGrowthRewardRule::visitRewardInForce()?->id);

        // And the screen now states the price it is about to apply.
        $this->actingAs($fixture['admin'])
            ->get(route('admin.suchak.visits.index'))
            ->assertOk()
            ->assertSee('Platform visit reward')
            ->assertSee('visit-reward-from-screen');
    }

    public function test_a_published_visit_reward_can_be_withdrawn_and_the_price_stops(): void
    {
        $fixture = $this->confirmedVisit();

        $this->actingAs($fixture['admin'])
            ->post(route('admin.suchak.visits.reward-rule.store'), [
                'rule_key' => 'visit-reward-to-withdraw',
                'reward_amount' => '900',
            ])
            ->assertRedirect(route('admin.suchak.visits.index'));

        $rule = SuchakGrowthRewardRule::query()->where('rule_key', 'visit-reward-to-withdraw')->firstOrFail();
        $this->assertTrue($rule->is_active);
        $this->assertSame($rule->id, SuchakGrowthRewardRule::visitRewardInForce()?->id);

        // `is_active` had two readers and no writer that could make either false: publishing a
        // later rule can only say "the price is different", never "the platform stops paying".
        $this->actingAs($fixture['admin'])
            ->post(route('admin.suchak.visits.reward-rule.withdraw', $rule), [
                'withdraw_reason' => 'Meeting rewards are ending this quarter.',
            ])
            ->assertRedirect(route('admin.suchak.visits.index'));

        $this->assertFalse($rule->fresh()->is_active);
        $this->assertNull(SuchakGrowthRewardRule::visitRewardInForce());

        // The PRICE is untouched — a payout already qualified at ₹900 still reads back at ₹900.
        $this->assertSame('900.00', $rule->fresh()->reward_amount);

        // One way. "We withdrew it and then we did not" is a rewrite of what the platform said, so
        // a second withdrawal is refused rather than silently accepted.
        $this->actingAs($fixture['admin'])
            ->post(route('admin.suchak.visits.reward-rule.withdraw', $rule), [
                'withdraw_reason' => 'Changed our minds about ending it.',
            ])
            ->assertSessionHas('error', 'Suchak growth reward rule is already withdrawn.');
        $this->assertFalse($rule->fresh()->is_active);

        $this->assertDatabaseHas('admin_audit_logs', [
            'action_type' => 'suchak_growth_reward_rule_withdrawn',
            'entity_type' => 'SuchakGrowthRewardRule',
            'entity_id' => $rule->id,
        ]);
    }

    private function publishVisitRewardRule(
        User $admin,
        string $ruleKey,
        string $amount,
        mixed $startsAt = null,
        mixed $endsAt = null,
    ): SuchakGrowthRewardRule {
        return SuchakGrowthRewardRule::query()->create([
            'rule_key' => $ruleKey,
            'reward_trigger' => SuchakGrowthRewardRule::TRIGGER_PLATFORM_VISIT_CONFIRMED,
            'reward_type' => SuchakGrowthRewardRule::TYPE_CASH,
            'attribution_policy' => 'coupon_priority',
            'reward_amount' => $amount,
            'reward_currency' => 'INR',
            'credit_value' => '0.00',
            'is_active' => true,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'created_by_admin_user_id' => $admin->id,
        ]);
    }

    /**
     * A meeting that is CONFIRMED and payout-eligible: Suchak marked it
     * complete, the family confirmed, and the admin confirmed. Everything the
     * pre-existing guards in `assertEligibleForPayout()` want is already
     * satisfied, so a refusal in these tests is about the amount or the actor
     * and nothing else.
     *
     * @return array{admin: User, visit: SuchakVisitConfirmation}
     */
    private function confirmedVisit(): array
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
            'full_name' => 'Payout Bound Requesting User',
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        $targetProfile = MatrimonyProfile::factory()->create([
            'full_name' => 'Payout Bound Target Candidate',
            'date_of_birth' => '1997-04-12',
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
            'message' => 'Please coordinate the introduction through the Suchak.',
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
            'resolution_note' => 'Platform context for the meeting payout bound tests.',
        ]);

        $service = app(SuchakVisitConfirmationService::class);
        $visit = $service->scheduleVisit($pipeline->fresh(['selectedSuchakAccount', 'request', 'representation']), $suchakUser, [
            'payment_context_id' => $paymentContext->id,
            'scheduled_for' => '2026-08-01 15:00:00',
            'schedule_note' => 'Family introduction meeting scheduled at the Suchak office.',
        ]);
        $visit = $service->markSuchakCompleted($visit, $suchakUser, [
            'completion_note' => 'Suchak marked the introduction meeting completed.',
        ]);
        $visit = $service->confirmByUser($visit, $requestingUser, [
            'confirmation_note' => 'The family confirms the meeting happened as scheduled.',
        ]);
        $visit = $service->confirmByAdmin($visit, $admin, [
            'confirmation_note' => 'Admin verified both sides before any payout.',
        ]);

        return ['admin' => $admin, 'visit' => $visit];
    }
}
