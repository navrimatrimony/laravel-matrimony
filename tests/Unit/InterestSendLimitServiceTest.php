<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\UserFeatureUsage;
use App\Services\EntitlementService;
use App\Services\InterestSendLimitService;
use App\Services\SubscriptionService;
use App\Services\UserFeatureUsageService;
use App\Support\PlanFeatureKeys;
use App\Support\UserFeatureUsageKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $subscriptions = $this->createMock(SubscriptionService::class);

        $usage = app(UserFeatureUsageService::class);
        $svc = new InterestSendLimitService($entitlements, $usage, $subscriptions);

        $svc->assertCanSend($user);
        $svc->recordSuccessfulSend($user);
        $svc->assertCanSend($user);
        $svc->recordSuccessfulSend($user);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage(__('interest.daily_limit_reached'));
        $svc->assertCanSend($user);
    }

    #[Test]
    public function it_uses_daily_period_for_interest_send_usage_key(): void
    {
        $user = User::factory()->create();

        $entitlements = $this->createMock(EntitlementService::class);
        $entitlements->method('hasAccess')->willReturnCallback(
            fn (int $uid, string $key) => $key === PlanFeatureKeys::INTEREST_SEND_LIMIT
        );
        $entitlements->method('getValue')->willReturn('5');

        $subscriptions = $this->createMock(SubscriptionService::class);

        $usage = app(UserFeatureUsageService::class);
        $svc = new InterestSendLimitService($entitlements, $usage, $subscriptions);

        $svc->recordSuccessfulSend($user);

        $this->assertDatabaseHas('user_feature_usage', [
            'user_id' => $user->id,
            'feature_key' => UserFeatureUsageKeys::INTEREST_SEND,
            'period' => UserFeatureUsage::PERIOD_DAILY,
            'used_count' => 1,
        ]);
    }
}
