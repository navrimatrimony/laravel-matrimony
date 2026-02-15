<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_relatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')
                ->constrained('matrimony_profiles')
                ->restrictOnDelete();
            $table->string('relation_type');
            $table->string('name');
            $table->string('occupation')->nullable();
            $table->string('marital_status')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('profile_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_relatives');
    }
};
