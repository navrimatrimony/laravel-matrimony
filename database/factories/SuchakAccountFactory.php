<?php

namespace Database\Factories;

use App\Models\SuchakAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SuchakAccount>
 */
class SuchakAccountFactory extends Factory
{
    protected $model = SuchakAccount::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'suchak_name' => fake()->name(),
            'office_name' => null,
            'business_type' => SuchakAccount::BUSINESS_TYPE_INDIVIDUAL,
            'mobile_number' => fake()->numerify('##########'),
            'whatsapp_number' => null,
            'email' => fake()->safeEmail(),
            'address_line' => null,
            'city_id' => null,
            'taluka_id' => null,
            'district_id' => null,
            'state_id' => null,
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
            'verified_at' => null,
            'rejected_at' => null,
            'suspended_at' => null,
            'archived_at' => null,
            'suspension_reason' => null,
        ];
    }
}
