<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_visibility_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')
                ->unique()
                ->constrained('matrimony_profiles')
                ->restrictOnDelete();
            $table->string('visibility_scope');
            $table->string('show_photo_to');
            $table->string('show_contact_to');
            $table->boolean('hide_from_blocked_users')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_visibility_settings');
    }
};
