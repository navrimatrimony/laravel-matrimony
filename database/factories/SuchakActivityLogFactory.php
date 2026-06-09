<?php

namespace Database\Factories;

use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SuchakActivityLog>
 */
class SuchakActivityLogFactory extends Factory
{
    protected $model = SuchakActivityLog::class;

    public function definition(): array
    {
        return [
            'suchak_account_id' => SuchakAccount::factory(),
            'actor_user_id' => User::factory(),
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => SuchakActivityLog::ACTION_SOURCE_LINK_CREATED,
            'target_type' => 'suchak_biodata_intake_link',
            'target_id' => $this->faker->numberBetween(1, 1000),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'SuchakActivityLogFactory',
            'metadata_json' => ['source' => 'factory'],
            'occurred_at' => now(),
        ];
    }
}
