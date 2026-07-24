<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Relax the Suchak customer-agreement terms policy from `strict` to
 * `recommended` (Product Owner decision, 2026-07-24).
 *
 * Under `strict`, only an admin could settle/bypass a customer's terms, which
 * blocked a Suchak from raising a payment request without admin involvement.
 * `recommended` keeps the terms on record and still requires a recorded reason
 * to settle them, but lets the Suchak do it themselves — so ready-made plans
 * (and any package) can go straight to a payment request.
 *
 * Config-only: updates a single row in `suchak_policies`. No schema change.
 */
return new class extends Migration
{
    private const KEY = 'suchak_terms_policy_mode';

    public function up(): void
    {
        DB::table('suchak_policies')
            ->where('policy_key', self::KEY)
            ->update([
                'policy_value' => 'recommended',
                'value_type' => 'string',
                'is_active' => true,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('suchak_policies')
            ->where('policy_key', self::KEY)
            ->update([
                'policy_value' => 'strict',
                'value_type' => 'string',
                'is_active' => true,
                'updated_at' => now(),
            ]);
    }
};
