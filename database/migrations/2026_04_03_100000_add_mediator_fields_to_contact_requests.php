<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mediator requests live in contact_requests with type=mediator (single table; mediation_requests kept unused for legacy envs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_requests', function (Blueprint $table) {
            $table->string('type', 32)->default('contact');
            $table->foreignId('subject_profile_id')->nullable()->constrained('matrimony_profiles')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('admin_notified_at')->nullable();
            $table->index(['receiver_id', 'type', 'status'], 'contact_requests_receiver_type_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contact_requests', function (Blueprint $table) {
            $table->dropIndex('contact_requests_receiver_type_status_idx');
            $table->dropConstrainedForeignId('subject_profile_id');
            $table->dropColumn(['type', 'meta', 'responded_at', 'admin_notified_at']);
        });
    }
};
