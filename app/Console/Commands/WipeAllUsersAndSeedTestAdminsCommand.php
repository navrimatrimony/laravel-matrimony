<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Maintenance\UserAccountDatabasePurger;
use Database\Seeders\TestAdminRolesSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Deletes every user row (members + admins) using the same dependency cleanup as
 * {@see RetainSuperAdminUsersOnlyCommand}, then seeds {@see TestAdminRolesSeeder}
 * (three test admin accounts; shared password in that seeder).
 *
 * Default: dry-run. Requires {@code --execute}. In {@code production}, also requires {@code --force}.
 */
class WipeAllUsersAndSeedTestAdminsCommand extends Command
{
    protected $signature = 'users:wipe-all-seed-test-admins
                            {--execute : Actually delete all users and run TestAdminRolesSeeder}
                            {--force : Allow in production (APP_ENV=production)}';

    protected $description = 'Delete ALL user accounts and data tied to them, then seed test admin users (dry-run unless --execute)';

    public function handle(): int
    {
        if ($this->laravel->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to run in production without --force.');

            return self::FAILURE;
        }

        if (! Schema::hasTable('users')) {
            $this->error('Table users does not exist.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $users = User::query()->orderBy('id')->get();
        $profileCount = Schema::hasTable('matrimony_profiles')
            ? (int) \Illuminate\Support\Facades\DB::table('matrimony_profiles')->count()
            : 0;

        $this->table(
            ['Metric', 'Value'],
            [
                ['Environment', (string) config('app.env')],
                ['Users to delete', (string) $users->count()],
                ['Matrimony profiles (before)', (string) $profileCount],
            ]
        );

        if ($users->isEmpty()) {
            if ($execute) {
                $this->call('db:seed', ['--class' => TestAdminRolesSeeder::class]);
                $this->info('Seeded '.TestAdminRolesSeeder::class.' (no users were present).');
            } else {
                $this->info('No users. Use --execute to run TestAdminRolesSeeder only.');
            }

            return self::SUCCESS;
        }

        if (! $execute) {
            $this->warn('Dry-run only. Re-run with --execute to delete '.$users->count().' user(s) and seed test admins.');
            $this->line('After wipe, admins are created by TestAdminRolesSeeder (emails: super_admin_test@example.com, data_admin_test@example.com, auditor_test@example.com).');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($users->count());
        $bar->start();

        foreach ($users as $user) {
            UserAccountDatabasePurger::purgeUserAccount($user);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->call('db:seed', ['--class' => TestAdminRolesSeeder::class]);

        $this->info('Done. All users removed; TestAdminRolesSeeder applied. Use passwords from Database\\Seeders\\TestAdminRolesSeeder (currently Password@123).');

        return self::SUCCESS;
    }
}
