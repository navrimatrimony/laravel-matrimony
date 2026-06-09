<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\SuchakProfileRepresentation;

class SuchakCandidateMaskingService
{
    /**
     * @return array<string, mixed>
     */
    public function maskedSummary(
        MatrimonyProfile $profile,
        ?SuchakProfileRepresentation $representation = null,
    ): array {
        return [
            'candidate_reference' => 'masked-candidate',
            'basic' => [
                'gender_id' => $profile->gender_id,
                'marital_status_id' => $profile->marital_status_id,
            ],
            'representation' => [
                'status' => $representation?->representation_status,
                'mode' => $representation?->representation_mode,
                'consent_status' => $representation?->consent_status,
            ],
            'visibility' => [
                'is_public_user_visible' => $representation?->isPubliclyVisible() === true,
                'requires_valid_consent' => true,
                'contact_reveal_allowed' => false,
            ],
            'contact' => [
                'is_masked' => true,
                'phone' => null,
                'whatsapp' => null,
                'email' => null,
                'address_line' => null,
            ],
        ];
    }
}
