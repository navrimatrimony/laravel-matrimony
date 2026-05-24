<?php

namespace Tests\Unit;

use App\Models\City;
use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\InterestSendLimitService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MasterLookupSeeder;
use Database\Seeders\MinimalLocationSeeder;
use Database\Seeders\PlanStandardFeatureKeysSeeder;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InterestIncomingViewLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MinimalLocationSeeder::class);
        $this->seed(MasterLookupSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    /**
     * @param  array<string, mixed>  $factoryAttributes
     */
    private function createActiveProfileWithResidence(User $user, array $factoryAttributes = []): MatrimonyProfile
    {
        $p = MatrimonyProfile::factory()->for($user)->create(array_merge([
            'lifecycle_state' => 'draft',
        ], $factoryAttributes));
        $tbl = $p->getTable();
        $leafId = (int) City::query()->where('name', 'Pune City')->firstOrFail()->id;
        if (Schema::hasColumn($tbl, 'location_id')) {
            DB::table($tbl)->where('id', $p->id)->update(['location_id' => $leafId]);
            $p->refresh();
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $p->id, $leafId, null, true, false);
        }
        $p->update([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        return $p->fresh();
    }

    public function test_first_n_pending_in_window_unlock_rest_locked(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $receiver = User::factory()->create(['is_admin' => false]);
        $receiverProfile = $this->createActiveProfileWithResidence($receiver);

        $senders = [];
        for ($i = 0; $i < 4; $i++) {
            $u = User::factory()->create();
            $senders[] = $this->createActiveProfileWithResidence($u);
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
        $receiverProfile = $this->createActiveProfileWithResidence($receiver);
        $sender = $this->createActiveProfileWithResidence(User::factory()->create());

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
