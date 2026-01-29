<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('field_registry', function (Blueprint $table) {
            $table->id();

            // Immutable internal identifier (NEVER change after insert)
            $table->string('field_key', 64)->unique();

            // CORE or EXTENDED
            $table->enum('field_type', ['CORE', 'EXTENDED'])->default('CORE');

            // Data semantics only (no validation logic here)
            $table->enum('data_type', ['text', 'number', 'date', 'boolean', 'select']);

            // Completeness + search metadata
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('is_searchable')->default(false);

            // Edit & overwrite governance
            $table->boolean('is_user_editable')->default(false);
            $table->boolean('is_system_overwritable')->default(false);
            $table->boolean('lock_after_user_edit')->default(true);

            // UI metadata (labels can change, keys cannot)
            $table->string('display_label', 128);
            $table->integer('display_order')->default(0);
            $table->string('category', 64)->default('basic');

            // Archival & replacement (non-destructive evolution)
            $table->boolean('is_archived')->default(false);
            $table->unsignedBigInteger('replaced_by_field')->nullable();

            // Audit
            $table->timestamps();

            // Self-reference FK (no cascade delete)
            $table->foreign('replaced_by_field')
                  ->references('id')
                  ->on('field_registry')
                  ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('field_registry');
    }
};
