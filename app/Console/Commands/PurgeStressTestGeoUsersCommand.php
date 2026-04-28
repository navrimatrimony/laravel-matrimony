<?php

namespace App\Console\Commands;

use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\Maintenance\MatrimonyProfileDatabasePurger;
use App\Support\Location\StressTestSyntheticGeo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes members whose biodata touches {@see \Database\Seeders\LocationStressTestSeeder} synthetic
 * districts/talukas/cities so Step 1 can drop that geo without fallback remaps.
 *
 * Safe default: dry-run. Use {@code --execute} to apply.
 */
class PurgeStressTestGeoUsersCommand extends Command
{
    protected $signature = 'location:purge-stress-test-geo-users
                            {--execute : Actually delete rows (default is dry-run)}';

    protected $description = 'Dry-run or delete users/profiles tied to LocationStressTestSeeder synthetic geo';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');

        $districtIds = StressTestSyntheticGeo::stressDistrictIds();
        if ($districtIds->isEmpty()) {
            $this->info('No stress-test districts found (names like Gujarat-1 … Madhya Pradesh-5). Nothing to do.');

            return self::SUCCESS;
        }

        $profiles = StressTestSyntheticGeo::profilesTouchingStressGeo();
        if ($profiles->isEmpty()) {
            $this->info('Stress districts exist but no matrimony_profiles reference them. OK for Step 1.');

            return self::SUCCESS;
        }

        $userIds = $profiles->pluck('user_id')->unique()->sort()->values();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Stress district rows', (string) $districtIds->count()],
                ['Profiles to purge', (string) $profiles->count()],
                ['Distinct users', (string) $userIds->count()],
            ]
        );

        $this->line('User IDs: '.$userIds->implode(', '));
        foreach ($userIds as $uid) {
            $email = User::query()->whereKey($uid)->value('email');
            $this->line("  user {$uid}: ".($email ?? '(missing)'));
        }

        if (! $execute) {
            $this->warn('Dry-run only. Re-run with --execute to delete these profiles/users and clear matching location_suggestions.');

            return self::SUCCESS;
        }

        $suggestionCount = 0;
        if (Schema::hasTable('location_suggestions')) {
            $suggestionCount = (int) DB::table('location_suggestions')->whereIn('district_id', $districtIds)->count();
        }

        DB::transaction(function () use ($profiles, $districtIds, $userIds, $suggestionCount) {
            if ($suggestionCount > 0 && Schema::hasTable('location_suggestions')) {
                DB::table('location_suggestions')->whereIn('district_id', $districtIds)->delete();
            }

            foreach ($profiles as $profile) {
                $fresh = MatrimonyProfile::query()->whereKey($profile->id)->first();
                if ($fresh === null) {
                    continue;
                }
                MatrimonyProfileDatabasePurger::purge($fresh);
            }

            foreach ($userIds as $uid) {
                $stillHas = MatrimonyProfile::withTrashed()->where('user_id', $uid)->exists();
                if ($stillHas) {
                    continue;
                }
                if (Schema::hasTable('location_suggestions')) {
                    DB::table('location_suggestions')->where('suggested_by', $uid)->delete();
                }
                if (Schema::hasTable('biodata_intakes')) {
                    DB::table('biodata_intakes')->where('uploaded_by', $uid)->delete();
                }
                $user = User::query()->whereKey($uid)->first();
                if ($user !== null) {
                    $user->forceDelete();
                }
            }
        });

        $this->info('Done. Deleted '.$suggestionCount.' location_suggestion(s) on stress districts, purged '.$profiles->count().' profile(s), removed orphan user account(s) where applicable.');

        return self::SUCCESS;
    }
}
