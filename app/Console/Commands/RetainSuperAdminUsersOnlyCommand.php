<?php

namespace App\Console\Commands;

use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\Maintenance\MatrimonyProfileDatabasePurger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
            DB::transaction(function () use ($user) {
                $uid = (int) $user->id;

                foreach (MatrimonyProfile::withTrashed()->where('user_id', $uid)->cursor() as $profile) {
                    MatrimonyProfileDatabasePurger::purge($profile);
                }

                if (Schema::hasTable('location_suggestions')) {
                    DB::table('location_suggestions')->where('suggested_by', $uid)->delete();
                }

                if (Schema::hasTable('biodata_intakes')) {
                    $intakeIds = DB::table('biodata_intakes')->where('uploaded_by', $uid)->pluck('id');
                    MatrimonyProfileDatabasePurger::deleteOcrAndIntakesByIntakeIds(collect($intakeIds));
                }
                if (Schema::hasTable('ocr_correction_logs') && Schema::hasColumn('ocr_correction_logs', 'corrected_by')) {
                    DB::table('ocr_correction_logs')->where('corrected_by', $uid)->delete();
                }

                if (Schema::hasTable('admin_capabilities')) {
                    DB::table('admin_capabilities')->where('admin_id', $uid)->delete();
                }
                if (Schema::hasTable('admin_audit_logs')) {
                    DB::table('admin_audit_logs')->where('admin_id', $uid)->delete();
                }
                if (Schema::hasTable('abuse_reports') && Schema::hasColumn('abuse_reports', 'resolved_by_admin_id')) {
                    DB::table('abuse_reports')->where('resolved_by_admin_id', $uid)->update(['resolved_by_admin_id' => null]);
                }
                if (Schema::hasTable('users') && Schema::hasColumn('users', 'mobile_duplicate_of_user_id')) {
                    DB::table('users')->where('mobile_duplicate_of_user_id', $uid)->update(['mobile_duplicate_of_user_id' => null]);
                }

                $user->forceDelete();
            });
        }

        $this->info('Deleted '.$victims->count().' non–super-admin user(s). Kept: '.implode(', ', $keepEmails));

        return self::SUCCESS;
    }
}
