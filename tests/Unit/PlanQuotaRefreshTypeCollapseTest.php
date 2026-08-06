<?php

namespace Tests\Unit;

use App\Models\PlanQuotaPolicy;
use App\Support\PlanFeatureKeys;
use App\Support\PlanQuotaCatalogFormatter;
use App\Support\PlanQuotaLimitCalculator;
use App\Support\PlanQuotaRefreshRuntime;
use Tests\TestCase;

class PlanQuotaRefreshTypeCollapseTest extends TestCase
{
    public function test_normalize_collapses_total_and_plan_duration_to_lifetime(): void
    {
        $this->assertSame(
            PlanQuotaPolicy::REFRESH_LIFETIME,
            PlanQuotaPolicy::normalizeRefreshType(PlanQuotaPolicy::REFRESH_TOTAL)
        );
        $this->assertSame(
            PlanQuotaPolicy::REFRESH_LIFETIME,
            PlanQuotaPolicy::normalizeRefreshType(PlanQuotaPolicy::REFRESH_PLAN_DURATION)
        );
        $this->assertSame(
            PlanQuotaPolicy::REFRESH_LIFETIME,
            PlanQuotaPolicy::normalizeRefreshType('TOTAL')
        );
        $this->assertSame(
            PlanQuotaPolicy::REFRESH_LIFETIME,
            PlanQuotaRefreshRuntime::normalizeRefreshTypeString('plan_duration')
        );
        $this->assertSame(
            PlanQuotaPolicy::REFRESH_LIFETIME,
            PlanQuotaPolicy::normalizeRefreshType(PlanQuotaPolicy::REFRESH_LIFETIME)
        );
    }

    public function test_admin_refresh_types_exclude_total_and_plan_duration(): void
    {
        $types = PlanQuotaPolicy::refreshTypes();

        $this->assertContains(PlanQuotaPolicy::REFRESH_LIFETIME, $types);
        $this->assertNotContains(PlanQuotaPolicy::REFRESH_TOTAL, $types);
        $this->assertNotContains(PlanQuotaPolicy::REFRESH_PLAN_DURATION, $types);
    }

    public function test_calculator_treats_legacy_aliases_as_lifetime_plan_period(): void
    {
        foreach ([
            PlanQuotaPolicy::REFRESH_LIFETIME,
            PlanQuotaPolicy::REFRESH_TOTAL,
            PlanQuotaPolicy::REFRESH_PLAN_DURATION,
        ] as $refresh) {
            $this->assertTrue(PlanQuotaLimitCalculator::isPlanPeriodRefreshType($refresh));
            $this->assertSame(
                330,
                PlanQuotaLimitCalculator::effectiveLimit(50, $refresh, 10, 6.0)
            );
        }
    }

    public function test_interest_reset_token_and_catalog_line_via_aliases(): void
    {
        foreach ([
            PlanQuotaPolicy::REFRESH_TOTAL,
            PlanQuotaPolicy::REFRESH_PLAN_DURATION,
            PlanQuotaPolicy::REFRESH_LIFETIME,
        ] as $refresh) {
            $payload = [
                'is_enabled' => true,
                'refresh_type' => $refresh,
                'limit_value' => 50,
                'daily_sub_cap' => null,
                'per_day_usage_limit_enabled' => false,
                'policy_meta' => [],
            ];
            $this->assertSame(
                'lifetime',
                PlanQuotaRefreshRuntime::interestViewResetPeriodTokenFromPayload($payload)
            );
            $this->assertSame(
                __('subscriptions.quota_line_total', ['count' => '330']),
                PlanQuotaCatalogFormatter::quotaValueLineOnlyFromPayload(
                    PlanFeatureKeys::INTEREST_VIEW_LIMIT,
                    $payload,
                    10,
                    'half_yearly',
                    6.0
                )
            );
        }
    }
}
