<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_referrals')) {
            return;
        }

        Schema::table('user_referrals', function (Blueprint $table) {
            if (! Schema::hasColumn('user_referrals', 'review_status')) {
                $table->string('review_status', 32)->default('approved')->after('reward_applied');
            }
            if (! Schema::hasColumn('user_referrals', 'fraud_flags')) {
                $table->json('fraud_flags')->nullable()->after('review_status');
            }
            if (! Schema::hasColumn('user_referrals', 'fraud_notes')) {
                $table->text('fraud_notes')->nullable()->after('fraud_flags');
            }
            if (! Schema::hasColumn('user_referrals', 'registration_ip')) {
                $table->string('registration_ip', 45)->nullable()->after('fraud_notes');
            }
            if (! Schema::hasColumn('user_referrals', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('registration_ip');
            }
            if (! Schema::hasColumn('user_referrals', 'reviewed_by_admin_id')) {
                $table->foreignId('reviewed_by_admin_id')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            }
        });

        if (Schema::hasColumn('user_referrals', 'review_status')) {
            DB::table('user_referrals')->whereNull('review_status')->update(['review_status' => 'approved']);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_referrals')) {
            return;
        }

        Schema::table('user_referrals', function (Blueprint $table) {
            if (Schema::hasColumn('user_referrals', 'reviewed_by_admin_id')) {
                $table->dropConstrainedForeignId('reviewed_by_admin_id');
            }
            foreach (['reviewed_at', 'registration_ip', 'fraud_notes', 'fraud_flags', 'review_status'] as $col) {
                if (Schema::hasColumn('user_referrals', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
