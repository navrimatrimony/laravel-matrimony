<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plans') || ! Schema::hasTable('plan_features')) {
            return;
        }

        $defaults = [
            'free' => ['biodata_export_limit' => '1', 'biodata_premium_templates' => '0'],
            'free_male' => ['biodata_export_limit' => '1', 'biodata_premium_templates' => '0'],
            'free_female' => ['biodata_export_limit' => '2', 'biodata_premium_templates' => '0'],
            'basic_male' => ['biodata_export_limit' => '5', 'biodata_premium_templates' => '0'],
            'basic_female' => ['biodata_export_limit' => '10', 'biodata_premium_templates' => '0'],
            'silver_male' => ['biodata_export_limit' => '20', 'biodata_premium_templates' => '1'],
            'silver_female' => ['biodata_export_limit' => '40', 'biodata_premium_templates' => '1'],
            'gold_male' => ['biodata_export_limit' => '-1', 'biodata_premium_templates' => '1'],
            'gold_female' => ['biodata_export_limit' => '-1', 'biodata_premium_templates' => '1'],
        ];

        DB::transaction(function () use ($defaults): void {
            foreach ($defaults as $slug => $features) {
                $planId = DB::table('plans')->where('slug', $slug)->value('id');
                if (! $planId) {
                    continue;
                }

                foreach ($features as $key => $value) {
                    $exists = DB::table('plan_features')
                        ->where('plan_id', $planId)
                        ->where('key', $key)
                        ->exists();
                    if ($exists) {
                        continue;
                    }

                    DB::table('plan_features')->insert([
                        'plan_id' => $planId,
                        'key' => $key,
                        'value' => $value,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Intentionally no-op: these rows may be edited by admins after deployment.
    }
};
