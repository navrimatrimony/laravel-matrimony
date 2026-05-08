<?php

namespace Tests\Feature\Admin;

use App\Models\AdminSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataEngineFixSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_fix_route_is_blocked_when_fix_mode_safety_lock_is_off(): void
    {
        config()->set('data_engine.enabled', true);
        config()->set('data_engine.allow_fix_mode', false);

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('admin.data-engine.fix'))
            ->assertRedirect(route('admin.data-engine.index'))
            ->assertSessionHas('error', 'Fix mode is safety-locked. Enable DATA_ENGINE_ALLOW_FIX_MODE=true for intentional runs.');
    }

    public function test_fix_mode_auto_disables_when_expiry_is_past(): void
    {
        config()->set('data_engine.enabled', true);
        config()->set('data_engine.allow_fix_mode', false);

        AdminSetting::setValue('data_engine_allow_fix_mode', '1');
        AdminSetting::setValue('data_engine_fix_mode_expires_at', CarbonImmutable::now()->subMinute()->toIso8601String());

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('admin.data-engine.fix'))
            ->assertRedirect(route('admin.data-engine.index'))
            ->assertSessionHas('error', 'Fix mode is safety-locked. Enable DATA_ENGINE_ALLOW_FIX_MODE=true for intentional runs.');

        $this->assertFalse(AdminSetting::getBool('data_engine_allow_fix_mode', true));
        $this->assertSame('', (string) AdminSetting::getValue('data_engine_fix_mode_expires_at', 'x'));
    }

    public function test_super_admin_can_set_timed_fix_mode_window_from_app_settings(): void
    {
        $superAdmin = User::factory()->create([
            'is_admin' => true,
            'admin_role' => 'super_admin',
        ]);

        $this->actingAs($superAdmin)
            ->post(route('admin.app-settings.update'), [
                'interest_min_core_completeness_pct' => 0,
                'member_presence_online_threshold_minutes' => 5,
                'plans_enforce_gender_specific_visibility' => 1,
                'mobile_clean_mode' => 1,
                'success_rate_threshold' => 85,
                'webhook_failure_threshold' => 5,
                'queue_lag_threshold' => 120,
                'invoice_failure_threshold' => 2,
                'dashboard_notification_cards_limit' => 2,
                'dashboard_activity_autohide_seconds' => 7,
                'billing_legal_name' => 'Test Billing',
                'billing_address' => 'Addr',
                'billing_email' => 'billing@example.com',
                'billing_phone' => '9999999999',
                'billing_gstin' => '',
                'billing_pan' => '',
                'billing_state_code' => '',
                'billing_invoice_prefix' => '',
                'billing_invoice_terms' => '',
                'data_engine_allow_fix_mode' => 1,
                'data_engine_fix_mode_duration' => '2_hours',
            ])
            ->assertRedirect(route('admin.app-settings.index'));

        $this->assertTrue(AdminSetting::getBool('data_engine_allow_fix_mode', false));
        $this->assertSame('2_hours', (string) AdminSetting::getValue('data_engine_fix_mode_duration', ''));
        $this->assertNotSame('', (string) AdminSetting::getValue('data_engine_fix_mode_expires_at', ''));
    }
}

