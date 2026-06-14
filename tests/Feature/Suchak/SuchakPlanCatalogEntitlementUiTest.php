<?php

namespace Tests\Feature\Suchak;

use App\Models\AdminAuditLog;
use App\Models\Plan;
use App\Models\PlanQuotaPolicy;
use App\Models\SuchakAccount;
use App\Models\SuchakBiodataExport;
use App\Models\SuchakBiodataIntakeLink;
use App\Models\SuchakPlan;
use App\Models\SuchakPlanFeature;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SuchakPlanCatalogEntitlementUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_update_suchak_plan_catalog_without_member_plan_rows(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.suchak.plans.index'))
            ->assertOk()
            ->assertSee('Suchak Plan Catalog', false)
            ->assertSee('Free trial', false)
            ->assertSee('Grace period', false)
            ->assertSee('Plan access and limits', false)
            ->assertSee('Active customer profiles', false)
            ->assertSee('Maximum customer profiles this Suchak can keep active at one time.', false)
            ->assertSee('Payment record book', false)
            ->assertSee('Available to Suchak?', false)
            ->assertSee('Yes - include', false)
            ->assertSee('No - do not include', false)
            ->assertDontSee('Feature entitlements', false)
            ->assertDontSee('>Integer<', false)
            ->assertDontSee('>Boolean<', false);

        $createResponse = $this->actingAs($admin)->post(route('admin.suchak.plans.store'), [
            'name' => 'Suchak Growth',
            'slug' => 'suchak-growth',
            'description' => 'Growth plan for active Suchak bureaus.',
            'price_amount' => '1499',
            'currency' => 'INR',
            'billing_period_days' => '45',
            'is_active' => '1',
            'is_visible' => '1',
            'sort_order' => '20',
            'reason' => 'Create Day 30 Suchak growth plan.',
            'features' => $this->featurePayload([
                SuchakPlanFeature::FEATURE_ACTIVE_PROFILE_LIMIT => ['feature_value' => '80', 'is_enabled' => '1'],
                SuchakPlanFeature::FEATURE_MONTHLY_UPLOAD_LIMIT => ['feature_value' => '150', 'is_enabled' => '1'],
                SuchakPlanFeature::FEATURE_CRM_FEATURES => ['feature_value' => '1', 'is_enabled' => '1'],
            ]),
        ]);

        $createResponse->assertRedirect(route('admin.suchak.plans.index'));

        $plan = SuchakPlan::query()->where('slug', 'suchak-growth')->firstOrFail();

        $this->assertSame('1499.00', $plan->price_amount);
        $this->assertSame('INR', $plan->currency);
        $this->assertSame(45, $plan->billing_period_days);
        $this->assertTrue($plan->is_active);
        $this->assertTrue($plan->is_visible);
        $this->assertSame(0, Plan::query()->count());
        $this->assertDatabaseHas('suchak_plan_features', [
            'suchak_plan_id' => $plan->id,
            'feature_key' => SuchakPlanFeature::FEATURE_ACTIVE_PROFILE_LIMIT,
            'value_type' => SuchakPlanFeature::TYPE_INTEGER,
            'feature_value' => '80',
            'is_enabled' => true,
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_id' => $admin->id,
            'action_type' => 'suchak_plan_catalog_created',
            'entity_type' => 'SuchakPlan',
            'entity_id' => $plan->id,
        ]);

        $updateResponse = $this->actingAs($admin)->put(route('admin.suchak.plans.update', $plan), [
            'name' => 'Suchak Growth Plus',
            'slug' => 'suchak-growth-plus',
            'description' => 'Updated growth plan.',
            'price_amount' => '',
            'currency' => '',
            'billing_period_days' => '30',
            'is_active' => '1',
            'is_visible' => '0',
            'sort_order' => '25',
            'reason' => 'Update Day 30 Suchak growth plan.',
            'features' => $this->featurePayload([
                SuchakPlanFeature::FEATURE_ACTIVE_PROFILE_LIMIT => ['feature_value' => '120', 'is_enabled' => '1'],
                SuchakPlanFeature::FEATURE_CRM_FEATURES => ['feature_value' => '0', 'is_enabled' => '0'],
            ]),
        ]);

        $updateResponse->assertRedirect(route('admin.suchak.plans.index'));

        $plan = $plan->fresh(['features']);

        $this->assertSame('Suchak Growth Plus', $plan->name);
        $this->assertSame('suchak-growth-plus', $plan->slug);
        $this->assertNull($plan->price_amount);
        $this->assertNull($plan->currency);
        $this->assertSame(30, $plan->billing_period_days);
        $this->assertFalse($plan->is_visible);
        $this->assertSame(9, $plan->features->count());
        $this->assertDatabaseHas('suchak_plan_features', [
            'suchak_plan_id' => $plan->id,
            'feature_key' => SuchakPlanFeature::FEATURE_ACTIVE_PROFILE_LIMIT,
            'feature_value' => '120',
            'is_enabled' => true,
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_id' => $admin->id,
            'action_type' => 'suchak_plan_catalog_updated',
            'entity_type' => 'SuchakPlan',
            'entity_id' => $plan->id,
        ]);
        $this->assertSame(2, AdminAuditLog::query()->where('entity_type', 'SuchakPlan')->count());
    }

    public function test_admin_assigns_suchak_plan_and_suchak_dashboard_displays_limits_and_usage(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$suchakUser, $account] = $this->verifiedSuchakActor();
        $plan = SuchakPlan::factory()->create([
            'name' => 'Suchak Operator',
            'slug' => 'suchak-operator-day-30',
            'price_amount' => '999.00',
            'currency' => 'INR',
            'is_active' => true,
            'is_visible' => true,
        ]);
        $this->createFeature($plan, SuchakPlanFeature::FEATURE_ACTIVE_PROFILE_LIMIT, '3');
        $this->createFeature($plan, SuchakPlanFeature::FEATURE_MONTHLY_UPLOAD_LIMIT, '5');
        $this->createFeature($plan, SuchakPlanFeature::FEATURE_PDF_DOWNLOAD_SHARE_LIMIT, '4');
        $this->createFeature($plan, SuchakPlanFeature::FEATURE_CRM_FEATURES, 'true', SuchakPlanFeature::TYPE_BOOLEAN);

        SuchakProfileRepresentation::factory()->count(2)->create([
            'suchak_account_id' => $account->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
        ]);
        SuchakBiodataIntakeLink::factory()->create([
            'suchak_account_id' => $account->id,
            'created_by_user_id' => $suchakUser->id,
            'created_at' => now(),
        ]);
        SuchakBiodataExport::factory()->create([
            'suchak_account_id' => $account->id,
            'export_type' => SuchakBiodataExport::TYPE_BIODATA_PDF,
            'created_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.suchak.accounts.show', $account))
            ->assertOk()
            ->assertSee('Plan & Entitlement Assignment', false)
            ->assertSee('Assign Suchak plan', false)
            ->assertSee('Suchak Operator', false);

        $assignResponse = $this->actingAs($admin)->post(route('admin.suchak.plans.accounts.assign', $account), [
            'suchak_plan_id' => $plan->id,
            'starts_at' => now()->format('Y-m-d\TH:i'),
            'ends_at' => now()->addMonth()->format('Y-m-d\TH:i'),
            'reason' => 'Assign Day 30 operator plan manually.',
        ]);

        $assignResponse->assertRedirect(route('admin.suchak.accounts.show', $account));

        $this->assertDatabaseHas('suchak_subscriptions', [
            'suchak_account_id' => $account->id,
            'suchak_plan_id' => $plan->id,
            'assigned_by_user_id' => $admin->id,
            'status' => SuchakSubscription::STATUS_ACTIVE,
        ]);
        $this->assertSame(0, DB::table('payments')->count());

        $this->actingAs($suchakUser)
            ->get(route('suchak.dashboard'))
            ->assertOk()
            ->assertSee('Suchak Operator', false)
            ->assertSee('Usage', false)
            ->assertSee('Active customer profiles', false)
            ->assertSee('2 / 3', false)
            ->assertSee('Monthly biodata uploads', false)
            ->assertSee('1 / 5', false)
            ->assertSee('Daily biodata PDF / QR shares', false)
            ->assertSee('1 / 4', false)
            ->assertSee('Free trial policy', false)
            ->assertSee('Visible catalog', false);
    }

    public function test_member_plan_catalog_does_not_show_suchak_plans(): void
    {
        SuchakPlan::factory()->create([
            'name' => 'Suchak Bureau Hidden From Members',
            'slug' => 'suchak-bureau-hidden-from-members',
            'is_active' => true,
            'is_visible' => true,
        ]);
        $memberPlan = Plan::query()->create($this->memberPlanPayload());
        PlanQuotaPolicy::ensureAllKeysForPlan($memberPlan);

        $this->actingAs(User::factory()->create())
            ->get(route('plans.index'))
            ->assertOk()
            ->assertSee('Member Premium', false)
            ->assertDontSee('Suchak Bureau Hidden From Members', false);
    }

    /**
     * @param  array<string, array<string, string>>  $overrides
     * @return array<string, array<string, string>>
     */
    private function featurePayload(array $overrides = []): array
    {
        $payload = [];

        foreach (SuchakPlanFeature::FEATURE_KEYS as $featureKey) {
            $isBoolean = in_array($featureKey, [
                SuchakPlanFeature::FEATURE_LEDGER_FEATURES,
                SuchakPlanFeature::FEATURE_CRM_FEATURES,
                SuchakPlanFeature::FEATURE_PRIORITY_SUPPORT,
                SuchakPlanFeature::FEATURE_BULK_UPLOAD_ACCESS,
            ], true);

            $payload[$featureKey] = array_merge([
                'feature_key' => $featureKey,
                'value_type' => $isBoolean ? SuchakPlanFeature::TYPE_BOOLEAN : SuchakPlanFeature::TYPE_INTEGER,
                'feature_value' => $isBoolean ? '0' : '0',
                'is_enabled' => '0',
            ], $overrides[$featureKey] ?? []);
        }

        return $payload;
    }

    private function createFeature(
        SuchakPlan $plan,
        string $featureKey,
        string $featureValue,
        string $valueType = SuchakPlanFeature::TYPE_INTEGER,
    ): void {
        SuchakPlanFeature::factory()->create([
            'suchak_plan_id' => $plan->id,
            'feature_key' => $featureKey,
            'value_type' => $valueType,
            'feature_value' => $featureValue,
            'is_enabled' => true,
        ]);
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

    /**
     * @return array<string, mixed>
     */
    private function memberPlanPayload(): array
    {
        $payload = [
            'name' => 'Member Premium',
            'slug' => 'member-premium-day-30',
            'price' => '999.00',
            'discount_percent' => 0,
            'duration_days' => 30,
            'is_active' => true,
            'sort_order' => 10,
            'highlight' => false,
        ];

        foreach ([
            'is_visible' => true,
            'tier' => 1,
            'duration_quantity' => 1,
            'duration_unit' => 'month',
            'default_billing_key' => 'monthly',
            'grace_period_days' => 0,
            'leftover_quota_carry_window_days' => 0,
            'applies_to_gender' => 'all',
            'marketing_badge' => null,
        ] as $column => $value) {
            if (Schema::hasColumn('plans', $column)) {
                $payload[$column] = $value;
            }
        }

        return $payload;
    }
}
