<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Maintenance\UserAccountDatabasePurger;
use Illuminate\Console\Command;

/**
 * Deletes every {@see User} except those with {@code admin_role = super_admin}.
 * {@see TestAdminRolesSeeder} seeds {@code super_admin_test@example.com} with a known password.
 *
 * Default: dry-run. Pass {@code --execute} to apply.
 */
class RetainSuperAdminUsersOnlyCommand extends Command
{
    protected $signature = 'users:retain-super-admins-only
                            {--execute : Actually delete users (default is dry-run)}';

    protected $description = 'Remove all users except admin_role=super_admin (dry-run unless --execute)';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');

        $keepIds = User::query()->where('admin_role', 'super_admin')->pluck('id');
        if ($keepIds->isEmpty()) {
            $this->error('No user with admin_role=super_admin found. Run: php artisan db:seed --class=Database\\\\Seeders\\\\TestAdminRolesSeeder');

            return self::FAILURE;
        }

        $keepEmails = User::query()->whereIn('id', $keepIds)->pluck('email')->all();
        $victims = User::query()->whereNotIn('id', $keepIds)->orderBy('id')->get();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Super admin user id(s)', $keepIds->implode(', ')],
                ['Kept email(s)', implode('; ', $keepEmails)],
                ['Users to delete', (string) $victims->count()],
            ]
        );

        if ($victims->isEmpty()) {
            $this->info('Nothing to delete.');

            return self::SUCCESS;
        }

        if (! $execute) {
            $this->warn('Dry-run only. Re-run with --execute to delete '.(string) $victims->count().' user(s).');

            return self::SUCCESS;
        }

        foreach ($victims as $user) {
            UserAccountDatabasePurger::purgeUserAccount($user);
        }

        $this->info('Deleted '.$victims->count().' non–super-admin user(s). Kept: '.implode(', ', $keepEmails));

        return self::SUCCESS;
    }
}
