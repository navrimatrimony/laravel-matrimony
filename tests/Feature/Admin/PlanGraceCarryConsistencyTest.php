<?php

namespace Tests\Feature\Admin;

use App\Models\Plan;
use App\Models\PlanQuotaPolicy;
use App\Models\PlanTerm;
use App\Models\User;
use App\Support\PlanQuotaPolicyKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanGraceCarryConsistencyTest extends TestCase
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

    /**
     * @param  array<string, array<string, mixed>>  $rowDataByBillingKey
     * @return list<array<string, mixed>>
     */
    private function termRowsList(array $rowDataByBillingKey): array
    {
        $rows = [];
        foreach ($rowDataByBillingKey as $bk => $over) {
            $rows[] = array_merge([
                'billing_key' => $bk,
                'price' => '0',
                'discount_percent' => '',
                'is_visible' => '0',
            ], $over);
        }

        return $rows;
    }

    public function test_admin_save_keeps_grace_and_carry_in_db_and_edit_form(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = Plan::query()->create([
            'name' => 'Gold',
            'slug' => 'gold_male',
            'price' => 100.0,
            'duration_days' => 30,
            'grace_period_days' => 3,
            'leftover_quota_carry_window_days' => null,
            'is_active' => true,
            'applies_to_gender' => 'male',
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

        $save = function (int $grace, ?int $carry) use ($admin, $plan): void {
            $payload = [
                '_method' => 'PUT',
                'name' => 'Gold',
                'applies_to_gender' => 'male',
                'sort_order' => '40',
                'default_billing_key' => PlanTerm::BILLING_MONTHLY,
                'grace_period_days' => (string) $grace,
                'leftover_quota_carry_window_days' => $carry === null ? '' : (string) $carry,
                'is_active' => '1',
                'list_price_rupees' => '',
                'gst_inclusive' => '1',
                'quota_policies' => $this->quotaPoliciesPayload(),
                'term_rows' => $this->termRowsList([
                    PlanTerm::BILLING_MONTHLY => ['price' => '100', 'is_visible' => '1'],
                ]),
            ];

            $res = $this->actingAs($admin)
                ->from(route('admin.plans.edit', $plan))
                ->put(route('admin.plans.update', $plan), $payload);

            $res->assertSessionDoesntHaveErrors();
            $res->assertRedirect(route('admin.plans.edit', $plan));
        };

        // Random save #1
        $save(7, 30);
        $plan->refresh();
        $this->assertSame(7, (int) $plan->grace_period_days);
        $this->assertSame(30, (int) $plan->leftover_quota_carry_window_days);
        $this->actingAs($admin)
            ->get(route('admin.plans.edit', $plan))
            ->assertOk()
            ->assertSee('id="plan-admin-grace-days"', false)
            ->assertSee('id="plan-admin-carry-window"', false)
            ->assertSee('<option value="7" selected>', false)
            ->assertSee('<option value="30" selected>', false);

        // Random save #2
        $save(14, null);
        $plan->refresh();
        $this->assertSame(14, (int) $plan->grace_period_days);
        $this->assertNull($plan->leftover_quota_carry_window_days);
        $this->actingAs($admin)
            ->get(route('admin.plans.edit', $plan))
            ->assertOk()
            ->assertSee('<option value="14" selected>', false)
            ->assertSee('<option value="" selected>', false);
    }
}

