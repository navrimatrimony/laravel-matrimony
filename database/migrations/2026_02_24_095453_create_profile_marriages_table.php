<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_marriages', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('profile_id');

            $table->unsignedBigInteger('marital_status_id')->nullable();
            $table->year('marriage_year')->nullable();
            $table->year('separation_year')->nullable();
            $table->year('divorce_year')->nullable();
            $table->year('spouse_death_year')->nullable();

            $table->string('divorce_status')->nullable(); 
            // in_process / legally_divorced / mutual / contested / not_filed

            $table->text('remarriage_reason')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->foreign('profile_id')
                ->references('id')
                ->on('matrimony_profiles')
                ->cascadeOnDelete();

            $table->foreign('marital_status_id')
                ->references('id')
                ->on('master_marital_statuses')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_marriages');
    }
};