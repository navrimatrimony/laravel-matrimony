<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowcasePhotoPoolNavTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_bulk_create_shows_pool_health_and_links(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.showcase-profile.bulk-create'))
            ->assertOk()
            ->assertSee(__('showcase_bulk.pool_health_title'), false)
            ->assertSee(route('admin.showcase-photo-pool.index'), false);
    }

    public function test_admin_showcase_dashboard_links_photo_pool_and_bulk(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.showcase-dashboard.index'))
            ->assertOk()
            ->assertSee('Photo pool', false)
            ->assertSee(route('admin.showcase-photo-pool.index'), false)
            ->assertSee(route('admin.showcase-profile.bulk-create'), false);
    }

    public function test_showcase_layout_includes_photo_pool_tab(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.showcase-photo-pool.index'))
            ->assertOk()
            ->assertSee('Photo pool', false)
            ->assertSee(__('showcase_photo_pool_admin.upload_title'), false);
    }
}
