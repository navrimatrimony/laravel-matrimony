<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biodata_intakes', function (Blueprint $table) {
            $table->id();
            $table->string('file_path')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('file_type')->nullable();
            $table->longText('raw_ocr_text');
            $table->foreignId('uploaded_by')
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('ocr_mode')->nullable();
            $table->foreignId('matrimony_profile_id')
                ->nullable()
                ->constrained('matrimony_profiles')
                ->restrictOnDelete();
            $table->string('intake_status')->default('uploaded');
            $table->string('parse_status')->default('pending');
            $table->longText('parsed_json')->nullable();
            $table->boolean('approved_by_user')->default(false);
            $table->timestamp('approved_at')->nullable();
            $table->longText('approval_snapshot_json')->nullable();
            $table->unsignedInteger('snapshot_schema_version')->default(1);
            $table->boolean('intake_locked')->default(false);
            $table->timestamps();

            $table->index('uploaded_by');
            $table->index('intake_status');
            $table->index('parse_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biodata_intakes');
    }
};
