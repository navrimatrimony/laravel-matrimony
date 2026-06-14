<?php

namespace Database\Factories;

use App\Models\SuchakAccount;
use App\Models\SuchakContactNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SuchakContactNumber>
 */
class SuchakContactNumberFactory extends Factory
{
    protected $model = SuchakContactNumber::class;

    public function definition(): array
    {
        return [
            'suchak_account_id' => SuchakAccount::factory(),
            'phone_number' => fake()->numerify('9#########'),
            'label' => 'Office',
            'is_whatsapp' => false,
            'is_active' => true,
        ];
    }
}
