<?php

namespace Tests\Unit;

use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\InterestSendLimitService;
use Database\Seeders\PlanStandardFeatureKeysSeeder;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InterestIncomingViewLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_n_pending_in_window_unlock_rest_locked(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $receiver = User::factory()->create(['is_admin' => false]);
        $receiverProfile = MatrimonyProfile::factory()->for($receiver)->create(['lifecycle_state' => 'active']);

        $senders = [];
        for ($i = 0; $i < 4; $i++) {
            $u = User::factory()->create();
            $senders[] = MatrimonyProfile::factory()->for($u)->create(['lifecycle_state' => 'active']);
        }

        foreach ($senders as $sp) {
            Interest::query()->create([
                'sender_profile_id' => $sp->id,
                'receiver_profile_id' => $receiverProfile->id,
                'status' => 'pending',
                'priority_score' => 1,
            ]);
        }

        $svc = app(InterestSendLimitService::class);
        $list = Interest::query()
            ->where('receiver_profile_id', $receiverProfile->id)
            ->receivedInboxOrder()
            ->get();

        $map = $svc->incomingInterestUnlockMap($receiver->fresh(), $list);

        // Free plan: interest_view_limit = 3 → three pending unlock, one locked
        $this->assertCount(4, $map);
        $this->assertSame(3, collect($map)->filter(fn (bool $v) => $v)->count());
        $this->assertSame(1, collect($map)->filter(fn (bool $v) => ! $v)->count());
    }

    public function test_accepted_interest_always_unlocked_in_map(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $receiver = User::factory()->create(['is_admin' => false]);
        $receiverProfile = MatrimonyProfile::factory()->for($receiver)->create(['lifecycle_state' => 'active']);
        $sender = MatrimonyProfile::factory()->for(User::factory()->create())->create(['lifecycle_state' => 'active']);

        $interest = Interest::query()->create([
            'sender_profile_id' => $sender->id,
            'receiver_profile_id' => $receiverProfile->id,
            'status' => 'accepted',
            'priority_score' => 1,
        ]);

        $svc = app(InterestSendLimitService::class);
        $map = $svc->incomingInterestUnlockMap($receiver->fresh(), collect([$interest]));
        $this->assertTrue($map[(int) $interest->id]);
    }
}
