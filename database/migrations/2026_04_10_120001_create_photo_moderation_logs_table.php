<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('photo_moderation_logs')) {
            return;
        }

        Schema::create('photo_moderation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('photo_id')->nullable()->constrained('profile_photos')->nullOnDelete();
            $table->string('old_status', 32);
            $table->string('new_status', 32);
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_moderation_logs');
    }
};
