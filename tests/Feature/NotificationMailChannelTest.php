<?php

use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Notifications\InterestAcceptedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

test('activity notification includes mail channel when user has email and mail notifications are enabled', function () {
    config(['notifications.mail.enabled' => true]);

    $receiver = User::factory()->create(['email' => 'receiver@example.com']);
    MatrimonyProfile::factory()->for($receiver)->create();

    $accepter = MatrimonyProfile::factory()->create();

    $notification = new InterestAcceptedNotification($accepter);

    expect($notification->via($receiver))->toBe(['database', 'mail']);

    Mail::fake();
    $receiver->notify($notification);

    expect(DB::table('notifications')->where('notifiable_id', $receiver->id)->count())->toBe(1);
});

test('activity notification skips email when user has no email', function () {
    Mail::fake();

    config(['notifications.mail.enabled' => true]);

    $receiver = User::factory()->create(['email' => null]);
    MatrimonyProfile::factory()->for($receiver)->create();
    $accepter = MatrimonyProfile::factory()->create();

    $receiver->notify(new InterestAcceptedNotification($accepter));

    Mail::assertNothingOutgoing();
});

test('activity notification skips email when notification mail is disabled', function () {
    Mail::fake();

    config(['notifications.mail.enabled' => false]);

    $receiver = User::factory()->create(['email' => 'on@example.com']);
    MatrimonyProfile::factory()->for($receiver)->create();
    $accepter = MatrimonyProfile::factory()->create();

    $receiver->notify(new InterestAcceptedNotification($accepter));

    Mail::assertNothingOutgoing();
});
