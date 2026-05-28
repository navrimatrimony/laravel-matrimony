<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('referral_reward_rules')) {
            return;
        }

        Schema::table('referral_reward_rules', function (Blueprint $table) {
            if (! Schema::hasColumn('referral_reward_rules', 'referred_checkout_excluded')) {
                $table->boolean('referred_checkout_excluded')->default(false)->after('is_active');
            }
            if (! Schema::hasColumn('referral_reward_rules', 'referred_checkout_percent_off')) {
                $table->unsignedTinyInteger('referred_checkout_percent_off')->nullable()->after('referred_checkout_excluded');
            }
            if (! Schema::hasColumn('referral_reward_rules', 'referred_checkout_extra_days')) {
                $table->unsignedSmallInteger('referred_checkout_extra_days')->nullable()->after('referred_checkout_percent_off');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('referral_reward_rules')) {
            return;
        }

        Schema::table('referral_reward_rules', function (Blueprint $table) {
            foreach (['referred_checkout_extra_days', 'referred_checkout_percent_off', 'referred_checkout_excluded'] as $col) {
                if (Schema::hasColumn('referral_reward_rules', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
