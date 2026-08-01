<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carries the plan's meeting fee and post-marriage fee onto the package that is
 * actually sent to a customer.
 *
 * suchak_customer_plans is only the reusable preset; the package materialized at
 * send time is what the customer agreed to. Without these three columns a Suchak
 * editing a preset would retroactively change the terms of every package already
 * in a customer's hands, and there would be no record of what was really offered.
 *
 * Same names, types and modes as suchak_customer_plans on purpose — one fact,
 * one shape — so the send-time copy is a plain carry-over with nothing to
 * translate or reinterpret.
 *
 * Nullable because every package that predates this exists without these terms,
 * and NULL has to keep meaning "was never part of this deal" rather than zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suchak_service_packages', function (Blueprint $table): void {
            if (! Schema::hasColumn('suchak_service_packages', 'per_meeting_fee_amount')) {
                $table->decimal('per_meeting_fee_amount', 12, 2)
                    ->nullable()
                    ->after('currency');
            }

            // consts: as_wished / fixed / none
            if (! Schema::hasColumn('suchak_service_packages', 'post_marriage_fee_mode')) {
                $table->string('post_marriage_fee_mode', 16)
                    ->nullable()
                    ->after('per_meeting_fee_amount');
            }

            if (! Schema::hasColumn('suchak_service_packages', 'post_marriage_fee_amount')) {
                $table->decimal('post_marriage_fee_amount', 12, 2)
                    ->nullable()
                    ->after('post_marriage_fee_mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('suchak_service_packages', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                'per_meeting_fee_amount',
                'post_marriage_fee_mode',
                'post_marriage_fee_amount',
            ], static fn (string $column): bool => Schema::hasColumn('suchak_service_packages', $column)));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
