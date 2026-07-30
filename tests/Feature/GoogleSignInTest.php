<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.google.client_ids', ['test-client-id.apps.googleusercontent.com']);
});

function fakeGoogleTokenInfo(array $overrides = []): void
{
    Http::fake([
        'oauth2.googleapis.com/tokeninfo*' => Http::response(array_merge([
            'aud' => 'test-client-id.apps.googleusercontent.com',
            'email' => 'someone@gmail.com',
            'email_verified' => 'true',
            'name' => 'Some One',
        ], $overrides)),
    ]);
}

test('a first-time Google account is registered and signed in', function () {
    fakeGoogleTokenInfo();

    $response = $this->postJson('/api/v1/auth/google', ['id_token' => 'valid-token']);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('is_new_user', true)
        ->assertJsonPath('user.email', 'someone@gmail.com');

    expect($response->json('token'))->toBeString()->not->toBeEmpty();

    $user = User::query()->where('email', 'someone@gmail.com')->firstOrFail();
    expect($user->name)->toBe('Some One')
        // Google has already vouched for the address, so the member is never
        // asked to confirm what is already confirmed.
        ->and($user->email_verified_at)->not->toBeNull();
});

test('a returning Google account signs in without creating a second user', function () {
    $existing = User::factory()->create(['email' => 'someone@gmail.com']);
    fakeGoogleTokenInfo();

    $this->postJson('/api/v1/auth/google', ['id_token' => 'valid-token'])
        ->assertOk()
        ->assertJsonPath('is_new_user', false)
        ->assertJsonPath('user.id', $existing->id);

    expect(User::query()->where('email', 'someone@gmail.com')->count())->toBe(1);
});

test('an account created another way keeps its identity and gains a verified email', function () {
    // The realistic collision: this member registered by mobile OTP months ago
    // with the same address, unverified. Google signing them in must adopt that
    // account, not open a second one beside it.
    $existing = User::factory()->create([
        'email' => 'someone@gmail.com',
        'email_verified_at' => null,
    ]);

    fakeGoogleTokenInfo();

    $this->postJson('/api/v1/auth/google', ['id_token' => 'valid-token'])
        ->assertOk()
        ->assertJsonPath('is_new_user', false)
        ->assertJsonPath('user.id', $existing->id);

    expect($existing->fresh()->email_verified_at)->not->toBeNull();
});

test('a token minted for someone else app is rejected', function () {
    fakeGoogleTokenInfo(['aud' => 'someone-elses-client-id.apps.googleusercontent.com']);

    $this->postJson('/api/v1/auth/google', ['id_token' => 'stolen-token'])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    expect(User::query()->where('email', 'someone@gmail.com')->exists())->toBeFalse();
});

test('an unverified Google address is rejected', function () {
    fakeGoogleTokenInfo(['email_verified' => 'false']);

    $this->postJson('/api/v1/auth/google', ['id_token' => 'valid-token'])
        ->assertStatus(422);

    expect(User::query()->where('email', 'someone@gmail.com')->exists())->toBeFalse();
});

test('a token Google itself rejects does not sign anyone in', function () {
    Http::fake([
        'oauth2.googleapis.com/tokeninfo*' => Http::response(['error' => 'invalid_token'], 400),
    ]);

    $this->postJson('/api/v1/auth/google', ['id_token' => 'made-up-token'])
        ->assertStatus(422);
});

test('the route refuses a request with no token at all', function () {
    $this->postJson('/api/v1/auth/google', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['id_token']);
});
