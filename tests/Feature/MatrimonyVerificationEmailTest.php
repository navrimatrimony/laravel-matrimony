<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

test('matrimony verification email page shows single send action when account has no email', function () {
    $user = User::factory()->unverified()->create();
    $user->forceFill(['email' => null])->save();

    $response = $this->actingAs($user)->get(route('matrimony.verification.email'));

    $response->assertOk();
    $response->assertSee(__('profile.verification_email_send_link'), false);
    $response->assertSee('name="email"', false);
    $response->assertSee(route('matrimony.verification.email.send'), false);
});

test('matrimony verification email page shows send link when account has email', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get(route('matrimony.verification.email'));

    $response->assertOk();
    $response->assertSee($user->email, false);
    $response->assertSee(__('profile.verification_email_send_link'), false);
});

test('one-step submit saves email and sends verification notification', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();
    $user->forceFill(['email' => null])->save();

    $response = $this->actingAs($user)->post(route('matrimony.verification.email.send'), [
        'email' => 'newuser@example.com',
    ]);

    $response->assertRedirect(route('matrimony.verification.email'));
    $response->assertSessionHas('status', 'verification-link-sent');

    $user->refresh();
    expect($user->email)->toBe('newuser@example.com');
    expect($user->email_verified_at)->toBeNull();

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('user with email requests link without posting email field', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();
    $originalEmail = $user->email;

    $response = $this->actingAs($user)->post(route('matrimony.verification.email.send'), []);

    $response->assertRedirect(route('matrimony.verification.email'));
    $response->assertSessionHas('status', 'verification-link-sent');

    expect($user->fresh()->email)->toBe($originalEmail);
    Notification::assertSentTo($user->fresh(), VerifyEmail::class);
});

test('user with email ignores posted email on send', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();
    $originalEmail = $user->email;

    $this->actingAs($user)->post(route('matrimony.verification.email.send'), [
        'email' => 'hijacker@example.com',
    ]);

    expect($user->fresh()->email)->toBe($originalEmail);
    Notification::assertSentTo($user->fresh(), VerifyEmail::class);
});
