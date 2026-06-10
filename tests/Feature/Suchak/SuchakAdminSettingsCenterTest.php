<?php

namespace Tests\Feature\Suchak;

use App\Models\AdminAuditLog;
use App\Models\SuchakPolicy;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuchakAdminSettingsCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_suchak_settings_center(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.suchak.settings.index'))
            ->assertOk()
            ->assertSee('Suchak Settings Center', false)
            ->assertSee('Platform payment mode', false)
            ->assertSee('Commission Rules', false)
            ->assertSee(route('admin.suchak.settings.update'), false);
    }

    public function test_admin_can_update_suchak_settings_with_audit_and_service_readback(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('admin.suchak.settings.update'), $this->validPayload())
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
        $admin = User::factory()->create(['is_admin' => true]);

        $payload = array_merge($this->validPayload(), [
            'reason' => 'short',
            'request_action_sla_hours' => 0,
            'qr_token_expiry_days' => 366,
            'suchak_payment_mode' => 'unknown',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.suchak.settings.update'), $payload)
            ->assertRedirect()
            ->assertSessionHasErrors([
                'reason',
                'request_action_sla_hours',
                'qr_token_expiry_days',
                'suchak_payment_mode',
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
            'commission_mode' => 'percentage',
            'commission_default_percent' => 15,
            'commission_default_amount' => 2500,
            'commission_require_ack' => '0',
        ];
    }
}
