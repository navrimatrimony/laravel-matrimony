<?php

namespace Tests\Unit;

use App\Support\PlanFeatureKeys;
use App\Support\PlanFeatureLabel;
use Tests\TestCase;

class PlanFeatureLabelCatalogTest extends TestCase
{
    public function test_scales_contact_pool_for_quarterly_vs_monthly_baseline(): void
    {
        $out = PlanFeatureLabel::catalogFormatValue(
            PlanFeatureKeys::CONTACT_VIEW_LIMIT,
            '30',
            3.0,
            'quarterly'
        );
        $this->assertSame('90', $out);
    }

    public function test_daily_chat_send_not_scaled(): void
    {
        $out = PlanFeatureLabel::catalogFormatValue(
            'chat_send_limit',
            '150',
            3.0,
            'quarterly'
        );
        $this->assertSame('150/day', $out);
    }

    public function test_interest_view_reset_shows_quarter_when_billing_quarterly(): void
    {
        $out = PlanFeatureLabel::catalogFormatValue(
            PlanFeatureKeys::INTEREST_VIEW_RESET_PERIOD,
            'monthly',
            1.0,
            'quarterly'
        );
        $this->assertSame(__('interests.period_quarterly'), $out);
    }

    public function test_weekly_reset_not_overridden_by_quarterly_billing(): void
    {
        $out = PlanFeatureLabel::catalogFormatValue(
            PlanFeatureKeys::INTEREST_VIEW_RESET_PERIOD,
            'weekly',
            3.0,
            'quarterly'
        );
        $this->assertSame(__('interests.period_weekly'), $out);
    }
}
