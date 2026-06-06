<?php

namespace Tests\Feature\Admin;

use App\Models\AdminSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AppSettingsBrandingGuestBackgroundTest extends TestCase
{
    use RefreshDatabase;

    private ?string $createdBackgroundPath = null;

    protected function tearDown(): void
    {
        if ($this->createdBackgroundPath) {
            $absolutePath = public_path($this->createdBackgroundPath);
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
        }

        parent::tearDown();
    }

    public function test_super_admin_can_upload_guest_background_image_from_branding_settings(): void
    {
        $superAdmin = User::factory()->create([
            'is_admin' => true,
            'admin_role' => 'super_admin',
        ]);

        $response = $this->actingAs($superAdmin)->post(route('admin.app-settings.update'), [
            'return_tab' => 'branding',
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
            'auth_background_image' => UploadedFile::fake()->image('guest-bg.jpg', 1200, 800),
        ]);

        $response->assertRedirect(route('admin.app-settings.index', ['tab' => 'branding']));

        $storedPath = (string) AdminSetting::getValue('site_identity_auth_background_image', '');
        $this->createdBackgroundPath = $storedPath !== '' ? $storedPath : null;

        $this->assertNotSame('', $storedPath);
        $this->assertStringStartsWith('images/branding/auth-background-image-', $storedPath);
        $this->assertFileExists(public_path($storedPath));

        auth()->logout();
        $this->app['auth']->forgetGuards();

        $this->get(route('login'))
            ->assertOk()
            ->assertSee($storedPath, false);
    }
}
