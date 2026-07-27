<?php

namespace Tests\Feature\Push;

use App\Models\DeviceToken;
use App\Models\SuchakAccount;
use App\Models\User;
use App\Services\NotificationPlatformSettingsService;
use App\Services\Push\FirebasePushService;
use App\Services\Push\PushDispatchService;
use App\Services\UserNotificationPreferencesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The one focused suite for the push capability.
 *
 * Covers the four things that would silently break the feature: token
 * registration idempotency, ownership re-pointing when a device changes hands,
 * logout removal, and the sender staying a no-op (never an exception, never an
 * outbound call) when the channel is disabled.
 */
class DeviceTokenRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'fMEQ4xample-registration-token-0000000000000001';

    #[Test]
    public function member_can_register_a_device_token(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/device-tokens', [
            'token' => self::TOKEN,
            'platform' => 'android',
            'app' => 'member',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('device_tokens', [
            'token' => self::TOKEN,
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'app' => DeviceToken::APP_MEMBER,
            'platform' => 'android',
        ]);
    }

    #[Test]
    public function registering_the_same_token_twice_is_idempotent(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $payload = ['token' => self::TOKEN, 'platform' => 'android', 'app' => 'member'];

        $this->postJson('/api/v1/device-tokens', $payload)->assertOk();
        $this->postJson('/api/v1/device-tokens', $payload)->assertOk();

        $this->assertSame(1, DeviceToken::query()->where('token', self::TOKEN)->count());
    }

    #[Test]
    public function re_registering_an_existing_token_repoints_it_to_the_new_owner(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        Sanctum::actingAs($first);
        $this->postJson('/api/v1/device-tokens', ['token' => self::TOKEN])->assertOk();

        // Same physical device, now signed in as somebody else.
        Sanctum::actingAs($second);
        $this->postJson('/api/v1/device-tokens', ['token' => self::TOKEN])->assertOk();

        $this->assertSame(1, DeviceToken::query()->where('token', self::TOKEN)->count());
        $this->assertDatabaseHas('device_tokens', [
            'token' => self::TOKEN,
            'tokenable_id' => $second->id,
        ]);
        // The previous owner must no longer be able to push to that phone.
        $this->assertDatabaseMissing('device_tokens', [
            'token' => self::TOKEN,
            'tokenable_id' => $first->id,
        ]);
    }

    #[Test]
    public function a_suchak_device_repoints_a_token_away_from_a_member(): void
    {
        $member = User::factory()->create();
        Sanctum::actingAs($member);
        $this->postJson('/api/v1/device-tokens', ['token' => self::TOKEN])->assertOk();

        $suchakUser = User::factory()->create();
        $account = SuchakAccount::factory()->create(['user_id' => $suchakUser->id]);

        Sanctum::actingAs($suchakUser);
        $this->postJson('/api/v1/suchak/device-tokens', ['token' => self::TOKEN, 'app' => 'suchak'])->assertOk();

        $this->assertSame(1, DeviceToken::query()->where('token', self::TOKEN)->count());
        $this->assertDatabaseHas('device_tokens', [
            'token' => self::TOKEN,
            'tokenable_type' => SuchakAccount::class,
            'tokenable_id' => $account->id,
            'app' => DeviceToken::APP_SUCHAK,
        ]);
    }

    #[Test]
    public function delete_removes_the_token(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/device-tokens', ['token' => self::TOKEN])->assertOk();

        $this->deleteJson('/api/v1/device-tokens', ['token' => self::TOKEN])
            ->assertOk()
            ->assertJsonPath('data.removed', true);

        $this->assertDatabaseMissing('device_tokens', ['token' => self::TOKEN]);
    }

    #[Test]
    public function a_member_cannot_delete_another_members_token(): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/device-tokens', ['token' => self::TOKEN])->assertOk();

        Sanctum::actingAs(User::factory()->create());
        $this->deleteJson('/api/v1/device-tokens', ['token' => self::TOKEN])
            ->assertOk()
            ->assertJsonPath('data.removed', false);

        $this->assertDatabaseHas('device_tokens', ['token' => self::TOKEN, 'tokenable_id' => $owner->id]);
    }

    #[Test]
    public function registration_requires_a_token(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/device-tokens', [])->assertStatus(422);
    }

    #[Test]
    public function device_token_endpoints_require_authentication(): void
    {
        $this->postJson('/api/v1/device-tokens', ['token' => self::TOKEN])->assertStatus(401);
    }

    #[Test]
    public function sender_is_a_no_op_when_push_is_disabled_by_config(): void
    {
        config(['engagement.push.enabled' => false]);
        Http::fake();

        $result = app(FirebasePushService::class)->sendToTokens([self::TOKEN], 'Title', 'Body');

        $this->assertFalse($result['enabled']);
        $this->assertSame(0, $result['sent']);
        $this->assertSame(0, $result['failed']);
        Http::assertNothingSent();
    }

    #[Test]
    public function sender_is_a_no_op_when_the_credentials_file_is_missing(): void
    {
        config([
            'engagement.push.enabled' => true,
            'engagement.push.credentials' => storage_path('app/firebase/does-not-exist.json'),
        ]);
        Http::fake();

        $firebase = app(FirebasePushService::class);

        $this->assertFalse($firebase->enabled());
        $result = $firebase->sendToTokens([self::TOKEN], 'Title', 'Body');

        $this->assertFalse($result['enabled']);
        Http::assertNothingSent();
    }

    #[Test]
    public function dispatcher_does_not_send_while_the_channel_is_disabled(): void
    {
        config(['engagement.push.enabled' => false]);
        Http::fake();

        $user = User::factory()->create();

        $outcome = app(PushDispatchService::class)->dispatchForDatabaseNotification(
            $user,
            \App\Notifications\InterestSentNotification::class,
            ['interest_id' => 5],
        );

        $this->assertFalse($outcome['sent']);
        $this->assertSame('channel_disabled', $outcome['reason']);
        Http::assertNothingSent();
    }

    #[Test]
    public function an_unregistered_notification_type_is_skipped_rather_than_erroring(): void
    {
        config(['engagement.push.enabled' => true]);
        Http::fake();

        $outcome = app(PushDispatchService::class)->dispatchForDatabaseNotification(
            User::factory()->create(),
            'App\\Notifications\\SomeTypeNobodyRegistered',
        );

        $this->assertFalse($outcome['sent']);
        $this->assertSame('unregistered_type', $outcome['reason']);
        Http::assertNothingSent();
    }

    #[Test]
    public function admin_off_beats_user_on(): void
    {
        config(['engagement.push.enabled' => true]);
        Http::fake();

        $user = User::factory()->create();
        app(UserNotificationPreferencesService::class)->saveChannelOverrides($user, [
            'new_interest' => [UserNotificationPreferencesService::CHANNEL_PUSH => true],
        ]);

        \App\Models\AdminSetting::setValue(
            NotificationPlatformSettingsService::KEY_PUSH_TYPE_PREFIX.'new_interest',
            '0'
        );

        $outcome = app(PushDispatchService::class)->dispatchForDatabaseNotification(
            $user,
            \App\Notifications\InterestSentNotification::class,
        );

        $this->assertSame('type_disabled_by_admin', $outcome['reason']);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_user_can_silence_a_category_the_admin_allows(): void
    {
        config(['engagement.push.enabled' => true]);
        Http::fake();

        $user = User::factory()->create();
        app(UserNotificationPreferencesService::class)->saveChannelOverrides($user, [
            'new_interest' => [UserNotificationPreferencesService::CHANNEL_PUSH => false],
        ]);

        $outcome = app(PushDispatchService::class)->dispatchForDatabaseNotification(
            $user,
            \App\Notifications\InterestSentNotification::class,
        );

        $this->assertSame('type_disabled_by_user', $outcome['reason']);
        Http::assertNothingSent();
    }

    /**
     * The regression this whole two-axis design exists to prevent: the fact
     * "does this member want new-matches digests?" must have exactly ONE storage
     * key, the one the engagement engine already used.
     */
    #[Test]
    public function the_new_matches_digest_fact_has_exactly_one_storage_key(): void
    {
        $user = User::factory()->create();
        $preferences = app(UserNotificationPreferencesService::class);

        // The push registry must map onto the pre-existing engagement key.
        $this->assertSame(
            UserNotificationPreferencesService::KEY_ENGAGEMENT_MATCHES_DIGEST,
            app(\App\Services\Push\PushTypeRegistry::class)->preferenceKey('new_matches'),
        );

        // Turning the EVENT off through the long-standing engagement setting must
        // also stop the push — no second key that keeps saying "yes".
        $preferences->saveForUser($user, [
            UserNotificationPreferencesService::KEY_ENGAGEMENT_MATCHES_DIGEST => false,
        ]);

        $this->assertFalse($preferences->pushTypeEnabled($user->fresh(), 'new_matches'));

        // And no `push_*` shadow key was ever written for it.
        $stored = (array) $user->fresh()->notification_preferences;
        $this->assertArrayNotHasKey('push_new_matches', $stored);
        $this->assertArrayNotHasKey('push_inactive_reminder', $stored);
    }

    #[Test]
    public function a_member_can_keep_the_digest_by_email_but_silence_it_on_the_phone(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $preferences = app(UserNotificationPreferencesService::class);

        $this->putJson('/api/v1/notification-preferences', [
            'categories' => [UserNotificationPreferencesService::KEY_ENGAGEMENT_MATCHES_DIGEST => false],
        ])->assertOk();

        $fresh = $user->fresh();

        // Push silenced…
        $this->assertFalse($preferences->pushTypeEnabled($fresh, 'new_matches'));
        // …while the event itself — and therefore the email/in-app digest — stays on.
        $this->assertTrue($preferences->newMatchesDigestEnabled($fresh));
    }

    /**
     * Recurrence guard for the deep-link bug of 2026-07-27.
     *
     * `mediation_request_id` looked like a SuchakProfileRequest id and was wired
     * as one by the Suchak app, but MediationRequest extends ContactRequest — it
     * is a contact_requests row. A push id must name the model it belongs to, and
     * a target must belong to an app that can actually receive that type.
     */
    #[Test]
    public function every_push_payload_id_names_a_key_its_notification_actually_stores(): void
    {
        $registry = app(\App\Services\Push\PushTypeRegistry::class);

        foreach ($registry->all() as $pushKey => $row) {
            $source = file_get_contents((new \ReflectionClass($row['notification']))->getFileName());

            foreach ($row['data_keys'] as $dataKey) {
                $this->assertStringContainsString(
                    "'".$dataKey."' =>",
                    $source,
                    sprintf(
                        "Push type '%s' forwards '%s', but %s never writes that key. "
                        ."A deep link on an id the notification does not store opens nothing.",
                        $pushKey,
                        $dataKey,
                        class_basename($row['notification']),
                    ),
                );
            }

            $this->assertNotContains(
                'mediation_request_id',
                $row['data_keys'],
                "Push type '{$pushKey}' must forward contact_request_id: MediationRequest extends "
                .'ContactRequest, so mediation_request_id is an alias that reads as a different model.',
            );

            $this->assertNotEmpty($row['apps'], "Push type '{$pushKey}' reaches no app.");
        }
    }

    #[Test]
    public function no_target_is_emitted_without_a_producer(): void
    {
        $registry = app(\App\Services\Push\PushTypeRegistry::class);

        // `suchak_requests` was removed: no Notification class exists for
        // SuchakProfileRequest, so nothing could ever produce it.
        $targets = array_column($registry->all(), 'target');

        $this->assertNotContains(
            'suchak_requests',
            $targets,
            'suchak_requests has no producing notification; emitting it sends the Suchak app to a dead end.',
        );
    }

    #[Test]
    public function member_preferences_endpoint_is_server_driven_and_hides_admin_disabled_types(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        \App\Models\AdminSetting::setValue(
            NotificationPlatformSettingsService::KEY_PUSH_TYPE_PREFIX.'new_interest',
            '0'
        );

        $response = $this->getJson('/api/v1/notification-preferences')->assertOk();

        $keys = array_column($response->json('data.categories'), 'key');

        $this->assertNotEmpty($keys, 'The settings screen must be driven by the server, not the app.');
        $this->assertNotContains('new_interest', $keys, 'A platform-disabled type must not be offered to the member.');

        // Quiet hours default on, rendered with Latin digits.
        $response->assertJsonPath('data.quiet_hours.enabled', true);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $response->json('data.quiet_hours.starts_at'));
    }

    #[Test]
    public function member_can_update_a_preference_and_unknown_keys_are_rejected(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/notification-preferences', [
            'categories' => ['new_interest' => false],
            'quiet_hours_enabled' => false,
        ])->assertOk();

        $preferences = app(UserNotificationPreferencesService::class);
        $this->assertFalse($preferences->channelEnabled($user->fresh(), 'new_interest'));
        $this->assertFalse($preferences->forUser($user->fresh())[UserNotificationPreferencesService::KEY_PUSH_QUIET_HOURS]);

        $this->putJson('/api/v1/notification-preferences', [
            'categories' => ['not_a_real_category' => true],
        ])->assertStatus(422)->assertJsonValidationErrors('categories.not_a_real_category');
    }

    #[Test]
    public function a_suchak_shares_the_single_preference_home_with_their_user(): void
    {
        $user = User::factory()->create();
        SuchakAccount::factory()->create(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $this->putJson('/api/v1/suchak/notification-preferences', [
            'categories' => ['new_chat_message' => false],
        ])->assertOk();

        // Written to the linked User row — one engine, one home, no Suchak copy.
        $this->assertFalse(
            app(UserNotificationPreferencesService::class)->channelEnabled($user->fresh(), 'new_chat_message')
        );
    }
}
