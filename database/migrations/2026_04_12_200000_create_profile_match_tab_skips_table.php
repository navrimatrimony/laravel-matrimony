<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('profile_match_tab_skips')) {
            return;
        }

        Schema::create('profile_match_tab_skips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('observer_profile_id')
                ->constrained('matrimony_profiles')
                ->cascadeOnDelete();
            $table->foreignId('candidate_profile_id')
                ->constrained('matrimony_profiles')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->index(['observer_profile_id', 'candidate_profile_id'], 'pmt_skip_observer_candidate_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_match_tab_skips');
    }
};
