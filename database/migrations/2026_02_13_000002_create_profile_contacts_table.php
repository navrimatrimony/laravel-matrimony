<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')
                ->constrained('matrimony_profiles')
                ->restrictOnDelete();
            $table->string('relation_type');
            $table->string('contact_name');
            $table->string('phone_number');
            $table->boolean('is_primary')->default(false);
            $table->string('visibility_rule')->default('unlock_only');
            $table->boolean('verified_status')->default(false);
            $table->timestamps();

            $table->index('profile_id');
            $table->index('phone_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_contacts');
    }
};
