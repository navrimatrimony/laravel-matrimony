<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('profile_preferred_mother_tongues')) {
            Schema::create('profile_preferred_mother_tongues', function (Blueprint $table) {
                $table->id();
                $table->foreignId('profile_id')->constrained('matrimony_profiles')->cascadeOnDelete();
                $table->foreignId('mother_tongue_id')->constrained('master_mother_tongues')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['profile_id', 'mother_tongue_id'], 'ppmt_profile_mother_tongue_uq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_preferred_mother_tongues');
    }
};
