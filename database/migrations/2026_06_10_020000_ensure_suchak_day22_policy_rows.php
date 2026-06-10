<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('suchak_policies')->upsert([
            [
                'policy_key' => 'suchak_free_trial_days',
                'policy_value' => '0',
                'value_type' => 'integer',
                'description' => 'Default free trial days for Suchak plans.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'policy_key' => 'suchak_grace_period_days',
                'policy_value' => '0',
                'value_type' => 'integer',
                'description' => 'Default grace period days after Suchak plan expiry.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'policy_key' => 'suchak_plan_pricing_mode',
                'policy_value' => 'manual_catalog',
                'value_type' => 'string',
                'description' => 'Suchak plan pricing mode until live payment credentials are enabled.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'policy_key' => 'suchak_payment_mode',
                'policy_value' => 'manual_only',
                'value_type' => 'string',
                'description' => 'Suchak platform payment mode.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'policy_key' => 'suchak_commission_rules_json',
                'policy_value' => json_encode([
                    'mode' => 'to_be_discussed',
                    'default_percent' => 0,
                    'default_amount' => 0,
                    'require_ack' => true,
                ], JSON_THROW_ON_ERROR),
                'value_type' => 'json',
                'description' => 'Default Suchak collaboration commission rule.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['policy_key'], ['policy_value', 'value_type', 'description', 'is_active', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('suchak_policies')
            ->whereIn('policy_key', [
                'suchak_free_trial_days',
                'suchak_grace_period_days',
                'suchak_plan_pricing_mode',
                'suchak_payment_mode',
                'suchak_commission_rules_json',
            ])
            ->delete();
    }
};
