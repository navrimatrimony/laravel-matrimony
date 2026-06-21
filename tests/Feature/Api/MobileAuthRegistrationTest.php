<?php

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportException;

test('ApiAuthRegister returns token when registered event notification fails', function () {
    Log::spy();
    Event::shouldReceive('dispatch')
        ->once()
        ->withArgs(fn (mixed $event): bool => $event instanceof Registered && $event->user instanceof User)
        ->andThrow(new TransportException('Failed to authenticate on SMTP server with username "navrimatrimony@gmail.com".'));
    Event::shouldReceive('dispatch')
        ->zeroOrMoreTimes()
        ->withAnyArgs()
        ->andReturnNull();

    $response = $this->postJson('/api/v1/register', [
        'name' => 'Mobile Register User',
        'gender' => 'female',
        'email' => 'mobile-register@example.test',
        'password' => 'password',
    ]);

    $response
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Registration successful',
            'user' => [
                'name' => 'Mobile Register User',
                'email' => 'mobile-register@example.test',
            ],
        ])
        ->assertJsonStructure([
            'token',
            'user' => ['id', 'name', 'email'],
        ]);

    expect($response->json('token'))->toBeString()->not->toBeEmpty();
    expect($response->json('user'))->not->toHaveKey('gender');
    expect(User::query()->where('email', 'mobile-register@example.test')->exists())->toBeTrue();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'Mobile API registration email verification notification failed'
                && ($context['exception'] ?? null) === TransportException::class
                && str_contains((string) ($context['message'] ?? ''), '[redacted-email]')
                && ! str_contains((string) ($context['message'] ?? ''), 'navrimatrimony@gmail.com');
        });
});

test('ApiAuthLogin accepts mobile email username and legacy email payload', function (string $loginKey, string $loginValue) {
    User::factory()->create([
        'name' => 'MobileLoginUser',
        'email' => 'mobile-login@example.test',
        'mobile' => '9876543201',
        'password' => Hash::make('password'),
    ]);

    $response = $this->postJson('/api/v1/login', [
        $loginKey => $loginValue,
        'password' => 'password',
    ]);

    $response
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Login successful',
            'user' => [
                'email' => 'mobile-login@example.test',
            ],
        ])
        ->assertJsonStructure([
            'token',
            'user' => ['id', 'email'],
        ]);

    expect($response->json('token'))->toBeString()->not->toBeEmpty();
})->with([
    'mobile login field' => ['login', '9876543201'],
    'email login field' => ['login', 'mobile-login@example.test'],
    'username login field' => ['login', 'MobileLoginUser'],
    'legacy email payload' => ['email', 'mobile-login@example.test'],
]);

test('ApiAuthLogin rejects invalid mobile api credentials', function () {
    User::factory()->create([
        'email' => 'mobile-login-fail@example.test',
        'mobile' => '9876543202',
        'password' => Hash::make('password'),
    ]);

    $response = $this->postJson('/api/v1/login', [
        'login' => '9876543202',
        'password' => 'wrong-password',
    ]);

    $response
        ->assertUnauthorized()
        ->assertJson([
            'success' => false,
            'message' => 'Invalid credentials',
        ]);
});
