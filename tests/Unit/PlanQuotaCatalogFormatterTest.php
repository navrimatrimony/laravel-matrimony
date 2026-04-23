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
        $line = PlanQuotaCatalogFormatter::catalogLineFromPayload(PlanFeatureKeys::INTEREST_VIEW_LIMIT, $payload, 1.0, null);

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
        $line = PlanQuotaCatalogFormatter::catalogLineFromPayload(PlanFeatureKeys::INTEREST_VIEW_LIMIT, $payload, 1.0, null);

        $expected = PlanFeatureLabel::catalogLabelForPricing(PlanFeatureKeys::INTEREST_VIEW_LIMIT, $payload)
            .' — '
            .__('subscriptions.quota_line_per_month', ['count' => '12']);
        $this->assertSame($expected, $line);
    }
}
