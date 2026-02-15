<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')
                ->constrained('matrimony_profiles')
                ->restrictOnDelete();
            $table->string('child_name')->nullable();
            $table->string('gender');
            $table->unsignedInteger('age');
            $table->boolean('lives_with_parent')->default(true);
            $table->timestamps();

            $table->index('profile_id');
            $table->index('gender');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_children');
    }
};
