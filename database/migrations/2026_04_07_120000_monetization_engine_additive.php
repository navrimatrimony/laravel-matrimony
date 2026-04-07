<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advanced monetization: wallets, referrals, profile boosts, referral codes, coupon feature payload.
 * All checks are additive (PHASE-5 / no destructive changes).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('coupons', 'feature_payload')) {
            Schema::table('coupons', function (Blueprint $table) {
                $table->json('feature_payload')->nullable()->after('description');
            });
        }

        if (! Schema::hasColumn('users', 'referral_code')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('referral_code', 16)->nullable()->unique()->after('registering_for');
            });
        }

        if (! Schema::hasTable('user_wallets')) {
            Schema::create('user_wallets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('balance_paise')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('user_referrals')) {
            Schema::create('user_referrals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('referred_user_id')->unique()->constrained('users')->cascadeOnDelete();
                $table->boolean('reward_applied')->default(false);
                $table->timestamps();

                $table->index(['referrer_id', 'reward_applied']);
            });
        }

        if (! Schema::hasTable('profile_boosts')) {
            Schema::create('profile_boosts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamp('starts_at');
                $table->timestamp('ends_at');
                $table->string('source', 32)->nullable();
                $table->timestamps();

                $table->index(['user_id', 'ends_at']);
            });
        }

        if (! Schema::hasColumn('user_entitlements', 'value_override')) {
            Schema::table('user_entitlements', function (Blueprint $table) {
                $table->string('value_override', 64)->nullable()->after('valid_until');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('profile_boosts')) {
            Schema::dropIfExists('profile_boosts');
        }
        if (Schema::hasTable('user_referrals')) {
            Schema::dropIfExists('user_referrals');
        }
        if (Schema::hasTable('user_wallets')) {
            Schema::dropIfExists('user_wallets');
        }
        if (Schema::hasColumn('users', 'referral_code')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['referral_code']);
                $table->dropColumn('referral_code');
            });
        }
        if (Schema::hasColumn('coupons', 'feature_payload')) {
            Schema::table('coupons', function (Blueprint $table) {
                $table->dropColumn('feature_payload');
            });
        }
        if (Schema::hasColumn('user_entitlements', 'value_override')) {
            Schema::table('user_entitlements', function (Blueprint $table) {
                $table->dropColumn('value_override');
            });
        }
    }
};
