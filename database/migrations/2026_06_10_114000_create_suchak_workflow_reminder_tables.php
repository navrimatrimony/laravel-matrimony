<?php

use App\Models\SuchakWorkflowReminder;
use App\Models\SuchakWorkflowTimelineEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_workflow_reminders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('customer_context_id')->nullable();
            $table->unsignedBigInteger('matrimony_profile_id')->nullable();
            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id');
            $table->string('reminder_type', 40);
            $table->string('reminder_key', 191);
            $table->string('template_key', 80);
            $table->string('channel', 40)->default(SuchakWorkflowReminder::CHANNEL_WHATSAPP_COPY);
            $table->string('provider_status', 40)->default(SuchakWorkflowReminder::PROVIDER_PENDING_CREDENTIALS);
            $table->string('reminder_status', 40)->default(SuchakWorkflowReminder::STATUS_PENDING);
            $table->timestamp('due_at');
            $table->date('generated_for_date');
            $table->timestamp('last_generated_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->text('message_copy');
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique('reminder_key', 'sk_workflow_reminder_key_unique');
            $table->index('suchak_account_id', 'sk_workflow_reminder_account_idx');
            $table->index('customer_context_id', 'sk_workflow_reminder_customer_idx');
            $table->index('matrimony_profile_id', 'sk_workflow_reminder_profile_idx');
            $table->index(['source_type', 'source_id'], 'sk_workflow_reminder_source_idx');
            $table->index('reminder_type', 'sk_workflow_reminder_type_idx');
            $table->index('reminder_status', 'sk_workflow_reminder_status_idx');
            $table->index('provider_status', 'sk_workflow_reminder_provider_idx');
            $table->index('due_at', 'sk_workflow_reminder_due_idx');
            $table->index(['suchak_account_id', 'reminder_status', 'due_at'], 'sk_workflow_reminder_account_due_idx');

            $table->foreign('suchak_account_id', 'sk_workflow_reminder_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('customer_context_id', 'sk_workflow_reminder_customer_fk')->references('id')->on('suchak_customer_contexts')->restrictOnDelete();
            $table->foreign('matrimony_profile_id', 'sk_workflow_reminder_profile_fk')->references('id')->on('matrimony_profiles')->restrictOnDelete();
        });

        Schema::create('suchak_workflow_timeline_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('workflow_reminder_id')->nullable();
            $table->unsignedBigInteger('customer_context_id')->nullable();
            $table->unsignedBigInteger('matrimony_profile_id')->nullable();
            $table->string('event_type', 64)->default(SuchakWorkflowTimelineEvent::EVENT_REMINDER_GENERATED);
            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('actor_type', 32)->default(SuchakWorkflowTimelineEvent::ACTOR_SYSTEM);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('event_title', 160);
            $table->text('event_summary')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index('suchak_account_id', 'sk_workflow_event_account_idx');
            $table->index('workflow_reminder_id', 'sk_workflow_event_reminder_idx');
            $table->index('customer_context_id', 'sk_workflow_event_customer_idx');
            $table->index('matrimony_profile_id', 'sk_workflow_event_profile_idx');
            $table->index('event_type', 'sk_workflow_event_type_idx');
            $table->index(['source_type', 'source_id'], 'sk_workflow_event_source_idx');
            $table->index('actor_user_id', 'sk_workflow_event_actor_idx');
            $table->index('occurred_at', 'sk_workflow_event_occurred_idx');
            $table->index(['suchak_account_id', 'event_type', 'occurred_at'], 'sk_workflow_event_account_type_idx');

            $table->foreign('suchak_account_id', 'sk_workflow_event_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('workflow_reminder_id', 'sk_workflow_event_reminder_fk')->references('id')->on('suchak_workflow_reminders')->restrictOnDelete();
            $table->foreign('customer_context_id', 'sk_workflow_event_customer_fk')->references('id')->on('suchak_customer_contexts')->restrictOnDelete();
            $table->foreign('matrimony_profile_id', 'sk_workflow_event_profile_fk')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('actor_user_id', 'sk_workflow_event_actor_fk')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_workflow_timeline_events');
        Schema::dropIfExists('suchak_workflow_reminders');
    }
};
