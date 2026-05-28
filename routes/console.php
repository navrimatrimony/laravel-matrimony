<?php

use App\Services\MonitoringService;
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
Schedule::command('engagement:inactive-reminders')->dailyAt('09:15')->withoutOverlapping(120)->onOneServer();
Schedule::command('engagement:new-match-digest')->dailyAt('11:45')->withoutOverlapping(180)->onOneServer();
Schedule::command('showcase-chat:tick')->everyMinute();
Schedule::command('showcase:respond-incoming-interests')->everyFifteenMinutes();
Schedule::command('showcase:send-outgoing-interests')->everyFifteenMinutes();
Schedule::command('payments:repair-missing')->everyFiveMinutes();
Schedule::command('payments:reconcile')->dailyAt('01:00');
Schedule::call(function (): void {
    app(MonitoringService::class)->evaluateAlerts();
})->everyMinute()->name('payments:evaluate-alerts');

/*
| Phase 4+ productionization governance schedules (deterministic, lock-safe).
*/
Schedule::command('data-audit:analyze')
    ->dailyAt('02:30')
    ->name('data-audit-analyze')
    ->withoutOverlapping(45)
    ->onOneServer();
Schedule::command('data-audit:snapshot --entity=matrimony_profile --limit=10')
    ->dailyAt('02:45')
    ->name('data-audit-snapshot')
    ->withoutOverlapping(45)
    ->onOneServer();
Schedule::command('data-audit:compare --latest')
    ->dailyAt('03:00')
    ->name('data-audit-compare')
    ->withoutOverlapping(45)
    ->onOneServer();
Schedule::command('data-audit:cleanup --dry-run')
    ->dailyAt('03:10')
    ->name('data-audit-cleanup-dryrun')
    ->withoutOverlapping(30)
    ->onOneServer();
Schedule::command('data-audit:notify')
    ->dailyAt('03:15')
    ->name('data-audit-notify')
    ->withoutOverlapping(15)
    ->onOneServer();

/*
| Autonomous governance operations (phase: autonomous ops hardening).
*/
Schedule::command('governance:autonomous-ops --queue')
    ->dailyAt('03:30')
    ->name('governance-autonomous-ops')
    ->withoutOverlapping(60)
    ->onOneServer();
Schedule::command('governance:queue-health')
    ->everyThirtyMinutes()
    ->name('governance-queue-health')
    ->withoutOverlapping(25)
    ->onOneServer();
