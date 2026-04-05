<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Speeds up discover-sort subqueries that filter by receiver_profile_id + sender_profile_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('interests')) {
            return;
        }
        if (Schema::hasIndex('interests', 'interests_receiver_sender_idx')) {
            return;
        }
        Schema::table('interests', function (Blueprint $table) {
            $table->index(['receiver_profile_id', 'sender_profile_id'], 'interests_receiver_sender_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('interests')) {
            return;
        }
        if (! Schema::hasIndex('interests', 'interests_receiver_sender_idx')) {
            return;
        }
        Schema::table('interests', function (Blueprint $table) {
            $table->dropIndex('interests_receiver_sender_idx');
        });
    }
};
