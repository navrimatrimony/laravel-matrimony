<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_moderation_stats')) {
            Schema::create('user_moderation_stats', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
                $table->unsignedInteger('total_uploads')->default(0);
                $table->unsignedInteger('total_approved')->default(0);
                $table->unsignedInteger('total_rejected')->default(0);
                $table->unsignedInteger('total_review')->default(0);
                $table->timestamp('last_upload_at')->nullable();
                $table->double('risk_score')->default(0);
                $table->boolean('is_flagged')->default(false);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'photo_uploads_suspended')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('photo_uploads_suspended')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'photo_uploads_suspended')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('photo_uploads_suspended');
            });
        }

        Schema::dropIfExists('user_moderation_stats');
    }
};
