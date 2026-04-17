<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: optional event stream + admin risk flags (does not replace existing moderation stats).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_activities')) {
            Schema::create('user_activities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('type', 64);
                $table->json('meta')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['user_id', 'created_at'], 'user_activities_user_created_idx');
                $table->index(['type', 'created_at'], 'user_activities_type_created_idx');
            });
        }

        if (! Schema::hasTable('user_flags')) {
            Schema::create('user_flags', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('type', 64);
                $table->unsignedSmallInteger('score')->default(0);
                /** system = synced rule; manual = admin mark */
                $table->string('source', 16)->default('system');
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'type'], 'user_flags_user_type_uq');
                $table->index(['type', 'created_at'], 'user_flags_type_created_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('user_flags')) {
            Schema::dropIfExists('user_flags');
        }
        if (Schema::hasTable('user_activities')) {
            Schema::dropIfExists('user_activities');
        }
    }
};
