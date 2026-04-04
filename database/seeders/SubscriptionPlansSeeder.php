<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanFeature;
use App\Services\SubscriptionService;
use Illuminate\Database\Seeder;

class SubscriptionPlansSeeder extends Seeder
{
    public function run(): void
    {
        $defs = [
            [
                'name' => 'Free',
                'slug' => 'free',
                'price' => 0,
                'discount_percent' => null,
                'duration_days' => 0,
                'sort_order' => 10,
                'highlight' => false,
                'features' => [
                    SubscriptionService::FEATURE_DAILY_CHAT_SEND_LIMIT => '5',
                    SubscriptionService::FEATURE_MONTHLY_INTEREST_SEND_LIMIT => '3',
                    SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT => '50',
                    SubscriptionService::FEATURE_CONTACT_NUMBER_ACCESS => '0',
                    SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES => '0',
                ],
            ],
            [
                'name' => 'Basic',
                'slug' => 'basic',
                'price' => 999,
                'discount_percent' => null,
                'duration_days' => 30,
                'sort_order' => 20,
                'highlight' => false,
                'features' => [
                    SubscriptionService::FEATURE_DAILY_CHAT_SEND_LIMIT => '25',
                    SubscriptionService::FEATURE_MONTHLY_INTEREST_SEND_LIMIT => '15',
                    SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT => '200',
                    SubscriptionService::FEATURE_CONTACT_NUMBER_ACCESS => '1',
                    SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES => '0',
                ],
            ],
            [
                'name' => 'Silver',
                'slug' => 'silver',
                'price' => 2499,
                'discount_percent' => null,
                'duration_days' => 30,
                'sort_order' => 30,
                'highlight' => false,
                'features' => [
                    SubscriptionService::FEATURE_DAILY_CHAT_SEND_LIMIT => '100',
                    SubscriptionService::FEATURE_MONTHLY_INTEREST_SEND_LIMIT => '50',
                    SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT => '-1',
                    SubscriptionService::FEATURE_CONTACT_NUMBER_ACCESS => '1',
                    SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES => '1',
                ],
            ],
            [
                'name' => 'Gold',
                'slug' => 'gold',
                'price' => 4999,
                'discount_percent' => null,
                'duration_days' => 30,
                'sort_order' => 40,
                'highlight' => true,
                'features' => [
                    SubscriptionService::FEATURE_DAILY_CHAT_SEND_LIMIT => '-1',
                    SubscriptionService::FEATURE_MONTHLY_INTEREST_SEND_LIMIT => '-1',
                    SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT => '-1',
                    SubscriptionService::FEATURE_CONTACT_NUMBER_ACCESS => '1',
                    SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES => '1',
                ],
            ],
        ];

        foreach ($defs as $row) {
            $features = $row['features'];
            unset($row['features']);

            $plan = Plan::query()->updateOrCreate(
                ['slug' => $row['slug']],
                $row
            );

            PlanFeature::query()->where('plan_id', $plan->id)->delete();
            foreach ($features as $key => $value) {
                PlanFeature::query()->create([
                    'plan_id' => $plan->id,
                    'key' => $key,
                    'value' => (string) $value,
                ]);
            }
        }
    }
}
