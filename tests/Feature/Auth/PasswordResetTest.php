<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

test('reset password link screen can be rendered', function () {
    $response = $this->get('/forgot-password');

    $response->assertStatus(200);
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['login' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['login' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->get('/reset-password/'.$notification->token);

        $response->assertStatus(200);

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['login' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->post('/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });
});

test('reset password link can be requested using mobile', function () {
    Notification::fake();

    $user = User::factory()->create([
        'mobile' => '9123456789',
        'email' => 'mobile-reset@example.com',
    ]);

    $this->post('/forgot-password', ['login' => $user->mobile]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password link can be requested using username', function () {
    Notification::fake();

    $user = User::factory()->create([
        'name' => 'ResetByUsername',
        'email' => 'username-reset@example.com',
    ]);

    $this->post('/forgot-password', ['login' => 'ResetByUsername']);

    Notification::assertSentTo($user, ResetPassword::class);
});
