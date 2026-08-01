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
            // A registered Suchak, mirroring SuchakRegistrationService::register(),
            // which is the only path that creates a usable account and always sets
            // this. Null means "signup abandoned mid-onboarding", and every access
            // gate (SuchakAccessService::canOperate / canPrepareCustomers) refuses
            // such an account before it ever looks at verification_status. Fixtures
            // that mean to test an unfinished signup say so explicitly with null.
            'registration_completed_at' => now(),
            'rejected_at' => null,
            'suspended_at' => null,
            'archived_at' => null,
            'suspension_reason' => null,
        ];
    }
}
