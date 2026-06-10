<?php

namespace Database\Factories;

use App\Models\SuchakPlan;
use App\Models\SuchakPlanFeature;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SuchakPlanFeature>
 */
class SuchakPlanFeatureFactory extends Factory
{
    protected $model = SuchakPlanFeature::class;

    public function definition(): array
    {
        return [
            'suchak_plan_id' => SuchakPlan::factory(),
            'feature_key' => SuchakPlanFeature::FEATURE_ACTIVE_PROFILE_LIMIT,
            'value_type' => SuchakPlanFeature::TYPE_INTEGER,
            'feature_value' => '25',
            'is_enabled' => true,
        ];
    }
}
