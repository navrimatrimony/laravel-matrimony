<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Day-32 Step 1: Contact grants (receiver approved; sender gets access to scopes until valid_until).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_request_id')->constrained('contact_requests')->cascadeOnDelete();
            $table->json('granted_scopes'); // subset of requested: email, phone, whatsapp
            $table->timestamp('valid_until');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['contact_request_id']);
            $table->index(['valid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_grants');
    }
};
