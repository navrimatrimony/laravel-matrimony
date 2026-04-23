<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\UserFeatureUsage;
use App\Services\EntitlementService;
use App\Services\FeatureUsageService;
use App\Services\InterestSendLimitService;
use App\Services\SubscriptionService;
use App\Services\UserFeatureUsageService;
use App\Support\PlanFeatureKeys;
use App\Support\UserFeatureUsageKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InterestSendLimitServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_blocks_after_daily_bucket_reaches_entitlement_limit(): void
    {
        $user = User::factory()->create();

        $entitlements = $this->createMock(EntitlementService::class);
        $entitlements->method('hasAccess')->willReturnCallback(
            fn (int $uid, string $key) => $key === PlanFeatureKeys::INTEREST_SEND_LIMIT
        );
        $entitlements->method('getValue')->willReturn('2');
        $entitlements->method('getValueOverride')->willReturnCallback(
            fn (int $uid, string $key) => $key === PlanFeatureKeys::INTEREST_SEND_LIMIT ? '2' : null
        );

        $subscriptions = $this->createMock(SubscriptionService::class);

        $usage = app(UserFeatureUsageService::class);
        $featureUsage = $this->createMock(FeatureUsageService::class);
        $featureUsage->method('shouldBypassUsageLimits')->willReturn(false);
        $svc = new InterestSendLimitService($entitlements, $usage, $subscriptions, $featureUsage);

        $svc->assertCanSend($user);
        $svc->recordSuccessfulSend($user);
        $svc->assertCanSend($user);
        $svc->recordSuccessfulSend($user);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage(__('interest.daily_limit_reached'));
        $svc->assertCanSend($user);
    }

    #[Test]
    public function it_uses_daily_period_for_interest_send_limit_usage_key(): void
    {
        $user = User::factory()->create();

        $entitlements = $this->createMock(EntitlementService::class);
        $entitlements->method('hasAccess')->willReturnCallback(
            fn (int $uid, string $key) => $key === PlanFeatureKeys::INTEREST_SEND_LIMIT
        );
        $entitlements->method('getValue')->willReturn('5');
        $entitlements->method('getValueOverride')->willReturnCallback(
            fn (int $uid, string $key) => $key === PlanFeatureKeys::INTEREST_SEND_LIMIT ? '5' : null
        );

        $subscriptions = $this->createMock(SubscriptionService::class);

        $usage = app(UserFeatureUsageService::class);
        $featureUsage = $this->createMock(FeatureUsageService::class);
        $featureUsage->method('shouldBypassUsageLimits')->willReturn(false);
        $svc = new InterestSendLimitService($entitlements, $usage, $subscriptions, $featureUsage);

        $svc->recordSuccessfulSend($user);

        $day = now()->startOfDay()->toDateString();
        $this->assertTrue(
            DB::table('user_feature_usages')
                ->where('user_id', $user->id)
                ->where('feature_key', UserFeatureUsageKeys::INTEREST_SEND_LIMIT)
                ->where('period', UserFeatureUsage::PERIOD_DAILY)
                ->whereDate('period_start', $day)
                ->whereDate('period_end', $day)
                ->where('used_count', 1)
                ->exists(),
            'Expected daily interest_send_limit bucket row on user_feature_usages'
        );
    }
}
