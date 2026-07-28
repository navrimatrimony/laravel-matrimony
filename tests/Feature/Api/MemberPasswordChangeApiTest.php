<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * POST /api/v1/account/password — the only change-password path a member has.
 *
 * Pins the contract the member Flutter app is built against AND the two
 * compensating controls that pay for not asking for the current password.
 */
class MemberPasswordChangeApiTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'NewPassword1!';

    /**
     * A real Sanctum token, not Sanctum::actingAs() — actingAs installs a
     * TransientToken with no personal_access_tokens row, which is exactly the
     * thing "the caller's own token survives" is about.
     *
     * @return array{0: User, 1: string}
     */
    private function signedInMember(array $attributes = []): array
    {
        $user = User::factory()->create(array_merge([
            'mobile' => '9876543210',
            'mobile_verified_at' => now(),
            'password' => Hash::make('OldPassword1!'),
        ], $attributes));

        $token = $user->createToken('mobile-app')->plainTextToken;

        return [$user, $token];
    }

    /**
     * Laravel keeps one application across the HTTP calls of a single test, and
     * `RequestGuard` memoises the user it resolved. Without this, every call
     * after the first authenticated one is answered from that cache — a revoked
     * token would appear to still work. Test-harness artifact, not behaviour.
     */
    private function asToken(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    public function test_signed_in_member_sets_a_new_password_and_can_log_in_with_it(): void
    {
        [$user, $token] = $this->signedInMember(['email' => 'member@example.com']);

        $this
            ->asToken($token)
            ->postJson('/api/v1/account/password', [
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'message']);

        $user->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, (string) $user->password));
        $this->assertFalse(Hash::check('OldPassword1!', (string) $user->password));

        // The point of the whole endpoint: the new password actually logs in.
        $this
            ->postJson('/api/v1/login', [
                'login' => 'member@example.com',
                'password' => self::NEW_PASSWORD,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        // ...and the old one no longer does.
        $this
            ->postJson('/api/v1/login', [
                'login' => 'member@example.com',
                'password' => 'OldPassword1!',
            ])
            ->assertStatus(401);
    }

    public function test_other_sessions_are_revoked_while_the_callers_own_token_survives(): void
    {
        [$user, $token] = $this->signedInMember();

        $otherToken = $user->createToken('mobile-app')->plainTextToken;
        $thirdToken = $user->createToken('mobile-app')->plainTextToken;
        $strangerToken = User::factory()->create()->createToken('mobile-app')->plainTextToken;

        $this->assertSame(3, $user->tokens()->count());

        $this
            ->asToken($token)
            ->postJson('/api/v1/account/password', [
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertOk();

        $this->assertSame(1, $user->tokens()->count());

        // The caller stays signed in — no being thrown out mid-action.
        $this->asToken($token)->getJson('/api/v1/notifications/unread-count')->assertOk();

        // The stolen phone does not.
        foreach ([$otherToken, $thirdToken] as $revoked) {
            $this->asToken($revoked)->getJson('/api/v1/notifications/unread-count')->assertStatus(401);
        }

        // Blast radius stays inside this account.
        $this->asToken($strangerToken)->getJson('/api/v1/notifications/unread-count')->assertOk();
    }

    public function test_a_reset_link_emailed_before_the_change_stops_working(): void
    {
        [$user, $token] = $this->signedInMember(['email' => 'member@example.com']);

        DB::table('password_reset_tokens')->insert([
            'email' => 'member@example.com',
            'token' => Hash::make('pending-reset-token'),
            'created_at' => now(),
        ]);

        $this
            ->asToken($token)
            ->postJson('/api/v1/account/password', [
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertOk();

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'member@example.com']);
    }

    public function test_the_member_is_told_the_password_changed(): void
    {
        Notification::fake();

        [$user, $token] = $this->signedInMember();

        $this
            ->asToken($token)
            ->postJson('/api/v1/account/password', [
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertOk();

        Notification::assertSentTo($user, PasswordChangedNotification::class);
    }

    public function test_a_weak_password_is_rejected_with_a_usable_message(): void
    {
        [$user, $token] = $this->signedInMember();

        $response = $this
            ->asToken($token)
            ->postJson('/api/v1/account/password', [
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->assertNotSame('', trim((string) $response->json('message')));
        $this->assertTrue(Hash::check('OldPassword1!', (string) $user->fresh()->password));
    }

    public function test_a_mismatched_confirmation_is_rejected(): void
    {
        [$user, $token] = $this->signedInMember();

        $this
            ->asToken($token)
            ->postJson('/api/v1/account/password', [
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => 'SomethingElse1!',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->assertTrue(Hash::check('OldPassword1!', (string) $user->fresh()->password));
    }

    public function test_an_unauthenticated_caller_gets_401(): void
    {
        $this
            ->postJson('/api/v1/account/password', [
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertStatus(401);
    }
}
