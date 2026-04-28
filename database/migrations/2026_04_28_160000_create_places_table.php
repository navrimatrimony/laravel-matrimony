<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('places', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('type', [
                'country',
                'state',
                'district',
                'taluka',
                'city',
                'suburb',
                'village',
            ]);
            $table->foreignId('parent_id')->nullable()->constrained('places')->nullOnDelete();
            $table->unsignedTinyInteger('level');
            $table->string('state_code', 32)->nullable();
            $table->string('district_code', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('name');
            $table->index('parent_id');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('places');
    }
};

