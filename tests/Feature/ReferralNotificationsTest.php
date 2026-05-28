<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Models\UserReferral;
use App\Services\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReferralNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'referral.enabled' => true,
            'referral.rewards_by_plan_slug' => ['gold_notify' => 3],
        ]);
    }

    public function test_referrer_gets_notification_when_invitee_registers(): void
    {
        $referrer = User::factory()->create(['referral_code' => 'REFNOTIF1']);
        $invitee = User::factory()->create(['name' => 'New Invitee']);

        app(ReferralService::class)->recordReferralIfEligible($invitee, 'REFNOTIF1');

        $row = DB::table('notifications')
            ->where('notifiable_id', $referrer->id)
            ->where('notifiable_type', User::class)
            ->latest('id')
            ->first();

        $this->assertNotNull($row);
        $data = json_decode((string) $row->data, true);
        $this->assertSame('referral_invite_registered', $data['type'] ?? null);
    }

    public function test_referrer_gets_pending_notification_when_reward_queued(): void
    {
        $referrer = User::factory()->create(['referral_code' => 'REFNOTIF2']);
        $buyer = User::factory()->create(['name' => 'Buyer Person']);
        $plan = Plan::query()->create([
            'name' => 'Gold Notify',
            'slug' => 'gold_notify',
            'price' => 500,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => false,
        ]);

        app(ReferralService::class)->applyPurchaseRewardIfEligible($buyer, $plan);

        $types = DB::table('notifications')
            ->where('notifiable_id', $referrer->id)
            ->pluck('data')
            ->map(fn ($json) => json_decode((string) $json, true)['type'] ?? null)
            ->all();

        $this->assertContains('referral_invite_upgraded', $types);
        $this->assertContains('referral_reward_pending', $types);
    }
}
