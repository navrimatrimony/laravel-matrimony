<?php

namespace Tests\Unit;

use App\Models\PlanQuotaPolicy;
use App\Support\PlanFeatureKeys;
use App\Support\PlanFeatureLabel;
use App\Support\PlanQuotaCatalogFormatter;
use Tests\TestCase;

class PlanQuotaCatalogFormatterTest extends TestCase
{
    public function test_interest_view_limit_lifetime_shows_total_not_meta(): void
    {
        $payload = [
            'is_enabled' => true,
            'refresh_type' => PlanQuotaPolicy::REFRESH_LIFETIME,
            'limit_value' => 10,
            'daily_sub_cap' => null,
            'per_day_usage_limit_enabled' => false,
            'policy_meta' => ['interest_view_reset_period' => 'monthly'],
        ];
        $line = PlanQuotaCatalogFormatter::catalogLineFromPayload(PlanFeatureKeys::INTEREST_VIEW_LIMIT, $payload, 0, null);

        $expected = PlanFeatureLabel::catalogLabelForPricing(PlanFeatureKeys::INTEREST_VIEW_LIMIT, $payload)
            .' — '
            .__('subscriptions.quota_line_total', ['count' => '10']);
        $this->assertSame($expected, $line);
    }

    public function test_interest_view_limit_monthly_refresh_ignores_stale_meta(): void
    {
        $payload = [
            'is_enabled' => true,
            'refresh_type' => PlanQuotaPolicy::REFRESH_MONTHLY_30D_IST,
            'limit_value' => 12,
            'daily_sub_cap' => null,
            'per_day_usage_limit_enabled' => false,
            'policy_meta' => ['interest_view_reset_period' => 'weekly'],
        ];
        $line = PlanQuotaCatalogFormatter::catalogLineFromPayload(PlanFeatureKeys::INTEREST_VIEW_LIMIT, $payload, 0, null);

        $expected = PlanFeatureLabel::catalogLabelForPricing(PlanFeatureKeys::INTEREST_VIEW_LIMIT, $payload)
            .' — '
            .__('subscriptions.quota_line_per_month', ['count' => '12']);
        $this->assertSame($expected, $line);
    }

    public function test_quota_bonus_applies_to_daily_weekly_monthly_catalog_limits_only(): void
    {
        $dailyChat = [
            'is_enabled' => true,
            'refresh_type' => PlanQuotaPolicy::REFRESH_DAILY,
            'limit_value' => 20,
            'daily_sub_cap' => null,
            'per_day_usage_limit_enabled' => false,
            'policy_meta' => [],
        ];
        $weeklyMediator = [
            'is_enabled' => true,
            'refresh_type' => PlanQuotaPolicy::REFRESH_WEEKLY,
            'limit_value' => 100,
            'daily_sub_cap' => null,
            'per_day_usage_limit_enabled' => false,
            'policy_meta' => [],
        ];
        $lifetimeInterest = [
            'is_enabled' => true,
            'refresh_type' => PlanQuotaPolicy::REFRESH_LIFETIME,
            'limit_value' => 10,
            'daily_sub_cap' => null,
            'per_day_usage_limit_enabled' => false,
            'policy_meta' => [],
        ];

        $this->assertSame(
            '22'.__('subscriptions.quota_line_chat_suffix_per_day'),
            PlanQuotaCatalogFormatter::quotaValueLineOnlyFromPayload(PlanFeatureKeys::CHAT_SEND_LIMIT, $dailyChat, 10, 'half_yearly')
        );
        $this->assertSame(
            __('subscriptions.quota_line_per_week', ['count' => '110']),
            PlanQuotaCatalogFormatter::quotaValueLineOnlyFromPayload(PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH, $weeklyMediator, 10, 'half_yearly')
        );
        $this->assertSame(
            __('subscriptions.quota_line_total', ['count' => '10']),
            PlanQuotaCatalogFormatter::quotaValueLineOnlyFromPayload(PlanFeatureKeys::INTEREST_VIEW_LIMIT, $lifetimeInterest, 10, 'half_yearly')
        );
    }

    public function test_quota_bonus_keeps_unlimited_and_zero_catalog_limits_unchanged(): void
    {
        $unlimited = [
            'is_enabled' => true,
            'refresh_type' => PlanQuotaPolicy::REFRESH_UNLIMITED,
            'limit_value' => null,
            'daily_sub_cap' => null,
            'per_day_usage_limit_enabled' => false,
            'policy_meta' => [],
        ];
        $zero = [
            'is_enabled' => true,
            'refresh_type' => PlanQuotaPolicy::REFRESH_DAILY,
            'limit_value' => 0,
            'daily_sub_cap' => null,
            'per_day_usage_limit_enabled' => false,
            'policy_meta' => [],
        ];

        $this->assertSame(
            __('subscriptions.unlimited'),
            PlanQuotaCatalogFormatter::quotaValueLineOnlyFromPayload(PlanFeatureKeys::CHAT_SEND_LIMIT, $unlimited, 10, 'half_yearly')
        );
        $this->assertSame(
            __('subscriptions.quota_line_per_day', ['count' => '0']),
            PlanQuotaCatalogFormatter::quotaValueLineOnlyFromPayload(PlanFeatureKeys::CONTACT_VIEW_LIMIT, $zero, 10, 'half_yearly')
        );
    }
}
