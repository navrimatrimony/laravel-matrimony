<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_engine_runs', function (Blueprint $table) {
            $table->unsignedTinyInteger('quality_score')->nullable()->after('total_fixed');
            $table->json('priority_summary')->nullable()->after('quality_score');
        });
    }

    public function down(): void
    {
        Schema::table('data_engine_runs', function (Blueprint $table) {
            $table->dropColumn(['quality_score', 'priority_summary']);
        });
    }
};
