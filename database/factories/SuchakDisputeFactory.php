<?php

namespace Database\Factories;

use App\Models\SuchakAccount;
use App\Models\SuchakDispute;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SuchakDispute>
 */
class SuchakDisputeFactory extends Factory
{
    protected $model = SuchakDispute::class;

    public function definition(): array
    {
        return [
            'suchak_account_id' => SuchakAccount::factory(),
            'matrimony_profile_id' => null,
            'representation_id' => null,
            'opened_by_user_id' => User::factory(),
            'assigned_admin_user_id' => null,
            'dispute_type' => SuchakDispute::TYPE_REPRESENTATION_CLAIM,
            'status' => SuchakDispute::STATUS_OPEN,
            'priority' => SuchakDispute::PRIORITY_NORMAL,
            'summary' => 'Factory Suchak dispute summary.',
            'evidence_summary' => null,
            'resolution_note' => null,
            'opened_at' => now(),
            'resolved_at' => null,
        ];
    }
}
