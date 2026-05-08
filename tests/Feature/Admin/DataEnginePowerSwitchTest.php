<?php

namespace Tests\Feature\Admin;

use App\Models\AdminSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataEnginePowerSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_toggle_engine(): void
    {
        $this->post(route('admin.data-engine.toggle-engine'))
            ->assertRedirect();
    }

    public function test_admin_toggle_flips_database_setting(): void
    {
        AdminSetting::setValue('data_engine_enabled', '1');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->from(route('admin.data-engine.index'))
            ->post(route('admin.data-engine.toggle-engine'))
            ->assertRedirect(route('admin.data-engine.index'))
            ->assertSessionHas('engine_toggle', 'db_off');

        $this->assertFalse(AdminSetting::getBool('data_engine_enabled', true));

        $this->actingAs($admin)->post(route('admin.data-engine.toggle-engine'))
            ->assertRedirect(route('admin.data-engine.index'));

        $this->assertTrue(AdminSetting::getBool('data_engine_enabled', false));
    }
}
