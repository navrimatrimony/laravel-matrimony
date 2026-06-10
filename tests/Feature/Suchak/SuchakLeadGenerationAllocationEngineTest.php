<?php

namespace Tests\Feature\Suchak;

use App\Models\AdminAuditLog;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakLeadAllocationEvent;
use App\Models\SuchakLeadAllocationPreference;
use App\Models\SuchakLeadRotationCursor;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPlan;
use App\Models\SuchakPlanFeature;
use App\Models\SuchakPlatformLead;
use App\Models\SuchakPlatformLeadAllocation;
use App\Models\SuchakProfileRequest;
use App\Models\SuchakSubscription;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakLeadAllocationService;
use App\Modules\Suchak\Services\SuchakPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SuchakLeadGenerationAllocationEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_day_46_lead_allocation_tables_are_structured_policy_driven_and_contact_safe(): void
    {
        $this->assertTrue(Schema::hasTable('suchak_platform_leads'));
        $this->assertTrue(Schema::hasTable('suchak_lead_allocation_preferences'));
        $this->assertTrue(Schema::hasTable('suchak_platform_lead_allocations'));
        $this->assertTrue(Schema::hasTable('suchak_lead_rotation_cursors'));
        $this->assertTrue(Schema::hasTable('suchak_lead_allocation_events'));

        foreach ([
            'lead_type',
            'lead_source',
            'lead_status',
            'allocation_policy',
            'allocation_sla_hours',
            'target_matrimony_profile_id',
            'district_id',
            'taluka_id',
            'city_id',
            'religion_id',
            'caste_id',
            'sub_caste_id',
            'lead_title',
            'created_by_admin_user_id',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_platform_leads', $column), $column);
        }

        foreach ([
            'platform_lead_id',
            'suchak_account_id',
            'customer_context_id',
            'payment_context_id',
            'allocation_status',
            'allocation_policy',
            'rotation_bucket_key',
            'rotation_sequence',
            'matched_area_level',
            'matched_community_level',
            'plan_limit_snapshot',
            'sla_expires_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_platform_lead_allocations', $column), $column);
        }

        $this->assertFalse(Schema::hasColumn('suchak_platform_leads', 'mobile_number'));
        $this->assertFalse(Schema::hasColumn('suchak_platform_leads', 'email'));
        $this->assertFalse(Schema::hasColumn('suchak_platform_lead_allocations', 'contact_number'));
        $this->assertFalse(Schema::hasColumn('suchak_lead_allocation_events', 'updated_at'));
        $this->assertDatabaseHas('suchak_policies', [
            'policy_key' => SuchakPolicyService::KEY_SUCHAK_LEAD_ALLOCATION_POLICY_MODE,
            'policy_value' => SuchakPolicyService::DEFAULT_SUCHAK_LEAD_ALLOCATION_POLICY_MODE,
        ]);
        $this->assertDatabaseHas('suchak_policies', [
            'policy_key' => SuchakPolicyService::KEY_SUCHAK_LEAD_ALLOCATION_SLA_HOURS,
            'policy_value' => (string) SuchakPolicyService::DEFAULT_SUCHAK_LEAD_ALLOCATION_SLA_HOURS,
        ]);
        $this->assertContains(SuchakPlatformLead::POLICY_AREA_COMMUNITY_ROTATION, SuchakPlatformLead::POLICIES);
        $this->assertContains(SuchakPlatformLeadAllocation::STATUS_ALLOCATED, SuchakPlatformLeadAllocation::OPEN_STATUSES);
    }

    public function test_admin_allocates_platform_lead_by_area_community_policy_with_platform_payment_collector(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [, $account] = $this->verifiedSuchakAccount();
        $profile = $this->profile('Day 46 Platform Lead Candidate');
        $service = app(SuchakLeadAllocationService::class);
        $service->recordAllocationPreference($account, $admin, [
            'district_id' => 501,
            'religion_id' => 11,
            'caste_id' => 22,
            'priority_weight' => 5,
            'preference_note' => 'District and community allocation preference.',
        ]);
        $lead = $service->createPlatformLead($admin, $this->leadPayload($profile, [
            'district_id' => 501,
            'religion_id' => 11,
            'caste_id' => 22,
        ]));

        $allocation = $service->allocateLead($lead, $admin, [
            'allocation_note' => 'Allocate by configured area community policy.',
        ]);

        $this->assertSame($account->id, $allocation->suchak_account_id);
        $this->assertSame(SuchakPlatformLeadAllocation::STATUS_ALLOCATED, $allocation->allocation_status);
        $this->assertSame(SuchakPlatformLead::POLICY_AREA_COMMUNITY_ROTATION, $allocation->allocation_policy);
        $this->assertSame(SuchakPlatformLeadAllocation::MATCH_DISTRICT, $allocation->matched_area_level);
        $this->assertSame(SuchakPlatformLeadAllocation::MATCH_CASTE, $allocation->matched_community_level);
        $this->assertSame(SuchakPlatformLead::STATUS_ALLOCATED, $allocation->platformLead->lead_status);

        $customerContext = $allocation->customerContext;
        $this->assertSame(SuchakCustomerContext::SOURCE_OWNER_PLATFORM, $customerContext->source_owner);
        $this->assertSame(SuchakCustomerContext::SOURCE_TYPE_PLATFORM_REQUEST, $customerContext->source_type);
        $this->assertSame(SuchakCustomerContext::STATUS_LEAD, $customerContext->customer_lifecycle_status);
        $this->assertSame($profile->id, $customerContext->candidate_matrimony_profile_id);

        $paymentContext = $allocation->paymentContext;
        $this->assertSame(SuchakPaymentContext::SOURCE_PLATFORM, $paymentContext->source_owner);
        $this->assertSame(SuchakPaymentContext::COLLECTOR_PLATFORM, $paymentContext->payment_collector);
        $this->assertSame(SuchakPaymentContext::STATUS_ACTIVE, $paymentContext->context_status);
        $this->assertSame($customerContext->id, $paymentContext->customer_context_id);

        $audit = AdminAuditLog::query()
            ->where('action_type', 'suchak_platform_lead_allocated')
            ->firstOrFail();
        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $admin->id,
            'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
            'action_type' => SuchakActivityLog::ACTION_PLATFORM_LEAD_ALLOCATED,
            'target_type' => 'suchak_platform_lead_allocation',
            'target_id' => $allocation->id,
            'admin_audit_log_id' => $audit->id,
        ]);
        $this->assertDatabaseHas('suchak_lead_allocation_events', [
            'platform_lead_id' => $lead->id,
            'lead_allocation_id' => $allocation->id,
            'event_type' => SuchakLeadAllocationEvent::EVENT_ALLOCATED,
            'to_status' => SuchakPlatformLead::STATUS_ALLOCATED,
        ]);
    }

    public function test_rotation_policy_moves_next_matching_suchak_in_same_bucket(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [, $firstAccount] = $this->verifiedSuchakAccount();
        [, $secondAccount] = $this->verifiedSuchakAccount();
        $service = app(SuchakLeadAllocationService::class);

        foreach ([$firstAccount, $secondAccount] as $account) {
            $service->recordAllocationPreference($account, $admin, [
                'district_id' => 700,
                'religion_id' => 33,
                'caste_id' => 44,
                'priority_weight' => 10,
                'preference_note' => 'Same bucket preference for rotation.',
            ]);
        }

        $firstLead = $service->createPlatformLead($admin, $this->leadPayload($this->profile('Rotation Candidate One'), [
            'district_id' => 700,
            'religion_id' => 33,
            'caste_id' => 44,
        ]));
        $secondLead = $service->createPlatformLead($admin, $this->leadPayload($this->profile('Rotation Candidate Two'), [
            'district_id' => 700,
            'religion_id' => 33,
            'caste_id' => 44,
        ]));

        $firstAllocation = $service->allocateLead($firstLead, $admin);
        $secondAllocation = $service->allocateLead($secondLead, $admin);

        $this->assertSame($firstAccount->id, $firstAllocation->suchak_account_id);
        $this->assertSame($secondAccount->id, $secondAllocation->suchak_account_id);
        $this->assertSame($firstAllocation->rotation_bucket_key, $secondAllocation->rotation_bucket_key);
        $this->assertSame(1, $firstAllocation->rotation_sequence);
        $this->assertSame(2, $secondAllocation->rotation_sequence);
        $cursor = SuchakLeadRotationCursor::query()
            ->where('rotation_bucket_key', $firstAllocation->rotation_bucket_key)
            ->firstOrFail();
        $this->assertSame($secondAccount->id, $cursor->last_allocated_suchak_account_id);
        $this->assertSame(2, $cursor->last_rotation_sequence);
    }

    public function test_plan_wise_open_lead_limit_blocks_platform_allocation_before_mutation(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [, $account] = $this->verifiedSuchakAccount();
        $this->assignLeadLimit($account, $admin, 1);
        $service = app(SuchakLeadAllocationService::class);

        $firstLead = $service->createPlatformLead($admin, $this->leadPayload($this->profile('Limited Lead One'), [
            'allocation_policy' => SuchakPlatformLead::POLICY_ADMIN_OVERRIDE,
        ]));
        $service->allocateLead($firstLead, $admin, [
            'allocation_policy' => SuchakPlatformLead::POLICY_ADMIN_OVERRIDE,
            'suchak_account_id' => $account->id,
        ]);

        $secondLead = $service->createPlatformLead($admin, $this->leadPayload($this->profile('Limited Lead Two'), [
            'allocation_policy' => SuchakPlatformLead::POLICY_ADMIN_OVERRIDE,
        ]));

        try {
            $service->allocateLead($secondLead, $admin, [
                'allocation_policy' => SuchakPlatformLead::POLICY_ADMIN_OVERRIDE,
                'suchak_account_id' => $account->id,
            ]);
            $this->fail('Open platform lead allocation should count against plan-wise lead limit.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Open Suchak lead request limit reached for this account.', $exception->getMessage());
        }

        $this->assertSame(1, SuchakPlatformLeadAllocation::query()->where('suchak_account_id', $account->id)->count());
        $this->assertSame(1, SuchakCustomerContext::query()->where('suchak_account_id', $account->id)->count());
        $this->assertSame(1, SuchakPaymentContext::query()->where('suchak_account_id', $account->id)->count());
        $this->assertSame(0, SuchakProfileRequest::query()->where('selected_suchak_account_id', $account->id)->count());
    }

    public function test_lead_text_rejects_private_contact_before_assignment(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $service = app(SuchakLeadAllocationService::class);

        try {
            $service->createPlatformLead($admin, $this->leadPayload($this->profile('Contact Leak Candidate'), [
                'lead_note' => 'Call family on 9876543210 before assigning lead.',
            ]));
            $this->fail('Lead notes should not store direct contact details.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Suchak platform lead records must not store private contact details.', $exception->getMessage());
        }

        $this->assertSame(0, SuchakPlatformLead::query()->count());
    }

    public function test_suchak_acceptance_and_sla_expiry_update_status_without_contact_leak(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10 09:00:00'));
        $admin = User::factory()->create(['is_admin' => true]);
        [$suchakUser, $account] = $this->verifiedSuchakAccount();
        $service = app(SuchakLeadAllocationService::class);
        $acceptedLead = $service->createPlatformLead($admin, $this->leadPayload($this->profile('Accepted Lead'), [
            'allocation_policy' => SuchakPlatformLead::POLICY_ADMIN_OVERRIDE,
            'allocation_sla_hours' => 1,
        ]));
        $acceptedAllocation = $service->allocateLead($acceptedLead, $admin, [
            'allocation_policy' => SuchakPlatformLead::POLICY_ADMIN_OVERRIDE,
            'suchak_account_id' => $account->id,
        ]);

        $accepted = $service->acceptAllocation($acceptedAllocation, $suchakUser, [
            'acceptance_note' => 'Suchak accepted platform lead for service follow-up.',
        ]);

        $this->assertSame(SuchakPlatformLeadAllocation::STATUS_ACCEPTED, $accepted->allocation_status);
        $this->assertSame(SuchakPlatformLead::STATUS_ACCEPTED, $accepted->platformLead->lead_status);
        $this->assertSame(SuchakCustomerContext::STATUS_ACTIVE_SERVICE, $accepted->customerContext->customer_lifecycle_status);

        [, $secondAccount] = $this->verifiedSuchakAccount();
        $expiringLead = $service->createPlatformLead($admin, $this->leadPayload($this->profile('Expiring Lead'), [
            'allocation_policy' => SuchakPlatformLead::POLICY_ADMIN_OVERRIDE,
            'allocation_sla_hours' => 1,
        ]));
        $expiringAllocation = $service->allocateLead($expiringLead, $admin, [
            'allocation_policy' => SuchakPlatformLead::POLICY_ADMIN_OVERRIDE,
            'suchak_account_id' => $secondAccount->id,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-10 11:01:00'));
        $expired = $service->expireAllocationIfPastSla($expiringAllocation);

        $this->assertSame(SuchakPlatformLeadAllocation::STATUS_EXPIRED, $expired->allocation_status);
        $this->assertSame(SuchakPlatformLead::STATUS_EXPIRED, $expired->platformLead->lead_status);
        $this->assertSame(SuchakPaymentContext::STATUS_CANCELLED, $expired->paymentContext->context_status);

        $event = SuchakLeadAllocationEvent::query()
            ->where('lead_allocation_id', $expired->id)
            ->where('event_type', SuchakLeadAllocationEvent::EVENT_EXPIRED)
            ->firstOrFail();

        try {
            $event->update(['event_note' => 'changed']);
            $this->fail('Lead allocation events should be immutable.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak lead allocation events are immutable and cannot be modified.', $exception->getMessage());
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @return array{0: User, 1: SuchakAccount}
     */
    private function verifiedSuchakAccount(): array
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        return [$user, $account];
    }

    private function profile(string $name): MatrimonyProfile
    {
        return MatrimonyProfile::factory()->create([
            'user_id' => User::factory()->create()->id,
            'full_name' => $name,
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function leadPayload(MatrimonyProfile $profile, array $overrides = []): array
    {
        return array_merge([
            'lead_type' => SuchakPlatformLead::TYPE_PACKAGE_LEAD,
            'lead_source' => SuchakPlatformLead::SOURCE_PLATFORM,
            'target_matrimony_profile_id' => $profile->id,
            'service_context' => SuchakCustomerContext::SERVICE_PACKAGE_LEAD,
            'lead_title' => 'Platform package lead for '.$profile->full_name,
            'lead_note' => 'Platform sourced lead without private contact details.',
        ], $overrides);
    }

    private function assignLeadLimit(SuchakAccount $account, User $admin, int $limit): void
    {
        $plan = SuchakPlan::query()->create([
            'name' => 'Day 46 Lead Limit',
            'slug' => 'day-46-lead-limit-'.Str::random(8),
            'description' => 'Day 46 lead allocation limit plan.',
            'price_amount' => null,
            'currency' => null,
            'is_active' => true,
            'is_visible' => false,
            'sort_order' => 1,
        ]);
        SuchakPlanFeature::query()->create([
            'suchak_plan_id' => $plan->id,
            'feature_key' => SuchakPlanFeature::FEATURE_LEAD_REQUEST_LIMIT,
            'value_type' => SuchakPlanFeature::TYPE_INTEGER,
            'feature_value' => (string) $limit,
            'is_enabled' => true,
        ]);
        SuchakSubscription::query()->create([
            'suchak_account_id' => $account->id,
            'suchak_plan_id' => $plan->id,
            'assigned_by_user_id' => $admin->id,
            'status' => SuchakSubscription::STATUS_ACTIVE,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'assigned_at' => now(),
            'notes' => 'Day 46 lead limit fixture.',
        ]);
    }
}
