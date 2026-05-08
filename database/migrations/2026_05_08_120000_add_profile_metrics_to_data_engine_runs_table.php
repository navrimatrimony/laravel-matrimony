<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_engine_runs', function (Blueprint $table) {
            $table->json('profile_metrics')->nullable()->after('priority_summary');
        });
    }

    public function down(): void
    {
        Schema::table('data_engine_runs', function (Blueprint $table) {
            $table->dropColumn('profile_metrics');
        });
    }
};
