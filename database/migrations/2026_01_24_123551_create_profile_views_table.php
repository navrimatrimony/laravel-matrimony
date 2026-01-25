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
        Schema::dropIfExists('profile_views');
        Schema::create('profile_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('viewer_profile_id')->constrained('matrimony_profiles')->cascadeOnDelete();
            $table->foreignId('viewed_profile_id')->constrained('matrimony_profiles')->cascadeOnDelete();
            $table->timestamps();
            $table->index(['viewer_profile_id', 'viewed_profile_id', 'created_at'], 'pv_viewer_viewed_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profile_views');
    }
};
