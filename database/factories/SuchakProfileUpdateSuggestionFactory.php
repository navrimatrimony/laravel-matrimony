<?php

namespace Database\Factories;

use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileUpdateSuggestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SuchakProfileUpdateSuggestion>
 */
class SuchakProfileUpdateSuggestionFactory extends Factory
{
    protected $model = SuchakProfileUpdateSuggestion::class;

    public function definition(): array
    {
        $representation = SuchakProfileRepresentation::factory()->create();

        return [
            'suchak_account_id' => $representation->suchak_account_id,
            'matrimony_profile_id' => $representation->matrimony_profile_id,
            'representation_id' => $representation->id,
            'field_key' => 'highest_education',
            'old_value' => 'B.Com',
            'suggested_value' => 'M.Com',
            'suggestion_status' => SuchakProfileUpdateSuggestion::STATUS_PENDING_CANDIDATE_CONFIRMATION,
            'otp_hash' => null,
            'otp_attempts' => 0,
            'last_otp_sent_at' => null,
            'candidate_verified_at' => null,
            'admin_reviewed_at' => null,
            'applied_at' => null,
        ];
    }
}
