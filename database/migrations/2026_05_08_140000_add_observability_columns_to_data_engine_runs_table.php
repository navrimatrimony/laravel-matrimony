<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_engine_runs', function (Blueprint $table) {
            $table->string('engine_version', 32)->nullable()->after('conversion_metrics');
            $table->integer('quality_delta')->nullable()->after('engine_version');
            $table->integer('issues_delta')->nullable()->after('quality_delta');
        });
    }

    public function down(): void
    {
        Schema::table('data_engine_runs', function (Blueprint $table) {
            $table->dropColumn(['engine_version', 'quality_delta', 'issues_delta']);
        });
    }
};
