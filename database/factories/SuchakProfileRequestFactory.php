<?php

namespace Database\Factories;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SuchakProfileRequest>
 */
class SuchakProfileRequestFactory extends Factory
{
    protected $model = SuchakProfileRequest::class;

    public function definition(): array
    {
        $requestingUser = User::factory()->create();
        $requestingProfile = MatrimonyProfile::factory()->create([
            'user_id' => $requestingUser->id,
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);

        $suchakUser = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        $targetProfile = MatrimonyProfile::factory()->create([
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);

        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $targetProfile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);

        return [
            'requesting_user_id' => $requestingUser->id,
            'requesting_matrimony_profile_id' => $requestingProfile->id,
            'target_matrimony_profile_id' => $targetProfile->id,
            'selected_suchak_account_id' => $account->id,
            'representation_id' => $representation->id,
            'request_status' => SuchakProfileRequest::STATUS_PENDING,
            'request_reason' => null,
            'message' => null,
        ];
    }
}
