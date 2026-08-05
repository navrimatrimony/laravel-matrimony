<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\Account\MemberAccountDeletionService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The member-facing half of account deletion, end to end over the API.
 *
 * Google Play requires the in-app path to actually work, so these exercise the
 * HTTP endpoints the app calls rather than the service directly.
 */
class MemberAccountDeletionFlowTest extends TestCase
{
    use RefreshDatabase;

    private function member(): User
    {
        $this->seed(MinimalLocationSeeder::class);
        $leaf = (int) City::query()->where('name', 'Pune City')->value('id');

        $user = User::factory()->create(['is_admin' => false, 'mobile' => '9111100001']);

        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'lifecycle_state' => 'draft',
            'is_showcase' => false,
            'location_id' => $leaf,
        ]);
        $profile->lifecycle_state = 'active';
        $profile->save();

        return $user->fresh('matrimonyProfile');
    }

    public function test_a_member_can_request_cancel_and_be_fully_restored(): void
    {
        $user = $this->member();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/account/deletion')
            ->assertOk()
            ->assertJsonPath('deletion.state', 'active');

        $this->postJson('/api/v1/account/deletion', [
            'confirmation' => 'delete',
            'reason_key' => 'privacy_concern',
        ])
            ->assertOk()
            ->assertJsonPath('deletion.state', 'deletion_pending')
            ->assertJsonPath('deletion.days_left', MemberAccountDeletionService::GRACE_DAYS);

        // Hidden immediately, but nothing erased yet — that is the whole point
        // of the grace period.
        $this->assertSame('archived', $user->fresh()->matrimonyProfile->lifecycle_state);
        $this->assertDatabaseHas('matrimony_profiles', ['id' => $user->matrimonyProfile->id, 'deleted_at' => null]);

        $this->deleteJson('/api/v1/account/deletion')
            ->assertOk()
            ->assertJsonPath('deletion.state', 'active');

        $user->refresh();
        $this->assertNull($user->deletion_requested_at);
        $this->assertSame('active', $user->fresh()->matrimonyProfile->lifecycle_state);
    }

    public function test_the_typed_confirmation_is_enforced_on_the_server(): void
    {
        $user = $this->member();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/account/deletion', [
            'confirmation' => 'yes',
            'reason_key' => 'other',
        ])->assertStatus(422);

        $this->assertNull($user->fresh()->deletion_requested_at);
        $this->assertSame('active', $user->fresh()->matrimonyProfile->lifecycle_state);
    }

    public function test_asking_twice_does_not_buy_another_thirty_days(): void
    {
        $user = $this->member();
        Sanctum::actingAs($user);

        $payload = ['confirmation' => 'delete', 'reason_key' => 'hard_to_use'];
        $this->postJson('/api/v1/account/deletion', $payload)->assertOk();
        $first = $user->fresh()->deletion_requested_at;

        $this->travel(2)->days();
        $this->postJson('/api/v1/account/deletion', $payload)->assertOk();

        $this->assertEquals(
            $first->toDateTimeString(),
            $user->fresh()->deletion_requested_at->toDateTimeString(),
            'a second tap must not restart the countdown'
        );
    }

    public function test_pausing_hides_the_profile_without_scheduling_anything(): void
    {
        $user = $this->member();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/account/pause')
            ->assertOk()
            ->assertJsonPath('deletion.state', 'paused');

        $this->assertNull($user->fresh()->deletion_requested_at);
        $this->assertSame('archived', $user->fresh()->matrimonyProfile->lifecycle_state);

        $this->postJson('/api/v1/account/resume')
            ->assertOk()
            ->assertJsonPath('deletion.state', 'active');
    }

    /**
     * The sweep materialises its list before iterating, so it must re-verify
     * each user under lock at purge time. This pins the observable half: a
     * cancellation that lands before the purge always wins.
     */
    public function test_a_cancelled_account_is_never_purged(): void
    {
        $user = $this->member();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/account/deletion', ['confirmation' => 'delete', 'reason_key' => 'other'])->assertOk();
        $this->travel(MemberAccountDeletionService::GRACE_DAYS + 1)->days();

        $this->deleteJson('/api/v1/account/deletion')->assertOk();

        $this->assertSame(
            ['purged' => 0, 'failed' => 0],
            app(MemberAccountDeletionService::class)->purgeDue(),
            'a cancel landing before the sweep must always win'
        );
        $this->assertNull(DB::table('users')->where('id', $user->id)->value('account_deleted_at'));
    }

    public function test_cancel_after_purge_is_a_refusing_noop(): void
    {
        $user = $this->member();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/account/deletion', ['confirmation' => 'delete', 'reason_key' => 'other'])->assertOk();
        $this->travel(MemberAccountDeletionService::GRACE_DAYS + 1)->days();
        app(MemberAccountDeletionService::class)->purgeDue();

        $this->assertNotNull(DB::table('users')->where('id', $user->id)->value('account_deleted_at'));

        // A request that authenticated just before the purge commit lands here.
        app(MemberAccountDeletionService::class)->cancelDeletion($user->fresh());

        $row = DB::table('users')->where('id', $user->id)->first();
        $this->assertNotNull($row->account_deleted_at, 'a tombstone must never be half-revived');
        $this->assertSame(
            'archived',
            DB::table('matrimony_profiles')->where('user_id', $user->id)->value('lifecycle_state'),
            'the tombstoned profile must not come back to active'
        );
    }

    public function test_the_sweep_erases_only_accounts_past_the_window(): void
    {
        $due = $this->member();
        Sanctum::actingAs($due);
        $this->postJson('/api/v1/account/deletion', ['confirmation' => 'delete', 'reason_key' => 'other'])->assertOk();

        $service = app(MemberAccountDeletionService::class);

        $this->travel(MemberAccountDeletionService::GRACE_DAYS - 1)->days();
        $this->assertSame(['purged' => 0, 'failed' => 0], $service->purgeDue(), 'day 29 must not erase anything');

        $this->travel(2)->days();
        $this->assertSame(['purged' => 1, 'failed' => 0], $service->purgeDue());

        $row = DB::table('users')->where('id', $due->id)->first();
        $this->assertNotNull($row->account_deleted_at);
        $this->assertNull($row->mobile, 'the number must be released for re-registration');
        $this->assertNull($row->deletion_requested_at, 'a finished deletion is no longer pending');
    }
}
