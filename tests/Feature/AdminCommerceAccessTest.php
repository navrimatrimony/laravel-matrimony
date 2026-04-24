<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCommerceAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_commerce_admin(): void
    {
        $this->get(route('admin.commerce.analytics.index'))->assertRedirect();
        $this->get(route('admin.commerce.coupons.index'))->assertRedirect();
        $this->get(route('admin.revenue.index'))->assertRedirect();
    }

    public function test_non_admin_user_cannot_access_commerce_admin(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'admin_role' => null]);

        $this->actingAs($user)
            ->get(route('admin.commerce.analytics.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('admin.revenue.index'))
            ->assertForbidden();
    }

    public function test_admin_can_open_commerce_analytics_and_coupons(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.commerce.analytics.index'))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.commerce.coupons.index'))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.commerce.overrides.index'))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.revenue.index'))
            ->assertOk()
            ->assertSee(__('admin_commerce.revenue_title'), false);

        $this->actingAs($admin)
            ->get(route('admin.revenue.index', ['from' => '2026-01-01', 'to' => '2026-01-31']))
            ->assertOk()
            ->assertSee(__('admin_commerce.revenue_table_daily_revenue'), false);
    }
}
