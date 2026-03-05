<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Day-32 Step 7: Audit trail for contact request state changes and revokes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_request_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_request_id')->constrained('contact_requests')->cascadeOnDelete();
            $table->foreignId('contact_grant_id')->nullable()->constrained('contact_grants')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // actor
            $table->string('action', 32); // created, approved, rejected, expired, revoked, cancelled
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['contact_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_request_audit_log');
    }
};
