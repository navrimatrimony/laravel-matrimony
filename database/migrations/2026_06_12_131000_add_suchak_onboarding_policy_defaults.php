<?php

use App\Modules\Suchak\Services\SuchakPolicyService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('suchak_policies')) {
            return;
        }

        $now = now();

        DB::table('suchak_policies')->upsert([
            [
                'policy_key' => SuchakPolicyService::KEY_SUCHAK_HERO_REGISTRATION_FORM_ENABLED,
                'policy_value' => SuchakPolicyService::DEFAULT_SUCHAK_HERO_REGISTRATION_FORM_ENABLED ? 'true' : 'false',
                'value_type' => 'boolean',
                'description' => 'Show the Suchak registration form directly inside the public Suchak hero section.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'policy_key' => SuchakPolicyService::KEY_SUCHAK_WORK_AREA_MIN_CONSENTED_CUSTOMERS,
                'policy_value' => (string) SuchakPolicyService::DEFAULT_SUCHAK_WORK_AREA_MIN_CONSENTED_CUSTOMERS,
                'value_type' => 'integer',
                'description' => 'Minimum valid consented customers required before an area is treated as Suchak work area.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['policy_key'], ['policy_value', 'value_type', 'description', 'is_active', 'updated_at']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('suchak_policies')) {
            return;
        }

        DB::table('suchak_policies')
            ->whereIn('policy_key', [
                SuchakPolicyService::KEY_SUCHAK_HERO_REGISTRATION_FORM_ENABLED,
                SuchakPolicyService::KEY_SUCHAK_WORK_AREA_MIN_CONSENTED_CUSTOMERS,
            ])
            ->delete();
    }
};
