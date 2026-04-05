<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mediation / introduction requests (extensible request_type for future channels e.g. WhatsApp).
     */
    public function up(): void
    {
        Schema::create('mediation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('receiver_id')->constrained('users')->cascadeOnDelete();
            $table->string('request_type', 32)->default('mediator');
            $table->string('status', 32)->default('pending');
            $table->foreignId('subject_profile_id')->nullable()->constrained('matrimony_profiles')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamp('admin_notified_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['receiver_id', 'status', 'created_at'], 'mediation_receiver_status_idx');
            $table->index(['sender_id', 'status', 'created_at'], 'mediation_sender_status_idx');
            $table->index('request_type', 'mediation_request_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mediation_requests');
    }
};
