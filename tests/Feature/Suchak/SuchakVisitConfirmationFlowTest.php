<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCustomerPayment;
use App\Models\SuchakDispute;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPipeline;
use App\Models\SuchakPipelineEvent;
use App\Models\SuchakPlatformPayout;
use App\Models\SuchakPayoutHold;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileRequest;
use App\Models\SuchakVisitConfirmation;
use App\Models\SuchakVisitConfirmationEvent;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakPolicyService;
use App\Modules\Suchak\Services\SuchakVisitConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SuchakVisitConfirmationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_day_44_visit_confirmation_tables_policy_and_no_contact_columns_are_structured(): void
    {
        $this->assertTrue(Schema::hasTable('suchak_visit_confirmations'));
        $this->assertTrue(Schema::hasTable('suchak_visit_confirmation_events'));

        foreach ([
            'pipeline_id',
            'suchak_account_id',
            'request_id',
            'representation_id',
            'target_matrimony_profile_id',
            'requesting_matrimony_profile_id',
            'payment_context_id',
            'customer_context_id',
            'platform_payout_id',
            'dispute_id',
            'payout_hold_id',
            'visit_status',
            'confirmation_policy_mode',
            'scheduled_for',
            'scheduled_by_user_id',
            'scheduled_at',
            'schedule_note',
            'suchak_completion_status',
            'user_confirmation_status',
            'admin_confirmation_status',
            'refund_review_status',
            'payout_qualified_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_visit_confirmations', $column), $column);
        }

        foreach ([
            'visit_confirmation_id',
            'pipeline_id',
            'suchak_account_id',
            'event_type',
            'actor_type',
            'actor_user_id',
            'from_status',
            'to_status',
            'event_note',
            'metadata_json',
            'occurred_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_visit_confirmation_events', $column), $column);
        }

        $this->assertFalse(Schema::hasColumn('suchak_visit_confirmations', 'contact_number'));
        $this->assertFalse(Schema::hasColumn('suchak_visit_confirmations', 'email'));
        $this->assertFalse(Schema::hasColumn('suchak_visit_confirmation_events', 'updated_at'));
        $this->assertDatabaseHas('suchak_policies', [
            'policy_key' => SuchakPolicyService::KEY_SUCHAK_VISIT_CONFIRMATION_POLICY_MODE,
            'policy_value' => SuchakVisitConfirmation::POLICY_USER_AND_ADMIN,
        ]);
        $this->assertContains(SuchakPayoutHold::SCOPE_VISIT_CONFIRMATION_DISPUTE, SuchakPayoutHold::SCOPES);
        $this->assertContains(SuchakDispute::TYPE_VISIT_CONFIRMATION, SuchakDispute::TYPES);
    }

    public function test_suchak_user_and_admin_confirmed_visit_qualifies_platform_visit_payout_without_customer_ledger(): void
    {
        [$admin, $suchakUser, $requestingUser, $pipeline, $paymentContext] = $this->visitFixture();
        $service = app(SuchakVisitConfirmationService::class);

        $visit = $service->scheduleVisit($pipeline, $suchakUser, [
            'payment_context_id' => $paymentContext->id,
            'scheduled_for' => '2026-06-22 15:00:00',
            'schedule_note' => 'Family introduction meeting scheduled at the Suchak office.',
        ]);

        $this->assertSame(SuchakVisitConfirmation::STATUS_SCHEDULED, $visit->visit_status);
        $this->assertSame(SuchakVisitConfirmation::POLICY_USER_AND_ADMIN, $visit->confirmation_policy_mode);
        $this->assertSame($paymentContext->id, $visit->payment_context_id);
        $this->assertDatabaseHas('suchak_pipeline_events', [
            'pipeline_id' => $pipeline->id,
            'event_type' => SuchakPipelineEvent::EVENT_MEETING_SCHEDULED,
            'actor_type' => SuchakPipelineEvent::ACTOR_SUCHAK,
            'actor_id' => $suchakUser->id,
        ]);

        $completed = $service->markSuchakCompleted($visit, $suchakUser, [
            'completion_note' => 'Suchak marked the family introduction visit completed.',
        ]);

        $this->assertSame(SuchakVisitConfirmation::STATUS_COMPLETED, $completed->visit_status);
        $this->assertSame(SuchakVisitConfirmation::COMPLETION_SUCHAK_MARKED, $completed->suchak_completion_status);
        $this->assertDatabaseHas('suchak_pipeline_events', [
            'pipeline_id' => $pipeline->id,
            'event_type' => SuchakPipelineEvent::EVENT_MEETING_COMPLETED,
            'actor_type' => SuchakPipelineEvent::ACTOR_SUCHAK,
        ]);

        $userConfirmed = $service->confirmByUser($completed, $requestingUser, [
            'confirmation_note' => 'User confirmed that the visit happened as scheduled.',
        ]);

        $this->assertSame(SuchakVisitConfirmation::STATUS_COMPLETED, $userConfirmed->visit_status);
        $this->assertSame(SuchakVisitConfirmation::CONFIRMATION_CONFIRMED, $userConfirmed->user_confirmation_status);

        $adminConfirmed = $service->confirmByAdmin($userConfirmed, $admin, [
            'confirmation_note' => 'Admin verified user and Suchak completion before payout.',
        ]);

        $this->assertSame(SuchakVisitConfirmation::STATUS_CONFIRMED, $adminConfirmed->visit_status);
        $this->assertSame(SuchakVisitConfirmation::CONFIRMATION_CONFIRMED, $adminConfirmed->admin_confirmation_status);

        $qualified = $service->qualifyPayoutForVisit($adminConfirmed, $admin, [
            'amount' => '1500',
            'currency' => 'INR',
            'qualification_note' => 'Confirmed platform introduction visit qualifies Suchak reward.',
        ]);

        $this->assertSame(SuchakVisitConfirmation::STATUS_PAYOUT_QUALIFIED, $qualified->visit_status);
        $this->assertNotNull($qualified->platform_payout_id);
        $payout = SuchakPlatformPayout::query()->firstOrFail();
        $this->assertSame($qualified->platform_payout_id, $payout->id);
        $this->assertSame(SuchakPlatformPayout::EVENT_PLATFORM_VISIT_CONFIRMED, $payout->platform_event_type);
        $this->assertSame('visit-confirmation-'.$qualified->id, $payout->platform_event_key);
        $this->assertSame(SuchakPlatformPayout::REASON_PLATFORM_VISIT_REWARD, $payout->payout_reason);
        $this->assertSame('1500.00', $payout->amount);
        $this->assertSame(0, SuchakCustomerPayment::query()->count());
        $this->assertSame(0, SuchakLedgerEntry::query()->count());

        $this->assertDatabaseHas('suchak_visit_confirmation_events', [
            'visit_confirmation_id' => $qualified->id,
            'event_type' => SuchakVisitConfirmationEvent::EVENT_PAYOUT_QUALIFIED,
            'actor_type' => SuchakVisitConfirmationEvent::ACTOR_ADMIN,
        ]);
        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $qualified->suchak_account_id,
            'actor_user_id' => $admin->id,
            'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
            'action_type' => SuchakActivityLog::ACTION_VISIT_PAYOUT_QUALIFIED,
            'target_type' => 'suchak_visit_confirmation',
            'target_id' => $qualified->id,
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_id' => $admin->id,
            'action_type' => 'suchak_visit_payout_qualified',
            'entity_type' => 'SuchakVisitConfirmation',
            'entity_id' => $qualified->id,
        ]);
    }

    public function test_admin_only_confirmation_policy_can_qualify_without_user_confirmation(): void
    {
        DB::table('suchak_policies')
            ->where('policy_key', SuchakPolicyService::KEY_SUCHAK_VISIT_CONFIRMATION_POLICY_MODE)
            ->update(['policy_value' => SuchakVisitConfirmation::POLICY_ADMIN_ONLY]);

        [$admin, $suchakUser, , $pipeline, $paymentContext] = $this->visitFixture();
        $service = app(SuchakVisitConfirmationService::class);

        $visit = $service->scheduleVisit($pipeline, $suchakUser, [
            'payment_context_id' => $paymentContext->id,
            'schedule_note' => 'Admin-only policy introduction visit scheduled.',
        ]);
        $completed = $service->markSuchakCompleted($visit, $suchakUser, [
            'completion_note' => 'Visit completion marked under admin-only policy.',
        ]);
        $confirmed = $service->confirmByAdmin($completed, $admin, [
            'confirmation_note' => 'Admin confirmed this visit under configured admin-only policy.',
        ]);

        $this->assertSame(SuchakVisitConfirmation::POLICY_ADMIN_ONLY, $confirmed->confirmation_policy_mode);
        $this->assertSame(SuchakVisitConfirmation::CONFIRMATION_NOT_REQUIRED, $confirmed->user_confirmation_status);
        $this->assertSame(SuchakVisitConfirmation::STATUS_CONFIRMED, $confirmed->visit_status);

        $qualified = $service->qualifyPayoutForVisit($confirmed, $admin, [
            'amount' => '750',
            'currency' => 'INR',
            'qualification_note' => 'Admin-only confirmed visit qualifies payout.',
        ]);

        $this->assertSame(SuchakVisitConfirmation::STATUS_PAYOUT_QUALIFIED, $qualified->visit_status);
        $this->assertSame(SuchakPlatformPayout::STATUS_ON_HOLD, $qualified->platformPayout?->payout_status);
    }

    public function test_disputed_visit_opens_refund_review_and_holds_platform_payout(): void
    {
        [$admin, $suchakUser, $requestingUser, $pipeline, $paymentContext] = $this->visitFixture();
        $service = app(SuchakVisitConfirmationService::class);
        $visit = $service->scheduleVisit($pipeline, $suchakUser, [
            'payment_context_id' => $paymentContext->id,
            'schedule_note' => 'Dispute path visit scheduled.',
        ]);
        $completed = $service->markSuchakCompleted($visit, $suchakUser, [
            'completion_note' => 'Suchak marked visit complete, awaiting user review.',
        ]);

        $disputed = $service->disputeVisit($completed, $requestingUser, [
            'dispute_reason' => 'User disputes that the meeting was completed and asks for refund review.',
        ]);

        $this->assertSame(SuchakVisitConfirmation::STATUS_DISPUTED, $disputed->visit_status);
        $this->assertSame(SuchakVisitConfirmation::REFUND_PENDING_REVIEW, $disputed->refund_review_status);
        $this->assertNotNull($disputed->dispute_id);
        $this->assertNotNull($disputed->payout_hold_id);
        $this->assertDatabaseHas('suchak_disputes', [
            'id' => $disputed->dispute_id,
            'dispute_type' => SuchakDispute::TYPE_VISIT_CONFIRMATION,
            'risk_source' => SuchakDispute::RISK_SOURCE_VISIT_CONFIRMATION_DISPUTE,
            'status' => SuchakDispute::STATUS_OPEN,
            'payment_context_id' => $paymentContext->id,
        ]);
        $this->assertDatabaseHas('suchak_payout_holds', [
            'id' => $disputed->payout_hold_id,
            'hold_scope' => SuchakPayoutHold::SCOPE_VISIT_CONFIRMATION_DISPUTE,
            'hold_status' => SuchakPayoutHold::STATUS_ACTIVE,
            'payment_context_id' => $paymentContext->id,
        ]);

        try {
            $service->qualifyPayoutForVisit($disputed, $admin, [
                'amount' => '1500',
                'currency' => 'INR',
                'qualification_note' => 'Attempt to qualify disputed visit payout.',
            ]);
            $this->fail('Disputed visits must not qualify platform payout.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Disputed Suchak visit confirmations cannot qualify platform payout.', $exception->getMessage());
        }

        $this->assertSame(0, SuchakPlatformPayout::query()->count());
    }

    public function test_visit_confirmation_notes_reject_private_contact_and_events_do_not_leak_profile_contact(): void
    {
        [$admin, $suchakUser, $requestingUser, $pipeline, $paymentContext, $targetProfile] = $this->visitFixture([
            'target_profile' => [
                'full_name' => 'Sensitive Visit Candidate',
                'father_contact_1' => '9876543210',
                'mother_contact_1' => '9876500000',
            ],
        ]);
        $service = app(SuchakVisitConfirmationService::class);

        try {
            $service->scheduleVisit($pipeline, $suchakUser, [
                'payment_context_id' => $paymentContext->id,
                'schedule_note' => 'Call 9876543210 before the visit.',
            ]);
            $this->fail('Visit notes must reject private contact text.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Suchak visit confirmation records must not store private contact details.', $exception->getMessage());
        }

        $visit = $service->scheduleVisit($pipeline, $suchakUser, [
            'payment_context_id' => $paymentContext->id,
            'schedule_note' => 'Private-safe office visit scheduled.',
        ]);
        $completed = $service->markSuchakCompleted($visit, $suchakUser, [
            'completion_note' => 'Suchak completed the introduction meeting safely.',
        ]);
        $userConfirmed = $service->confirmByUser($completed, $requestingUser, [
            'confirmation_note' => 'User confirmed the meeting without sharing contact.',
        ]);
        $service->confirmByAdmin($userConfirmed, $admin, [
            'confirmation_note' => 'Admin confirmed no direct contact was exposed.',
        ]);

        $encodedEvents = json_encode(SuchakVisitConfirmationEvent::query()->get()->toArray(), JSON_THROW_ON_ERROR);
        $encodedActivity = json_encode(SuchakActivityLog::query()->where('target_type', 'suchak_visit_confirmation')->get()->toArray(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('9876543210', $encodedEvents);
        $this->assertStringNotContainsString('9876500000', $encodedEvents);
        $this->assertStringNotContainsString('Sensitive Visit Candidate', $encodedEvents);
        $this->assertStringNotContainsString('9876543210', $encodedActivity);
        $this->assertStringNotContainsString('9876500000', $encodedActivity);
        $this->assertStringNotContainsString($targetProfile->full_name, $encodedActivity);
    }

    public function test_visit_confirmation_records_and_events_are_non_deletable_and_events_immutable(): void
    {
        [, $suchakUser, , $pipeline, $paymentContext] = $this->visitFixture();
        $visit = app(SuchakVisitConfirmationService::class)->scheduleVisit($pipeline, $suchakUser, [
            'payment_context_id' => $paymentContext->id,
            'schedule_note' => 'Immutable visit confirmation scheduled.',
        ]);
        $event = $visit->events()->firstOrFail();

        try {
            $visit->delete();
            $this->fail('Suchak visit confirmation delete should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak visit confirmation records cannot be deleted.', $exception->getMessage());
        }

        try {
            $event->update(['event_note' => 'changed']);
            $this->fail('Suchak visit confirmation event update should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak visit confirmation events are immutable and cannot be modified or deleted.', $exception->getMessage());
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $overrides
     * @return array{0: User, 1: User, 2: User, 3: SuchakPipeline, 4: SuchakPaymentContext, 5: MatrimonyProfile}
     */
    private function visitFixture(array $overrides = []): array
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $suchakUser = User::factory()->create();
        $requestingUser = User::factory()->create();
        $account = SuchakAccount::factory()->create(array_merge([
            'user_id' => $suchakUser->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ], $overrides['account'] ?? []));
        $requestingProfile = MatrimonyProfile::factory()->create(array_merge([
            'user_id' => $requestingUser->id,
            'full_name' => 'Day 44 Requesting User',
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ], $overrides['requesting_profile'] ?? []));
        $targetProfile = MatrimonyProfile::factory()->create(array_merge([
            'full_name' => 'Day 44 Target Candidate',
            'date_of_birth' => '1998-06-10',
            'father_contact_1' => '9876543210',
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ], $overrides['target_profile'] ?? []));
        $representation = SuchakProfileRepresentation::factory()->create(array_merge([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $targetProfile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_verified_consent_at' => now(),
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ], $overrides['representation'] ?? []));
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
            'resolution_note' => 'Day-44 visit payout platform context.',
        ]);

        return [
            $admin,
            $suchakUser,
            $requestingUser,
            $pipeline->fresh(['selectedSuchakAccount', 'request', 'representation']),
            $paymentContext->fresh(['suchakAccount', 'pipeline', 'matrimonyProfile']),
            $targetProfile,
        ];
    }
}
