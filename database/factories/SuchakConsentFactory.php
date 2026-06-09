<?php

namespace Database\Factories;

use App\Models\SuchakConsent;
use App\Models\SuchakProfileRepresentation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SuchakConsent>
 */
class SuchakConsentFactory extends Factory
{
    protected $model = SuchakConsent::class;

    public function definition(): array
    {
        $representation = SuchakProfileRepresentation::factory()->create();
        $rawToken = Str::random(64);

        return [
            'suchak_account_id' => $representation->suchak_account_id,
            'matrimony_profile_id' => $representation->matrimony_profile_id,
            'representation_id' => $representation->id,
            'consent_status' => SuchakConsent::STATUS_REQUESTED,
            'consent_type' => SuchakConsent::TYPE_ONE_YEAR,
            'consent_text_snapshot' => 'Suchak consent text snapshot for testing.',
            'consent_template_version' => SuchakConsent::TEMPLATE_VERSION_V1,
            'consent_given_by_name' => null,
            'relationship_to_candidate' => null,
            'consent_mobile_number' => null,
            'token_hash' => hash('sha256', $rawToken),
            'token_expires_at' => now()->addDays(SuchakConsent::DEFAULT_TOKEN_EXPIRY_DAYS),
            'otp_hash' => null,
            'otp_attempts' => 0,
            'last_otp_sent_at' => null,
            'accepted_at' => null,
            'rejected_at' => null,
            'revoked_at' => null,
            'used_at' => null,
            'otp_verified_at' => null,
            'consent_channel' => SuchakConsent::CHANNEL_WHATSAPP_DEEP_LINK,
            'valid_from' => null,
            'valid_until' => null,
            'revocation_reason' => null,
            'ip_address' => null,
            'user_agent' => null,
        ];
    }
}
