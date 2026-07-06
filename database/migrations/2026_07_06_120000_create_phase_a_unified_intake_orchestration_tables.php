<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_intake_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('uploaded_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('uploaded_by_actor_type', 32)->nullable();
            $table->string('source_surface', 32)->nullable();
            $table->string('batch_name')->nullable();
            $table->string('batch_status', 40)->default('pending');
            $table->string('intake_creation_policy')->nullable()->default('existing_chain');
            $table->string('ocr_policy')->nullable()->default('free_ocr_first');
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('total_files')->default(0);
            $table->unsignedInteger('total_texts')->default(0);
            $table->unsignedInteger('total_intakes_created')->default(0);
            $table->unsignedInteger('total_profiles_created')->default(0);
            $table->unsignedInteger('total_conflicts_generated')->default(0);
            $table->unsignedInteger('total_needs_review')->default(0);
            $table->unsignedInteger('total_failed')->default(0);
            $table->decimal('ai_cost_estimate', 12, 4)->nullable();
            $table->decimal('ai_cost_actual', 12, 4)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->index('batch_status');
            $table->index('uploaded_by_user_id');
            $table->index('uploaded_by_actor_type');
            $table->index('source_surface');
            $table->index('created_at');
        });

        Schema::create('intake_whatsapp_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('wa_phone_number_id', 64)->nullable();
            $table->string('wa_business_account_id', 64)->nullable();
            $table->string('wa_contact_wa_id', 64);
            $table->string('normalized_mobile', 32)->nullable();
            $table->foreignId('linked_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('actor_type', 32)->nullable();
            $table->string('source_surface', 32)->default('whatsapp');
            $table->string('session_status', 40)->default('open');
            $table->string('current_state', 80)->nullable();
            $table->string('consent_status', 40)->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->json('session_meta_json')->nullable();
            $table->timestamps();

            $table->unique(['wa_phone_number_id', 'wa_contact_wa_id'], 'intake_wa_sessions_phone_contact_unique');
            $table->index('normalized_mobile');
            $table->index('linked_user_id');
            $table->index('actor_type');
            $table->index('session_status');
            $table->index('last_message_at');
        });

        Schema::create('bulk_intake_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bulk_intake_batch_id')
                ->constrained('bulk_intake_batches')
                ->restrictOnDelete();
            $table->foreignId('biodata_intake_id')
                ->nullable()
                ->constrained('biodata_intakes')
                ->restrictOnDelete();
            $table->unsignedInteger('item_sequence');
            $table->string('input_type', 32)->default('unknown');
            $table->string('original_filename')->nullable();
            $table->string('source_file_path')->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->string('raw_text_hash', 64)->nullable();
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->string('item_status', 40)->default('pending');
            $table->text('summary_text')->nullable();
            $table->decimal('quality_score', 5, 3)->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message')->nullable();
            $table->json('item_meta_json')->nullable();
            $table->timestamps();

            $table->unique(['bulk_intake_batch_id', 'item_sequence'], 'bulk_intake_items_batch_sequence_unique');
            $table->index(['bulk_intake_batch_id', 'item_status'], 'bulk_intake_items_batch_status_idx');
            $table->index('biodata_intake_id');
            $table->index('file_hash');
            $table->index('raw_text_hash');
        });

        Schema::create('intake_whatsapp_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intake_whatsapp_session_id')
                ->constrained('intake_whatsapp_sessions')
                ->restrictOnDelete();
            $table->foreignId('biodata_intake_id')
                ->nullable()
                ->constrained('biodata_intakes')
                ->restrictOnDelete();
            $table->string('direction', 20);
            $table->string('wa_message_id', 128)->nullable()->unique();
            $table->string('message_type', 40)->default('unknown');
            $table->longText('text_body')->nullable();
            $table->string('media_id', 128)->nullable();
            $table->string('media_mime_type', 120)->nullable();
            $table->string('media_filename')->nullable();
            $table->string('media_storage_path')->nullable();
            $table->string('processing_status', 40)->default('received');
            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message')->nullable();
            $table->json('webhook_payload_json')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['intake_whatsapp_session_id', 'direction'], 'intake_wa_messages_session_direction_idx');
            $table->index('biodata_intake_id');
            $table->index('message_type');
            $table->index('processing_status');
            $table->index('received_at');
        });

        Schema::create('intake_source_contexts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('biodata_intake_id')
                ->nullable()
                ->constrained('biodata_intakes')
                ->restrictOnDelete();
            $table->string('source_type', 40);
            $table->string('source_surface', 32)->nullable();
            $table->string('actor_type', 32)->nullable();
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('bulk_intake_batch_id')
                ->nullable()
                ->constrained('bulk_intake_batches')
                ->restrictOnDelete();
            $table->foreignId('bulk_intake_batch_item_id')
                ->nullable()
                ->constrained('bulk_intake_batch_items')
                ->restrictOnDelete();
            $table->foreignId('intake_whatsapp_session_id')
                ->nullable()
                ->constrained('intake_whatsapp_sessions')
                ->restrictOnDelete();
            $table->foreignId('intake_whatsapp_message_id')
                ->nullable()
                ->constrained('intake_whatsapp_messages')
                ->restrictOnDelete();
            $table->string('external_source_id', 128)->nullable();
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->json('source_meta_json')->nullable();
            $table->timestamps();

            $table->index('biodata_intake_id');
            $table->index('source_type');
            $table->index('source_surface');
            $table->index('actor_type');
            $table->index('actor_user_id');
            $table->index('bulk_intake_batch_id');
            $table->index('intake_whatsapp_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_source_contexts');
        Schema::dropIfExists('intake_whatsapp_messages');
        Schema::dropIfExists('bulk_intake_batch_items');
        Schema::dropIfExists('intake_whatsapp_sessions');
        Schema::dropIfExists('bulk_intake_batches');
    }
};
