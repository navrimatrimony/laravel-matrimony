<?php

use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create([
        'mobile' => '9876543210',
    ]);

    $response = $this->post('/login', [
        'mobile' => $user->mobile,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('matrimony.onboarding.show', ['step' => 2], absolute: false));
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create([
        'mobile' => '9876543211',
    ]);

    $this->post('/login', [
        'mobile' => $user->mobile,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});

test('otp verify sends user without matrimony profile to onboarding step 2 when intended url missing', function () {
    $user = User::factory()->create([
        'mobile_verified_at' => null,
    ]);
    $this->assertNull($user->matrimonyProfile);

    $otp = '654321';
    Cache::put('mobile_otp:'.$user->id, $otp, 600);

    $response = $this->actingAs($user)->post(route('mobile.verify.submit'), [
        'otp' => $otp,
    ]);

    $response->assertRedirect(route('matrimony.onboarding.show', ['step' => 2], absolute: false));
    $response->assertSessionHasNoErrors();
    expect($user->fresh()->mobile_verified_at)->not->toBeNull();
});

test('otp skip sends user without matrimony profile to onboarding step 2', function () {
    $user = User::factory()->create([
        'mobile_verified_at' => null,
    ]);
    $this->assertNull($user->matrimonyProfile);

    $response = $this->actingAs($user)->get(route('mobile.verify.skip'));

    $response->assertRedirect(route('matrimony.onboarding.show', ['step' => 2], absolute: false));
    $response->assertSessionHas('wizard_minimal', true);
    expect($user->fresh()->mobile_verified_at)->toBeNull();
});

test('otp skip after registration goes to onboarding even if intended_after_verify was lost', function () {
    $user = User::factory()->create([
        'mobile_verified_at' => null,
    ]);
    $this->assertNull($user->matrimonyProfile);

    $response = $this->actingAs($user)
        ->withSession(['from_registration' => true])
        ->get(route('mobile.verify.skip'));

    $response->assertRedirect(route('matrimony.onboarding.show', ['step' => 2], absolute: false));
    $response->assertSessionHas('wizard_minimal', true);
});

test('otp verify after registration goes to onboarding even if intended_after_verify was lost', function () {
    $user = User::factory()->create([
        'mobile_verified_at' => null,
    ]);
    $this->assertNull($user->matrimonyProfile);

    $otp = '111222';
    Cache::put('mobile_otp:'.$user->id, $otp, 600);

    $response = $this->actingAs($user)
        ->withSession(['from_registration' => true])
        ->post(route('mobile.verify.submit'), [
            'otp' => $otp,
        ]);

    $response->assertRedirect(route('matrimony.onboarding.show', ['step' => 2], absolute: false));
    $response->assertSessionHas('wizard_minimal', true);
});

test('otp skip with draft profile still goes to onboarding when wizard_minimal is set', function () {
    $user = User::factory()->create([
        'mobile_verified_at' => null,
    ]);
    MatrimonyProfile::factory()->create(['user_id' => $user->id]);
    expect($user->fresh()->matrimonyProfile)->not->toBeNull();

    $response = $this->actingAs($user)
        ->withSession(['wizard_minimal' => true])
        ->get(route('mobile.verify.skip'));

    $response->assertRedirect(route('matrimony.onboarding.show', ['step' => 2], absolute: false));
    $response->assertSessionHas('wizard_minimal', true);
});

test('otp verify with draft profile still goes to onboarding when wizard_minimal is set', function () {
    $user = User::factory()->create([
        'mobile_verified_at' => null,
    ]);
    MatrimonyProfile::factory()->create(['user_id' => $user->id]);
    $otp = '333444';
    Cache::put('mobile_otp:'.$user->id, $otp, 600);

    $response = $this->actingAs($user)
        ->withSession(['wizard_minimal' => true])
        ->post(route('mobile.verify.submit'), [
            'otp' => $otp,
        ]);

    $response->assertRedirect(route('matrimony.onboarding.show', ['step' => 2], absolute: false));
    expect($user->fresh()->mobile_verified_at)->not->toBeNull();
});

test('otp skip sends profiled user without wizard_minimal to dashboard when no intended url', function () {
    $user = User::factory()->create([
        'mobile_verified_at' => now(),
    ]);
    MatrimonyProfile::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get(route('mobile.verify.skip'));

    $response->assertRedirect(route('dashboard', absolute: false));
});
