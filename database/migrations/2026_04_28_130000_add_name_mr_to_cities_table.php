<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5 additive: Marathi display for cities (mirrors villages / search).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cities')) {
            return;
        }
        if (! Schema::hasColumn('cities', 'name_mr')) {
            Schema::table('cities', function (Blueprint $table) {
                $table->string('name_mr')->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cities') && Schema::hasColumn('cities', 'name_mr')) {
            Schema::table('cities', function (Blueprint $table) {
                $table->dropColumn('name_mr');
            });
        }
    }
};
