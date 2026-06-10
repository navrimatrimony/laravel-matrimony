<?php

namespace Database\Factories;

use App\Models\SuchakPlan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SuchakPlan>
 */
class SuchakPlanFactory extends Factory
{
    protected $model = SuchakPlan::class;

    public function definition(): array
    {
        $name = 'Suchak '.fake()->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'description' => null,
            'price_amount' => null,
            'currency' => null,
            'is_active' => true,
            'is_visible' => true,
            'sort_order' => 10,
        ];
    }
}
