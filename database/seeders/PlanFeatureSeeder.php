<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanFeature;
use Illuminate\Database\Seeder;

/**
 * Example defaults for monetization-related keys (Silver / Gold / Platinum).
 * Idempotent: upserts only listed keys; other plan_features rows are left unchanged.
 */
class PlanFeatureSeeder extends Seeder
{
    public function run(): void
    {
        $sets = [
            'silver' => [
                'chat_send_limit' => '20',
                'contact_view_limit' => '5',
                'chat_can_read' => '0',
            ],
            'gold' => [
                'chat_send_limit' => '100',
                'contact_view_limit' => '20',
                'chat_can_read' => '1',
            ],
            'platinum' => [
                'chat_send_limit' => '-1',
                'contact_view_limit' => '-1',
                'chat_can_read' => '1',
                'priority_listing' => '1',
            ],
        ];

        foreach ($sets as $slug => $features) {
            $plan = Plan::query()->where('slug', $slug)->first();
            if (! $plan) {
                continue;
            }

            foreach ($features as $key => $value) {
                PlanFeature::query()->updateOrCreate(
                    [
                        'plan_id' => $plan->id,
                        'key' => $key,
                    ],
                    [
                        'value' => (string) $value,
                    ]
                );
            }

            $plan->forgetCachedPlanFeatures();
        }
    }
}
