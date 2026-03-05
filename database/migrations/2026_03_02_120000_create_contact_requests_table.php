<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Day-32 Step 1: Contact requests (sender requests receiver's contact).
 * State: pending | accepted | rejected | expired | revoked | cancelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('receiver_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 64); // talk_to_family, meet, need_more_details, discuss_marriage_timeline, other
            $table->text('other_reason_text')->nullable();
            $table->json('requested_scopes'); // ['email','phone','whatsapp']
            $table->string('status', 32)->default('pending'); // pending, accepted, rejected, expired, revoked, cancelled
            $table->timestamp('cooldown_ends_at')->nullable(); // set when rejected
            $table->timestamp('expires_at')->nullable(); // pending auto-expiry
            $table->timestamps();

            $table->index(['receiver_id', 'status']);
            $table->index(['sender_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_requests');
    }
};
