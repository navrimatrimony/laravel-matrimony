<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second, INDEPENDENT per-meeting fee for meetings held online.
 *
 * Online counselling is its own kind of work, not a discount on a visit: a
 * two-hour video session can legitimately cost more than the Suchak walking to
 * a family's house, and the reverse is just as common. So this is a stored
 * amount, never a percentage or a factor derived from the offline fee — there
 * is deliberately no relationship between the two columns for anything to keep
 * in sync or to silently recompute.
 *
 * Nullable, and NULL keeps meaning "this plan did not fix an online fee" rather
 * than zero — the fee row on the send screen stays opt-in exactly like the
 * offline one, and every plan that predates this column must keep reading as
 * "never offered online meetings".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suchak_customer_plans', function (Blueprint $table): void {
            if (! Schema::hasColumn('suchak_customer_plans', 'per_meeting_online_fee_amount')) {
                $table->decimal('per_meeting_online_fee_amount', 12, 2)
                    ->nullable()
                    ->after('per_meeting_fee_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('suchak_customer_plans', function (Blueprint $table): void {
            if (Schema::hasColumn('suchak_customer_plans', 'per_meeting_online_fee_amount')) {
                $table->dropColumn('per_meeting_online_fee_amount');
            }
        });
    }
};
