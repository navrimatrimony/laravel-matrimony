<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\User;
use App\Models\UserReferral;
use App\Services\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralMonthlyCapProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['referral.enabled' => true]);
    }

    public function test_monthly_cap_progress_null_when_cap_disabled(): void
    {
        AdminSetting::query()->updateOrCreate(
            ['key' => 'referral_engine_monthly_cap_per_referrer'],
            ['value' => '0'],
        );

        $referrer = User::factory()->create(['referral_code' => 'CAPTEST01']);

        $this->assertNull(app(ReferralService::class)->monthlyCapProgressForReferrer($referrer));
    }

    public function test_monthly_cap_progress_counts_rewards_this_month(): void
    {
        AdminSetting::query()->updateOrCreate(
            ['key' => 'referral_engine_monthly_cap_per_referrer'],
            ['value' => '5'],
        );

        $referrer = User::factory()->create(['referral_code' => 'CAPTEST02']);
        $buyer = User::factory()->create();

        UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => true,
            'reward_status' => UserReferral::STATUS_APPLIED,
            'updated_at' => now(),
        ]);

        $progress = app(ReferralService::class)->monthlyCapProgressForReferrer($referrer);

        $this->assertNotNull($progress);
        $this->assertSame(5, $progress['cap']);
        $this->assertSame(1, $progress['earned']);
        $this->assertSame(4, $progress['remaining']);
        $this->assertFalse($progress['at_cap']);
    }

    public function test_referrals_page_shows_monthly_cap_progress(): void
    {
        AdminSetting::query()->updateOrCreate(
            ['key' => 'referral_engine_monthly_cap_per_referrer'],
            ['value' => '3'],
        );

        $referrer = User::factory()->create(['referral_code' => 'CAPTEST03']);

        $this->actingAs($referrer)
            ->get(route('referrals.index'))
            ->assertOk()
            ->assertSee(__('referrals.monthly_cap_progress', ['earned' => 0, 'cap' => 3]), false);
    }
}
