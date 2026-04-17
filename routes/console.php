<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('notifications:cleanup')->daily();
Schedule::job(new \App\Jobs\NightlyOcrLearningJob)->dailyAt('02:00');
Schedule::command('showcase:random-views')->hourly();
Schedule::command('admin:evaluate-action-effects')->hourly();

Schedule::command('intake:purge-old-files')->dailyAt('03:00');
Schedule::command('subscriptions:expire')->daily();
Schedule::command('engagement:inactive-reminders')->dailyAt('09:15');
Schedule::command('engagement:new-match-digest')->dailyAt('11:45');
Schedule::command('showcase-chat:tick')->everyMinute();
Schedule::command('showcase:respond-incoming-interests')->everyFifteenMinutes();
Schedule::command('showcase:send-outgoing-interests')->everyFifteenMinutes();
