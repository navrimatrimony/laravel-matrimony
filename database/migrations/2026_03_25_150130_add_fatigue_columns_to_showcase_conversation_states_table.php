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
            if (!Schema::hasColumn('showcase_conversation_states', 'unanswered_incoming_count')) {
                $table->unsignedInteger('unanswered_incoming_count')->default(0)->after('admin_takeover_until');
            }
            if (!Schema::hasColumn('showcase_conversation_states', 'last_incoming_at')) {
                $table->timestamp('last_incoming_at')->nullable()->after('unanswered_incoming_count');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('showcase_conversation_states', function (Blueprint $table) {
            if (Schema::hasColumn('showcase_conversation_states', 'last_incoming_at')) {
                $table->dropColumn('last_incoming_at');
            }
            if (Schema::hasColumn('showcase_conversation_states', 'unanswered_incoming_count')) {
                $table->dropColumn('unanswered_incoming_count');
            }
        });
    }
};
