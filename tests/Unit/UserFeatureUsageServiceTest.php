<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\UserFeatureUsage;
use App\Services\UserFeatureUsageService;
use App\Support\UserFeatureUsageKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UserFeatureUsageServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_increment_and_get_usage_monthly_bucket(): void
    {
        $user = User::factory()->create();
        $svc = app(UserFeatureUsageService::class);

        $this->assertSame(0, $svc->getUsage($user->id, UserFeatureUsageKeys::CONTACT_VIEW_LIMIT));

        $this->assertSame(1, $svc->incrementUsage($user->id, UserFeatureUsageKeys::CONTACT_VIEW_LIMIT));
        $this->assertSame(1, $svc->getUsage($user->id, UserFeatureUsageKeys::CONTACT_VIEW_LIMIT));

        $this->assertSame(3, $svc->incrementUsage($user->id, UserFeatureUsageKeys::CONTACT_VIEW_LIMIT, 2));
        $this->assertSame(3, $svc->getUsage($user->id, UserFeatureUsageKeys::CONTACT_VIEW_LIMIT));

        $this->assertSame(1, UserFeatureUsage::query()->count());
    }

    public function test_monthly_reset_uses_new_row_next_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00', config('app.timezone')));

        $user = User::factory()->create();
        $svc = app(UserFeatureUsageService::class);
        $svc->incrementUsage($user->id, UserFeatureUsageKeys::MEDIATOR_REQUEST);

        Carbon::setTestNow(Carbon::parse('2026-04-02 12:00:00', config('app.timezone')));

        $this->assertSame(0, $svc->getUsage($user->id, UserFeatureUsageKeys::MEDIATOR_REQUEST));
        $svc->incrementUsage($user->id, UserFeatureUsageKeys::MEDIATOR_REQUEST);
        $this->assertSame(1, $svc->getUsage($user->id, UserFeatureUsageKeys::MEDIATOR_REQUEST));

        $this->assertSame(2, UserFeatureUsage::query()->count());

        Carbon::setTestNow();
    }

    public function test_daily_period_bucket(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-10 18:00:00', config('app.timezone')));

        $user = User::factory()->create();
        $svc = app(UserFeatureUsageService::class);

        $svc->incrementUsage($user->id, UserFeatureUsageKeys::CONTACT_VIEW_LIMIT, 1, UserFeatureUsage::PERIOD_DAILY);
        $this->assertSame(1, $svc->getUsage($user->id, UserFeatureUsageKeys::CONTACT_VIEW_LIMIT, UserFeatureUsage::PERIOD_DAILY));

        Carbon::setTestNow(Carbon::parse('2026-05-11 08:00:00', config('app.timezone')));
        $this->assertSame(0, $svc->getUsage($user->id, UserFeatureUsageKeys::CONTACT_VIEW_LIMIT, UserFeatureUsage::PERIOD_DAILY));

        Carbon::setTestNow();
    }

    public function test_increment_persists_period_end(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-15 12:00:00', config('app.timezone')));

        $user = User::factory()->create();
        $svc = app(UserFeatureUsageService::class);
        $svc->incrementUsage($user->id, UserFeatureUsageKeys::CONTACT_VIEW_LIMIT);

        $row = UserFeatureUsage::query()->firstOrFail();
        $this->assertSame('2026-04-01', $row->period_start->format('Y-m-d'));
        $this->assertSame('2026-04-30', $row->period_end->format('Y-m-d'));

        Carbon::setTestNow(Carbon::parse('2026-05-10', config('app.timezone')));
        $svc->incrementUsage($user->id, UserFeatureUsageKeys::CONTACT_VIEW_LIMIT, 1, UserFeatureUsage::PERIOD_DAILY);
        $daily = UserFeatureUsage::query()
            ->where('feature_key', UserFeatureUsageKeys::CONTACT_VIEW_LIMIT)
            ->whereColumn('period_start', 'period_end')
            ->firstOrFail();
        $this->assertSame('2026-05-10', $daily->period_start->format('Y-m-d'));
        $this->assertSame('2026-05-10', $daily->period_end->format('Y-m-d'));

        Carbon::setTestNow();
    }
}
