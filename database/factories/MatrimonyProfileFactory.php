<?php

namespace Database\Factories;

use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MatrimonyProfile>
 */
class MatrimonyProfileFactory extends Factory
{
    protected $model = MatrimonyProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'full_name' => fake()->name(),
            'lifecycle_state' => 'draft',
        ];
    }
}
