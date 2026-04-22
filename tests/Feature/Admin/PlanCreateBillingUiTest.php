<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanCreateBillingUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_plan_page_includes_billing_periods_and_add_button(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->get(route('admin.plans.create'));

        $response->assertOk();
        $response->assertSee(__('subscriptions.admin_billing_rows_title'), false);
        $response->assertSee(__('subscriptions.admin_add_billing_period'), false);
        $response->assertSee(__('subscriptions.admin_billing_default_catalog_tab'), false);
        $response->assertSee('id="plan-admin-billing-panel"', false);
        $response->assertSee('name="duration_preset"', false);
        $response->assertSee("term_rows[' + i + '][billing_key]", false);
    }

    public function test_plans_index_includes_footer_create_cta(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.plans.index'))
            ->assertOk()
            ->assertSee(route('admin.plans.create'), false)
            ->assertSee(__('subscriptions.admin_plans_index_footer_hint'), false);
    }
}
