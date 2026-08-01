<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\Location;
use App\Services\Image\ProfilePhotoUrlService;
use App\Models\SuchakProfileRepresentation;
use App\Support\CandidateNameMask;
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
            'candidate_reference' => $this->maskedCandidateReference($profile, $representation),
            'display_name' => $this->displayName($profile, $representation),
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
            'location' => $this->locationSlot($profile, $representation),
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

    /**
     * Stable, non-reversible handle for one candidate. Representation-keyed when there is one; otherwise
     * profile-keyed, so unrepresented platform members in a Suchak suggestion list stay distinguishable
     * from one another instead of all collapsing onto a single "unknown" reference.
     */
    private function maskedCandidateReference(
        MatrimonyProfile $profile,
        ?SuchakProfileRepresentation $representation,
    ): string {
        $source = $representation?->getKey() !== null
            ? 'representation:'.$representation->getKey()
            : 'profile:'.($profile->getKey() ?? 'unknown');

        return 'masked-'.substr(hash('sha256', (string) $source), 0, 12);
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

    /**
     * What another Suchak may call this candidate.
     *
     * The originating Suchak decides, per candidate, because he is the one who
     * knows the family. Default is the shared mask — the same one the
     * pending-consent list uses, so a candidate is never named two different
     * ways in two places.
     *
     * `shared_display_name` exists because `matrimony_profiles.full_name` is a
     * single column: there is no surname to peel off, and splitting names is
     * wrong for a great many of them. A Suchak who wants "Gaikwad" or
     * "Sunita G. (Lakhandur)" types it.
     */
    private function displayName(
        MatrimonyProfile $profile,
        ?SuchakProfileRepresentation $representation,
    ): ?string {
        $fullName = trim((string) ($profile->full_name ?? ''));
        if ($fullName === '') {
            return null;
        }

        $typed = trim((string) ($representation->shared_display_name ?? ''));
        if ($typed !== '') {
            return $typed;
        }

        return $representation?->shares_name === true
            ? $fullName
            : CandidateNameMask::mask($fullName);
    }

    /**
     * How precisely another Suchak may place this candidate.
     *
     * `city` was never a city: locationNameForCitySlot() walks up to the
     * village-tagged node, so the exact village was going out under that key
     * while `is_broad` was hardcoded true — a flag asserting the opposite of
     * what was in the payload beside it. A matchmaker needs the region to find
     * a match; he does not need the village to decide whether to look, and the
     * family did not agree to be locatable by every account on the platform.
     *
     * So taluka-and-above by default, the village only when the originating
     * Suchak says so, and the flag now reports what was actually sent.
     */
    private function locationSlot(
        MatrimonyProfile $profile,
        ?SuchakProfileRepresentation $representation,
    ): array {
        $revealVillage = $representation?->shares_village === true;
        $district = $this->locationNameOfType($profile->location, 'district');

        $city = $revealVillage
            ? $this->locationNameForCitySlot($profile->location)
            : $this->locationNameOfType($profile->location, 'taluka');

        return [
            'city' => $city,
            'district' => $district,
            'is_broad' => ! $revealVillage,
            'exact_address' => $representation?->shares_detailed_address === true
                ? $this->safeText($profile->address_line)
                : null,
        ];
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
