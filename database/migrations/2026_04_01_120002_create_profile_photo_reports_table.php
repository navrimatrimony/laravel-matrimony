<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_photo_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('reported_profile_id')
                ->constrained('matrimony_profiles')
                ->cascadeOnDelete();
            $table->foreignId('profile_photo_id')
                ->constrained('profile_photos')
                ->restrictOnDelete();
            $table->text('reason');
            $table->string('status')->default('open');
            $table->text('resolution_reason')->nullable();
            $table->foreignId('resolved_by_admin_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('reporter_user_id');
            $table->index('reported_profile_id');
            $table->index('profile_photo_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_photo_reports');
    }
};
