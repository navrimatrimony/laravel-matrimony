<?php

namespace Tests\Feature\Admin;

use App\Models\Plan;
use App\Models\PlanQuotaPolicy;
use App\Models\PlanTerm;
use App\Models\User;
use App\Support\PlanQuotaPolicyKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanMultiDurationStoreTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, array<string, mixed>>  $overridesByKey
     * @return array<string, array<string, mixed>>
     */
    private function quotaPoliciesPayload(array $overridesByKey = []): array
    {
        $qp = [];
        foreach (PlanQuotaPolicyKeys::ordered() as $fk) {
            $d = PlanQuotaPolicy::defaultsForNewPlan($fk);
            $row = [
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
            if (isset($overridesByKey[$fk]) && is_array($overridesByKey[$fk])) {
                $row = array_merge($row, $overridesByKey[$fk]);
            }
            $qp[$fk] = $row;
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

    /**
     * Two catalog plans (separate DB rows): each has Monthly + Quarterly (3 mo) + Half-yearly (6 mo) billing rows.
     * Plan-wide grace and per-plan quota limits are independent between plans.
     */
    public function test_two_plans_quarterly_and_half_yearly_terms_independent_controls(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $silverTerms = $this->termRowsList([
            PlanTerm::BILLING_MONTHLY => ['price' => '100', 'is_visible' => '1'],
            PlanTerm::BILLING_QUARTERLY => ['price' => '280', 'is_visible' => '1'],
            PlanTerm::BILLING_HALF_YEARLY => ['price' => '520', 'is_visible' => '1'],
        ]);

        $silverResponse = $this->actingAs($admin)->post(route('admin.plans.store'), [
            'name' => 'Silver Line',
            'applies_to_gender' => 'all',
            'sort_order' => 1,
            'default_billing_key' => PlanTerm::BILLING_QUARTERLY,
            'grace_period_days' => 3,
            'leftover_quota_carry_window_days' => '',
            'is_active' => '1',
            'list_price_rupees' => '',
            'gst_inclusive' => '1',
            'quota_policies' => $this->quotaPoliciesPayload([
                \App\Support\PlanFeatureKeys::CHAT_SEND_LIMIT => ['limit_value' => '10'],
            ]),
            'term_rows' => $silverTerms,
        ]);
        $silverResponse->assertSessionDoesntHaveErrors();
        $silverResponse->assertRedirect();

        $goldTerms = $this->termRowsList([
            PlanTerm::BILLING_MONTHLY => ['price' => '100', 'is_visible' => '1'],
            PlanTerm::BILLING_QUARTERLY => ['price' => '290', 'is_visible' => '1'],
            PlanTerm::BILLING_HALF_YEARLY => ['price' => '540', 'is_visible' => '0'],
        ]);

        $goldResponse = $this->actingAs($admin)->post(route('admin.plans.store'), [
            'name' => 'Gold Line',
            'applies_to_gender' => 'all',
            'sort_order' => 2,
            'default_billing_key' => PlanTerm::BILLING_QUARTERLY,
            'grace_period_days' => 14,
            'leftover_quota_carry_window_days' => '7',
            'is_active' => '1',
            'list_price_rupees' => '',
            'gst_inclusive' => '1',
            'quota_policies' => $this->quotaPoliciesPayload([
                \App\Support\PlanFeatureKeys::CHAT_SEND_LIMIT => ['limit_value' => '999'],
            ]),
            'term_rows' => $goldTerms,
        ]);
        $goldResponse->assertSessionDoesntHaveErrors();
        $goldResponse->assertRedirect();

        $silver = Plan::query()->where('slug', 'silver-line-all')->with(['terms', 'quotaPolicies'])->firstOrFail();
        $gold = Plan::query()->where('slug', 'gold-line-all')->with(['terms', 'quotaPolicies'])->firstOrFail();

        $this->assertSame(3, $silver->terms->count());
        $this->assertSame(3, $gold->terms->count());
        foreach ([$silver, $gold] as $p) {
            $keys = $p->terms->pluck('billing_key')->sort()->values()->all();
            $this->assertSame(
                [PlanTerm::BILLING_HALF_YEARLY, PlanTerm::BILLING_MONTHLY, PlanTerm::BILLING_QUARTERLY],
                $keys
            );
        }

        $this->assertSame(3, $silver->grace_period_days);
        $this->assertSame(14, $gold->grace_period_days);
        $this->assertNull($silver->leftover_quota_carry_window_days);
        $this->assertSame(7, $gold->leftover_quota_carry_window_days);
        $this->assertFalse($silver->highlight);
        $this->assertFalse($gold->highlight);

        $silverChat = $silver->quotaPolicies->firstWhere('feature_key', \App\Support\PlanFeatureKeys::CHAT_SEND_LIMIT);
        $goldChat = $gold->quotaPolicies->firstWhere('feature_key', \App\Support\PlanFeatureKeys::CHAT_SEND_LIMIT);
        $this->assertSame(10, (int) $silverChat->limit_value);
        $this->assertSame(999, (int) $goldChat->limit_value);

        $this->assertSame(280.0, (float) $silver->terms->firstWhere('billing_key', PlanTerm::BILLING_QUARTERLY)->price);
        $this->assertSame(290.0, (float) $gold->terms->firstWhere('billing_key', PlanTerm::BILLING_QUARTERLY)->price);
        $this->assertTrue($silver->terms->firstWhere('billing_key', PlanTerm::BILLING_HALF_YEARLY)->is_visible);
        $this->assertFalse($gold->terms->firstWhere('billing_key', PlanTerm::BILLING_HALF_YEARLY)->is_visible);
    }

    public function test_store_creates_one_plan_with_two_billing_terms(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $payload = [
            'name' => 'Gold Pack',
            'applies_to_gender' => 'all',
            'sort_order' => 5,
            'default_billing_key' => PlanTerm::BILLING_MONTHLY,
            'grace_period_days' => 3,
            'leftover_quota_carry_window_days' => '',
            'is_active' => '1',
            'list_price_rupees' => '',
            'gst_inclusive' => '1',
            'quota_policies' => $this->quotaPoliciesPayload(),
            'term_rows' => $this->termRowsList([
                PlanTerm::BILLING_MONTHLY => ['price' => '100', 'is_visible' => '1'],
                PlanTerm::BILLING_QUARTERLY => ['price' => '270', 'discount_percent' => '5', 'is_visible' => '1'],
            ]),
        ];

        $response = $this->actingAs($admin)->post(route('admin.plans.store'), $payload);
        $plan = Plan::query()->where('slug', 'gold-pack-all')->firstOrFail();
        $response->assertRedirect(route('admin.plans.edit', $plan));

        $this->assertSame(1, Plan::query()->where('slug', 'gold-pack-all')->count());
        $plan->load('terms');
        $this->assertSame('Gold Pack', $plan->name);
        $this->assertSame(2, $plan->terms->count());
        $this->assertTrue($plan->terms->pluck('billing_key')->contains(PlanTerm::BILLING_MONTHLY));
        $this->assertTrue($plan->terms->pluck('billing_key')->contains(PlanTerm::BILLING_QUARTERLY));
    }
}
