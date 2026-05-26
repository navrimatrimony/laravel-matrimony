<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homepage_success_stories', function (Blueprint $table) {
            $table->id();
            $table->string('couple_names', 160);
            $table->string('location', 160)->nullable();
            $table->date('wedding_date')->nullable();
            $table->text('story_mr')->nullable();
            $table->text('story_en')->nullable();
            $table->string('image_path', 512)->nullable();
            $table->boolean('is_published')->default(false)->index();
            $table->boolean('is_featured')->default(false)->index();
            $table->boolean('consent_confirmed')->default(false);
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homepage_success_stories');
    }
};
