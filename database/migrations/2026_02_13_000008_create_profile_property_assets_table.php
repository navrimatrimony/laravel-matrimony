<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_property_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')
                ->constrained('matrimony_profiles')
                ->restrictOnDelete();
            $table->string('asset_type');
            $table->string('location')->nullable();
            $table->decimal('estimated_value', 12, 2)->nullable();
            $table->string('ownership_type');
            $table->timestamps();

            $table->index('profile_id');
            $table->index('asset_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_property_assets');
    }
};
