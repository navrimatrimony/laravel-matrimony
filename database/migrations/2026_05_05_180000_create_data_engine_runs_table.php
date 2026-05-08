<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_engine_runs', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 16);
            $table->string('status', 24);
            $table->string('report_path')->nullable();
            $table->unsignedInteger('total_issues')->default(0);
            $table->unsignedInteger('total_fixed')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_engine_runs');
    }
};
