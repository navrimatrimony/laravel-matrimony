<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Goal 4: staged native registration + org team size.
 * registration_completed_at null ⇒ incomplete onboarding (cannot operate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suchak_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('suchak_accounts', 'employee_count')) {
                $table->unsignedInteger('employee_count')->nullable()->after('business_type');
            }
            if (! Schema::hasColumn('suchak_accounts', 'registration_completed_at')) {
                $table->timestamp('registration_completed_at')->nullable()->after('verified_at');
            }
            if (! Schema::hasColumn('suchak_accounts', 'onboarding_step')) {
                $table->string('onboarding_step', 64)->nullable()->after('registration_completed_at');
            }
        });

        // Existing accounts (web/complete) are treated as registration-complete so access is not broken.
        if (Schema::hasColumn('suchak_accounts', 'registration_completed_at')) {
            \Illuminate\Support\Facades\DB::table('suchak_accounts')
                ->whereNull('registration_completed_at')
                ->update([
                    'registration_completed_at' => \Illuminate\Support\Facades\DB::raw('COALESCE(verified_at, created_at)'),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('suchak_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('suchak_accounts', 'employee_count')) {
                $table->dropColumn('employee_count');
            }
            if (Schema::hasColumn('suchak_accounts', 'registration_completed_at')) {
                $table->dropColumn('registration_completed_at');
            }
            if (Schema::hasColumn('suchak_accounts', 'onboarding_step')) {
                $table->dropColumn('onboarding_step');
            }
        });
    }
};
