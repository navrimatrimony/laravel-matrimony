<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_referrals')) {
            return;
        }

        Schema::table('user_referrals', function (Blueprint $table) {
            if (! Schema::hasColumn('user_referrals', 'referred_checkout_bonus_used_at')) {
                $table->timestamp('referred_checkout_bonus_used_at')->nullable()->after('pending_reward');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_referrals')) {
            return;
        }

        Schema::table('user_referrals', function (Blueprint $table) {
            if (Schema::hasColumn('user_referrals', 'referred_checkout_bonus_used_at')) {
                $table->dropColumn('referred_checkout_bonus_used_at');
            }
        });
    }
};
