<?php

namespace Database\Factories;

use App\Models\SuchakAccount;
use App\Models\SuchakPlan;
use App\Models\SuchakSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SuchakSubscription>
 */
class SuchakSubscriptionFactory extends Factory
{
    protected $model = SuchakSubscription::class;

    public function definition(): array
    {
        return [
            'suchak_account_id' => SuchakAccount::factory(),
            'suchak_plan_id' => SuchakPlan::factory(),
            'assigned_by_user_id' => User::factory(),
            'status' => SuchakSubscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'ends_at' => null,
            'assigned_at' => now(),
            'cancelled_at' => null,
            'expired_at' => null,
            'notes' => null,
        ];
    }
}
