<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('showcase_conversation_states', function (Blueprint $table) {
            if (! Schema::hasColumn('showcase_conversation_states', 'active_lock_until')) {
                $table->timestamp('active_lock_until')->nullable()->after('last_incoming_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('showcase_conversation_states', function (Blueprint $table) {
            if (Schema::hasColumn('showcase_conversation_states', 'active_lock_until')) {
                $table->dropColumn('active_lock_until');
            }
        });
    }
};
