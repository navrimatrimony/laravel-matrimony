<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('photo_learning_dataset')) {
            return;
        }

        Schema::create('photo_learning_dataset', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_photo_id')->nullable()->constrained('profile_photos')->nullOnDelete();
            $table->json('moderation_scan_json')->nullable();
            $table->string('final_decision', 32);
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_learning_dataset');
    }
};
