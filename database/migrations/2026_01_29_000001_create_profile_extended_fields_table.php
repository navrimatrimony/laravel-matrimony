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
        Schema::create('profile_extended_fields', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('profile_id');
            $table->string('field_key', 64);
            $table->text('field_value')->nullable();
            $table->timestamps();

            $table->foreign('profile_id')
                  ->references('id')
                  ->on('matrimony_profiles')
                  ->onDelete('restrict');

            $table->unique(['profile_id', 'field_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profile_extended_fields');
    }
};
