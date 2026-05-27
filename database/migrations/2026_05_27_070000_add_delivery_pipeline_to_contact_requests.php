<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_requests', function (Blueprint $table) {
            $table->string('channel_mode', 32)->default('manual_simulation')->after('meta');
            $table->string('delivery_status', 32)->default('pending')->after('channel_mode');
            $table->timestamp('send_due_at')->nullable()->after('delivery_status');
            $table->timestamp('sent_at')->nullable()->after('send_due_at');
            $table->timestamp('first_reminder_due_at')->nullable()->after('sent_at');
            $table->timestamp('first_reminder_sent_at')->nullable()->after('first_reminder_due_at');
            $table->timestamp('expired_at')->nullable()->after('expires_at');
            $table->text('last_delivery_error')->nullable()->after('expired_at');
            $table->unsignedInteger('delivery_attempts')->default(0)->after('last_delivery_error');

            $table->index(['type', 'delivery_status'], 'contact_requests_type_delivery_status_idx');
            $table->index(['type', 'expires_at'], 'contact_requests_type_expires_at_idx');
            $table->index(['type', 'first_reminder_due_at'], 'contact_requests_type_reminder_due_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contact_requests', function (Blueprint $table) {
            $table->dropIndex('contact_requests_type_delivery_status_idx');
            $table->dropIndex('contact_requests_type_expires_at_idx');
            $table->dropIndex('contact_requests_type_reminder_due_idx');
            $table->dropColumn([
                'channel_mode',
                'delivery_status',
                'send_due_at',
                'sent_at',
                'first_reminder_due_at',
                'first_reminder_sent_at',
                'expired_at',
                'last_delivery_error',
                'delivery_attempts',
            ]);
        });
    }
};
