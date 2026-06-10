<?php

namespace Tests\Feature\Suchak;

use App\Models\SuchakAccount;
use App\Models\SuchakPlan;
use App\Models\SuchakPlanFeature;
use App\Models\SuchakPolicy;
use App\Models\SuchakServicePackage;
use App\Models\SuchakSubscription;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAccessService;
use App\Modules\Suchak\Services\SuchakEntitlementService;
use App\Modules\Suchak\Services\SuchakLimitService;
use App\Modules\Suchak\Services\SuchakPaymentStatusService;
use App\Modules\Suchak\Services\SuchakPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SuchakCentralServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_access_service_centralizes_owner_status_and_public_route_decisions(): void
    {
        $service = app(SuchakAccessService::class);
        [$user, $verified] = $this->suchakAccount(SuchakAccount::VERIFICATION_VERIFIED, SuchakAccount::PUBLIC_ACTIVE);
        [, $pending] = $this->suchakAccount(SuchakAccount::VERIFICATION_PENDING, SuchakAccount::PUBLIC_HIDDEN);
        [, $suspended] = $this->suchakAccount(SuchakAccount::VERIFICATION_SUSPENDED, SuchakAccount::PUBLIC_HIDDEN);
        [, $archived] = $this->suchakAccount(SuchakAccount::VERIFICATION_ARCHIVED, SuchakAccount::PUBLIC_HIDDEN);

        $this->assertTrue($service->canOperate($verified));
        $this->assertTrue($service->canPubliclyRoute($verified));
        $this->assertFalse($service->canOperate($pending));
        $this->assertFalse($service->canOperate($suspended));
        $this->assertFalse($service->canOperate($archived));

        try {
            $service->assertOwnerCanOperate(
                $pending,
                $pending->user,
                'Owner mismatch.',
                'Suchak is not verified.',
            );
            $this->fail('Pending Suchak must be blocked by central access service.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Suchak is not verified.', $exception->getMessage());
        }

        try {
            $service->assertOwnerCanOperate(
                $verified,
                User::factory()->create(),
                'Owner mismatch.',
                'Suchak is not verified.',
            );
            $this->fail('Non-owner actor must be blocked by central access service.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Owner mismatch.', $exception->getMessage());
        }

        $this->assertTrue($service->canOwnerOperate($verified, $user));
    }

    public function test_policy_service_reads_active_typed_policies_with_safe_defaults(): void
    {
        SuchakPolicy::query()
            ->where('policy_key', SuchakPolicyService::KEY_REQUEST_ACTION_SLA_HOURS)
            ->update(['policy_value' => '72']);
        SuchakPolicy::query()
            ->where('policy_key', SuchakPolicyService::KEY_ALLOW_TWO_YEAR_CONSENT)
            ->update(['policy_value' => 'false']);
        SuchakPolicy::query()
            ->where('policy_key', SuchakPolicyService::KEY_QR_TOKEN_EXPIRY_DAYS)
            ->update(['policy_value' => 'not-an-int']);
        SuchakPolicy::query()
            ->where('policy_key', SuchakPolicyService::KEY_SUCHAK_FREE_TRIAL_DAYS)
            ->update(['policy_value' => '14']);
        SuchakPolicy::query()
            ->where('policy_key', SuchakPolicyService::KEY_SUCHAK_PLAN_PRICING_MODE)
            ->update(['policy_value' => 'paid_required']);
        SuchakPolicy::query()
            ->where('policy_key', SuchakPolicyService::KEY_SUCHAK_PAYMENT_MODE)
            ->update(['policy_value' => 'payu_test_mode']);
        SuchakPolicy::query()
            ->where('policy_key', SuchakPolicyService::KEY_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE)
            ->update(['policy_value' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH]);
        SuchakPolicy::query()
            ->where('policy_key', SuchakPolicyService::KEY_SUCHAK_COMMISSION_RULES_JSON)
            ->update([
                'policy_value' => json_encode([
                    'mode' => 'percentage',
                    'default_percent' => 12,
                    'default_amount' => 0,
                    'require_ack' => false,
                ], JSON_THROW_ON_ERROR),
            ]);

        $service = app(SuchakPolicyService::class);

        $this->assertSame(72, $service->requestActionSlaHours());
        $this->assertFalse($service->allowsTwoYearConsent());
        $this->assertSame(SuchakPolicyService::DEFAULT_QR_TOKEN_EXPIRY_DAYS, $service->qrTokenExpiryDays());
        $this->assertSame(14, $service->freeTrialDays());
        $this->assertSame('paid_required', $service->planPricingMode());
        $this->assertSame('payu_test_mode', $service->paymentMode());
        $this->assertSame(SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH, $service->packagePublishApprovalMode());
        $this->assertSame([
            'mode' => 'percentage',
            'default_percent' => 12,
            'default_amount' => 0,
            'require_ack' => false,
        ], $service->commissionRules());

        SuchakPolicy::query()
            ->where('policy_key', SuchakPolicyService::KEY_REQUEST_ACTION_SLA_HOURS)
            ->update(['is_active' => false]);

        $this->assertSame(SuchakPolicyService::DEFAULT_REQUEST_ACTION_SLA_HOURS, $service->requestActionSlaHours());
    }

    public function test_payment_entitlement_and_limit_services_resolve_current_suchak_plan_state(): void
    {
        [, $account] = $this->suchakAccount(SuchakAccount::VERIFICATION_VERIFIED, SuchakAccount::PUBLIC_ACTIVE);
        $plan = SuchakPlan::factory()->create([
            'is_active' => true,
            'is_visible' => true,
        ]);
        SuchakPlanFeature::factory()->create([
            'suchak_plan_id' => $plan->id,
            'feature_key' => SuchakPlanFeature::FEATURE_ACTIVE_PROFILE_LIMIT,
            'value_type' => SuchakPlanFeature::TYPE_INTEGER,
            'feature_value' => '125',
            'is_enabled' => true,
        ]);
        SuchakPlanFeature::factory()->create([
            'suchak_plan_id' => $plan->id,
            'feature_key' => SuchakPlanFeature::FEATURE_PDF_DOWNLOAD_SHARE_LIMIT,
            'value_type' => SuchakPlanFeature::TYPE_INTEGER,
            'feature_value' => '9',
            'is_enabled' => true,
        ]);
        SuchakSubscription::factory()->create([
            'suchak_account_id' => $account->id,
            'suchak_plan_id' => $plan->id,
            'status' => SuchakSubscription::STATUS_ACTIVE,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        $payment = app(SuchakPaymentStatusService::class);
        $entitlements = app(SuchakEntitlementService::class);
        $limits = app(SuchakLimitService::class);

        $status = $payment->statusFor($account);
        $this->assertSame(SuchakPaymentStatusService::STATUS_ACTIVE, $status['status']);
        $this->assertTrue($status['has_active_subscription']);

        $this->assertSame(125, $entitlements->currentFeatureValue($account, SuchakPlanFeature::FEATURE_ACTIVE_PROFILE_LIMIT));
        $this->assertSame(125, $limits->activeProfileLimit($account));
        $this->assertSame(9, $limits->pdfDownloadShareLimitPerDay($account));
        $this->assertSame(SuchakPolicyService::DEFAULT_SUCHAK_UPLOAD_DAILY_LIMIT, $limits->uploadDailyLimit());
    }

    public function test_payment_status_reports_pending_review_without_granting_entitlements(): void
    {
        [, $account] = $this->suchakAccount(SuchakAccount::VERIFICATION_VERIFIED, SuchakAccount::PUBLIC_ACTIVE);
        $plan = SuchakPlan::factory()->create(['is_active' => true]);
        SuchakPlanFeature::factory()->create([
            'suchak_plan_id' => $plan->id,
            'feature_key' => SuchakPlanFeature::FEATURE_ACTIVE_PROFILE_LIMIT,
            'value_type' => SuchakPlanFeature::TYPE_INTEGER,
            'feature_value' => '50',
            'is_enabled' => true,
        ]);
        SuchakSubscription::factory()->create([
            'suchak_account_id' => $account->id,
            'suchak_plan_id' => $plan->id,
            'status' => SuchakSubscription::STATUS_PENDING_ADMIN_REVIEW,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        $payment = app(SuchakPaymentStatusService::class);
        $entitlements = app(SuchakEntitlementService::class);

        $status = $payment->statusFor($account);
        $this->assertSame(SuchakSubscription::STATUS_PENDING_ADMIN_REVIEW, $status['status']);
        $this->assertFalse($status['has_active_subscription']);
        $this->assertSame([], $entitlements->currentFeatureLimits($account));
    }

    /**
     * @return array{0: User, 1: SuchakAccount}
     */
    private function suchakAccount(string $verificationStatus, string $publicStatus): array
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => $verificationStatus,
            'public_status' => $publicStatus,
            'verified_at' => $verificationStatus === SuchakAccount::VERIFICATION_VERIFIED ? now() : null,
        ]);

        return [$user, $account->fresh('user')];
    }
}
