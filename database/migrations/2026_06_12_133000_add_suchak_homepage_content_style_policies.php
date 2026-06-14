<?php

use App\Models\SuchakPolicy;
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
        $rows = [
            [
                'policy_key' => SuchakPolicyService::KEY_SUCHAK_HOMEPAGE_COPY_JSON,
                'policy_value' => json_encode(SuchakPolicyService::DEFAULT_SUCHAK_HOMEPAGE_COPY, JSON_THROW_ON_ERROR),
                'value_type' => SuchakPolicy::TYPE_JSON,
                'description' => 'Public Suchak homepage bilingual copy, benefits, process steps, and tool labels.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'policy_key' => SuchakPolicyService::KEY_SUCHAK_HOMEPAGE_STYLE_JSON,
                'policy_value' => json_encode(SuchakPolicyService::DEFAULT_SUCHAK_HOMEPAGE_STYLE, JSON_THROW_ON_ERROR),
                'value_type' => SuchakPolicy::TYPE_JSON,
                'description' => 'Public Suchak homepage hero visual style controls.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];
        $updateColumns = ['policy_value', 'value_type', 'description', 'is_active', 'updated_at'];

        if (Schema::hasColumn('suchak_policies', 'description_mr')) {
            $rows[0]['description_mr'] = 'Public Suchak homepage वरील Marathi/English मजकूर, फायदे, process steps आणि tool labels.';
            $rows[1]['description_mr'] = 'Public Suchak homepage hero visual style controls.';
            $updateColumns[] = 'description_mr';
        }

        DB::table('suchak_policies')->upsert($rows, ['policy_key'], $updateColumns);
    }

    public function down(): void
    {
        if (! Schema::hasTable('suchak_policies')) {
            return;
        }

        DB::table('suchak_policies')
            ->whereIn('policy_key', [
                SuchakPolicyService::KEY_SUCHAK_HOMEPAGE_COPY_JSON,
                SuchakPolicyService::KEY_SUCHAK_HOMEPAGE_STYLE_JSON,
            ])
            ->delete();
    }
};
