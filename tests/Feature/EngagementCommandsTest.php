<?php

use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

test('inactive reminders command updates user and stores notification', function () {
    config([
        'engagement.inactive_reminder.enabled' => true,
        'engagement.inactive_reminder.after_days' => 3,
        'engagement.inactive_reminder.cooldown_days' => 7,
    ]);

    $user = User::factory()->create([
        'last_seen_at' => now()->subDays(10),
        'last_inactive_reminder_sent_at' => null,
    ]);

    MatrimonyProfile::factory()->for($user)->create([
        'lifecycle_state' => 'active',
    ]);

    Artisan::call('engagement:inactive-reminders');

    $user->refresh();
    expect($user->last_inactive_reminder_sent_at)->not->toBeNull();
    expect($user->notifications()->count())->toBe(1);
});

test('inactive reminders command is a no-op when disabled', function () {
    config(['engagement.inactive_reminder.enabled' => false]);

    $user = User::factory()->create([
        'last_seen_at' => now()->subDays(10),
        'last_inactive_reminder_sent_at' => null,
    ]);
    MatrimonyProfile::factory()->for($user)->create(['lifecycle_state' => 'active']);

    Artisan::call('engagement:inactive-reminders');

    $user->refresh();
    expect($user->last_inactive_reminder_sent_at)->toBeNull();
});
