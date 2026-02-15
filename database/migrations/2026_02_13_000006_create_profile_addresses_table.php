<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')
                ->constrained('matrimony_profiles')
                ->restrictOnDelete();
            $table->string('address_type');
            $table->string('village')->nullable();
            $table->string('taluka')->nullable();
            $table->string('district')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('pin_code')->nullable();
            $table->timestamps();

            $table->index('profile_id');
            $table->index('address_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_addresses');
    }
};
