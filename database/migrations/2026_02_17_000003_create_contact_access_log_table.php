<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_access_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_profile_id')
                ->constrained('matrimony_profiles')
                ->cascadeOnDelete();
            $table->foreignId('viewer_profile_id')
                ->constrained('matrimony_profiles')
                ->cascadeOnDelete();
            $table->string('source');
            $table->timestamp('unlocked_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_access_log');
    }
};
