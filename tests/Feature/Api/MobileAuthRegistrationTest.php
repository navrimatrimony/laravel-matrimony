<?php

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
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
                'gender' => 'female',
            ],
        ])
        ->assertJsonStructure([
            'token',
            'user' => ['id', 'name', 'email', 'gender'],
        ]);

    expect($response->json('token'))->toBeString()->not->toBeEmpty();
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
