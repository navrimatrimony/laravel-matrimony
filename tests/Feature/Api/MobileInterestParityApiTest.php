<?php

namespace Tests\Feature\Api;

use App\Models\Block;
use App\Models\City;
use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Notifications\InterestAcceptedNotification;
use App\Notifications\InterestSentNotification;
use App\Services\InterestSendLimitService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MasterLookupSeeder;
use Database\Seeders\MinimalLocationSeeder;
use Database\Seeders\PlanStandardFeatureKeysSeeder;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The mobile interest endpoints must behave exactly like the website's.
 *
 * Before InterestActionService these two surfaces had drifted: the API sent no notification at all
 * (so an interest from the app looked ignored — "nobody ever replies"), skipped the block check,
 * skipped the paid reveal gate on accept, and never granted contact visibility on accept.
 * Every case below fails against that older API controller.
 */
class MobileInterestParityApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MinimalLocationSeeder::class);
        $this->seed(MasterLookupSeeder::class);
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    /**
     * @param  array<string, mixed>  $factoryAttributes
     */
    private function activeProfile(User $user, array $factoryAttributes = []): MatrimonyProfile
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

    /**
     * @return array{0: User, 1: MatrimonyProfile}
     */
    private function member(array $profileAttributes = []): array
    {
        $user = User::factory()->create(['is_admin' => false]);

        return [$user, $this->activeProfile($user, $profileAttributes)];
    }

    private function pendingInterest(MatrimonyProfile $sender, MatrimonyProfile $receiver): Interest
    {
        return Interest::query()->create([
            'sender_profile_id' => $sender->id,
            'receiver_profile_id' => $receiver->id,
            'status' => 'pending',
            'priority_score' => 1,
        ]);
    }

    // -------------------------------------------------------------------------
    // Gap 1 — the app never notified anybody
    // -------------------------------------------------------------------------

    public function test_sending_an_interest_from_the_app_notifies_the_receiver(): void
    {
        Notification::fake();

        [$sender] = $this->member();
        [$receiverUser, $receiverProfile] = $this->member();

        Sanctum::actingAs($sender);

        $this->postJson('/api/v1/interests', ['receiver_profile_id' => $receiverProfile->id])
            ->assertOk()
            ->assertJsonPath('success', true);

        Notification::assertSentTo($receiverUser, InterestSentNotification::class);
    }

    public function test_a_repeat_send_from_the_app_does_not_notify_again(): void
    {
        [$sender, $senderProfile] = $this->member();
        [, $receiverProfile] = $this->member();

        $this->pendingInterest($senderProfile, $receiverProfile);

        Notification::fake();
        Sanctum::actingAs($sender);

        $this->postJson('/api/v1/interests', ['receiver_profile_id' => $receiverProfile->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'INTEREST_DUPLICATE');

        Notification::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Gap 2 — no block check on the app surface
    // -------------------------------------------------------------------------

    public function test_a_blocked_member_cannot_send_an_interest_from_the_app(): void
    {
        Notification::fake();

        [$sender, $senderProfile] = $this->member();
        [, $receiverProfile] = $this->member();

        // Receiver blocked the sender.
        Block::query()->create([
            'blocker_profile_id' => $receiverProfile->id,
            'blocked_profile_id' => $senderProfile->id,
        ]);

        Sanctum::actingAs($sender);

        $this->postJson('/api/v1/interests', ['receiver_profile_id' => $receiverProfile->id])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'INTEREST_BLOCKED')
            // Localized from lang/mr + lang/en — never a bare English string.
            ->assertJsonPath('message', __('interest.cannot_send_to_profile'));

        $this->assertDatabaseCount('interests', 0);
        Notification::assertNothingSent();
    }

    public function test_a_member_cannot_send_an_interest_to_someone_they_blocked(): void
    {
        [$sender, $senderProfile] = $this->member();
        [, $receiverProfile] = $this->member();

        // Sender blocked the receiver — the other direction of the same rule.
        Block::query()->create([
            'blocker_profile_id' => $senderProfile->id,
            'blocked_profile_id' => $receiverProfile->id,
        ]);

        Sanctum::actingAs($sender);

        $this->postJson('/api/v1/interests', ['receiver_profile_id' => $receiverProfile->id])
            ->assertStatus(403)
            ->assertJsonPath('code', 'INTEREST_SENDER_BLOCKED')
            ->assertJsonPath('message', __('interest.blocked_unblock_to_send'));

        $this->assertDatabaseCount('interests', 0);
    }

    // -------------------------------------------------------------------------
    // Gap 3 — accept did not unlock contact
    // -------------------------------------------------------------------------

    public function test_accepting_from_the_app_grants_contact_visibility(): void
    {
        Notification::fake();

        [$senderUser, $senderProfile] = $this->member();
        [$receiverUser, $receiverProfile] = $this->member([
            'contact_unlock_mode' => 'after_interest_accepted',
        ]);

        $interest = $this->pendingInterest($senderProfile, $receiverProfile);

        Sanctum::actingAs($receiverUser);

        $this->postJson("/api/v1/interests/{$interest->id}/accept")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('accepted', $interest->fresh()->status);

        $this->assertDatabaseHas('profile_contact_visibility', [
            'owner_profile_id' => $receiverProfile->id,
            'viewer_profile_id' => $senderProfile->id,
            'granted_via' => 'interest_accept',
            'revoked_at' => null,
        ]);
        $this->assertDatabaseHas('contact_access_log', [
            'owner_profile_id' => $receiverProfile->id,
            'viewer_profile_id' => $senderProfile->id,
            'source' => 'interest',
        ]);

        Notification::assertSentTo($senderUser, InterestAcceptedNotification::class);
    }

    // -------------------------------------------------------------------------
    // Gap 4 — no paid reveal gate on accept
    // -------------------------------------------------------------------------

    public function test_accepting_an_unrevealed_interest_from_the_app_is_refused(): void
    {
        [$receiverUser, $receiverProfile] = $this->member();

        // Free plan reveals the first 3 pending interests per window; the 4th stays locked.
        $interests = [];
        for ($i = 0; $i < 4; $i++) {
            [, $senderProfile] = $this->member();
            $interests[] = $this->pendingInterest($senderProfile, $receiverProfile);
        }

        $unlockMap = app(InterestSendLimitService::class)
            ->incomingInterestUnlockMap($receiverUser->fresh(), collect($interests));

        $locked = collect($interests)->first(fn (Interest $i) => ($unlockMap[(int) $i->id] ?? true) === false);
        $this->assertNotNull($locked, 'Expected the free plan to leave one incoming interest unrevealed.');

        Sanctum::actingAs($receiverUser);

        $this->postJson("/api/v1/interests/{$locked->id}/accept")
            ->assertStatus(403)
            ->assertJsonPath('code', 'INTEREST_ACCEPT_NEEDS_REVEAL')
            ->assertJsonPath('message', __('interests.accept_reject_requires_reveal'));

        $this->assertSame('pending', $locked->fresh()->status);
    }

    public function test_rejecting_an_unrevealed_interest_from_the_app_is_still_allowed(): void
    {
        [$receiverUser, $receiverProfile] = $this->member();

        $interests = [];
        for ($i = 0; $i < 4; $i++) {
            [, $senderProfile] = $this->member();
            $interests[] = $this->pendingInterest($senderProfile, $receiverProfile);
        }

        $unlockMap = app(InterestSendLimitService::class)
            ->incomingInterestUnlockMap($receiverUser->fresh(), collect($interests));

        $locked = collect($interests)->first(fn (Interest $i) => ($unlockMap[(int) $i->id] ?? true) === false);
        $this->assertNotNull($locked);

        Sanctum::actingAs($receiverUser);

        $this->postJson("/api/v1/interests/{$locked->id}/reject")->assertOk();

        $this->assertSame('rejected', $locked->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // Parity — same action, same resulting state, whichever surface ran it
    // -------------------------------------------------------------------------

    public function test_sending_through_web_and_app_produces_the_same_state(): void
    {
        Notification::fake();

        // Web surface
        [$webSender] = $this->member();
        [$webReceiverUser, $webReceiverProfile] = $this->member();

        $this->actingAs($webSender)
            ->from(route('interests.index'))
            ->post(route('interests.send', ['matrimony_profile_id' => $webReceiverProfile->id]))
            ->assertRedirect();

        // App surface
        [$apiSender] = $this->member();
        [$apiReceiverUser, $apiReceiverProfile] = $this->member();

        Sanctum::actingAs($apiSender);
        $this->postJson('/api/v1/interests', ['receiver_profile_id' => $apiReceiverProfile->id])->assertOk();

        $webInterest = Interest::query()->where('receiver_profile_id', $webReceiverProfile->id)->firstOrFail();
        $apiInterest = Interest::query()->where('receiver_profile_id', $apiReceiverProfile->id)->firstOrFail();

        $this->assertSame($webInterest->status, $apiInterest->status);

        // Both receivers were told.
        Notification::assertSentTo($webReceiverUser, InterestSentNotification::class);
        Notification::assertSentTo($apiReceiverUser, InterestSentNotification::class);

        // Both fed the matching engine.
        $this->assertDatabaseHas('user_match_behaviors', [
            'target_profile_id' => $webReceiverProfile->id,
            'action' => 'interest_sent',
        ]);
        $this->assertDatabaseHas('user_match_behaviors', [
            'target_profile_id' => $apiReceiverProfile->id,
            'action' => 'interest_sent',
        ]);
    }

    public function test_accepting_through_web_and_app_produces_the_same_state(): void
    {
        Notification::fake();

        // Web surface
        [$webSenderUser, $webSenderProfile] = $this->member();
        [$webReceiverUser, $webReceiverProfile] = $this->member([
            'contact_unlock_mode' => 'after_interest_accepted',
        ]);
        $webInterest = $this->pendingInterest($webSenderProfile, $webReceiverProfile);

        $this->actingAs($webReceiverUser)
            ->from(route('interests.index'))
            ->post(route('interests.accept', ['interest' => $webInterest->id]))
            ->assertRedirect();

        // App surface
        [$apiSenderUser, $apiSenderProfile] = $this->member();
        [$apiReceiverUser, $apiReceiverProfile] = $this->member([
            'contact_unlock_mode' => 'after_interest_accepted',
        ]);
        $apiInterest = $this->pendingInterest($apiSenderProfile, $apiReceiverProfile);

        Sanctum::actingAs($apiReceiverUser);
        $this->postJson("/api/v1/interests/{$apiInterest->id}/accept")->assertOk();

        // Same status.
        $this->assertSame('accepted', $webInterest->fresh()->status);
        $this->assertSame('accepted', $apiInterest->fresh()->status);

        // Same contact rows on both sides.
        foreach ([[$webReceiverProfile, $webSenderProfile], [$apiReceiverProfile, $apiSenderProfile]] as [$owner, $viewer]) {
            $this->assertDatabaseHas('profile_contact_visibility', [
                'owner_profile_id' => $owner->id,
                'viewer_profile_id' => $viewer->id,
                'granted_via' => 'interest_accept',
            ]);
            $this->assertDatabaseHas('contact_access_log', [
                'owner_profile_id' => $owner->id,
                'viewer_profile_id' => $viewer->id,
                'source' => 'interest',
            ]);
        }

        // Same notifications.
        Notification::assertSentTo($webSenderUser, InterestAcceptedNotification::class);
        Notification::assertSentTo($apiSenderUser, InterestAcceptedNotification::class);

        // Same matching-engine signal.
        $this->assertDatabaseHas('user_match_behaviors', [
            'target_profile_id' => $webSenderProfile->id,
            'action' => 'interest_accepted',
        ]);
        $this->assertDatabaseHas('user_match_behaviors', [
            'target_profile_id' => $apiSenderProfile->id,
            'action' => 'interest_accepted',
        ]);
    }

    public function test_block_refusal_matches_on_web_and_app(): void
    {
        [$webSender, $webSenderProfile] = $this->member();
        [, $webReceiverProfile] = $this->member();
        Block::query()->create([
            'blocker_profile_id' => $webReceiverProfile->id,
            'blocked_profile_id' => $webSenderProfile->id,
        ]);

        $this->actingAs($webSender)
            ->from(route('interests.index'))
            ->post(route('interests.send', ['matrimony_profile_id' => $webReceiverProfile->id]))
            ->assertRedirect()
            ->assertSessionHas('error', __('interest.cannot_send_to_profile'));

        [$apiSender, $apiSenderProfile] = $this->member();
        [, $apiReceiverProfile] = $this->member();
        Block::query()->create([
            'blocker_profile_id' => $apiReceiverProfile->id,
            'blocked_profile_id' => $apiSenderProfile->id,
        ]);

        Sanctum::actingAs($apiSender);
        $this->postJson('/api/v1/interests', ['receiver_profile_id' => $apiReceiverProfile->id])
            ->assertStatus(403)
            ->assertJsonPath('message', __('interest.cannot_send_to_profile'));

        // Neither surface created a row.
        $this->assertDatabaseCount('interests', 0);
    }
}
