<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileEmailVerificationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        RateLimiter::clear('mobile-email-otp-send:ip:'.sha1('127.0.0.1'));
        RateLimiter::clear('mobile-email-otp-verify:ip:'.sha1('127.0.0.1'));
    }

    public function test_google_email_verification_marks_email_verified(): void
    {
        Config::set('services.google.client_ids', ['web-client-id']);
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'web-client-id',
                'email' => 'verified@example.com',
                'email_verified' => 'true',
            ], 200),
        ]);

        $user = User::factory()->create([
            'email' => null,
            'email_verified_at' => null,
        ]);
        Sanctum::actingAs($user);

        $response = $this
            ->postJson('/api/v1/account/email/google', [
                'email' => 'Verified@Example.com',
                'id_token' => 'google-id-token',
            ])
            ->assertOk()
            ->json();

        $this->assertTrue($response['success']);
        $this->assertSame('verified@example.com', $response['user']['email']);

        $user->refresh();
        $this->assertSame('verified@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_google_email_verification_failure_returns_otp_fallback_without_updating_email(): void
    {
        Config::set('services.google.client_ids', ['web-client-id']);
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'web-client-id',
                'email' => 'verified@example.com',
                'email_verified' => 'false',
            ], 200),
        ]);

        $user = User::factory()->create([
            'email' => null,
            'email_verified_at' => null,
        ]);
        Sanctum::actingAs($user);

        $this
            ->postJson('/api/v1/account/email/google', [
                'email' => 'verified@example.com',
                'id_token' => 'google-id-token',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('fallback', 'email_otp');

        $user->refresh();
        $this->assertNull($user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_otp_fallback_verifies_email(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => null,
            'email_verified_at' => null,
        ]);
        Sanctum::actingAs($user);

        $sent = $this
            ->postJson('/api/v1/account/email-otp/send', [
                'email' => 'OtpUser@Example.com',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json();

        $this->assertNotEmpty($sent['challenge_id']);
        $this->assertNotEmpty($sent['debug_otp']);

        $response = $this
            ->postJson('/api/v1/account/email-otp/verify', [
                'challenge_id' => $sent['challenge_id'],
                'email' => 'otpuser@example.com',
                'otp' => $sent['debug_otp'],
            ])
            ->assertOk()
            ->json();

        $this->assertTrue($response['success']);
        $this->assertSame('otpuser@example.com', $response['user']['email']);

        $user->refresh();
        $this->assertSame('otpuser@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_email_verification_rejects_email_used_by_another_account(): void
    {
        User::factory()->create(['email' => 'used@example.com']);
        $user = User::factory()->create(['email' => null]);
        Sanctum::actingAs($user);

        $this
            ->postJson('/api/v1/account/email-otp/send', [
                'email' => 'used@example.com',
            ])
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertNull($user->fresh()->email);
    }
}
