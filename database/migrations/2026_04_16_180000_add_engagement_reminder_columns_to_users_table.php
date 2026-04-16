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
            if (! Schema::hasColumn('users', 'last_inactive_reminder_sent_at')) {
                $table->timestamp('last_inactive_reminder_sent_at')->nullable()->after('last_seen_at');
            }
            if (! Schema::hasColumn('users', 'last_new_matches_digest_sent_at')) {
                $table->timestamp('last_new_matches_digest_sent_at')->nullable()->after('last_inactive_reminder_sent_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'last_new_matches_digest_sent_at')) {
                $table->dropColumn('last_new_matches_digest_sent_at');
            }
            if (Schema::hasColumn('users', 'last_inactive_reminder_sent_at')) {
                $table->dropColumn('last_inactive_reminder_sent_at');
            }
        });
    }
};
