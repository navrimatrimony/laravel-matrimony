<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Recover from partial deploy where CREATE succeeded but FK names were too long for MySQL.
        if (Schema::hasTable('bulk_intake_identity_histories')) {
            Schema::drop('bulk_intake_identity_histories');
        }

        Schema::create('bulk_intake_identity_histories', function (Blueprint $table): void {
            $table->id();
            $table->string('reason_code', 40);
            $table->string('normalized_mobile', 32)->nullable();
            $table->string('normalized_name', 255)->nullable();
            $table->string('normalized_dob', 8)->nullable();
            $table->string('normalized_gender', 16)->nullable();
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_bulk_intake_batch_item_id')->nullable();
            $table->unsignedBigInteger('source_biodata_intake_id')->nullable();
            $table->unsignedBigInteger('recorded_by_user_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->foreign('source_bulk_intake_batch_item_id', 'biih_source_item_fk')
                ->references('id')
                ->on('bulk_intake_batch_items')
                ->nullOnDelete();
            $table->foreign('source_biodata_intake_id', 'biih_source_intake_fk')
                ->references('id')
                ->on('biodata_intakes')
                ->nullOnDelete();
            $table->foreign('recorded_by_user_id', 'biih_recorded_by_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index('reason_code');
            $table->index('normalized_mobile');
            $table->index(['normalized_name', 'normalized_dob'], 'biih_name_dob_idx');
            $table->index('source_bulk_intake_batch_item_id', 'biih_source_item_idx');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_intake_identity_histories');
    }
};
