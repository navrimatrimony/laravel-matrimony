<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biodata_intake_ocr_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_id')
                ->constrained('biodata_intakes')
                ->restrictOnDelete();
            $table->string('engine', 80);
            $table->string('source', 80)->nullable();
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('created_by_actor_type', 32)->nullable();
            $table->string('source_surface', 32)->nullable();
            $table->string('status', 40)->default('success');

            $table->longText('raw_text')->nullable();
            $table->longText('normalized_text')->nullable();
            $table->string('text_hash', 64)->nullable();
            $table->string('normalized_text_hash', 64)->nullable();
            $table->string('image_hash', 64)->nullable();
            $table->string('perceptual_hash', 128)->nullable();

            $table->decimal('quality_score', 5, 3)->nullable();
            $table->decimal('layout_score', 5, 3)->nullable();
            $table->json('field_scores_json')->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message')->nullable();

            $table->json('raw_blocks_json')->nullable();
            $table->json('raw_lines_json')->nullable();
            $table->json('layout_meta_json')->nullable();
            $table->json('engine_meta_json')->nullable();

            $table->string('parser_version')->nullable();
            $table->string('prompt_version')->nullable();
            $table->string('preprocessing_version')->nullable();
            $table->string('selection_policy_version')->nullable();

            $table->unsignedInteger('duration_ms')->nullable();
            $table->decimal('cost_units', 12, 4)->nullable();
            $table->string('provider_request_id')->nullable();
            $table->string('provider_response_id')->nullable();

            $table->boolean('is_primary')->default(false);
            $table->string('selected_by')->nullable();
            $table->foreignId('selected_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('selected_by_actor_type', 32)->nullable();
            $table->timestamp('selected_at')->nullable();
            $table->string('selected_policy')->nullable();
            $table->text('selected_reason')->nullable();
            $table->unsignedBigInteger('previous_primary_attempt_id')->nullable();

            $table->timestamps();

            $table->index(['intake_id', 'engine']);
            $table->index(['intake_id', 'is_primary']);
            $table->index(['created_by_actor_type']);
            $table->index(['source_surface']);
            $table->index(['selected_by_actor_type']);
            $table->index(['status']);
            $table->index(['text_hash']);
            $table->index(['normalized_text_hash']);
            $table->index(['image_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biodata_intake_ocr_attempts');
    }
};
