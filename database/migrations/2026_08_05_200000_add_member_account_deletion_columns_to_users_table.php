<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member self-service account deletion (Google Play data-deletion requirement).
 *
 * The request lives on `users`, not on `matrimony_profiles`: what the member asks
 * to remove is the ACCOUNT. The profile is archived and later purged as a
 * consequence of that request, and an OTP-shell user may not have a profile yet.
 *
 * `deletion_requested_at` is the single source for "is a deletion pending" and for
 * the 30-day countdown — there is no second scheduled_at column to drift from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'deletion_requested_at')) {
                $table->timestamp('deletion_requested_at')->nullable()->index();
            }
            if (! Schema::hasColumn('users', 'deletion_reason_key')) {
                $table->string('deletion_reason_key', 40)->nullable();
            }
            if (! Schema::hasColumn('users', 'deletion_reason_note')) {
                $table->string('deletion_reason_note', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            foreach (['deletion_requested_at', 'deletion_reason_key', 'deletion_reason_note'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
