<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pincodes', function (Blueprint $table) {
            $table->id();
            $table->string('pincode');
            $table->foreignId('place_id')->constrained('places')->cascadeOnDelete();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index('pincode');
            $table->unique(['pincode', 'place_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pincodes');
    }
};

