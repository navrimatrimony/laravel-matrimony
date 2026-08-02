<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\Location;
use App\Services\Image\ProfilePhotoUrlService;
use App\Models\SuchakProfileRepresentation;
use App\Support\CandidateNameMask;
use Illuminate\Support\Carbon;

/**
 * The cross-Suchak presenter: what one Suchak may see of another Suchak's candidate (D19a).
 *
 * Four methods on it are PUBLIC and are not masking decisions — {@see self::ageYears()},
 * {@see self::locationNameOfType()}, {@see self::locationNameForCitySlot()} and
 * {@see self::masterLabel()}. They are the Suchak domain's one age rule, two location walks and one
 * lookup-label rule, and they are public because the marketplace's OWN-candidate reads (D7a, and the
 * publisher's own challenge list) render the same facts UNMASKED and must not grow a second copy of
 * any of them. A private helper duplicated into the caller that needed it second is exactly the
 * defect the frozen no-duplicate rule names; a shared reader is not.
 */
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
                'gender' => $this->masterLabel($profile->gender),
                'marital_status_id' => $profile->marital_status_id,
                'marital_status' => $this->masterLabel($profile->maritalStatus),
                'age_years' => $this->ageYears($profile->date_of_birth),
                'age_range' => $this->ageRange($profile->date_of_birth),
                'height_feet_inches' => $this->heightFeetInches($profile->height_cm),
                'height_range' => $this->heightRange($profile->height_cm),
            ],
            'community' => [
                'religion' => $this->masterLabel($profile->religion),
                'caste' => $this->masterLabel($profile->caste),
                'is_policy_limited' => false,
            ],
            'location' => $this->locationSlot($profile, $representation),
            'education' => [
                'highest' => $this->safeText($profile->highest_education),
            ],
            'occupation' => [
                'broad' => $this->masterLabel($profile->occupationMaster),
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

    /**
     * Exact age in years, or null when the date of birth is missing or outside the marriageable band
     * this product records. Shared: masked cards and own-book cards state an age the same way.
     */
    public function ageYears(mixed $dateOfBirth): ?int
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
     * THE reveal question for the village: may this reader be placed at village level at all?
     *
     * Public, and the only place `shares_village` is read. It was already the rule behind
     * {@see self::locationSlot()}; it is public now because the FIT EXPLANATION has to obey the same
     * answer as the card it is printed beside — {@see SuchakMatchFitService::fit()} asks here rather
     * than reading the column a second time. A masked card that prints the taluka while the score
     * beside it distinguishes the village is not a partial disclosure, it is the whole disclosure
     * with an extra request in front of it.
     *
     * Null representation — an unrepresented platform member in a Suchak list — reveals nothing, so
     * the default is closed. That is deliberate: a caller that forgets to pass the representation
     * gets the SAFE answer, never the precise one.
     */
    public function revealsVillage(?SuchakProfileRepresentation $representation): bool
    {
        return $representation?->shares_village === true;
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
        $revealVillage = $this->revealsVillage($representation);
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

    /**
     * Walk UP to the VILLAGE-tagged node — the deepest place this product records a candidate at.
     *
     * Public for the same reason {@see self::locationNameOfType()} is: the reads that show a Suchak
     * HIS OWN candidate place him exactly this precisely, and a second copy of the walk in the caller
     * that needed it is the duplicate the frozen rule names. Making it public reveals nothing on its
     * own — {@see self::locationSlot()} still asks {@see self::revealsVillage()} before calling it, so
     * every CROSS-Suchak read is unchanged.
     */
    public function locationNameForCitySlot(?Location $location): ?string
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

    /**
     * Walk UP the `addresses` chain to the named level and return its localized name.
     *
     * The one walk. `country > state > district > taluka > village` is a parent chain, not a set of
     * columns, so "which district is this candidate in" is answered by walking and never by reading a
     * `district_id` that does not exist on the profile.
     */
    public function locationNameOfType(?Location $location, string $type): ?string
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

    /**
     * The display label of a master lookup row, in the order the schema actually fills them.
     * Shared with the own-book reads so one gender is never "Female" on one screen and "female" on
     * the next.
     */
    public function masterLabel(mixed $model): ?string
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
