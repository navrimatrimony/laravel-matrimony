<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\Location;
use App\Services\Image\ProfilePhotoUrlService;
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
            'visibilitySetting',
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
                'age_years' => $this->ageYears($profile->date_of_birth),
                'age_range' => $this->ageRange($profile->date_of_birth),
                'height_feet_inches' => $this->heightFeetInches($profile->height_cm),
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
            'photo' => $this->photoSummary($profile),
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

    private function ageYears(mixed $dateOfBirth): ?int
    {
        if ($dateOfBirth === null || $dateOfBirth === '') {
            return null;
        }

        try {
            $age = Carbon::parse($dateOfBirth)->age;
        } catch (\Throwable) {
            return null;
        }

        return $age >= 18 && $age <= 100 ? $age : null;
    }

    private function ageRange(mixed $dateOfBirth): ?string
    {
        $age = $this->ageYears($dateOfBirth);
        if ($age === null) {
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

    private function heightFeetInches(mixed $heightCm): ?string
    {
        if (! is_numeric($heightCm)) {
            return null;
        }

        $height = (int) $heightCm;
        if ($height < 100) {
            return null;
        }

        $totalInches = (int) round($height / 2.54);
        $feet = intdiv($totalInches, 12);
        $inches = $totalInches % 12;

        return $feet.' ft '.$inches.' in';
    }

    /**
     * @return array{is_masked: bool, url: ?string, placeholder_url: string, label: string}
     */
    private function photoSummary(MatrimonyProfile $profile): array
    {
        $showPhotoTo = strtolower(trim((string) ($profile->visibilitySetting?->show_photo_to ?? 'all')));
        $path = trim((string) ($profile->profile_photo ?? ''));
        $placeholderUrl = $this->placeholderPhotoUrl($profile);

        if ($showPhotoTo === 'all' && $path !== '' && $profile->photo_approved !== false) {
            return [
                'is_masked' => false,
                'url' => app(ProfilePhotoUrlService::class)->publicUrl($path, $profile),
                'placeholder_url' => $placeholderUrl,
                'label' => 'Photo visible',
            ];
        }

        return [
            'is_masked' => $showPhotoTo !== 'all' && $path !== '',
            'url' => null,
            'placeholder_url' => $placeholderUrl,
            'label' => $path === '' ? 'No photo' : 'Photo hidden by setting',
        ];
    }

    private function placeholderPhotoUrl(MatrimonyProfile $profile): string
    {
        return match ($profile->gender?->key) {
            'male' => asset('images/placeholders/male-profile.svg'),
            'female' => asset('images/placeholders/female-profile.svg'),
            default => asset('images/placeholders/default-profile.svg'),
        };
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
