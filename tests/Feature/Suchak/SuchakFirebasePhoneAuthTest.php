<?php

namespace Tests\Feature\Suchak;

use App\Http\Middleware\EnsureSuchakLegacyOtpEnabled;
use App\Models\MobileOtpChallenge;
use App\Models\SuchakAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Server-side verification of Firebase Phone Auth ID tokens.
 *
 * Everything here is signed locally with a throwaway RSA key that exists only
 * in tests/Fixtures/firebase, and the key set is stubbed with Http::fake().
 * Nothing in this file talks to Google.
 *
 * The point of the suite is one sentence: the server must not believe a phone
 * number it has not verified the signature for.
 */
class SuchakFirebasePhoneAuthTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_ID = 'navri-mile-navryala-c1456';

    private const KEY_ID = 'test-key-1';

    private const MOBILE = '9876543210';

    private \OpenSSLAsymmetricKey $signingKey;

    protected function setUp(): void
    {
        parent::setUp();

        // Lowercase `fixtures` on purpose — this repo has BOTH tests/Fixtures
        // and tests/fixtures tracked, and only the lowercase one resolves on a
        // case-sensitive filesystem for this path.
        $pem = file_get_contents(base_path('tests/fixtures/firebase/test-signing-key.pem'));
        $key = openssl_pkey_get_private((string) $pem);
        $this->assertNotFalse($key, 'Test signing key fixture could not be read.');
        $this->signingKey = $key;

        config([
            'firebase_auth.enabled' => true,
            'firebase_auth.project_id' => self::PROJECT_ID,
            'firebase_auth.jwks_url' => 'https://jwks.test/keys',
            'firebase_auth.leeway' => 60,
        ]);

        $this->fakeJwks();
    }

    // ---------------------------------------------------------------- accepts

    public function test_a_valid_token_signs_an_existing_suchak_in(): void
    {
        $user = $this->existingSuchak();

        $response = $this->postJson('/api/v1/suchak/auth/firebase/login', [
            'firebase_id_token' => $this->token(),
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.mobile', self::MOBILE)
            ->assertJsonPath('verification.channel', 'firebase');

        $this->assertNotEmpty($response->json('token'));
        $this->assertNotNull($user->refresh()->mobile_verified_at);
    }

    public function test_a_verified_login_is_recorded_with_its_channel_and_never_claims_a_tier(): void
    {
        $this->existingSuchak();

        $this->postJson('/api/v1/suchak/auth/firebase/login', [
            'firebase_id_token' => $this->token(),
        ])->assertOk();

        $record = MobileOtpChallenge::query()->where('mobile', self::MOBILE)->sole();

        $this->assertSame('firebase', $record->channel);
        $this->assertSame('suchak_firebase_login', $record->purpose);
        $this->assertSame('firebase-uid-1', $record->provider_uid);
        $this->assertNotNull($record->verified_at);
        // No code was ever sent, so there is no hash to record — and a null
        // hash also means this row can never be completed as an OTP challenge.
        $this->assertNull($record->otp_hash);
    }

    public function test_registration_creates_a_verified_suchak_and_issues_no_otp(): void
    {
        $response = $this->postJson('/api/v1/suchak/auth/firebase/register', [
            'firebase_id_token' => $this->token(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('verification.channel', 'firebase')
            // The OTP step of the wizard is already satisfied.
            ->assertJsonPath('account.onboarding_step', 'identity')
            ->assertJsonMissingPath('otp');

        $user = User::query()->where('mobile', self::MOBILE)->sole();
        $this->assertNotNull($user->mobile_verified_at);
        $this->assertNotNull($user->suchakAccount);

        $record = MobileOtpChallenge::query()->where('mobile', self::MOBILE)->sole();
        $this->assertSame('suchak_firebase_register', $record->purpose);
    }

    // ---------------------------------------------------------------- refuses

    public function test_a_token_for_another_audience_is_refused(): void
    {
        $this->existingSuchak();

        $this->postJson('/api/v1/suchak/auth/firebase/login', [
            'firebase_id_token' => $this->token(['aud' => 'someone-elses-project']),
        ])
            ->assertStatus(401)
            ->assertJsonPath('code', 'audience_mismatch');

        $this->assertNoVerificationRecorded();
    }

    public function test_a_token_from_another_issuer_is_refused(): void
    {
        $this->existingSuchak();

        $this->postJson('/api/v1/suchak/auth/firebase/login', [
            'firebase_id_token' => $this->token([
                'iss' => 'https://securetoken.google.com/someone-elses-project',
            ]),
        ])
            ->assertStatus(401)
            ->assertJsonPath('code', 'issuer_mismatch');

        $this->assertNoVerificationRecorded();
    }

    public function test_an_expired_token_is_refused(): void
    {
        $this->existingSuchak();

        $this->postJson('/api/v1/suchak/auth/firebase/login', [
            'firebase_id_token' => $this->token([
                'iat' => time() - 7200,
                'auth_time' => time() - 7200,
                'exp' => time() - 3600,
            ]),
        ])
            ->assertStatus(401)
            ->assertJsonPath('code', 'token_expired');

        $this->assertNoVerificationRecorded();
    }

    public function test_a_tampered_signature_is_refused(): void
    {
        $this->existingSuchak();

        // A real token, re-pointed at a different phone number after signing.
        [$header, , $signature] = explode('.', $this->token());
        $forgedPayload = $this->base64UrlEncode((string) json_encode(
            $this->claims(['phone_number' => '+919999999999'])
        ));

        $this->postJson('/api/v1/suchak/auth/firebase/login', [
            'firebase_id_token' => $header.'.'.$forgedPayload.'.'.$signature,
        ])
            ->assertStatus(401)
            ->assertJsonPath('code', 'signature_invalid');

        $this->assertNoVerificationRecorded();
    }

    public function test_a_token_without_a_phone_number_claim_is_refused(): void
    {
        $this->existingSuchak();

        $this->postJson('/api/v1/suchak/auth/firebase/login', [
            'firebase_id_token' => $this->token(['phone_number' => null]),
        ])
            ->assertStatus(401)
            ->assertJsonPath('code', 'phone_number_missing');

        $this->assertNoVerificationRecorded();
    }

    public function test_a_token_signed_by_an_unpublished_key_is_refused(): void
    {
        $this->existingSuchak();

        $this->postJson('/api/v1/suchak/auth/firebase/login', [
            'firebase_id_token' => $this->token(header: ['kid' => 'a-key-google-never-published']),
        ])
            ->assertStatus(401)
            ->assertJsonPath('code', 'unknown_signing_key');

        $this->assertNoVerificationRecorded();
    }

    public function test_an_unsigned_token_is_refused(): void
    {
        $this->existingSuchak();

        // alg:none — the classic "just drop the signature" forgery.
        $header = $this->base64UrlEncode((string) json_encode(['alg' => 'none', 'typ' => 'JWT']));
        $payload = $this->base64UrlEncode((string) json_encode($this->claims()));

        $this->postJson('/api/v1/suchak/auth/firebase/login', [
            'firebase_id_token' => $header.'.'.$payload.'.'.$this->base64UrlEncode('x'),
        ])
            ->assertStatus(401)
            ->assertJsonPath('code', 'unsupported_algorithm');

        $this->assertNoVerificationRecorded();
    }

    public function test_a_token_from_a_non_phone_provider_is_refused(): void
    {
        $this->existingSuchak();

        $this->postJson('/api/v1/suchak/auth/firebase/login', [
            'firebase_id_token' => $this->token(['firebase' => ['sign_in_provider' => 'google.com']]),
        ])
            ->assertStatus(401)
            ->assertJsonPath('code', 'provider_not_phone');

        $this->assertNoVerificationRecorded();
    }

    // -------------------------------------------- reconciliation and fallback

    public function test_a_number_that_disagrees_with_the_token_is_refused(): void
    {
        $victim = $this->existingSuchak();
        $attacker = User::query()->create([
            'name' => 'Other',
            'mobile' => '9000000001',
            'password' => Hash::make(Str::random(16)),
        ]);

        // The signed token proves 9876543210; the body claims another number.
        // Neither account may be touched.
        $this->postJson('/api/v1/suchak/auth/firebase/login', [
            'firebase_id_token' => $this->token(),
            'mobile' => $attacker->mobile,
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'mobile_mismatch');

        $this->assertNull($victim->refresh()->mobile_verified_at);
        $this->assertNull($attacker->refresh()->mobile_verified_at);
        $this->assertNoVerificationRecorded();
    }

    public function test_a_verified_number_with_no_suchak_account_is_refused(): void
    {
        User::query()->create([
            'name' => 'Member only',
            'mobile' => self::MOBILE,
            'password' => Hash::make(Str::random(16)),
        ]);

        $this->postJson('/api/v1/suchak/auth/firebase/login', [
            'firebase_id_token' => $this->token(),
        ])
            ->assertStatus(404)
            ->assertJsonPath('code', 'suchak_not_found');
    }

    public function test_an_unconfigured_server_fails_closed_and_never_falls_back(): void
    {
        // Nothing left to resolve a project id from: no dedicated env, no FCM
        // override, and no service-account file to read one out of.
        config([
            'firebase_auth.project_id' => null,
            'engagement.push.project_id' => null,
            'engagement.push.credentials' => base_path('tests/fixtures/firebase/no-such-file.json'),
        ]);
        $this->existingSuchak();

        $this->postJson('/api/v1/suchak/auth/firebase/login', [
            'firebase_id_token' => $this->token(),
        ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'firebase_auth_unconfigured')
            ->assertJsonPath('success', false)
            // 503 must NOT carry a session — that would be the bypass.
            ->assertJsonMissingPath('token');
    }

    public function test_legacy_code_sign_in_is_off_in_production_by_default(): void
    {
        config(['firebase_auth.legacy_suchak_otp' => null]);

        $this->app->detectEnvironment(fn (): string => 'production');
        $this->assertFalse(EnsureSuchakLegacyOtpEnabled::enabled());

        $this->app->detectEnvironment(fn (): string => 'testing');
        $this->assertTrue(EnsureSuchakLegacyOtpEnabled::enabled());

        // And the switch still works when the owner sets it deliberately.
        config(['firebase_auth.legacy_suchak_otp' => false]);
        $this->assertFalse(EnsureSuchakLegacyOtpEnabled::enabled());
    }

    public function test_the_status_route_reports_availability_without_a_token(): void
    {
        $this->getJson('/api/v1/suchak/auth/firebase/status')
            ->assertOk()
            ->assertJsonPath('data.available', true);

        config(['firebase_auth.enabled' => false]);

        $this->getJson('/api/v1/suchak/auth/firebase/status')
            ->assertOk()
            ->assertJsonPath('data.available', false);
    }

    // ------------------------------------------------------------------ setup

    private function existingSuchak(): User
    {
        $user = User::query()->create([
            'name' => 'Test Suchak',
            'mobile' => self::MOBILE,
            'password' => Hash::make(Str::random(16)),
            'registering_for' => 'other',
        ]);

        SuchakAccount::query()->create([
            'user_id' => $user->id,
            'suchak_name' => 'Test Suchak',
            'business_type' => SuchakAccount::BUSINESS_TYPE_INDIVIDUAL,
            'mobile_number' => self::MOBILE,
            'whatsapp_number' => self::MOBILE,
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
            'registration_completed_at' => now(),
            'onboarding_step' => 'complete',
        ]);

        return $user->refresh();
    }

    /**
     * Google's key set, as this server would fetch it — except the key is ours.
     */
    private function fakeJwks(): void
    {
        $details = openssl_pkey_get_details($this->signingKey);

        Http::fake([
            'https://jwks.test/keys' => Http::response([
                'keys' => [[
                    'kty' => 'RSA',
                    'use' => 'sig',
                    'alg' => 'RS256',
                    'kid' => self::KEY_ID,
                    'n' => $this->base64UrlEncode((string) $details['rsa']['n']),
                    'e' => $this->base64UrlEncode((string) $details['rsa']['e']),
                ]],
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides  null removes a claim entirely
     * @return array<string, mixed>
     */
    private function claims(array $overrides = []): array
    {
        $now = time();

        $claims = [
            'iss' => 'https://securetoken.google.com/'.self::PROJECT_ID,
            'aud' => self::PROJECT_ID,
            'auth_time' => $now - 10,
            'user_id' => 'firebase-uid-1',
            'sub' => 'firebase-uid-1',
            'iat' => $now - 10,
            'exp' => $now + 3600,
            'phone_number' => '+91'.self::MOBILE,
            'firebase' => [
                'identities' => ['phone' => ['+91'.self::MOBILE]],
                'sign_in_provider' => 'phone',
            ],
        ];

        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($claims[$key]);

                continue;
            }

            $claims[$key] = $value;
        }

        return $claims;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $header
     */
    private function token(array $overrides = [], array $header = []): string
    {
        $encodedHeader = $this->base64UrlEncode((string) json_encode(array_merge([
            'alg' => 'RS256',
            'typ' => 'JWT',
            'kid' => self::KEY_ID,
        ], $header)));

        $encodedPayload = $this->base64UrlEncode((string) json_encode($this->claims($overrides)));

        $signature = '';
        openssl_sign(
            $encodedHeader.'.'.$encodedPayload,
            $signature,
            $this->signingKey,
            OPENSSL_ALGO_SHA256
        );

        return $encodedHeader.'.'.$encodedPayload.'.'.$this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function assertNoVerificationRecorded(): void
    {
        $this->assertSame(0, MobileOtpChallenge::query()->count());
    }
}
