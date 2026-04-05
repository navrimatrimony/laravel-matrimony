<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Received inbox ordering: paid senders surface first ({@see \App\Services\InterestPriorityService}).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('interests')) {
            return;
        }

        if (! Schema::hasColumn('interests', 'priority_score')) {
            Schema::table('interests', function (Blueprint $table) {
                $table->unsignedSmallInteger('priority_score')->default(1)->after('status');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('interests') || ! Schema::hasColumn('interests', 'priority_score')) {
            return;
        }

        Schema::table('interests', function (Blueprint $table) {
            $table->dropColumn('priority_score');
        });
    }
};
