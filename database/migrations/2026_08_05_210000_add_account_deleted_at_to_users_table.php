<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a user row that has been reduced to a tombstone by account deletion.
 *
 * A separate fact from `deletion_requested_at`: that one says the member ASKED
 * and starts the 30-day clock, this one says the erase actually RAN. Both are
 * needed — a support question six months later ("when did this account go?")
 * cannot be answered from the request date alone, because the request can be
 * cancelled.
 *
 * The row survives only because conversations.profile_one_id/two_id and
 * messages.sender/receiver_profile_id are NOT NULL with RESTRICT foreign keys,
 * so the counterpart's chat cannot be kept without it. Everything identifying
 * is wiped; what is left is a shell that owns no personal data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'account_deleted_at')) {
                $table->timestamp('account_deleted_at')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'account_deleted_at')) {
                $table->dropColumn('account_deleted_at');
            }
        });
    }
};
