<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakBiodataExport;
use App\Models\SuchakConsent;
use App\Models\SuchakConsentEvent;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakGrowthAttribution;
use App\Models\SuchakGrowthReward;
use App\Models\SuchakGrowthRewardRule;
use App\Models\SuchakLoyaltyTierSnapshot;
use App\Models\SuchakMonthlyValueReport;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPaymentRequest;
use App\Models\SuchakPaymentRequestEvent;
use App\Models\SuchakPlatformPayout;
use App\Models\SuchakPlatformPayoutSettlement;
use App\Models\SuchakProfileNote;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakQrToken;
use App\Models\SuchakScheduledJobRun;
use App\Models\SuchakServicePackage;
use App\Models\SuchakWorkflowReminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class SuchakScheduledJobsConsolidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_day_59_scheduled_job_run_table_is_structured_without_private_contact_columns(): void
    {
        $this->assertTrue(Schema::hasTable('suchak_scheduled_job_runs'));

        foreach ([
            'run_key',
            'job_key',
            'job_status',
            'triggered_by',
            'triggered_by_user_id',
            'admin_audit_log_id',
            'account_scope_id',
            'run_for_date',
            'run_month',
            'metrics_json',
            'started_at',
            'completed_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_scheduled_job_runs', $column), $column);
        }

        foreach (['phone', 'mobile', 'whatsapp', 'email', 'upi', 'deleted_at'] as $forbiddenColumn) {
            $this->assertFalse(Schema::hasColumn('suchak_scheduled_job_runs', $forbiddenColumn), $forbiddenColumn);
        }

        $this->assertSame([
            SuchakScheduledJobRun::JOB_OVERDUE_PAYMENTS,
            SuchakScheduledJobRun::JOB_PAYOUT_CYCLES,
            SuchakScheduledJobRun::JOB_REWARD_QUALIFICATION,
            SuchakScheduledJobRun::JOB_CONSENT_EXPIRY,
            SuchakScheduledJobRun::JOB_QR_EXPIRY,
            SuchakScheduledJobRun::JOB_FOLLOW_UP_REMINDERS,
            SuchakScheduledJobRun::JOB_MONTHLY_REPORTS,
            SuchakScheduledJobRun::JOB_LOYALTY_RECALCULATION,
        ], SuchakScheduledJobRun::JOBS);

        $run = SuchakScheduledJobRun::query()->create([
            'run_key' => 'day59-schema-check',
            'job_key' => SuchakScheduledJobRun::JOB_OVERDUE_PAYMENTS,
            'job_status' => SuchakScheduledJobRun::STATUS_COMPLETED,
            'triggered_by' => SuchakScheduledJobRun::TRIGGER_SYSTEM,
            'run_for_date' => now()->toDateString(),
            'metrics_json' => ['expired_requests' => 0],
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        try {
            $run->delete();
            $this->fail('Scheduled job run delete should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak scheduled job runs cannot be deleted.', $exception->getMessage());
        }
    }

    public function test_consolidated_scheduled_jobs_are_due_aware_idempotent_and_do_not_leak_private_contact(): void
    {
        $now = Carbon::parse('2026-06-11 10:00:00');
        $this->travelTo($now);

        [
            $admin,
            $account,
            $profile,
            $dueRequest,
            $futureRequest,
            $dueConsent,
            $futureConsent,
            $expiredQr,
            $futureQr,
        ] = $this->scheduledJobsFixture($now);

        $this->artisan('suchak:scheduled-jobs', [
            '--account-id' => $account->id,
            '--admin-id' => $admin->id,
            '--at' => $now->toDateTimeString(),
            '--month' => '2026-06',
        ])
            ->expectsOutput('Suchak scheduled jobs completed: 8')
            ->assertExitCode(0);

        $this->assertSame(SuchakPaymentRequest::STATUS_EXPIRED, $dueRequest->fresh()->payment_status);
        $this->assertNotNull($dueRequest->fresh()->expired_at);
        $this->assertSame(SuchakPaymentRequest::STATUS_SENT, $futureRequest->fresh()->payment_status);
        $this->assertNull($futureRequest->fresh()->expired_at);
        $this->assertDatabaseHas('suchak_payment_request_events', [
            'payment_request_id' => $dueRequest->id,
            'event_type' => SuchakPaymentRequestEvent::EVENT_EXPIRED,
            'to_status' => SuchakPaymentRequest::STATUS_EXPIRED,
        ]);

        $this->assertSame(SuchakConsent::STATUS_EXPIRED, $dueConsent->fresh()->consent_status);
        $this->assertSame(SuchakConsent::STATUS_REQUESTED, $futureConsent->fresh()->consent_status);
        $this->assertDatabaseHas('suchak_consent_events', [
            'consent_id' => $dueConsent->id,
            'event_type' => SuchakConsentEvent::EVENT_CONSENT_EXPIRED,
            'actor_type' => SuchakConsentEvent::ACTOR_SYSTEM,
        ]);

        $this->assertNotNull($expiredQr->fresh()->revoked_at);
        $this->assertSame('Expired by consolidated Suchak scheduled job.', $expiredQr->fresh()->revoked_reason);
        $this->assertNull($futureQr->fresh()->revoked_at);

        $this->assertSame(1, SuchakGrowthReward::query()->count());
        $this->assertSame(SuchakGrowthAttribution::STATUS_REWARDED, SuchakGrowthAttribution::query()->firstOrFail()->attribution_status);
        $this->assertSame(1, SuchakPlatformPayoutSettlement::query()->where('statement_month', '2026-06')->count());
        $this->assertSame(1, SuchakMonthlyValueReport::query()->where('report_month', '2026-06')->count());
        $this->assertSame(1, SuchakLoyaltyTierSnapshot::query()->where('snapshot_month', '2026-06')->count());

        $this->assertSame(3, SuchakWorkflowReminder::query()->count());
        SuchakWorkflowReminder::query()->each(function (SuchakWorkflowReminder $reminder) use ($profile): void {
            $this->assertStringContainsString('masked-', $reminder->message_copy);
            $this->assertStringNotContainsString('9876543210', $reminder->message_copy);
            $this->assertStringNotContainsString('day59-private@example.test', $reminder->message_copy);
            $this->assertStringNotContainsString('upi', strtolower($reminder->message_copy));
            $this->assertStringNotContainsString($profile->full_name, $reminder->message_copy);
        });

        $this->assertSame(8, SuchakScheduledJobRun::query()->count());
        foreach (SuchakScheduledJobRun::JOBS as $jobKey) {
            $this->assertTrue(
                SuchakScheduledJobRun::query()
                    ->where('account_scope_id', $account->id)
                    ->where('job_key', $jobKey)
                    ->whereDate('run_for_date', $now->toDateString())
                    ->exists(),
                $jobKey,
            );
        }

        $eventCount = SuchakPaymentRequestEvent::query()->count();
        $consentEventCount = SuchakConsentEvent::query()->count();

        $this->artisan('suchak:scheduled-jobs', [
            '--account-id' => $account->id,
            '--admin-id' => $admin->id,
            '--at' => $now->toDateTimeString(),
            '--month' => '2026-06',
        ])
            ->expectsOutput('Suchak scheduled jobs completed: 8')
            ->assertExitCode(0);

        $this->assertSame(8, SuchakScheduledJobRun::query()->count());
        $this->assertSame(1, SuchakGrowthReward::query()->count());
        $this->assertSame(1, SuchakPlatformPayoutSettlement::query()->count());
        $this->assertSame(1, SuchakMonthlyValueReport::query()->count());
        $this->assertSame(1, SuchakLoyaltyTierSnapshot::query()->count());
        $this->assertSame(3, SuchakWorkflowReminder::query()->count());
        $this->assertSame($eventCount, SuchakPaymentRequestEvent::query()->count());
        $this->assertSame($consentEventCount, SuchakConsentEvent::query()->count());
    }

    /**
     * @return array{0: User, 1: SuchakAccount, 2: MatrimonyProfile, 3: SuchakPaymentRequest, 4: SuchakPaymentRequest, 5: SuchakConsent, 6: SuchakConsent, 7: SuchakQrToken, 8: SuchakQrToken}
     */
    private function scheduledJobsFixture(Carbon $now): array
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $suchakUser = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => $now,
        ]);
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Day59 Private Candidate',
            'date_of_birth' => $now->copy()->subYears(28)->toDateString(),
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_verified_consent_at' => $now->copy()->subMonth(),
            'consent_verified_at' => $now->copy()->subMonth(),
            'consent_valid_until' => $now->copy()->addMonth(),
        ]);

        $suchakContext = $this->customerContext($account, $profile, $suchakUser, SuchakPaymentContext::SOURCE_SUCHAK);
        $suchakPaymentContext = $this->paymentContext($account, $profile, $suchakContext, $suchakUser, SuchakPaymentContext::SOURCE_SUCHAK, SuchakPaymentContext::COLLECTOR_SUCHAK);
        [$package, $agreement] = $this->packageAndAgreement($account, $suchakContext, $suchakUser);

        $dueRequest = $this->paymentRequest(
            $account,
            $suchakContext,
            $package,
            $agreement,
            $suchakPaymentContext,
            $suchakUser,
            'day59-due-payment-request',
            $now->copy()->subMinute(),
        );
        $futureRequest = $this->paymentRequest(
            $account,
            $suchakContext,
            $package,
            $agreement,
            $suchakPaymentContext,
            $suchakUser,
            'day59-future-payment-request',
            $now->copy()->addDay(),
        );

        $dueConsent = SuchakConsent::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_id' => $representation->id,
            'consent_status' => SuchakConsent::STATUS_REQUESTED,
            'consent_mobile_number' => '9876543210',
            'token_expires_at' => $now->copy()->subMinute(),
        ]);
        $futureConsent = SuchakConsent::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_id' => $representation->id,
            'consent_status' => SuchakConsent::STATUS_REQUESTED,
            'consent_mobile_number' => '9876543210',
            'token_expires_at' => $now->copy()->addDays(2),
        ]);

        $export = SuchakBiodataExport::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_id' => $representation->id,
            'generated_by_user_id' => $suchakUser->id,
        ]);
        $expiredQr = SuchakQrToken::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_id' => $representation->id,
            'export_id' => $export->id,
            'expires_at' => $now->copy()->subMinute(),
        ]);
        $futureQr = SuchakQrToken::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_id' => $representation->id,
            'export_id' => $export->id,
            'expires_at' => $now->copy()->addDays(5),
        ]);

        SuchakProfileNote::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'note_type' => SuchakProfileNote::TYPE_FOLLOW_UP,
            'note_text' => 'Call 9876543210 or day59-private@example.test; do not expose this contact.',
            'follow_up_at' => $now->copy()->subHour(),
        ]);

        $platformContext = $this->customerContext($account, $profile, $suchakUser, SuchakPaymentContext::SOURCE_PLATFORM);
        $platformPaymentContext = $this->paymentContext($account, $profile, $platformContext, $suchakUser, SuchakPaymentContext::SOURCE_PLATFORM, SuchakPaymentContext::COLLECTOR_PLATFORM);
        $this->growthRewardCandidate($account, $profile, $platformContext, $platformPaymentContext, $admin, $now);
        $this->paidPayoutCandidate($account, $profile, $platformContext, $platformPaymentContext, $admin, $now);

        return [
            $admin,
            $account,
            $profile,
            $dueRequest,
            $futureRequest,
            $dueConsent,
            $futureConsent,
            $expiredQr,
            $futureQr,
        ];
    }

    private function customerContext(SuchakAccount $account, MatrimonyProfile $profile, User $suchakUser, string $sourceOwner): SuchakCustomerContext
    {
        return SuchakCustomerContext::query()->create([
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $profile->id,
            'payer_name' => 'Day-59 customer family',
            'payer_relationship_to_candidate' => 'Parent',
            'service_context' => SuchakCustomerContext::SERVICE_PACKAGE_LEAD,
            'source_owner' => $sourceOwner,
            'source_type' => $sourceOwner === SuchakPaymentContext::SOURCE_PLATFORM
                ? SuchakCustomerContext::SOURCE_TYPE_PLATFORM_REQUEST
                : SuchakCustomerContext::SOURCE_TYPE_MANUAL,
            'customer_lifecycle_status' => SuchakCustomerContext::STATUS_ACTIVE_SERVICE,
            'created_by_user_id' => $suchakUser->id,
            'classified_by_user_id' => $suchakUser->id,
            'classified_at' => now(),
            'opened_at' => now(),
        ]);
    }

    private function paymentContext(
        SuchakAccount $account,
        MatrimonyProfile $profile,
        SuchakCustomerContext $customerContext,
        User $suchakUser,
        string $sourceOwner,
        string $collector,
    ): SuchakPaymentContext {
        return SuchakPaymentContext::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'matrimony_profile_id' => $profile->id,
            'source_owner' => $sourceOwner,
            'payment_collector' => $collector,
            'context_status' => SuchakPaymentContext::STATUS_ACTIVE,
            'resolved_by_user_id' => $suchakUser->id,
            'resolution_note' => 'Day-59 scheduled job fixture.',
        ]);
    }

    /**
     * @return array{0: SuchakServicePackage, 1: SuchakCustomerAgreement}
     */
    private function packageAndAgreement(SuchakAccount $account, SuchakCustomerContext $customerContext, User $suchakUser): array
    {
        $package = SuchakServicePackage::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'package_name' => 'Day-59 Scheduled Package',
            'package_description' => 'Structured package for scheduled payment request expiry.',
            'price_amount' => '15000.00',
            'currency' => 'INR',
            'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
            'requires_admin_approval' => false,
            'customized_by_user_id' => $suchakUser->id,
            'published_at' => now(),
        ]);
        $agreement = SuchakCustomerAgreement::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'service_package_id' => $package->id,
            'agreement_revision' => 1,
            'terms_status' => SuchakCustomerAgreement::TERMS_ACCEPTED,
            'terms_policy_mode' => SuchakCustomerAgreement::POLICY_STRICT,
            'agreement_snapshot_hash' => hash('sha256', 'day59-agreement-'.$package->id),
            'package_name' => $package->package_name,
            'package_description' => $package->package_description,
            'price_amount' => $package->price_amount,
            'currency' => $package->currency,
            'agreement_title' => 'Day-59 agreement terms',
            'agreement_body' => 'Customer accepts the scheduled payment request fixture terms.',
            'created_by_user_id' => $suchakUser->id,
            'accepted_by_user_id' => $suchakUser->id,
            'accepted_at' => now(),
        ]);

        return [$package, $agreement];
    }

    private function paymentRequest(
        SuchakAccount $account,
        SuchakCustomerContext $customerContext,
        SuchakServicePackage $package,
        SuchakCustomerAgreement $agreement,
        SuchakPaymentContext $paymentContext,
        User $suchakUser,
        string $tokenKey,
        Carbon $expiresAt,
    ): SuchakPaymentRequest {
        return SuchakPaymentRequest::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'service_package_id' => $package->id,
            'customer_agreement_id' => $agreement->id,
            'payment_context_id' => $paymentContext->id,
            'requested_by_user_id' => $suchakUser->id,
            'request_token_hash' => hash('sha256', $tokenKey),
            'payment_status' => SuchakPaymentRequest::STATUS_SENT,
            'payment_detail_visibility_policy' => SuchakPaymentRequest::VISIBILITY_TERMS_SATISFIED_ONLY,
            'request_title' => 'Day-59 scheduled request',
            'request_note' => 'Due/not-due scheduled job fixture.',
            'amount_due' => '15000.00',
            'currency' => 'INR',
            'collector_disclosure' => 'Payment collector: Suchak. Use structured payment request only.',
            'sent_at' => now()->subDays(2),
            'expires_at' => $expiresAt,
        ]);
    }

    private function growthRewardCandidate(
        SuchakAccount $account,
        MatrimonyProfile $profile,
        SuchakCustomerContext $customerContext,
        SuchakPaymentContext $paymentContext,
        User $admin,
        Carbon $now,
    ): void {
        SuchakGrowthAttribution::query()->create([
            'suchak_account_id' => $account->id,
            'attributed_user_id' => User::factory()->create()->id,
            'matrimony_profile_id' => $profile->id,
            'customer_context_id' => $customerContext->id,
            'payment_context_id' => $paymentContext->id,
            'attribution_source' => SuchakGrowthAttribution::SOURCE_COUPON_CODE,
            'attribution_policy' => SuchakGrowthAttribution::POLICY_COUPON_PRIORITY,
            'attribution_key' => 'day59-scheduled-growth',
            'coupon_code' => 'DAY59',
            'attribution_status' => SuchakGrowthAttribution::STATUS_ACTIVE,
            'fraud_status' => SuchakGrowthAttribution::FRAUD_CLEAR,
            'attribution_note' => 'Scheduled growth attribution candidate.',
            'attributed_by_admin_user_id' => $admin->id,
            'attributed_at' => $now,
        ]);
        SuchakGrowthRewardRule::query()->create([
            'rule_key' => 'day59-scheduled-credit',
            'reward_trigger' => SuchakGrowthRewardRule::TRIGGER_PLATFORM_PAYMENT_CONFIRMED,
            'reward_type' => SuchakGrowthRewardRule::TYPE_CREDIT,
            'attribution_policy' => SuchakGrowthAttribution::POLICY_COUPON_PRIORITY,
            'reward_amount' => '0.00',
            'reward_currency' => 'INR',
            'credit_value' => '250.00',
            'is_active' => true,
            'starts_at' => $now->copy()->subDay(),
            'ends_at' => $now->copy()->addDay(),
            'created_by_admin_user_id' => $admin->id,
        ]);
    }

    private function paidPayoutCandidate(
        SuchakAccount $account,
        MatrimonyProfile $profile,
        SuchakCustomerContext $customerContext,
        SuchakPaymentContext $paymentContext,
        User $admin,
        Carbon $now,
    ): void {
        SuchakPlatformPayout::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'payment_context_id' => $paymentContext->id,
            'matrimony_profile_id' => $profile->id,
            'platform_event_type' => SuchakPlatformPayout::EVENT_PLATFORM_CUSTOMER_PAYMENT,
            'platform_event_key' => 'day59-platform-paid-payout',
            'payout_reason' => SuchakPlatformPayout::REASON_PLATFORM_CUSTOMER_PAYMENT_REWARD,
            'qualification_source' => SuchakPlatformPayout::SOURCE_PLATFORM_CONFIRMED_EVENT,
            'payout_status' => SuchakPlatformPayout::STATUS_PAID,
            'amount' => '1000.00',
            'deduction_amount' => '0.00',
            'reversal_amount' => '0.00',
            'net_amount' => '1000.00',
            'currency' => 'INR',
            'liability_recognized_at' => $now->copy()->subDay(),
            'qualified_by_user_id' => $admin->id,
            'qualification_note' => 'Scheduled settlement candidate.',
            'approved_by_user_id' => $admin->id,
            'approved_at' => $now->copy()->subDay(),
            'paid_by_user_id' => $admin->id,
            'paid_at' => '2026-06-10 12:00:00',
            'payout_reference_number' => 'DAY59-PAYOUT-001',
            'payout_reference_note' => 'Paid before scheduled settlement generation.',
        ]);
    }
}
