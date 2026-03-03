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
        if (! Schema::hasTable('mutation_log')) {
            Schema::create('mutation_log', function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger('profile_id')->nullable()->index();
                $table->unsignedBigInteger('intake_id')->nullable()->index();

                $table->string('mutation_status')->nullable();
                $table->boolean('conflict_detected')->default(false);

                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mutation_log');
    }
};
