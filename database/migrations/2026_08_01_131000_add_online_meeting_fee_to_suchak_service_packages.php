<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carries the online meeting fee onto the package actually sent to a customer.
 *
 * The preset in suchak_customer_plans is reusable and editable; the package is
 * the frozen record of what one customer was offered. Without this column a
 * Suchak raising their online rate would retroactively rewrite the terms of
 * every package already in a family's hands, and nothing would record what was
 * really quoted.
 *
 * Same name, type and nullability as the plan column on purpose — one fact, one
 * shape — so the send-time copy stays a plain carry-over with nothing to
 * translate. Independent of per_meeting_fee_amount for the same reason it is on
 * the plan: online and offline are separately priced work, not one rate and a
 * modifier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suchak_service_packages', function (Blueprint $table): void {
            if (! Schema::hasColumn('suchak_service_packages', 'per_meeting_online_fee_amount')) {
                $table->decimal('per_meeting_online_fee_amount', 12, 2)
                    ->nullable()
                    ->after('per_meeting_fee_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('suchak_service_packages', function (Blueprint $table): void {
            if (Schema::hasColumn('suchak_service_packages', 'per_meeting_online_fee_amount')) {
                $table->dropColumn('per_meeting_online_fee_amount');
            }
        });
    }
};
