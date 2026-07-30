<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a representation whose consent the Suchak declared rather than the
 * candidate gave.
 *
 * Denormalised onto the representation for the same reason `consent_status`
 * already is: the rival check runs as a query over representations and cannot
 * afford to join every consent row to find out whether a candidate ever
 * actually spoke.
 *
 * The distinction has to survive forever. A declaration is the Suchak's word
 * that a customer agreed in person; it lets that Suchak work, but it must never
 * be mistaken for the candidate having agreed, because only the second one is
 * allowed to stand in another Suchak's way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suchak_profile_representations', function (Blueprint $table) {
            $table->boolean('consent_is_suchak_declared')
                ->default(false)
                ->after('consent_status');
        });
    }

    public function down(): void
    {
        Schema::table('suchak_profile_representations', function (Blueprint $table) {
            $table->dropColumn('consent_is_suchak_declared');
        });
    }
};
