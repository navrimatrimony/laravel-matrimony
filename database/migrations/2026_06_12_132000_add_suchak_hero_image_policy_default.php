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
        $row = [
            'policy_key' => SuchakPolicyService::KEY_SUCHAK_HERO_IMAGE_PATH,
            'policy_value' => SuchakPolicyService::DEFAULT_SUCHAK_HERO_IMAGE_PATH,
            'value_type' => 'string',
            'description' => 'Public Suchak homepage hero image path stored on the public disk.',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $updateColumns = ['policy_value', 'value_type', 'description', 'is_active', 'updated_at'];

        if (Schema::hasColumn('suchak_policies', 'description_mr')) {
            $row['description_mr'] = 'Public Suchak homepage वर दिसणाऱ्या hero image चा public disk path.';
            $updateColumns[] = 'description_mr';
        }

        DB::table('suchak_policies')->upsert([$row], ['policy_key'], $updateColumns);
    }

    public function down(): void
    {
        if (! Schema::hasTable('suchak_policies')) {
            return;
        }

        DB::table('suchak_policies')
            ->where('policy_key', SuchakPolicyService::KEY_SUCHAK_HERO_IMAGE_PATH)
            ->delete();
    }
};
