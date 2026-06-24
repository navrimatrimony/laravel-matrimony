<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('partner_preference_metadata')) {
            return;
        }

        Schema::create('partner_preference_metadata', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('matrimony_profile_id')->unique()->constrained('matrimony_profiles')->cascadeOnDelete();
            $table->string('source')->nullable();
            $table->json('strictness_json')->nullable();
            $table->string('generated_from')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_preference_metadata');
    }
};
