<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminNotificationNavTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_notification_debug_page_uses_debug_title(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertSee(__('admin_notifications.debug_index_title'), false);
    }

    public function test_app_settings_opens_notifications_tab_from_query(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.app-settings.index', ['tab' => 'notifications']))
            ->assertOk()
            ->assertSee(__('admin_notifications.platform_heading'), false)
            ->assertSee('notification_mail_enabled', false);
    }

    public function test_debug_inbox_accepts_internal_user_id(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.notifications.index', ['user_id' => $member->id]))
            ->assertOk()
            ->assertSee(__('admin_notifications.debug_user_title', ['id' => $member->id]), false);
    }

    public function test_debug_inbox_accepts_mobile_login_number(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create(['mobile' => '1111111111']);

        $this->actingAs($admin)
            ->get(route('admin.notifications.index', ['user_id' => '1111111111']))
            ->assertOk()
            ->assertSee(__('admin_notifications.debug_user_title', ['id' => $member->id]), false);
    }
}
