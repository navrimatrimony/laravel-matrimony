<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One billed contact_view credit per viewer + viewed profile + calendar month.
     */
    public function up(): void
    {
        Schema::create('user_contact_reveal_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('viewer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('viewed_profile_id')->constrained('matrimony_profiles')->cascadeOnDelete();
            $table->date('period_start');
            $table->timestamps();

            $table->unique(
                ['viewer_user_id', 'viewed_profile_id', 'period_start'],
                'ucr_viewer_profile_month_uq'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_contact_reveal_logs');
    }
};
