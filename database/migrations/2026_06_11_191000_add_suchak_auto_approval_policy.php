<?php

use App\Models\SuchakPolicy;
use App\Modules\Suchak\Services\SuchakPolicyService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('suchak_policies')->upsert([
            [
                'policy_key' => SuchakPolicyService::KEY_SUCHAK_AUTO_APPROVE_ON_OTP,
                'policy_value' => SuchakPolicyService::DEFAULT_SUCHAK_AUTO_APPROVE_ON_OTP ? 'true' : 'false',
                'value_type' => SuchakPolicy::TYPE_BOOLEAN,
                'description' => 'Automatically approve a pending Suchak account after WhatsApp OTP verification.',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['policy_key'], ['policy_value', 'value_type', 'description', 'is_active', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('suchak_policies')
            ->where('policy_key', SuchakPolicyService::KEY_SUCHAK_AUTO_APPROVE_ON_OTP)
            ->delete();
    }
};
