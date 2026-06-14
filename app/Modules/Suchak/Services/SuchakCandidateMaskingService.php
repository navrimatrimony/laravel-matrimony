<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\Location;
use App\Models\SuchakProfileRepresentation;
use Illuminate\Support\Carbon;

class SuchakCandidateMaskingService
{
    /**
     * @return array<string, mixed>
     */
    public function maskedSummary(
        MatrimonyProfile $profile,
        ?SuchakProfileRepresentation $representation = null,
    ): array {
        $profile->loadMissing([
            'gender',
            'maritalStatus',
            'religion',
            'caste',
            'location.parent.parent.parent',
            'occupationMaster',
        ]);

        return [
            'candidate_reference' => $this->maskedCandidateReference($representation),
            'basic' => [
                'gender_id' => $profile->gender_id,
                'gender' => $this->lookupLabel($profile->gender),
                'marital_status_id' => $profile->marital_status_id,
                'marital_status' => $this->lookupLabel($profile->maritalStatus),
                'age_range' => $this->ageRange($profile->date_of_birth),
                'height_range' => $this->heightRange($profile->height_cm),
            ],
            'community' => [
                'religion' => $this->lookupLabel($profile->religion),
                'caste' => $this->lookupLabel($profile->caste),
                'is_policy_limited' => false,
            ],
            'location' => [
                'city' => $this->locationNameForCitySlot($profile->location),
                'district' => $this->locationNameOfType($profile->location, 'district'),
                'is_broad' => true,
                'exact_address' => null,
            ],
            'education' => [
                'highest' => $this->safeText($profile->highest_education),
            ],
            'occupation' => [
                'broad' => $this->lookupLabel($profile->occupationMaster),
            ],
            'representation' => [
                'id' => $representation?->id,
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
            'photo' => [
                'is_masked' => true,
                'url' => null,
            ],
            'quality' => [
                'has_photo' => filled($profile->profile_photo),
                'has_verified_consent' => $representation?->hasValidConsent() === true,
                'active_representation' => $representation?->representation_status === SuchakProfileRepresentation::STATUS_ACTIVE,
            ],
        ];
    }

    private function maskedCandidateReference(?SuchakProfileRepresentation $representation): string
    {
        $source = $representation?->getKey() !== null
            ? 'representation:'.$representation->getKey()
            : 'candidate:unknown';

        return 'masked-'.substr(hash('sha256', $source), 0, 12);
    }

    private function ageRange(mixed $dateOfBirth): ?string
    {
        if ($dateOfBirth === null || $dateOfBirth === '') {
            return null;
        }

        try {
            $age = Carbon::parse($dateOfBirth)->age;
        } catch (\Throwable) {
            return null;
        }

        $lower = max(18, (int) floor($age / 5) * 5);
        $upper = $lower + 4;

        return $lower.'-'.$upper;
    }

    private function heightRange(mixed $heightCm): ?string
    {
        if (! is_numeric($heightCm)) {
            return null;
        }

        $height = (int) $heightCm;
        if ($height < 100) {
            return null;
        }

        $lower = (int) floor($height / 5) * 5;
        $upper = $lower + 4;

        return $lower.'-'.$upper.' cm';
    }

    private function locationNameForCitySlot(?Location $location): ?string
    {
        $current = $location;
        while ($current !== null) {
            if ($current->hierarchy === 'village' && in_array((string) $current->tag, ['city', 'suburban', 'rural'], true)) {
                return $current->localizedName();
            }
            $current = $current->parent;
        }

        return null;
    }

    private function locationNameOfType(?Location $location, string $type): ?string
    {
        $current = $location;
        while ($current !== null) {
            if ($current->hierarchy === $type) {
                return $current->localizedName();
            }
            $current = $current->parent;
        }

        return null;
    }

    private function lookupLabel(mixed $model): ?string
    {
        if (! $model) {
            return null;
        }

        foreach (['display_label', 'label', 'name'] as $attribute) {
            $value = $model->{$attribute} ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function safeText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
