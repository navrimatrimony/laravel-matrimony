<?php

namespace Tests\Feature\Admin;

use App\Models\Plan;
use App\Models\PlanQuotaPolicy;
use App\Models\PlanTerm;
use App\Models\User;
use App\Support\PlanQuotaPolicyKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanUpdateBillingRequestTest extends TestCase
{
    use RefreshDatabase;

    private function quotaPoliciesPayload(): array
    {
        $qp = [];
        foreach (PlanQuotaPolicyKeys::ordered() as $fk) {
            $d = PlanQuotaPolicy::defaultsForNewPlan($fk);
            $qp[$fk] = [
                'is_enabled' => $d['is_enabled'] ? '1' : '0',
                'refresh_type' => $d['refresh_type'],
                'limit_value' => (string) ($d['limit_value'] ?? '0'),
                'per_day_usage_limit_enabled' => '0',
                'daily_sub_cap' => '',
                'purchasable_if_exhausted' => '0',
                'pack_price_rupees' => '',
                'pack_message_count' => '',
                'pack_validity_days' => '',
            ];
        }

        return $qp;
    }

    public function test_update_plan_without_top_level_price_field_merges_from_catalog_tab_term_row(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = Plan::query()->create([
            'name' => 'Test Plan',
            'slug' => 'test-plan-all',
            'price' => 100.0,
            'duration_days' => 30,
            'grace_period_days' => 3,
            'is_active' => true,
        ]);
        PlanTerm::query()->create([
            'plan_id' => $plan->id,
            'billing_key' => PlanTerm::BILLING_MONTHLY,
            'duration_days' => 30,
            'price' => 100.0,
            'discount_percent' => null,
            'is_visible' => true,
            'sort_order' => 10,
        ]);

        $payload = [
            '_token' => csrf_token(),
            '_method' => 'PUT',
            'name' => 'Test Plan',
            'applies_to_gender' => 'all',
            'sort_order' => '0',
            'duration_preset' => PlanTerm::BILLING_MONTHLY,
            'default_billing_key' => PlanTerm::BILLING_MONTHLY,
            'grace_period_days' => '3',
            'leftover_quota_carry_window_days' => '',
            'is_active' => '1',
            'list_price_rupees' => '',
            'gst_inclusive' => '1',
            'quota_policies' => $this->quotaPoliciesPayload(),
            'term_rows' => [
                [
                    'billing_key' => PlanTerm::BILLING_MONTHLY,
                    'price' => '99',
                    'discount_percent' => '',
                    'is_visible' => '1',
                ],
            ],
        ];

        $response = $this->actingAs($admin)->from(route('admin.plans.edit', $plan))->put(route('admin.plans.update', $plan), $payload);

        $response->assertSessionDoesntHaveErrors();
        $response->assertRedirect(route('admin.plans.edit', $plan));

        $plan->refresh();
        $this->assertSame(99.0, (float) $plan->price);

        $term = PlanTerm::query()->where('plan_id', $plan->id)->where('billing_key', PlanTerm::BILLING_MONTHLY)->first();
        $this->assertNotNull($term);
        $this->assertSame(99.0, (float) $term->price);
    }

    public function test_update_plan_persists_multiple_term_rows_without_top_level_price(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = Plan::query()->create([
            'name' => 'Multi Plan',
            'slug' => 'multi-plan-all',
            'price' => 100.0,
            'duration_days' => 30,
            'grace_period_days' => 3,
            'is_active' => true,
        ]);
        PlanTerm::query()->create([
            'plan_id' => $plan->id,
            'billing_key' => PlanTerm::BILLING_MONTHLY,
            'duration_days' => 30,
            'price' => 100.0,
            'discount_percent' => null,
            'is_visible' => true,
            'sort_order' => 10,
        ]);

        $payload = [
            '_method' => 'PUT',
            'name' => 'Multi Plan',
            'applies_to_gender' => 'all',
            'sort_order' => '0',
            'duration_preset' => PlanTerm::BILLING_QUARTERLY,
            'default_billing_key' => PlanTerm::BILLING_QUARTERLY,
            'grace_period_days' => '3',
            'leftover_quota_carry_window_days' => '',
            'is_active' => '1',
            'list_price_rupees' => '',
            'gst_inclusive' => '1',
            'quota_policies' => $this->quotaPoliciesPayload(),
            'term_rows' => [
                [
                    'billing_key' => PlanTerm::BILLING_MONTHLY,
                    'price' => '100',
                    'discount_percent' => '',
                    'is_visible' => '1',
                ],
                [
                    'billing_key' => PlanTerm::BILLING_QUARTERLY,
                    'price' => '2',
                    'discount_percent' => '50',
                    'is_visible' => '1',
                ],
            ],
        ];

        $response = $this->actingAs($admin)->from(route('admin.plans.edit', $plan))->put(route('admin.plans.update', $plan), $payload);

        $response->assertSessionDoesntHaveErrors();
        $response->assertRedirect(route('admin.plans.edit', $plan));

        $plan->refresh();
        $this->assertSame(2.0, (float) $plan->price);
        $this->assertSame(50, (int) $plan->discount_percent);

        $q = PlanTerm::query()->where('plan_id', $plan->id)->where('billing_key', PlanTerm::BILLING_QUARTERLY)->first();
        $this->assertNotNull($q);
        $this->assertSame(2.0, (float) $q->price);
        $this->assertSame(50, (int) $q->discount_percent);
    }
}
