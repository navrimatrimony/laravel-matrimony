<?php

namespace Tests\Feature\Suchak;

use App\Models\AdminAuditLog;
use App\Models\Plan;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakPlan;
use App\Models\SuchakPlanFeature;
use App\Models\SuchakSubscription;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakBillingCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SuchakBillingCatalogFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_suchak_billing_catalog_tables_exist_with_day_16_columns(): void
    {
        $this->assertTrue(Schema::hasTable('suchak_plans'));
        $this->assertTrue(Schema::hasTable('suchak_plan_features'));
        $this->assertTrue(Schema::hasTable('suchak_subscriptions'));

        foreach ([
            'name',
            'slug',
            'description',
            'price_amount',
            'currency',
            'is_active',
            'is_visible',
            'sort_order',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_plans', $column), $column);
        }

        foreach ([
            'suchak_plan_id',
            'feature_key',
            'value_type',
            'feature_value',
            'is_enabled',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_plan_features', $column), $column);
        }

        foreach ([
            'suchak_account_id',
            'suchak_plan_id',
            'assigned_by_user_id',
            'status',
            'starts_at',
            'ends_at',
            'assigned_at',
            'cancelled_at',
            'expired_at',
            'notes',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_subscriptions', $column), $column);
        }

        foreach (['deleted_at', 'payment_id', 'payu_txnid', 'gateway_response_json'] as $forbiddenColumn) {
            $this->assertFalse(Schema::hasColumn('suchak_plans', $forbiddenColumn));
            $this->assertFalse(Schema::hasColumn('suchak_subscriptions', $forbiddenColumn));
        }
    }

    public function test_suchak_catalog_is_separate_from_normal_member_plans_and_uses_no_fake_price(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        $plan = $this->planWithFeature('Suchak Bureau', 'suchak-bureau', SuchakPlanFeature::FEATURE_ACTIVE_PROFILE_LIMIT, '100');

        $this->assertNull($plan->price_amount);
        $this->assertNull($plan->currency);
        $this->assertFalse($plan->hasConfiguredPrice());
        $this->assertSame(0, Plan::query()->where('name', 'Suchak Bureau')->count());

        $catalog = app(SuchakBillingCatalogService::class)->visibleCatalogForSuchak($account, $user);

        $this->assertCount(1, $catalog);
        $this->assertSame($plan->id, $catalog->first()->id);
        $this->assertCount(1, $catalog->first()->enabledFeatures);
        $this->assertSame(0, Plan::query()->count());
        $this->assertSame(0, Subscription::query()->count());
    }

    public function test_catalog_visibility_is_suchak_owner_or_admin_only(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        [, $otherAccount] = $this->verifiedSuchakActor();
        $admin = User::factory()->create(['is_admin' => true]);
        $nonAdmin = User::factory()->create(['is_admin' => false]);

        SuchakPlan::factory()->create([
            'name' => 'Hidden Admin Plan',
            'slug' => 'hidden-admin-plan',
            'is_active' => true,
            'is_visible' => false,
        ]);

        $service = app(SuchakBillingCatalogService::class);

        $this->assertCount(0, $service->visibleCatalogForSuchak($account, $user));
        $this->assertCount(1, $service->catalogForAdmin($admin));

        try {
            $service->visibleCatalogForSuchak($account, $otherAccount->user);
            $this->fail('Non-owner Suchak should not view another account billing catalog.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Only the owning Suchak account can view Suchak billing catalog.', $exception->getMessage());
        }

        try {
            $service->catalogForAdmin($nonAdmin);
            $this->fail('Non-admin user should not view admin Suchak catalog.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Only admins can manage Suchak billing catalog foundation.', $exception->getMessage());
        }
    }

    public function test_admin_can_assign_manual_suchak_subscription_with_audit_without_normal_payment_execution(): void
    {
        [, $account] = $this->verifiedSuchakActor();
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = $this->planWithFeature('Suchak Professional', 'suchak-professional', SuchakPlanFeature::FEATURE_MONTHLY_UPLOAD_LIMIT, '50');

        $subscription = app(SuchakBillingCatalogService::class)->assignManualSubscription(
            $account,
            $plan,
            $admin,
            'Manual Day-16 catalog assignment.',
            now(),
            now()->addMonth(),
            '127.0.0.1',
            'Day-16 test',
        );

        $this->assertSame($account->id, $subscription->suchak_account_id);
        $this->assertSame($plan->id, $subscription->suchak_plan_id);
        $this->assertSame($admin->id, $subscription->assigned_by_user_id);
        $this->assertSame(SuchakSubscription::STATUS_ACTIVE, $subscription->status);

        $audit = AdminAuditLog::query()
            ->where('action_type', 'suchak_billing_subscription_assigned')
            ->where('entity_type', 'SuchakSubscription')
            ->where('entity_id', $subscription->id)
            ->first();
        $this->assertNotNull($audit);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $admin->id,
            'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
            'action_type' => SuchakActivityLog::ACTION_BILLING_LIMIT_CHANGED,
            'target_type' => 'suchak_subscription',
            'target_id' => $subscription->id,
            'admin_audit_log_id' => $audit->id,
        ]);

        $activity = SuchakActivityLog::query()
            ->where('target_type', 'suchak_subscription')
            ->where('target_id', $subscription->id)
            ->firstOrFail();

        $this->assertFalse($activity->metadata_json['payment_execution']);
        $this->assertSame(0, Subscription::query()->count());
        $this->assertSame(0, DB::table('payments')->count());
    }

    public function test_reassignment_cancels_previous_active_subscription_and_resolves_plan_limits(): void
    {
        [, $account] = $this->verifiedSuchakActor();
        $admin = User::factory()->create(['is_admin' => true]);
        $starter = $this->planWithFeature('Suchak Starter', 'suchak-starter-day-16', SuchakPlanFeature::FEATURE_ACTIVE_PROFILE_LIMIT, '25');
        $bureau = $this->planWithFeature('Suchak Bureau', 'suchak-bureau-day-16', SuchakPlanFeature::FEATURE_ACTIVE_PROFILE_LIMIT, '100');

        SuchakPlanFeature::factory()->create([
            'suchak_plan_id' => $bureau->id,
            'feature_key' => SuchakPlanFeature::FEATURE_CRM_FEATURES,
            'value_type' => SuchakPlanFeature::TYPE_BOOLEAN,
            'feature_value' => 'true',
            'is_enabled' => true,
        ]);

        $service = app(SuchakBillingCatalogService::class);
        $first = $service->assignManualSubscription($account, $starter, $admin, 'Starter manual assignment.');
        $second = $service->assignManualSubscription($account, $bureau, $admin, 'Upgrade to Bureau manually.');

        $this->assertSame(SuchakSubscription::STATUS_CANCELLED, $first->fresh()->status);
        $this->assertNotNull($first->fresh()->cancelled_at);
        $this->assertSame(SuchakSubscription::STATUS_ACTIVE, $second->fresh()->status);

        $limits = $service->currentFeatureLimits($account);

        $this->assertSame(100, $limits[SuchakPlanFeature::FEATURE_ACTIVE_PROFILE_LIMIT]);
        $this->assertTrue($limits[SuchakPlanFeature::FEATURE_CRM_FEATURES]);
        $this->assertSame(100, $service->currentFeatureValue($account, SuchakPlanFeature::FEATURE_ACTIVE_PROFILE_LIMIT));
    }

    public function test_inactive_or_expired_suchak_plan_subscription_does_not_provide_current_limits(): void
    {
        [, $account] = $this->verifiedSuchakActor();
        $admin = User::factory()->create(['is_admin' => true]);
        $inactivePlan = SuchakPlan::factory()->create([
            'is_active' => false,
            'is_visible' => true,
        ]);
        $service = app(SuchakBillingCatalogService::class);

        try {
            $service->assignManualSubscription($account, $inactivePlan, $admin, 'Trying inactive plan.');
            $this->fail('Inactive Suchak plan should not be assignable.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Only active Suchak plans can be assigned.', $exception->getMessage());
        }

        $activePlan = $this->planWithFeature('Suchak Trial', 'suchak-trial-expired', SuchakPlanFeature::FEATURE_PDF_DOWNLOAD_SHARE_LIMIT, '5');
        SuchakSubscription::factory()->create([
            'suchak_account_id' => $account->id,
            'suchak_plan_id' => $activePlan->id,
            'status' => SuchakSubscription::STATUS_ACTIVE,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);

        $this->assertSame([], $service->currentFeatureLimits($account));
    }

    public function test_suchak_subscriptions_cannot_be_deleted(): void
    {
        $subscription = SuchakSubscription::factory()->create();

        try {
            $subscription->delete();
            $this->fail('Suchak subscription delete should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak subscriptions cannot be deleted.', $exception->getMessage());
        }
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
        ]);

        return [$user, $account];
    }

    private function planWithFeature(string $name, string $slug, string $featureKey, string $featureValue): SuchakPlan
    {
        $plan = SuchakPlan::factory()->create([
            'name' => $name,
            'slug' => $slug,
            'price_amount' => null,
            'currency' => null,
            'is_active' => true,
            'is_visible' => true,
        ]);

        SuchakPlanFeature::factory()->create([
            'suchak_plan_id' => $plan->id,
            'feature_key' => $featureKey,
            'value_type' => SuchakPlanFeature::TYPE_INTEGER,
            'feature_value' => $featureValue,
            'is_enabled' => true,
        ]);

        return $plan->fresh(['enabledFeatures']);
    }
}
