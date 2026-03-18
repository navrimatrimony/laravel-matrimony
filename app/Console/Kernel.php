<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        \App\Console\Commands\BuildOcrFrequencyPatterns::class,
        \App\Console\Commands\SeedOcrBaselinePatterns::class,
        \App\Console\Commands\PurgeOldIntakeFiles::class,
        \App\Console\Commands\IntakeAuditCommand::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // NightlyOcrLearningJob is scheduled in routes/console.php (Day-29)
        $schedule->command('intake:purge-old-files')->dailyAt('03:00');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
