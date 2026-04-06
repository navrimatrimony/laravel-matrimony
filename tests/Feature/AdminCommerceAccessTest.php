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
    }

    public function test_non_admin_user_cannot_access_commerce_admin(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'admin_role' => null]);

        $this->actingAs($user)
            ->get(route('admin.commerce.analytics.index'))
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
    }
}
