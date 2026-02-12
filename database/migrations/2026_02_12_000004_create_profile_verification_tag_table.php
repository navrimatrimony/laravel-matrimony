<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_verification_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matrimony_profile_id')
                ->constrained('matrimony_profiles')
                ->restrictOnDelete();
            $table->foreignId('verification_tag_id')
                ->constrained('verification_tags')
                ->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['matrimony_profile_id', 'verification_tag_id'], 'pvt_profile_tag_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_verification_tag');
    }
};
