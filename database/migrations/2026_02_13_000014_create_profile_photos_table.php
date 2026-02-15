<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')
                ->constrained('matrimony_profiles')
                ->restrictOnDelete();
            $table->string('file_path');
            $table->boolean('is_primary')->default(false);
            $table->string('uploaded_via');
            $table->string('approved_status');
            $table->boolean('watermark_detected')->default(false);
            $table->timestamps();

            $table->index('profile_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_photos');
    }
};
