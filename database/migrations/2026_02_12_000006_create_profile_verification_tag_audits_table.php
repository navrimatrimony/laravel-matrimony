<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_verification_tag_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matrimony_profile_id')
                ->constrained('matrimony_profiles')
                ->restrictOnDelete();
            $table->foreignId('verification_tag_id')
                ->constrained('verification_tags')
                ->restrictOnDelete();
            $table->string('action');
            $table->foreignId('performed_by_admin_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('reason')->nullable();
            $table->timestamp('performed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_verification_tag_audits');
    }
};
