<?php

namespace Tests\Feature\Suchak;

use App\Models\AdminAuditLog;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCampaignQualification;
use App\Models\SuchakCampaignRule;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPayment;
use App\Models\SuchakLoyaltyTierSnapshot;
use App\Models\SuchakMonthlyValueReport;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPaymentRequest;
use App\Models\SuchakPlatformLead;
use App\Models\SuchakPlatformLeadAllocation;
use App\Models\SuchakPlatformPayout;
use App\Models\SuchakPolicy;
use App\Models\SuchakRetentionOffer;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakPolicyService;
use App\Modules\Suchak\Services\SuchakRetentionCampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SuchakRetentionCampaignEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_day_55_retention_tables_and_admin_center_are_available_without_public_leak(): void
    {
        foreach ([
            'suchak_campaign_rules',
            'suchak_campaign_qualifications',
            'suchak_loyalty_tier_snapshots',
            'suchak_monthly_value_reports',
            'suchak_retention_offers',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        foreach ([
            'campaign_key',
            'qualification_metric',
            'threshold_value',
            'bonus_type',
            'admin_audit_log_id',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_campaign_rules', $column), $column);
        }

        foreach ([
            'platform_customer_value_amount',
            'suchak_customer_value_amount',
            'platform_payout_amount',
            'campaign_bonus_amount',
            'unsupported_claims_count',
            'unsupported_claims_note',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_monthly_value_reports', $column), $column);
        }

        $this->assertDatabaseHas('suchak_policies', [
            'policy_key' => SuchakPolicyService::KEY_SUCHAK_LOYALTY_TIER_POLICY_JSON,
            'value_type' => SuchakPolicy::TYPE_JSON,
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        $nonAdmin = User::factory()->create(['is_admin' => false]);
        [$suchakUser] = $this->retentionFixture($admin);

        $this->actingAs($admin)
            ->get(route('admin.suchak.retention.index'))
            ->assertOk()
            ->assertSee('Suchak Retention Center', false)
            ->assertSee('Policy-Driven Loyalty Tiers', false)
            ->assertSee('Create Campaign Rule', false)
            ->assertSee('Generate Monthly Value Report / Offer', false);

        $this->actingAs($nonAdmin)
            ->get(route('admin.suchak.retention.index'))
            ->assertForbidden();

        $this->actingAs($suchakUser)
            ->get(route('suchak.dashboard'))
            ->assertOk()
            ->assertDontSee('Suchak Retention Center', false)
            ->assertDontSee('Monthly Value Report', false);
    }

    public function test_day_55_campaign_qualification_loyalty_report_and_offer_tracking_are_auditable(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00'));

        try {
            $admin = User::factory()->create(['is_admin' => true]);
            [, $account] = $this->retentionFixture($admin);
            $service = app(SuchakRetentionCampaignService::class);

            SuchakPolicy::query()->updateOrCreate(
                ['policy_key' => SuchakPolicyService::KEY_SUCHAK_LOYALTY_TIER_POLICY_JSON],
                [
                    'policy_value' => json_encode([
                        ['tier_key' => 'base', 'tier_label' => 'Base', 'minimum_score' => 0],
                        ['tier_key' => 'silver', 'tier_label' => 'Silver Partner', 'minimum_score' => 10],
                    ], JSON_UNESCAPED_SLASHES),
                    'value_type' => SuchakPolicy::TYPE_JSON,
                    'description' => 'Day-55 test policy.',
                    'is_active' => true,
                ],
            );

            $rule = $service->createCampaignRule($admin, [
                'campaign_key' => 'day55-platform-value',
                'campaign_name' => 'Day55 Platform Value Campaign',
                'campaign_goal' => SuchakCampaignRule::GOAL_PLATFORM_VALUE,
                'qualification_metric' => SuchakCampaignRule::METRIC_PLATFORM_VALUE,
                'threshold_value' => '2000',
                'bonus_type' => SuchakCampaignRule::BONUS_CREDIT,
                'bonus_amount' => '300',
                'bonus_currency' => 'INR',
            ]);

            $this->assertNotNull($rule->admin_audit_log_id);
            $this->assertDatabaseHas('admin_audit_logs', [
                'action_type' => 'suchak_campaign_rule_created',
                'entity_type' => 'SuchakCampaignRule',
                'entity_id' => $rule->id,
            ]);

            $qualification = $service->qualifyCampaignBonus($rule, $account, $admin, [
                'qualification_month' => '2026-06',
                'metric_value' => '2500',
                'qualification_note' => 'Campaign bonus qualified from platform-recorded customer value.',
            ]);

            $this->assertSame(SuchakCampaignQualification::STATUS_QUALIFIED, $qualification->qualification_status);
            $this->assertSame('300.00', $qualification->bonus_amount);
            $this->assertNotNull($qualification->admin_audit_log_id);
            $this->assertDatabaseHas('admin_audit_logs', [
                'action_type' => 'suchak_campaign_bonus_qualified',
                'entity_type' => 'SuchakCampaignQualification',
                'entity_id' => $qualification->id,
            ]);

            $report = $service->generateMonthlyValueReport($account, $admin, '2026-06');

            $this->assertSame('2026-06', $report->report_month);
            $this->assertSame(1, $report->platform_leads_count);
            $this->assertSame('2500.00', $report->platform_customer_value_amount);
            $this->assertSame('7000.00', $report->suchak_customer_value_amount);
            $this->assertSame('2500.00', $report->platform_payout_amount);
            $this->assertSame('300.00', $report->campaign_bonus_amount);
            $this->assertSame(0, $report->unsupported_claims_count);
            $this->assertStringContainsString('not asserted', $report->unsupported_claims_note);
            $this->assertNotNull($report->admin_audit_log_id);
            $this->assertDatabaseHas('admin_audit_logs', [
                'action_type' => 'suchak_monthly_value_report_generated',
                'entity_type' => 'SuchakMonthlyValueReport',
                'entity_id' => $report->id,
            ]);

            $snapshot = SuchakLoyaltyTierSnapshot::query()->firstOrFail();
            $this->assertSame('silver', $snapshot->tier_key);
            $this->assertSame('Silver Partner', $snapshot->tier_label);
            $this->assertSame(SuchakPolicyService::KEY_SUCHAK_LOYALTY_TIER_POLICY_JSON, $snapshot->policy_key);

            $offer = $service->createRetentionOffer($account, $admin, [
                'offer_type' => SuchakRetentionOffer::TYPE_RENEWAL_DISCOUNT,
                'discount_percent' => '12.5',
                'currency' => 'INR',
                'offer_note' => 'Renewal discount offered based on platform-recorded monthly value.',
            ], $report);

            $this->assertSame(SuchakRetentionOffer::TYPE_RENEWAL_DISCOUNT, $offer->offer_type);
            $this->assertSame('12.50', $offer->discount_percent);
            $this->assertSame($report->id, $offer->monthly_value_report_id);
            $this->assertDatabaseHas('admin_audit_logs', [
                'action_type' => 'suchak_retention_offer_created',
                'entity_type' => 'SuchakRetentionOffer',
                'entity_id' => $offer->id,
            ]);

            try {
                $service->generateMonthlyValueReport($account, $admin, '2026-06');
                $this->fail('Duplicate monthly value reports should be blocked.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Suchak monthly value report already exists for this account and month.', $exception->getMessage());
            }

            $this->expectException(RuntimeException::class);
            $report->delete();
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @return array{0: User, 1: SuchakAccount}
     */
    private function retentionFixture(User $admin): array
    {
        $suchakUser = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'suchak_name' => 'Day55 Retention Suchak',
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Day 55 Retention Candidate',
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);

        $platformCustomer = $this->customerContext($account, $suchakUser, $profile, SuchakCustomerContext::SOURCE_OWNER_PLATFORM, SuchakCustomerContext::SOURCE_TYPE_PLATFORM_REQUEST);
        $platformContext = $this->paymentContext($account, $suchakUser, $profile, $platformCustomer, SuchakPaymentContext::SOURCE_PLATFORM, SuchakPaymentContext::COLLECTOR_PLATFORM);
        $suchakCustomer = $this->customerContext($account, $suchakUser, $profile, SuchakCustomerContext::SOURCE_OWNER_SUCHAK, SuchakCustomerContext::SOURCE_TYPE_MANUAL);
        $suchakContext = $this->paymentContext($account, $suchakUser, $profile, $suchakCustomer, SuchakPaymentContext::SOURCE_SUCHAK, SuchakPaymentContext::COLLECTOR_SUCHAK);

        SuchakPlatformPayout::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $platformCustomer->id,
            'payment_context_id' => $platformContext->id,
            'matrimony_profile_id' => $profile->id,
            'platform_event_type' => SuchakPlatformPayout::EVENT_PLATFORM_CUSTOMER_PAYMENT,
            'platform_event_key' => 'day55-platform-payment',
            'payout_reason' => SuchakPlatformPayout::REASON_PLATFORM_CUSTOMER_PAYMENT_REWARD,
            'qualification_source' => SuchakPlatformPayout::SOURCE_PLATFORM_CONFIRMED_EVENT,
            'payout_status' => SuchakPlatformPayout::STATUS_QUALIFIED,
            'amount' => '2500',
            'currency' => 'INR',
            'liability_recognized_at' => now(),
            'qualified_by_user_id' => $admin->id,
            'qualification_note' => 'Platform-recorded customer payment value for Day-55 report.',
        ]);

        $servicePackage = SuchakServicePackage::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $suchakCustomer->id,
            'package_name' => 'Day55 Retention Package',
            'package_description' => 'Structured package for Day-55 retention fixture.',
            'price_amount' => '7000',
            'currency' => 'INR',
            'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
            'requires_admin_approval' => false,
            'customized_by_user_id' => $suchakUser->id,
            'published_at' => now(),
        ]);
        $agreement = SuchakCustomerAgreement::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $suchakCustomer->id,
            'service_package_id' => $servicePackage->id,
            'agreement_revision' => 1,
            'terms_status' => SuchakCustomerAgreement::TERMS_ACCEPTED,
            'terms_policy_mode' => SuchakCustomerAgreement::POLICY_STRICT,
            'agreement_snapshot_hash' => hash('sha256', 'day55-retention-agreement'),
            'package_name' => $servicePackage->package_name,
            'package_description' => $servicePackage->package_description,
            'price_amount' => $servicePackage->price_amount,
            'currency' => 'INR',
            'agreement_title' => 'Day55 retention agreement',
            'agreement_body' => 'Agreement retained for structured payment fixture.',
            'created_by_user_id' => $suchakUser->id,
            'accepted_by_user_id' => $suchakUser->id,
            'accepted_at' => now(),
        ]);
        $paymentRequest = SuchakPaymentRequest::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $suchakCustomer->id,
            'service_package_id' => $servicePackage->id,
            'customer_agreement_id' => $agreement->id,
            'payment_context_id' => $suchakContext->id,
            'requested_by_user_id' => $suchakUser->id,
            'request_token_hash' => hash('sha256', 'day55-retention-payment-request'),
            'payment_status' => SuchakPaymentRequest::STATUS_PAID,
            'payment_detail_visibility_policy' => SuchakPaymentRequest::VISIBILITY_TERMS_SATISFIED_ONLY,
            'request_title' => 'Day55 retention payment request',
            'request_note' => 'Payment request fixture for Day-55 report.',
            'amount_due' => '7000',
            'currency' => 'INR',
            'collector_disclosure' => 'Payment collector: Suchak.',
            'sent_at' => now(),
            'opened_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        SuchakCustomerPayment::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $suchakCustomer->id,
            'service_package_id' => $servicePackage->id,
            'customer_agreement_id' => $agreement->id,
            'payment_context_id' => $suchakContext->id,
            'payment_request_id' => $paymentRequest->id,
            'recorded_by_user_id' => $suchakUser->id,
            'collection_channel' => SuchakCustomerPayment::CHANNEL_SUCHAK_DIRECT,
            'payment_mode' => SuchakCustomerPayment::MODE_UPI,
            'payment_status' => SuchakCustomerPayment::STATUS_PAID,
            'amount_due' => '7000',
            'amount_received' => '7000',
            'balance_amount' => '0',
            'currency' => 'INR',
            'payment_received_at' => now(),
            'proof_status' => SuchakCustomerPayment::PROOF_VERIFIED,
            'collection_note' => 'Suchak-collected customer value must stay separate from platform value.',
        ]);

        $lead = SuchakPlatformLead::query()->create([
            'lead_type' => SuchakPlatformLead::TYPE_PACKAGE_LEAD,
            'lead_source' => SuchakPlatformLead::SOURCE_PLATFORM,
            'lead_status' => SuchakPlatformLead::STATUS_ACCEPTED,
            'allocation_policy' => SuchakPlatformLead::POLICY_ADMIN_OVERRIDE,
            'allocation_sla_hours' => 48,
            'target_matrimony_profile_id' => $profile->id,
            'service_context' => SuchakCustomerContext::SERVICE_PACKAGE_LEAD,
            'lead_title' => 'Day55 platform retention lead',
            'lead_note' => 'Platform lead fixture without private contact details.',
            'created_by_admin_user_id' => $admin->id,
            'opened_at' => now(),
            'allocated_at' => now(),
        ]);
        SuchakPlatformLeadAllocation::query()->create([
            'platform_lead_id' => $lead->id,
            'suchak_account_id' => $account->id,
            'customer_context_id' => $platformCustomer->id,
            'payment_context_id' => $platformContext->id,
            'allocation_status' => SuchakPlatformLeadAllocation::STATUS_ACCEPTED,
            'allocation_policy' => SuchakPlatformLead::POLICY_ADMIN_OVERRIDE,
            'rotation_bucket_key' => 'day55-retention',
            'rotation_sequence' => 1,
            'matched_area_level' => SuchakPlatformLeadAllocation::MATCH_NONE,
            'matched_community_level' => SuchakPlatformLeadAllocation::MATCH_NONE,
            'allocated_by_admin_user_id' => $admin->id,
            'allocated_at' => now(),
            'sla_expires_at' => now()->addHours(48),
            'accepted_by_user_id' => $suchakUser->id,
            'accepted_at' => now(),
            'acceptance_note' => 'Accepted platform lead for retention report.',
        ]);

        return [$suchakUser, $account];
    }

    private function customerContext(
        SuchakAccount $account,
        User $suchakUser,
        MatrimonyProfile $profile,
        string $sourceOwner,
        string $sourceType
    ): SuchakCustomerContext {
        return SuchakCustomerContext::query()->create([
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $profile->id,
            'payer_name' => 'Day55 customer family',
            'payer_relationship_to_candidate' => 'Parent',
            'service_context' => SuchakCustomerContext::SERVICE_PACKAGE_LEAD,
            'source_owner' => $sourceOwner,
            'source_type' => $sourceType,
            'customer_lifecycle_status' => SuchakCustomerContext::STATUS_ACTIVE_SERVICE,
            'created_by_user_id' => $suchakUser->id,
            'classified_by_user_id' => $suchakUser->id,
            'classified_at' => now(),
            'opened_at' => now(),
        ]);
    }

    private function paymentContext(
        SuchakAccount $account,
        User $suchakUser,
        MatrimonyProfile $profile,
        SuchakCustomerContext $customerContext,
        string $sourceOwner,
        string $collector
    ): SuchakPaymentContext {
        return SuchakPaymentContext::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'matrimony_profile_id' => $profile->id,
            'source_owner' => $sourceOwner,
            'payment_collector' => $collector,
            'context_status' => SuchakPaymentContext::STATUS_ACTIVE,
            'resolved_by_user_id' => $suchakUser->id,
            'resolution_note' => 'Day-55 retention report fixture.',
        ]);
    }
}
