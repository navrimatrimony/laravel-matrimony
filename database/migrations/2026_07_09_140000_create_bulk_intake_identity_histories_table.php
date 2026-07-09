<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_intake_identity_histories', function (Blueprint $table): void {
            $table->id();
            $table->string('reason_code', 40);
            $table->string('normalized_mobile', 32)->nullable();
            $table->string('normalized_name', 255)->nullable();
            $table->string('normalized_dob', 8)->nullable();
            $table->string('normalized_gender', 16)->nullable();
            $table->string('source_type', 40);
            $table->foreignId('source_bulk_intake_batch_item_id')
                ->nullable()
                ->constrained('bulk_intake_batch_items')
                ->nullOnDelete();
            $table->foreignId('source_biodata_intake_id')
                ->nullable()
                ->constrained('biodata_intakes')
                ->nullOnDelete();
            $table->foreignId('recorded_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('reason_code');
            $table->index('normalized_mobile');
            $table->index(['normalized_name', 'normalized_dob'], 'bulk_intake_identity_hist_name_dob_idx');
            $table->index('source_bulk_intake_batch_item_id', 'bulk_intake_identity_hist_item_idx');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_intake_identity_histories');
    }
};
