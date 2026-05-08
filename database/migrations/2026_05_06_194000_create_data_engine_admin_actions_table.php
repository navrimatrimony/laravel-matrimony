<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_engine_admin_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('recipe', 128)->nullable();
            $table->string('status', 24)->default('queued');
            $table->boolean('dry_run')->default(true);
            $table->boolean('is_destructive')->default(false);
            $table->boolean('rollback_available')->default(false);
            $table->json('request_payload')->nullable();
            $table->json('result_payload')->nullable();
            $table->longText('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_engine_admin_actions');
    }
};

