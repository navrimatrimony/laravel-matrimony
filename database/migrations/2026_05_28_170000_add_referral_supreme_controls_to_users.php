<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'referral_rewards_frozen_at')) {
                $table->timestamp('referral_rewards_frozen_at')->nullable()->after('referral_code');
            }
            if (! Schema::hasColumn('users', 'referral_code_disabled_at')) {
                $table->timestamp('referral_code_disabled_at')->nullable()->after('referral_rewards_frozen_at');
            }
            if (! Schema::hasColumn('users', 'referral_monthly_cap_override')) {
                $table->unsignedSmallInteger('referral_monthly_cap_override')->nullable()->after('referral_code_disabled_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            foreach (['referral_monthly_cap_override', 'referral_code_disabled_at', 'referral_rewards_frozen_at'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
