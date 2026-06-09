<?php

namespace Database\Factories;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileRepresentation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SuchakProfileRepresentation>
 */
class SuchakProfileRepresentationFactory extends Factory
{
    protected $model = SuchakProfileRepresentation::class;

    public function definition(): array
    {
        return [
            'suchak_account_id' => SuchakAccount::factory(),
            'matrimony_profile_id' => MatrimonyProfile::factory(),
            'biodata_intake_id' => null,
            'representation_status' => SuchakProfileRepresentation::STATUS_PENDING,
            'representation_mode' => SuchakProfileRepresentation::MODE_UPLOADED_BY_SUCHAK,
            'consent_status' => SuchakProfileRepresentation::CONSENT_NOT_REQUESTED,
            'first_uploaded_at' => null,
            'first_identified_at' => null,
            'first_verified_consent_at' => null,
            'consent_verified_at' => null,
            'consent_valid_until' => null,
            'revoked_at' => null,
            'candidate_deactivated_at' => null,
        ];
    }
}
