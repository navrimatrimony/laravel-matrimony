<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_referrals', function (Blueprint $table) {
            if (! Schema::hasColumn('user_referrals', 'utm_source')) {
                $table->string('utm_source', 64)->nullable()->after('registration_ip');
            }
            if (! Schema::hasColumn('user_referrals', 'utm_medium')) {
                $table->string('utm_medium', 64)->nullable()->after('utm_source');
            }
            if (! Schema::hasColumn('user_referrals', 'utm_campaign')) {
                $table->string('utm_campaign', 64)->nullable()->after('utm_medium');
            }
            if (! Schema::hasColumn('user_referrals', 'utm_content')) {
                $table->string('utm_content', 64)->nullable()->after('utm_campaign');
            }
            if (! Schema::hasColumn('user_referrals', 'renewal_micro_bonus_applied_at')) {
                $table->timestamp('renewal_micro_bonus_applied_at')->nullable()->after('referred_checkout_bonus_used_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_referrals', function (Blueprint $table) {
            foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'renewal_micro_bonus_applied_at'] as $col) {
                if (Schema::hasColumn('user_referrals', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
