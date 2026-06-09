<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_profile_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('requesting_user_id');
            $table->unsignedBigInteger('requesting_matrimony_profile_id');
            $table->unsignedBigInteger('target_matrimony_profile_id');
            $table->unsignedBigInteger('selected_suchak_account_id');
            $table->unsignedBigInteger('representation_id');
            $table->string('request_status')->default('pending');
            $table->string('request_reason')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index('requesting_user_id');
            $table->index('requesting_matrimony_profile_id', 'suchak_requests_requesting_profile_idx');
            $table->index('target_matrimony_profile_id', 'suchak_requests_target_profile_idx');
            $table->index('selected_suchak_account_id', 'suchak_requests_selected_account_idx');
            $table->index('representation_id');
            $table->index('request_status');
            $table->index('created_at');
            $table->index([
                'requesting_matrimony_profile_id',
                'target_matrimony_profile_id',
                'selected_suchak_account_id',
                'request_status',
            ], 'suchak_requests_match_suchak_status_idx');

            $table->foreign('requesting_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('requesting_matrimony_profile_id')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('target_matrimony_profile_id')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('selected_suchak_account_id')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('representation_id')->references('id')->on('suchak_profile_representations')->restrictOnDelete();
        });

        Schema::create('suchak_pipelines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('target_matrimony_profile_id');
            $table->unsignedBigInteger('requesting_matrimony_profile_id');
            $table->unsignedBigInteger('selected_suchak_account_id');
            $table->unsignedBigInteger('representation_id');
            $table->string('pipeline_status')->default('pending');
            $table->timestamp('attribution_locked_at');
            $table->timestamp('lock_expires_at');
            $table->string('sla_status')->default('within_sla');
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique('request_id');
            $table->index('target_matrimony_profile_id', 'suchak_pipelines_target_profile_idx');
            $table->index('requesting_matrimony_profile_id', 'suchak_pipelines_requesting_profile_idx');
            $table->index('selected_suchak_account_id', 'suchak_pipelines_selected_account_idx');
            $table->index('representation_id');
            $table->index('pipeline_status');
            $table->index('sla_status');
            $table->index('lock_expires_at');
            $table->index('created_at');
            $table->index([
                'selected_suchak_account_id',
                'pipeline_status',
                'lock_expires_at',
            ], 'suchak_pipelines_account_status_expiry_idx');

            $table->foreign('request_id')->references('id')->on('suchak_profile_requests')->restrictOnDelete();
            $table->foreign('target_matrimony_profile_id')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('requesting_matrimony_profile_id')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('selected_suchak_account_id')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('representation_id')->references('id')->on('suchak_profile_representations')->restrictOnDelete();
        });

        Schema::create('suchak_pipeline_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('pipeline_id');
            $table->string('event_type');
            $table->string('actor_type');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('event_note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('pipeline_id');
            $table->index('event_type');
            $table->index('actor_type');
            $table->index('actor_id');
            $table->index('created_at');

            $table->foreign('pipeline_id')->references('id')->on('suchak_pipelines')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_pipeline_events');
        Schema::dropIfExists('suchak_pipelines');
        Schema::dropIfExists('suchak_profile_requests');
    }
};
