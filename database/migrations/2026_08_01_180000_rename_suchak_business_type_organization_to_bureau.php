<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Product decision 2026-08-01: the "organization" Suchak business type IS the
 * marriage bureau (विवाह मंडळ). There are now exactly two types — individual
 * and bureau — so the stored value is renamed in place. Nothing else about the
 * account changes: an "organization" row already meant an entity with an office
 * name and an employee count, which is exactly what a bureau is.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('suchak_accounts')
            ->where('business_type', 'organization')
            ->update(['business_type' => 'bureau']);
    }

    /**
     * NOT reversible, and the original reasoning here was wrong.
     *
     * This was written to reverse wholesale on the premise that no write path
     * could ever have stored 'bureau' — the constant existed but no validation
     * rule accepted it — so every 'bureau' row had to be one this migration
     * created. Production said otherwise: immediately before `up()` ran there
     * were already **8** accounts holding 'bureau' beside the 5 holding
     * 'organization'. Something wrote them; validation was evidently not the
     * only door.
     *
     * Those two populations are now indistinguishable, so a wholesale reversal
     * would relabel 8 accounts that were never 'organization' in the first
     * place. Refusing is the honest answer: a rollback that silently corrupts
     * rows is worse than a rollback that stops. To undo this deliberately,
     * write a new migration that names the specific account ids.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'Not reversible: 8 accounts already held "bureau" before this ran, '
            .'and they are now indistinguishable from the 5 it converted. '
            .'Undo by account id in a new migration if you really mean to.',
        );
    }
};
