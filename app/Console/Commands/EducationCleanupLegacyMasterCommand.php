<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops duplicate {@code educations} table and FK on matrimony_profiles.highest_education_id.
 * Legacy column highest_education_id remains (nullable) per Phase-5 column retention.
 */
class EducationCleanupLegacyMasterCommand extends Command
{
    protected $signature = 'education:cleanup-legacy-master {--force : Required to execute}';

    protected $description = 'Remove educations master table after migrating to education_degrees';

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->error('Refusing to run without --force (drops database table).');

            return self::FAILURE;
        }

        if (Schema::hasTable('matrimony_profiles')
            && Schema::hasColumn('matrimony_profiles', 'highest_education_id')) {
            DB::table('matrimony_profiles')->whereNotNull('highest_education_id')->update(['highest_education_id' => null]);
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                try {
                    $table->dropForeign(['highest_education_id']);
                } catch (\Throwable) {
                    //
                }
            });
        }

        Schema::dropIfExists('educations');

        $modelPath = app_path('Models/Education.php');
        if (is_file($modelPath)) {
            @unlink($modelPath);
            $this->warn('Removed app/Models/Education.php — verify git status.');
        }

        $this->info('Legacy educations master removed.');

        return self::SUCCESS;
    }
}
