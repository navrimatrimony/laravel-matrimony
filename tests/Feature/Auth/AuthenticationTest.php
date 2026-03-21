<?php

use App\Models\User;

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
