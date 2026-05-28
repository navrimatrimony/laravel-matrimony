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
            if (! Schema::hasColumn('user_referrals', 'pending_claim_at')) {
                $table->timestamp('pending_claim_at')->nullable()->after('pending_reward');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_referrals')) {
            return;
        }

        Schema::table('user_referrals', function (Blueprint $table) {
            if (Schema::hasColumn('user_referrals', 'pending_claim_at')) {
                $table->dropColumn('pending_claim_at');
            }
        });
    }
};
