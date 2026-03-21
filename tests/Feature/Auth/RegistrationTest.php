<?php

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'mobile' => '9123456789',
        'password' => 'password',
        'password_confirmation' => 'password',
        'registering_for' => 'self',
    ]);

    $response->assertRedirect();
    if (auth()->check()) {
        $this->assertAuthenticated();
    }

    $this->assertDatabaseHas('users', [
        'mobile' => '9123456789',
        'registering_for' => 'self',
        'email' => null,
    ]);
});
