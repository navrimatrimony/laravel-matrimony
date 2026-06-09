<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_type');
            $table->string('action_type');
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->unsignedBigInteger('matrimony_profile_id')->nullable();
            $table->unsignedBigInteger('admin_audit_log_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->nullable();

            $table->index('suchak_account_id');
            $table->index('actor_user_id');
            $table->index('actor_type');
            $table->index('action_type');
            $table->index(['target_type', 'target_id'], 'suchak_activity_target_idx');
            $table->index('matrimony_profile_id');
            $table->index('admin_audit_log_id');
            $table->index('occurred_at');
            $table->index('created_at');
            $table->index(['suchak_account_id', 'action_type', 'occurred_at'], 'suchak_activity_account_action_time_idx');

            $table->foreign('suchak_account_id')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('matrimony_profile_id')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('admin_audit_log_id')->references('id')->on('admin_audit_logs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_activity_logs');
    }
};
