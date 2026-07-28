<?php

namespace App\Services;

use App\Models\MatrimonyProfile;
use App\Support\SchemaPresence;
use Illuminate\Support\Facades\DB;

/**
 * "What is still worth filling in on this candidate?" — the section work-queue
 * behind the Suchak app's profile-readiness card and the edit hub's chips.
 *
 * THIS IS NOT A THIRD COMPLETENESS READING. Read this before changing it.
 *
 * Two readings already exist and are deliberately different:
 *
 *  - {@see \App\Modules\Suchak\Services\SuchakCustomerListService::ONBOARDING_SECTIONS}
 *    scores five sections and answers "was the onboarding run finished?" It
 *    drives the customer list's percentage and where the wizard resumes.
 *  - {@see ProfileCompletionService} owns the member-side meaning of
 *    "is this section complete?" and is what the represented-profile detail
 *    endpoint returns.
 *
 * This service adds no new opinion about completeness. It is a PRESENTER over
 * the second reading:
 *
 *  - `ready` is gated on {@see ProfileCompletionService::getSectionStatus()}
 *    reporting `completed`. A section this service calls ready is always a
 *    section ProfileCompletionService calls completed — never the reverse. So
 *    the card, the hub chips and the member-side status can never disagree
 *    about whether a section is done.
 *  - the only thing added is a FIELD CENSUS (`filled` / `total`) whose sole job
 *    is to split "not done" into "nothing at all" versus "half filled", which
 *    is the distinction the Suchak actually needs before phoning the customer.
 *    It can never promote a section to done on its own.
 *
 * Section grouping, order and labels are taken from the existing catalog
 * (config/field_catalog.php via {@see FieldCatalogService}), so a section is
 * called the same thing here, in the wizard and on the edit screen.
 */
class ProfileSectionReadinessService
{
    public const STATE_MISSING = 'missing';

    public const STATE_PARTIAL = 'partial';

    public const STATE_READY = 'ready';

    /**
     * The eleven rows the Suchak edit hub offers, in catalog order.
     *
     * `completion_keys` are {@see ProfileCompletionService::SECTIONS} keys —
     * "relatives" covers both `relatives` and `alliance` because the edit hub
     * edits them on one screen, exactly as the hub already merges them.
     *
     * `ready_at` is how many census fields make a section genuinely usable in a
     * biodata. It mirrors the member app's own readiness thresholds so the two
     * apps describe one candidate the same way. It can only make `ready`
     * STRICTER than ProfileCompletionService, never looser.
     *
     * @var array<string, array{completion_keys: list<string>, label_key: string, ready_at: int}>
     */
    private const SECTIONS = [
        'basic' => ['completion_keys' => ['basic-info'], 'label_key' => 'basic-info', 'ready_at' => 5],
        'physical' => ['completion_keys' => ['physical'], 'label_key' => 'physical', 'ready_at' => 3],
        'education_career' => ['completion_keys' => ['education-career'], 'label_key' => 'education-career', 'ready_at' => 2],
        'family_details' => ['completion_keys' => ['family-details'], 'label_key' => 'family-details', 'ready_at' => 3],
        'siblings' => ['completion_keys' => ['siblings'], 'label_key' => 'siblings', 'ready_at' => 1],
        'relatives' => ['completion_keys' => ['relatives', 'alliance'], 'label_key' => 'relatives', 'ready_at' => 1],
        'property' => ['completion_keys' => ['property'], 'label_key' => 'property', 'ready_at' => 1],
        'horoscope' => ['completion_keys' => ['horoscope'], 'label_key' => 'horoscope', 'ready_at' => 2],
        'about_me' => ['completion_keys' => ['about-me'], 'label_key' => 'about-me', 'ready_at' => 1],
        'partner_preferences' => ['completion_keys' => ['about-preferences'], 'label_key' => 'about-preferences', 'ready_at' => 3],
        'photo' => ['completion_keys' => ['photo'], 'label_key' => 'photo', 'ready_at' => 1],
    ];

