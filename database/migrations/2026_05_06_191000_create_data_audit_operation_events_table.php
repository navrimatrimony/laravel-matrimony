<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_audit_operation_events', function (Blueprint $table) {
            $table->id();
            $table->string('operation', 32);
            $table->string('status', 16);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('memory_peak_kb')->nullable();
            $table->text('error_message')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['operation', 'status', 'created_at'], 'da_op_events_op_status_created_idx');
            $table->index(['status', 'created_at'], 'da_op_events_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_audit_operation_events');
    }
};

