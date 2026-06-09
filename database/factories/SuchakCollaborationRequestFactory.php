<?php

namespace Database\Factories;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakProfileRepresentation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SuchakCollaborationRequest>
 */
class SuchakCollaborationRequestFactory extends Factory
{
    protected $model = SuchakCollaborationRequest::class;

    public function definition(): array
    {
        return [
            'requesting_suchak_account_id' => SuchakAccount::factory(),
            'target_suchak_account_id' => SuchakAccount::factory(),
            'requesting_matrimony_profile_id' => MatrimonyProfile::factory(),
            'target_matrimony_profile_id' => MatrimonyProfile::factory(),
            'requesting_representation_id' => SuchakProfileRepresentation::factory(),
            'target_representation_id' => SuchakProfileRepresentation::factory(),
            'status' => SuchakCollaborationRequest::STATUS_PENDING,
            'message' => null,
            'requested_at' => now(),
            'responded_at' => null,
            'expires_at' => now()->addDays(7),
        ];
    }
}