    /**
     * @return array{
     *     total_sections: int,
     *     ready_sections: int,
     *     missing_sections: int,
     *     partial_sections: int,
     *     sections: list<array{
     *         key: string,
     *         label: string,
     *         completion_keys: list<string>,
     *         state: string,
     *         filled: int,
     *         total: int,
     *         ready_at: int
     *     }>
     * }
     */
    public function forProfile(?MatrimonyProfile $profile): array
    {
        $facts = $profile instanceof MatrimonyProfile
            ? $this->relatedFacts($profile)
            : [];

        $sections = [];
        $ready = 0;
        $missing = 0;
        $partial = 0;

        foreach (self::SECTIONS as $key => $definition) {
            $probes = $this->probes($profile, $key, $facts);
            $total = count($probes);
            $filled = count(array_filter($probes));

            // The completeness authority stays ProfileCompletionService. This
            // service may only ever be stricter than it, never kinder.
            $statusCompleted = false;
            foreach ($definition['completion_keys'] as $completionKey) {
                if (ProfileCompletionService::getSectionStatus($profile, $completionKey) === 'completed') {
                    $statusCompleted = true;
                    break;
                }
            }

            $isReady = $statusCompleted && $filled >= $definition['ready_at'];
            if ($isReady) {
                $state = self::STATE_READY;
                $ready++;
            } elseif ($filled <= 0) {
                $state = self::STATE_MISSING;
                $missing++;
            } else {
                $state = self::STATE_PARTIAL;
                $partial++;
            }

            $sections[] = [
                'key' => $key,
                'label' => $this->sectionLabel($definition['label_key']),
                'completion_keys' => $definition['completion_keys'],
                'state' => $state,
                'filled' => $filled,
                'total' => $total,
                'ready_at' => $definition['ready_at'],
            ];
        }

        return [
            'total_sections' => count(self::SECTIONS),
            'ready_sections' => $ready,
            'missing_sections' => $missing,
            'partial_sections' => $partial,
            'sections' => $sections,
        ];
    }

    /**
     * Localized section name, resolved through the canonical catalog so the
     * card, the wizard and the edit screen all print the same word.
     */
    private function sectionLabel(string $completionKey): string
    {
        $translationKey = FieldCatalogService::getSectionLabel($completionKey);
        $translated = __($translationKey);

        return is_string($translated) && $translated !== $translationKey
            ? $translated
            : $completionKey;
    }

    /**
     * Related-table reads done once per profile instead of once per probe.
     *
     * @return array<string, mixed>
     */
    private function relatedFacts(MatrimonyProfile $profile): array
    {
        $profileId = (int) $profile->id;

        $horoscope = DB::table('profile_horoscope_data')->where('profile_id', $profileId)->first();
        $extended = DB::table('profile_extended_attributes')->where('profile_id', $profileId)->first();
        $criteria = DB::table('profile_preference_criteria')->where('profile_id', $profileId)->first();

        return [
            'siblings' => DB::table('profile_siblings')->where('profile_id', $profileId)->exists(),
            'relatives' => DB::table('profile_relatives')->where('profile_id', $profileId)->exists(),
            'alliance' => DB::table('profile_alliance_networks')->where('profile_id', $profileId)->exists(),
            'parents_address' => DB::table('profile_addresses')
                ->where('profile_id', $profileId)
                ->where('address_scope', 'parents')
                ->exists(),
            'horoscope' => $horoscope,
            'extended' => $extended,
            'criteria' => $criteria,
            'pref_education' => $this->pivotHasRows('profile_preferred_education_degrees', $profileId),
            'pref_occupation' => $this->pivotHasRows('profile_preferred_occupation_master', $profileId),
            'pref_religion' => $this->pivotHasRows('profile_preferred_religions', $profileId),
            'pref_caste' => $this->pivotHasRows('profile_preferred_castes', $profileId),
            'pref_district' => $this->pivotHasRows('profile_preferred_districts', $profileId),
        ];
    }

    private function pivotHasRows(string $table, int $profileId): bool
    {
        if (! SchemaPresence::hasTable($table)) {
            return false;
        }

        return DB::table($table)->where('profile_id', $profileId)->exists();
    }

