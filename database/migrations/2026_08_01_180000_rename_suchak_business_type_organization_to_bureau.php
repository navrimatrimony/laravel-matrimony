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
     * Safe to reverse wholesale: before this migration no write path could ever
     * store 'bureau' (the constant existed but no validation rule accepted it),
     * so every 'bureau' row is one this migration created.
     */
    public function down(): void
    {
        DB::table('suchak_accounts')
            ->where('business_type', 'bureau')
            ->update(['business_type' => 'organization']);
    }
};
