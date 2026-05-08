<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_engine_runs', function (Blueprint $table) {
            $table->json('conversion_metrics')->nullable()->after('profile_metrics');
        });
    }

    public function down(): void
    {
        Schema::table('data_engine_runs', function (Blueprint $table) {
            $table->dropColumn('conversion_metrics');
        });
    }
};