    /**
     * The census for one section: which of its user-visible facts are present.
     *
     * These are the fields the Suchak can actually type on the matching edit
     * screen, which is why the count is richer than the coarse "any data at
     * all" predicate ProfileCompletionService uses to call a section complete.
     * A section with 1 of 7 family facts is honestly half-filled even though
     * the completeness authority already counts it as done — and the card only
     * ever uses that to phrase "N पैकी M भरले", never to override `ready`.
     *
     * A null profile keeps the SAME probe keys, all false — so the denominator
     * ("0 of 6") comes from this one definition and is never restated.
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, bool>
     */
    private function probes(?MatrimonyProfile $profile, string $sectionKey, array $facts): array
    {
        $horoscope = $facts['horoscope'] ?? null;
        $extended = $facts['extended'] ?? null;
        $criteria = $facts['criteria'] ?? null;

        return match ($sectionKey) {
            'basic' => [
                'full_name' => $this->filledText($profile?->full_name),
                'date_of_birth' => ($profile?->date_of_birth ?? null) !== null && $profile?->date_of_birth !== '',
                'gender' => ($profile?->gender_id ?? null) !== null,
                'religion' => ($profile?->religion_id ?? null) !== null,
                'caste' => ($profile?->caste_id ?? null) !== null,
                'residence' => $profile instanceof MatrimonyProfile
                    && ProfileCompletionService::sectionLocationFilled($profile),
            ],
            'physical' => [
                'height' => ($profile?->height_cm ?? null) !== null,
                'weight' => ($profile?->weight_kg ?? null) !== null || $this->filledText($profile?->weight_range),
                'complexion' => ($profile?->complexion_id ?? null) !== null
                    || ($profile?->physical_build_id ?? null) !== null,
                'diet' => ($profile?->diet_id ?? null) !== null,
            ],
            'education_career' => [
                'education' => $this->filledText($profile?->highest_education),
                'occupation' => ($profile?->occupation_master_id ?? null) !== null
                    || ($profile?->occupation_custom_id ?? null) !== null
                    || $this->filledText($profile?->occupation_title),
                'workplace' => $this->filledText($profile?->company_name)
                    || $this->filledText($profile?->work_location_text)
                    || ($profile?->work_city_id ?? null) !== null,
                'income' => ($profile?->annual_income ?? null) !== null
                    || ($profile?->income_amount ?? null) !== null
                    || ($profile?->income_range_id ?? null) !== null,
            ],
            'family_details' => [
                'father_name' => $this->filledText($profile?->father_name),
                'father_occupation' => $this->filledText($profile?->father_occupation)
                    || ($profile?->father_occupation_master_id ?? null) !== null,
                'mother_name' => $this->filledText($profile?->mother_name),
                'mother_occupation' => $this->filledText($profile?->mother_occupation)
                    || ($profile?->mother_occupation_master_id ?? null) !== null,
                'family_address' => (bool) ($facts['parents_address'] ?? false),
                'family_type' => ($profile?->family_type_id ?? null) !== null,
                'family_standing' => $this->filledText($profile?->family_status)
                    || $this->filledText($profile?->family_values),
            ],
            'siblings' => [
                // "No siblings" is an answer, not a blank — the same rule
                // ProfileCompletionService uses for this section.
                'siblings_answered' => ($profile?->has_siblings ?? null) !== null
                    || ($profile?->brothers_count ?? null) !== null
                    || ($profile?->sisters_count ?? null) !== null
                    || (bool) ($facts['siblings'] ?? false),
            ],
            'relatives' => [
                'relatives' => (bool) ($facts['relatives'] ?? false),
                'other_relatives' => $this->filledText($profile?->other_relatives_text),
                'alliance' => (bool) ($facts['alliance'] ?? false),
            ],
            'property' => [
                'property' => $this->filledText($profile?->getAttribute('property_details')),
            ],
            'horoscope' => [
                'rashi' => ($horoscope->rashi_id ?? null) !== null,
                'nakshatra' => ($horoscope->nakshatra_id ?? null) !== null,
                'gan' => ($horoscope->gan_id ?? null) !== null,
                'devak' => $this->filledText($horoscope->devak ?? null),
                'kul' => $this->filledText($horoscope->kul ?? null),
                'gotra' => $this->filledText($horoscope->gotra ?? null),
            ],
            'about_me' => [
                'narrative' => $this->filledText($extended->narrative_about_me ?? null)
                    || $this->filledText($extended->narrative_expectations ?? null),
            ],
            'partner_preferences' => [
                'age' => ($criteria->preferred_age_min ?? null) !== null
                    || ($criteria->preferred_age_max ?? null) !== null,
                'height' => ($criteria->preferred_height_min_cm ?? null) !== null
                    || ($criteria->preferred_height_max_cm ?? null) !== null,
                'income' => ($criteria->preferred_income_min ?? null) !== null
                    || ($criteria->preferred_income_max ?? null) !== null,
                'city' => ($criteria->preferred_city_id ?? null) !== null,
                'marital_status' => ($criteria->preferred_marital_status_id ?? null) !== null,
                'education' => (bool) ($facts['pref_education'] ?? false),
                'occupation' => (bool) ($facts['pref_occupation'] ?? false),
                'religion_caste' => (bool) ($facts['pref_religion'] ?? false)
                    || (bool) ($facts['pref_caste'] ?? false),
                'district' => (bool) ($facts['pref_district'] ?? false),
            ],
            'photo' => [
                'photo' => $this->filledText($profile?->profile_photo),
            ],
            default => [],
        };
    }

    private function filledText(mixed $value): bool
    {
        return trim((string) ($value ?? '')) !== '';
    }
}
