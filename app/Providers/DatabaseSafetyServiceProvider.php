<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Blocks destructive Artisan DB commands outside automated tests.
 *
 * @see https://laravel.com/docs/artisan#migrate-fresh
 */
class DatabaseSafetyServiceProvider extends ServiceProvider
{
    private const BLOCKED_COMMANDS = [
        'migrate:fresh',
        'db:wipe',
    ];

    public function boot(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if (! in_array($event->command, self::BLOCKED_COMMANDS, true)) {
                return;
            }

            if ($this->app->environment('testing') || $this->app->runningUnitTests()) {
                return;
            }

            $userCount = $this->safeUserCount();

            if ($userCount > 0) {
                throw new RuntimeException(
                    "Refusing to run `{$event->command}`: the database contains {$userCount} user row(s). ".
                    'Wiping or rebuilding the database is disabled while user data exists. Use `php artisan migrate` only.'
                );
            }

            throw new RuntimeException(
                "Refusing to run `{$event->command}`: destructive database commands are disabled in this application. ".
                'Use `php artisan migrate` to apply migrations.'
            );
        });
    }

    private function safeUserCount(): int
    {
        try {
            if (! Schema::hasTable('users')) {
                return 0;
            }

            return (int) User::query()->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
