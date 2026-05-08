<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_engine_runs', function (Blueprint $table) {
            $table->longText('error_output')->nullable()->after('report_path');
        });
    }

    public function down(): void
    {
        Schema::table('data_engine_runs', function (Blueprint $table) {
            $table->dropColumn('error_output');
        });
    }
};
