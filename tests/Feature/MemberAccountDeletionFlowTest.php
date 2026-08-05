<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Notifications\SuchakCustomerDeletionCancelledNotification;
use App\Notifications\SuchakCustomerDeletionRequestedNotification;
use App\Services\Account\MemberAccountDeletionService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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
            'full_name' => 'Anita Deshmukh',
            'lifecycle_state' => 'draft',
            'is_showcase' => false,
            'location_id' => $leaf,
        ]);
        $profile->lifecycle_state = 'active';
        $profile->save();

        return $user->fresh('matrimonyProfile');
    }

    /**
     * @return array{0: User, 1: SuchakAccount, 2: SuchakProfileRepresentation}
     */
    private function suchakWithConsent(MatrimonyProfile $profile, array $representationOverrides = []): array
    {
        $suchakUser = User::factory()->create(['is_admin' => false, 'mobile' => '9222200'.random_int(100, 999)]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);

        $representation = SuchakProfileRepresentation::factory()->create(array_merge([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_verified_consent_at' => now(),
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
            'revoked_at' => null,
            'candidate_deactivated_at' => null,
        ], $representationOverrides));

        return [$suchakUser, $account, $representation];
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

    public function test_u2_deletion_request_notifies_each_valid_consent_suchak_once(): void
    {
        Notification::fake();

        $user = $this->member();
        [$suchakA] = $this->suchakWithConsent($user->matrimonyProfile);
        [$suchakB] = $this->suchakWithConsent($user->matrimonyProfile);

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/account/deletion', [
            'confirmation' => 'delete',
            'reason_key' => 'privacy_concern',
        ])->assertOk();

        Notification::assertSentTo($suchakA, SuchakCustomerDeletionRequestedNotification::class);
        Notification::assertSentTo($suchakB, SuchakCustomerDeletionRequestedNotification::class);
        Notification::assertSentToTimes($suchakA, SuchakCustomerDeletionRequestedNotification::class, 1);
        Notification::assertSentToTimes($suchakB, SuchakCustomerDeletionRequestedNotification::class, 1);
    }

    public function test_u2_pending_claim_expired_consent_and_revoked_suchaks_are_excluded(): void
    {
        Notification::fake();

        $user = $this->member();
        [$valid] = $this->suchakWithConsent($user->matrimonyProfile);
        $this->suchakWithConsent($user->matrimonyProfile, [
            'representation_status' => SuchakProfileRepresentation::STATUS_CONSENT_PENDING,
            'consent_status' => SuchakProfileRepresentation::CONSENT_REQUESTED,
            'first_verified_consent_at' => null,
            'consent_verified_at' => null,
            'consent_valid_until' => null,
        ]);
        $this->suchakWithConsent($user->matrimonyProfile, [
            'consent_valid_until' => now()->subDay(),
        ]);
        $this->suchakWithConsent($user->matrimonyProfile, [
            'revoked_at' => now(),
            'representation_status' => SuchakProfileRepresentation::STATUS_REVOKED,
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/account/deletion', [
            'confirmation' => 'delete',
            'reason_key' => 'other',
        ])->assertOk();

        Notification::assertSentTo($valid, SuchakCustomerDeletionRequestedNotification::class);
        Notification::assertCount(1);
    }

    public function test_u2_member_with_no_suchak_sends_zero_notifications(): void
    {
        Notification::fake();

        $user = $this->member();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/account/deletion', [
            'confirmation' => 'delete',
            'reason_key' => 'other',
        ])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_u2_second_deletion_request_does_not_notify_again(): void
    {
        Notification::fake();

        $user = $this->member();
        [$suchak] = $this->suchakWithConsent($user->matrimonyProfile);

        Sanctum::actingAs($user);
        $payload = ['confirmation' => 'delete', 'reason_key' => 'hard_to_use'];
        $this->postJson('/api/v1/account/deletion', $payload)->assertOk();
        $this->postJson('/api/v1/account/deletion', $payload)->assertOk();

        Notification::assertSentToTimes($suchak, SuchakCustomerDeletionRequestedNotification::class, 1);
    }

    public function test_u2_cancel_notifies_valid_consent_suchaks_once_with_name_and_date_only(): void
    {
        Notification::fake();

        $user = $this->member();
        [$suchak] = $this->suchakWithConsent($user->matrimonyProfile);

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/account/deletion', [
            'confirmation' => 'delete',
            'reason_key' => 'privacy_concern',
        ])->assertOk();
        Notification::fake();

        $this->deleteJson('/api/v1/account/deletion')->assertOk();

        Notification::assertSentTo(
            $suchak,
            SuchakCustomerDeletionCancelledNotification::class,
            function (SuchakCustomerDeletionCancelledNotification $notification, array $channels) use ($user): bool {
                $this->assertSame(['database'], $channels);
                $payload = $notification->toArray($user);
                $this->assertSame('Anita Deshmukh', $payload['customer_full_name']);
                $this->assertArrayHasKey('event_date', $payload);
                $this->assertArrayNotHasKey('reason_key', $payload);
                $this->assertArrayNotHasKey('deletion_reason_key', $payload);
                $this->assertArrayNotHasKey('mobile', $payload);

                return true;
            }
        );
        Notification::assertSentToTimes($suchak, SuchakCustomerDeletionCancelledNotification::class, 1);
        Notification::assertNotSentTo($suchak, SuchakCustomerDeletionRequestedNotification::class);
    }

    public function test_u2_double_cancel_sends_zero_cancelled_notifications(): void
    {
        Notification::fake();

        $user = $this->member();
        [$suchak] = $this->suchakWithConsent($user->matrimonyProfile);

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/account/deletion', [
            'confirmation' => 'delete',
            'reason_key' => 'other',
        ])->assertOk();
        $this->deleteJson('/api/v1/account/deletion')->assertOk();
        Notification::fake();

        $this->deleteJson('/api/v1/account/deletion')->assertOk();
        app(MemberAccountDeletionService::class)->cancelDeletion($user->fresh());

        Notification::assertNotSentTo($suchak, SuchakCustomerDeletionCancelledNotification::class);
        Notification::assertNothingSent();
    }

    public function test_u2_cancel_after_purge_sends_zero_cancelled_notifications(): void
    {
        Notification::fake();

        $user = $this->member();
        [$suchak] = $this->suchakWithConsent($user->matrimonyProfile);

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/account/deletion', [
            'confirmation' => 'delete',
            'reason_key' => 'other',
        ])->assertOk();
        $this->travel(MemberAccountDeletionService::GRACE_DAYS + 1)->days();
        app(MemberAccountDeletionService::class)->purgeDue();
        Notification::fake();

        app(MemberAccountDeletionService::class)->cancelDeletion($user->fresh());

        Notification::assertNotSentTo($suchak, SuchakCustomerDeletionCancelledNotification::class);
        Notification::assertNothingSent();
    }

    public function test_u2_requested_payload_carries_only_name_and_date(): void
    {
        Notification::fake();

        $user = $this->member();
        [$suchak] = $this->suchakWithConsent($user->matrimonyProfile);

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/account/deletion', [
            'confirmation' => 'delete',
            'reason_key' => 'privacy_concern',
            'reason_note' => 'secret note must not leak',
        ])->assertOk();

        Notification::assertSentTo(
            $suchak,
            SuchakCustomerDeletionRequestedNotification::class,
            function (SuchakCustomerDeletionRequestedNotification $notification, array $channels) use ($user): bool {
                $this->assertSame(['database'], $channels);
                $payload = $notification->toArray($user);
                $this->assertSame('Anita Deshmukh', $payload['customer_full_name']);
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $payload['event_date']);
                $this->assertArrayNotHasKey('reason_key', $payload);
                $this->assertArrayNotHasKey('deletion_reason_note', $payload);
                $this->assertArrayNotHasKey('reason_note', $payload);
                $this->assertStringNotContainsString('secret', json_encode($payload) ?: '');

                return true;
            }
        );
    }
}
