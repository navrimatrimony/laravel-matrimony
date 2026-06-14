<?php

namespace Tests\Feature\Suchak;

use App\Models\AdminAuditLog;
use App\Models\SuchakPolicy;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SuchakAdminSettingsCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_suchak_settings_center(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);

        $this->actingAs($admin)
            ->get(route('admin.suchak.settings.index'))
            ->assertOk()
            ->assertSee('Suchak Settings Center', false)
            ->assertSee('Homepage Settings', false)
            ->assertSee('Hero visual controls', false)
            ->assertSee('Platform payment mode', false)
            ->assertSee('Show Suchak registration form in homepage hero', false)
            ->assertSee('Work area customer threshold', false)
            ->assertSee('Allow Suchak work before admin approval', false)
            ->assertSee('Auto publish approved Suchak publicly', false)
            ->assertSee('Visit payout confirmation policy', false)
            ->assertSee('Commission Rules', false)
            ->assertSee(route('admin.suchak.settings.update'), false);
    }

    public function test_admin_can_update_suchak_settings_with_audit_and_service_readback(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);
        $payload = array_merge($this->validPayload(), [
            'suchak_hero_image' => UploadedFile::fake()->image('suchak-hero.jpg', 1400, 700),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.suchak.settings.update'), $payload)
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('suchak_policies', [
            'policy_key' => SuchakPolicyService::KEY_REQUEST_ACTION_SLA_HOURS,
            'policy_value' => '72',
            'value_type' => SuchakPolicy::TYPE_INTEGER,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('suchak_policies', [
            'policy_key' => SuchakPolicyService::KEY_ALLOW_TWO_YEAR_CONSENT,
            'policy_value' => 'false',
            'value_type' => SuchakPolicy::TYPE_BOOLEAN,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('suchak_policies', [
            'policy_key' => SuchakPolicyService::KEY_SUCHAK_PAYMENT_MODE,
            'policy_value' => 'payu_test_mode',
            'value_type' => SuchakPolicy::TYPE_STRING,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('suchak_policies', [
            'policy_key' => SuchakPolicyService::KEY_SUCHAK_VISIT_CONFIRMATION_POLICY_MODE,
            'policy_value' => 'admin_only',
            'value_type' => SuchakPolicy::TYPE_STRING,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('suchak_policies', [
            'policy_key' => SuchakPolicyService::KEY_SUCHAK_ALLOW_WORK_BEFORE_ADMIN_APPROVAL,
            'policy_value' => 'true',
            'value_type' => SuchakPolicy::TYPE_BOOLEAN,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('suchak_policies', [
            'policy_key' => SuchakPolicyService::KEY_SUCHAK_AUTO_PUBLISH_ON_APPROVAL,
            'policy_value' => 'true',
            'value_type' => SuchakPolicy::TYPE_BOOLEAN,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('suchak_policies', [
            'policy_key' => SuchakPolicyService::KEY_SUCHAK_HERO_REGISTRATION_FORM_ENABLED,
            'policy_value' => 'false',
            'value_type' => SuchakPolicy::TYPE_BOOLEAN,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('suchak_policies', [
            'policy_key' => SuchakPolicyService::KEY_SUCHAK_HOMEPAGE_COPY_JSON,
            'value_type' => SuchakPolicy::TYPE_JSON,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('suchak_policies', [
            'policy_key' => SuchakPolicyService::KEY_SUCHAK_HOMEPAGE_STYLE_JSON,
            'value_type' => SuchakPolicy::TYPE_JSON,
            'is_active' => true,
        ]);
        $heroImagePolicy = SuchakPolicy::query()
            ->where('policy_key', SuchakPolicyService::KEY_SUCHAK_HERO_IMAGE_PATH)
            ->firstOrFail();
        $this->assertSame(SuchakPolicy::TYPE_STRING, $heroImagePolicy->value_type);
        $this->assertTrue($heroImagePolicy->is_active);
        $this->assertStringStartsWith('suchak/hero-images/', $heroImagePolicy->policy_value);
        Storage::disk('public')->assertExists($heroImagePolicy->policy_value);

        $this->assertDatabaseHas('suchak_policies', [
            'policy_key' => SuchakPolicyService::KEY_SUCHAK_WORK_AREA_MIN_CONSENTED_CUSTOMERS,
            'policy_value' => '6',
            'value_type' => SuchakPolicy::TYPE_INTEGER,
            'is_active' => true,
        ]);

        $commissionRules = app(SuchakPolicyService::class)->commissionRules();
        $this->assertSame('percentage', $commissionRules['mode']);
        $this->assertSame(15, $commissionRules['default_percent']);
        $this->assertSame(2500.0, (float) $commissionRules['default_amount']);
        $this->assertFalse($commissionRules['require_ack']);

        $policyService = app(SuchakPolicyService::class);
        $this->assertSame(72, $policyService->requestActionSlaHours());
        $this->assertFalse($policyService->allowsTwoYearConsent());
        $this->assertSame(10, $policyService->freeTrialDays());
        $this->assertSame(3, $policyService->gracePeriodDays());
        $this->assertSame('free_trial_then_manual', $policyService->planPricingMode());
        $this->assertSame('payu_test_mode', $policyService->paymentMode());
        $this->assertTrue($policyService->allowsWorkBeforeAdminApproval());
        $this->assertTrue($policyService->autoPublishesOnApproval());
        $this->assertFalse($policyService->heroRegistrationFormEnabled());
        $this->assertSame($heroImagePolicy->policy_value, $policyService->heroImagePath());
        $this->assertSame(6, $policyService->workAreaMinimumConsentedCustomers());
        $this->assertSame('admin_only', $policyService->visitConfirmationPolicyMode());
        $this->assertSame('Suchak Growth Hub', $policyService->homepageCopy()['en']['title']);
        $this->assertSame('सूचक विकास केंद्र', $policyService->homepageCopy()['mr']['title']);
        $this->assertSame('#dc2626', $policyService->homepageStyle()['primary_color']);
        $this->assertSame(5, $policyService->homepageStyle()['hero_blur_px']);

        $this->get(route('suchak.home'))
            ->assertOk()
            ->assertSee('Suchak Growth Hub', false)
            ->assertSee('#dc2626', false);

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_id' => $admin->id,
            'action_type' => 'suchak_settings_update',
            'entity_type' => 'suchak_policy',
            'entity_id' => null,
        ]);

        $auditLog = AdminAuditLog::query()->latest('id')->firstOrFail();
        $this->assertStringContainsString('Day-22 settings update for launch pilot.', $auditLog->reason);
        $this->assertStringContainsString(SuchakPolicyService::KEY_REQUEST_ACTION_SLA_HOURS, $auditLog->reason);
    }

    public function test_invalid_suchak_settings_are_rejected_without_audit(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);

        $payload = array_merge($this->validPayload(), [
            'reason' => 'short',
            'request_action_sla_hours' => 0,
            'qr_token_expiry_days' => 366,
            'suchak_payment_mode' => 'unknown',
            'suchak_visit_confirmation_policy_mode' => 'unknown',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.suchak.settings.update'), $payload)
            ->assertRedirect()
            ->assertSessionHasErrors([
                'reason',
                'request_action_sla_hours',
                'qr_token_expiry_days',
                'suchak_payment_mode',
                'suchak_visit_confirmation_policy_mode',
            ]);

        $this->assertDatabaseHas('suchak_policies', [
            'policy_key' => SuchakPolicyService::KEY_REQUEST_ACTION_SLA_HOURS,
            'policy_value' => '48',
        ]);
        $this->assertDatabaseMissing('admin_audit_logs', [
            'action_type' => 'suchak_settings_update',
            'entity_type' => 'suchak_policy',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'reason' => 'Day-22 settings update for launch pilot.',
            'default_consent_validity_months' => 18,
            'allow_two_year_consent' => '0',
            'allow_until_revoked_consent' => '1',
            'request_action_sla_hours' => 72,
            'collaboration_sla_days' => 9,
            'pdf_download_limit_per_day' => 33,
            'qr_token_expiry_days' => 45,
            'suchak_upload_daily_limit' => 41,
            'suchak_active_profile_limit_by_plan' => 250,
            'suchak_free_trial_days' => 10,
            'suchak_grace_period_days' => 3,
            'suchak_plan_pricing_mode' => 'free_trial_then_manual',
            'suchak_payment_mode' => 'payu_test_mode',
            'suchak_allow_work_before_admin_approval' => '1',
            'suchak_auto_publish_on_approval' => '1',
            'suchak_hero_registration_form_enabled' => '0',
            'homepage_copy' => array_replace_recursive(SuchakPolicyService::DEFAULT_SUCHAK_HOMEPAGE_COPY, [
                'mr' => [
                    'title' => 'सूचक विकास केंद्र',
                    'primary_cta' => 'सूचक नोंदणी करा',
                ],
                'en' => [
                    'title' => 'Suchak Growth Hub',
                    'primary_cta' => 'Join as Suchak',
                ],
            ]),
            'homepage_benefits' => SuchakPolicyService::DEFAULT_SUCHAK_HOMEPAGE_COPY['benefits'],
            'homepage_process' => SuchakPolicyService::DEFAULT_SUCHAK_HOMEPAGE_COPY['process_steps'],
            'homepage_tools' => SuchakPolicyService::DEFAULT_SUCHAK_HOMEPAGE_COPY['tools'],
            'homepage_style' => array_replace(SuchakPolicyService::DEFAULT_SUCHAK_HOMEPAGE_STYLE, [
                'primary_color' => '#dc2626',
                'desktop_overlay_opacity' => 88,
                'mobile_overlay_opacity' => 91,
                'hero_blur_px' => 5,
                'bottom_fade_enabled' => '1',
                'form_shadow_enabled' => '0',
            ]),
            'suchak_work_area_min_consented_customers' => 6,
            'suchak_visit_confirmation_policy_mode' => 'admin_only',
            'commission_mode' => 'percentage',
            'commission_default_percent' => 15,
            'commission_default_amount' => 2500,
            'commission_require_ack' => '0',
        ];
    }
}
