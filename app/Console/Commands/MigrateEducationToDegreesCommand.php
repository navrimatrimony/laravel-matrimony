<?php

namespace App\Console\Commands;

use App\Models\MatrimonyProfile;
use App\Services\EducationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historical: backfilled matrimony_profiles FK/text columns from legacy data.
 * After consolidation, own qualification lives only in {@see MatrimonyProfile::$highest_education}.
 */
class MigrateEducationToDegreesCommand extends Command
{
    protected $signature = 'education:migrate-to-degrees {--cleanup : Drop duplicate educations table after backfill} {--force : Re-resolve every profile using current alias + matching logic}';

    protected $description = 'Migrate profile education data from legacy columns into education_degrees';

    public function handle(EducationService $educationService): int
    {
        if (! Schema::hasColumn('matrimony_profiles', 'education_degree_id')) {
            $this->info('Own education is stored only in highest_education; education_degree_id was removed. Nothing to migrate.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');

        $query = MatrimonyProfile::query()->orderBy('id');
        if (! $force) {
            $query->whereNull('education_degree_id');
        }

        $count = 0;

        $query->chunkById(100, function ($profiles) use ($educationService, $force, &$count) {
            foreach ($profiles as $profile) {
                $original = $this->resolveOriginalEducationInput($profile);

                if ($original === '') {
                    if ($force) {
                        $profile->forceFill([
                            'education_degree_id' => null,
                            'education_text' => null,
                        ])->saveQuietly();
                        $count++;
                    }

                    continue;
                }

                $match = $educationService->findDegreeMatch($original);

                if ($match) {
                    $fill = [
                        'education_degree_id' => $match->id,
                        'education_text' => null,
                        'highest_education' => $match->title ?: $match->code,
                    ];
                    if (Schema::hasColumn('matrimony_profiles', 'highest_education_text')) {
                        $fill['highest_education_text'] = null;
                    }
                    $profile->forceFill($fill);
                } else {
                    $fill = [
                        'education_degree_id' => null,
                        'education_text' => mb_substr($original, 0, 512),
                        'highest_education' => mb_substr($original, 0, 255),
                    ];
                    if (Schema::hasColumn('matrimony_profiles', 'highest_education_text')) {
                        $fill['highest_education_text'] = mb_substr($original, 0, 512);
                    }
                    $profile->forceFill($fill);
                }

                $profile->saveQuietly();
                $count++;
            }
        });

        $withDegree = MatrimonyProfile::query()->whereNotNull('education_degree_id')->count();
        $this->info("Processed {$count} profile row update(s). Profiles with education_degree_id set: {$withDegree}.");

        if ($this->option('cleanup')) {
            return $this->call('education:cleanup-legacy-master', ['--force' => true]);
        }

        return self::SUCCESS;
    }

    /**
     * Canonical string to remap from (prefer legacy mirror column, then manual text, then fallbacks).
     */
    private function resolveOriginalEducationInput(MatrimonyProfile $profile): string
    {
        $h = trim((string) ($profile->highest_education ?? ''));
        if ($h !== '') {
            return $h;
        }

        $eduText = trim((string) ($profile->education_text ?? ''));
        if ($eduText !== '') {
            return $eduText;
        }

        $legacyText = trim((string) ($profile->highest_education_text ?? ''));
        if ($legacyText !== '') {
            return $legacyText;
        }

        if ($profile->highest_education_id && Schema::hasTable('educations')) {
            $row = DB::table('educations')->where('id', $profile->highest_education_id)->first();
            if ($row && isset($row->name)) {
                $name = trim((string) $row->name);

                return $name !== '' ? $name : '';
            }
        }

        return '';
    }
}
