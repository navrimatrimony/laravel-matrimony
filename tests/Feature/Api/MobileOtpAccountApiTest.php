<?php

namespace Tests\Feature\Api;

use App\Models\MobileOtpChallenge;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Models\UserConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileOtpAccountApiTest extends TestCase
{
    use RefreshDatabase;

    private function validSendPayload(array $overrides = []): array
    {
        return array_merge([
            'mobile' => '9876543210',
            'locale' => 'mr',
            'channel' => 'sms',
            'purpose' => 'login_or_register',
            'terms_accepted' => true,
            'privacy_accepted' => true,
            'terms_version' => '2026-06-24',
            'privacy_version' => '2026-06-24',
            'whatsapp_alerts_opt_in' => true,
        ], $overrides);
    }

    private function sendOtp(array $overrides = []): array
    {
        RateLimiter::clear('mobile-otp-send:mobile:'.sha1('9876543210'));
        RateLimiter::clear('mobile-otp-send:ip:'.sha1('127.0.0.1'));

        return $this
            ->postJson('/api/v1/auth/mobile-otp/send', $this->validSendPayload($overrides))
            ->assertOk()
            ->json();
    }

    public function test_send_otp_creates_challenge_without_revealing_existing_or_new_account(): void
    {
        User::factory()->create([
            'mobile' => '9876543210',
            'mobile_verified_at' => now(),
        ]);

        $response = $this->sendOtp();

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('challenge_id', $response);
        $this->assertArrayNotHasKey('user', $response);
        $this->assertArrayNotHasKey('account_state', $response);

        $challenge = MobileOtpChallenge::query()->where('challenge_id', $response['challenge_id'])->first();
        $this->assertNotNull($challenge);
        $this->assertSame('9876543210', $challenge->mobile);
        $this->assertSame('sms', $challenge->channel);
        $this->assertNotEmpty($challenge->otp_hash);
        $this->assertNotSame($response['debug_otp'], $challenge->otp_hash);
    }

    public function test_send_otp_rejects_invalid_mobile(): void
    {
        $this
            ->postJson('/api/v1/auth/mobile-otp/send', $this->validSendPayload(['mobile' => '123']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mobile']);
    }

    public function test_send_otp_cooldown_returns_429(): void
    {
        RateLimiter::clear('mobile-otp-send:mobile:'.sha1('9876543210'));
        RateLimiter::clear('mobile-otp-send:ip:'.sha1('127.0.0.1'));

        $this->postJson('/api/v1/auth/mobile-otp/send', $this->validSendPayload())->assertOk();

        $this
            ->postJson('/api/v1/auth/mobile-otp/send', $this->validSendPayload())
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['resend_after']);
    }

    public function test_verify_otp_for_new_mobile_creates_otp_shell_user_and_consents(): void
    {
        $sent = $this->sendOtp(['mobile' => '+91 98765 43210']);

        $response = $this
            ->postJson('/api/v1/auth/mobile-otp/verify', [
                'challenge_id' => $sent['challenge_id'],
                'mobile' => '9876543210',
                'otp' => $sent['debug_otp'],
            ])
            ->assertOk()
            ->json();

        $this->assertTrue($response['success']);
        $this->assertNotEmpty($response['token']);
        $this->assertSame('Bearer', $response['token_type']);
        $this->assertSame('9876543210', $response['user']['mobile']);
        $this->assertNull($response['user']['email']);
        $this->assertNull($response['user']['creator_name']);
        $this->assertSame('account_details', $response['account_state']['next_action']);
        $this->assertTrue($response['account_state']['is_new_account']);
        $this->assertFalse($response['account_state']['has_profile']);

        $user = User::query()->where('mobile', '9876543210')->firstOrFail();
        $this->assertNotNull($user->mobile_verified_at);
        $this->assertNull($user->email);
        $this->assertNull($user->password);
        $this->assertSame('mr', $user->preferred_locale);
        $this->assertSame(true, $user->notification_preferences['whatsapp_alerts_opt_in'] ?? null);
        $this->assertSame(true, $user->notification_preferences['profile_alerts_opt_in'] ?? null);
        if (Schema::hasColumn('users', 'whatsapp_verified_at')) {
            $this->assertNull($user->getAttribute('whatsapp_verified_at'));
        }

        $this->assertDatabaseHas('user_consents', [
            'user_id' => $user->id,
            'consent_type' => 'terms',
            'version' => '2026-06-24',
        ]);
        $this->assertDatabaseHas('user_consents', [
            'user_id' => $user->id,
            'consent_type' => 'privacy',
            'version' => '2026-06-24',
        ]);
    }

    public function test_verify_otp_for_existing_mobile_logs_in_same_user_without_duplicate(): void
    {
        $user = User::factory()->create([
            'name' => 'Existing User',
            'mobile' => '9876543210',
            'mobile_verified_at' => null,
        ]);
        MatrimonyProfile::factory()->create(['user_id' => $user->id]);

        $sent = $this->sendOtp();

        $response = $this
            ->postJson('/api/v1/auth/mobile-otp/verify', [
                'challenge_id' => $sent['challenge_id'],
                'mobile' => '9876543210',
                'otp' => $sent['debug_otp'],
            ])
            ->assertOk()
            ->json();

        $this->assertSame($user->id, $response['user']['id']);
        $this->assertFalse($response['account_state']['is_new_account']);
        $this->assertTrue($response['account_state']['has_profile']);
        $this->assertSame('resume_onboarding', $response['account_state']['next_action']);
        $this->assertSame(1, User::query()->where('mobile', '9876543210')->count());
        $this->assertNotNull($user->fresh()->mobile_verified_at);
    }

    public function test_invalid_otp_increments_attempts_and_eventually_blocks(): void
    {
        $sent = $this->sendOtp();

        for ($i = 0; $i < 4; $i++) {
            $this
                ->postJson('/api/v1/auth/mobile-otp/verify', [
                    'challenge_id' => $sent['challenge_id'],
                    'mobile' => '9876543210',
                    'otp' => '000000',
                ])
                ->assertStatus(422);
        }

        $this
            ->postJson('/api/v1/auth/mobile-otp/verify', [
                'challenge_id' => $sent['challenge_id'],
                'mobile' => '9876543210',
                'otp' => '000000',
            ])
            ->assertStatus(429);

        $this->assertSame(5, (int) MobileOtpChallenge::query()->where('challenge_id', $sent['challenge_id'])->value('attempts'));
    }

    public function test_expired_otp_fails(): void
    {
        $sent = $this->sendOtp();
        MobileOtpChallenge::query()
            ->where('challenge_id', $sent['challenge_id'])
            ->update(['expires_at' => now()->subMinute()]);

        $this
            ->postJson('/api/v1/auth/mobile-otp/verify', [
                'challenge_id' => $sent['challenge_id'],
                'mobile' => '9876543210',
                'otp' => $sent['debug_otp'],
            ])
            ->assertStatus(422);
    }

    public function test_account_details_updates_creator_locale_optional_email_password_and_alerts(): void
    {
        $user = User::query()->create([
            'name' => null,
            'email' => null,
            'mobile' => '9876543210',
            'mobile_verified_at' => now(),
            'password' => null,
        ]);
        Sanctum::actingAs($user);

        $response = $this
            ->patchJson('/api/v1/account/details', [
                'creator_name' => 'Shankar Patil',
                'locale' => 'en',
                'whatsapp_alerts_opt_in' => false,
            ])
            ->assertOk()
            ->json();

        $this->assertSame('Shankar Patil', $response['user']['creator_name']);
        $this->assertSame('start_onboarding', $response['account_state']['next_action']);

        $user->refresh();
        $this->assertSame('Shankar Patil', $user->name);
        $this->assertNull($user->email);
        $this->assertSame('en', $user->preferred_locale);
        $this->assertNull($user->password);
        $this->assertSame(false, $user->notification_preferences['whatsapp_alerts_opt_in'] ?? null);

        $this
            ->patchJson('/api/v1/account/details', [
                'creator_name' => 'Shankar Patil',
                'email' => 'unique@example.com',
                'locale' => 'mr',
                'password' => 'Password1!',
                'password_confirmation' => 'Password1!',
            ])
            ->assertOk();

        $user->refresh();
        $this->assertSame('unique@example.com', $user->email);
        $this->assertSame('mr', $user->preferred_locale);
        $this->assertTrue(Hash::check('Password1!', (string) $user->password));
    }

    public function test_account_details_rejects_email_used_by_another_user(): void
    {
        User::factory()->create(['email' => 'used@example.com']);
        $user = User::factory()->create(['email' => null]);
        Sanctum::actingAs($user);

        $this
            ->patchJson('/api/v1/account/details', [
                'creator_name' => 'Current User',
                'email' => 'used@example.com',
                'locale' => 'mr',
            ])
            ->assertStatus(409)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_existing_password_login_and_register_remain_backward_compatible(): void
    {
        $password = 'Password1!';
        User::factory()->create([
            'email' => 'login@example.com',
            'password' => Hash::make($password),
        ]);

        $this
            ->postJson('/api/v1/login', [
                'login' => 'login@example.com',
                'password' => $password,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['token']);

        $this
            ->postJson('/api/v1/register', [
                'name' => 'Legacy Register',
                'email' => 'legacy-register@example.com',
                'password' => $password,
                'password_confirmation' => $password,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['token']);
    }
}
