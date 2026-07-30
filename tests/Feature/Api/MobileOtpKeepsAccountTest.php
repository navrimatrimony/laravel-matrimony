<?php

use App\Models\MatrimonyProfile;
use App\Models\MobileOtpChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function otpChallengeFor(string $mobile, string $otp = '123456'): MobileOtpChallenge
{
    return MobileOtpChallenge::query()->create([
        'challenge_id' => 'chal-'.$mobile,
        'mobile' => $mobile,
        'otp_hash' => Hash::make($otp),
        'expires_at' => now()->addMinutes(10),
        'attempts' => 0,
        'max_attempts' => 5,
        'locale' => 'mr',
    ]);
}

function verifyOtp(string $mobile, string $otp = '123456')
{
    return test()->postJson('/api/v1/auth/mobile-otp/verify', [
        'challenge_id' => 'chal-'.$mobile,
        'mobile' => $mobile,
        'otp' => $otp,
    ]);
}

test('a signed-in member keeps their account when verifying their number', function () {
    $user = User::factory()->create(['name' => 'Mohit', 'mobile' => null]);
    MatrimonyProfile::query()->create([
        'user_id' => $user->id,
        'full_name' => 'Mohit Kumar',
    ]);

    otpChallengeFor('9999900001');
    Sanctum::actingAs($user);

    $response = verifyOtp('9999900001')->assertOk();

    // The whole bug: this used to answer with a different, empty account, and
    // the app then sent the member back to the first onboarding question.
    expect($response->json('user.id'))->toBe($user->id)
        ->and($response->json('account_state.has_profile'))->toBeTrue()
        ->and($response->json('account_state.next_action'))->toBe('resume_onboarding');

    expect($user->fresh()->mobile)->toBe('9999900001')
        ->and($user->fresh()->mobile_verified_at)->not->toBeNull();

    // Exactly one account — no orphan was created alongside.
    expect(User::query()->count())->toBe(1);
});

test('the existing session is not replaced', function () {
    $user = User::factory()->create(['name' => 'Mohit', 'mobile' => null]);
    otpChallengeFor('9999900002');
    Sanctum::actingAs($user);

    $response = verifyOtp('9999900002')->assertOk();

    // A second token here would tempt the app to swap a session that was
    // already fine.
    expect($response->json('token'))->toBeNull();
});

test('a number belonging to someone else is refused, not taken', function () {
    $owner = User::factory()->create(['name' => 'Owner', 'mobile' => '9999900003']);
    $actor = User::factory()->create(['name' => 'Someone', 'mobile' => null]);

    otpChallengeFor('9999900003');
    Sanctum::actingAs($actor);

    verifyOtp('9999900003')->assertStatus(409);

    expect($owner->fresh()->mobile)->toBe('9999900003')
        ->and($actor->fresh()->mobile)->toBeNull();
});

test('signing in with a new number still creates an account', function () {
    otpChallengeFor('9999900004');

    $response = verifyOtp('9999900004')->assertOk();

    // The sign-in path is untouched: an anonymous caller with an unknown number
    // is a new member, and still gets an account and a token.
    expect($response->json('account_state.is_new_account'))->toBeTrue()
        ->and($response->json('token'))->toBeString()->not->toBeEmpty();

    expect(User::query()->where('mobile', '9999900004')->exists())->toBeTrue();
});

test('signing in with a known number returns that account', function () {
    $existing = User::factory()->create(['name' => 'Pooja', 'mobile' => '9999900005']);
    otpChallengeFor('9999900005');

    $response = verifyOtp('9999900005')->assertOk();

    expect($response->json('user.id'))->toBe($existing->id)
        ->and($response->json('account_state.is_new_account'))->toBeFalse();

    expect(User::query()->count())->toBe(1);
});
