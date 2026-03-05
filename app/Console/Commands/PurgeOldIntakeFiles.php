<?php

namespace App\Console\Commands;

use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PurgeOldIntakeFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Run manually or via scheduler to delete old biodata intake files
     * according to admin-configured retention policy.
     */
    protected $signature = 'intake:purge-old-files';

    /**
     * The console command description.
     */
    protected $description = 'Delete old biodata intake upload files based on retention settings.';

    public function handle(): int
    {
        $days = (int) AdminSetting::getValue('intake_file_retention_days', '0');
        if ($days <= 0) {
            $this->info('intake_file_retention_days is 0 or not set. Skipping purge.');
            return self::SUCCESS;
        }

        $keepParsed = AdminSetting::getBool('intake_keep_parsed_json_after_purge', true);
        $cutoff = now()->subDays($days);

        $this->info("Purging biodata intake files older than {$days} days (created_at <= {$cutoff->toDateTimeString()})...");

        $total = 0;
        $batchSize = 100;

        BiodataIntake::whereNotNull('file_path')
            ->where('file_path', '!=', '')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById($batchSize, function ($intakes) use (&$total, $keepParsed) {
                foreach ($intakes as $intake) {
                    $path = $intake->file_path;
                    $fullPath = storage_path('app/private/' . ltrim($path, '/'));

                    try {
                        if (is_file($fullPath)) {
                            @unlink($fullPath);
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Failed to delete biodata intake file during purge', [
                            'intake_id' => $intake->id,
                            'file_path' => $path,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $update = ['file_path' => null];
                    if (! $keepParsed) {
                        $update['parsed_json'] = null;
                    }

                    $intake->forceFill($update);
                    $intake->saveQuietly();

                    $total++;
                }
            });

        $this->info("Purged files for {$total} biodata intakes.");

        return self::SUCCESS;
    }
}

