<?php

namespace Database\Factories;

use App\Models\SuchakPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SuchakPolicy>
 */
class SuchakPolicyFactory extends Factory
{
    protected $model = SuchakPolicy::class;

    public function definition(): array
    {
        return [
            'policy_key' => fake()->unique()->slug(),
            'policy_value' => 'value',
            'value_type' => SuchakPolicy::TYPE_STRING,
            'description' => null,
            'is_active' => true,
        ];
    }
}
