<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowcaseEngineDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_open_showcase_dashboard(): void
    {
        $this->get(route('admin.showcase-dashboard.index'))->assertRedirect();
    }

    public function test_admin_can_open_showcase_dashboard(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.showcase-dashboard.index'))
            ->assertOk()
            ->assertSee('Showcase activity', false);
    }

    public function test_showcase_index_redirects_to_dashboard(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.showcase.index'))
            ->assertRedirect(route('admin.showcase-dashboard.index'));
    }
}
