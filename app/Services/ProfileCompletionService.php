<?php

namespace App\Services;

use App\Models\MatrimonyProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5B: Section-based completion percentage for profile wizard.
 * Full SSOT section order: basic-info, personal-family, location, property, horoscope, about-preferences, contacts, photo.
 * Completion % uses original 5 sections (20% each); new sections have 0 weight for backward compatibility.
 */
class ProfileCompletionService
{
    public const SECTIONS = [
        'basic-info' => 20,
        'personal-family' => 20,
        'siblings' => 0,
        'relatives' => 0,
        'alliance' => 0,
        'location' => 0,
        'property' => 0,
        'horoscope' => 0,
        'about-preferences' => 20,
        'contacts' => 0,
        'photo' => 20,
    ];

    /**
     * Calculate completion percentage (0–100) based on filled sections.
     */
    public static function calculateCompletionPercentage(?MatrimonyProfile $profile): int
    {
        if (! $profile) {
            return 0;
        }

        $pct = 0;

        // Basic Info (20%): full_name, gender, date_of_birth, marital_status
        if (self::sectionBasicInfoFilled($profile)) {
            $pct += 20;
        }

        // Personal/Family (20%): family/education/career summary or children/education_history/career_history
        if (self::sectionPersonalFamilyFilled($profile)) {
            $pct += 20;
        }

        // Location (20%): country_id, state_id, city_id or addresses
        if (self::sectionLocationFilled($profile)) {
            $pct += 20;
        }

        // About/Preferences (20%): preferences or extended_narrative
        if (self::sectionAboutPreferencesFilled($profile)) {
            $pct += 20;
        }

        // Photo (20%)
        if (self::sectionPhotoFilled($profile)) {
            $pct += 20;
        }

        return min(100, $pct);
    }

    private static function sectionBasicInfoFilled(MatrimonyProfile $profile): bool
    {
        $name = trim((string) ($profile->full_name ?? ''));
        $genderId = $profile->gender_id ?? null;
        $dob = $profile->date_of_birth ?? null;
        $maritalStatusId = $profile->marital_status_id ?? null;

        return $name !== '' && $genderId !== null && $dob !== null && $maritalStatusId !== null;
    }

    private static function sectionPersonalFamilyFilled(MatrimonyProfile $profile): bool
    {
        $hasFamily = ($profile->father_name ?? '') !== '' || ($profile->mother_name ?? '') !== ''
            || ($profile->highest_education ?? '') !== '' || ($profile->occupation_title ?? '') !== '';
        if ($hasFamily) {
            return true;
        }
        $children = DB::table('profile_children')->where('profile_id', $profile->id)->count();
        $edu = DB::table('profile_education')->where('profile_id', $profile->id)->count();
        $career = DB::table('profile_career')->where('profile_id', $profile->id)->count();

        return $children > 0 || $edu > 0 || $career > 0;
    }

    private static function sectionLocationFilled(MatrimonyProfile $profile): bool
    {
        if (($profile->location_id ?? null)) {
            return true;
        }
        $hasVillage = DB::table('profile_addresses')
            ->where('profile_id', $profile->id)
            ->whereNotNull('village_id')
            ->exists();

        return $hasVillage;
    }

    private static function sectionAboutPreferencesFilled(MatrimonyProfile $profile): bool
    {
        $criteria = DB::table('profile_preference_criteria')->where('profile_id', $profile->id)->first();
        if ($criteria && (
            ($criteria->preferred_city_id ?? null) !== null
            || ($criteria->preferred_age_min ?? null) !== null
            || ($criteria->preferred_age_max ?? null) !== null
            || ($criteria->preferred_education ?? '') !== ''
        )) {
            return true;
        }
        if (DB::table('profile_preferred_religions')->where('profile_id', $profile->id)->exists()) {
            return true;
        }
        if (DB::table('profile_preferred_castes')->where('profile_id', $profile->id)->exists()) {
            return true;
        }
        if (DB::table('profile_preferred_districts')->where('profile_id', $profile->id)->exists()) {
            return true;
        }
        if (Schema::hasTable('profile_preferred_marital_statuses')
            && DB::table('profile_preferred_marital_statuses')->where('profile_id', $profile->id)->exists()) {
            return true;
        }
        $ext = DB::table('profile_extended_attributes')->where('profile_id', $profile->id)->first();
        if ($ext && (
            trim((string) ($ext->narrative_about_me ?? '')) !== '' || trim((string) ($ext->narrative_expectations ?? '')) !== ''
        )) {
            return true;
        }

        return false;
    }

    private static function sectionPhotoFilled(MatrimonyProfile $profile): bool
    {
        return trim((string) ($profile->profile_photo ?? '')) !== '';
    }

    /**
     * Return the next section key after the given one, or null if last.
     */
    public static function nextSection(string $current): ?string
    {
        $keys = array_keys(self::SECTIONS);
        $idx = array_search($current, $keys, true);
        if ($idx === false || $idx === count($keys) - 1) {
            return null;
        }

        return $keys[$idx + 1];
    }

    /**
     * Return the first section key.
     */
    public static function firstSection(): string
    {
        return array_key_first(self::SECTIONS);
    }

    /**
     * Status for nav display: completed, incomplete, or warning (important missing).
     */
    public static function getSectionStatus(?MatrimonyProfile $profile, string $sectionKey): string
    {
        if (! $profile) {
            return 'incomplete';
        }
        switch ($sectionKey) {
            case 'basic-info':
                return self::sectionBasicInfoFilled($profile) ? 'completed' : 'incomplete';
            case 'physical':
                $has = ($profile->height_cm ?? null) !== null || ($profile->complexion_id ?? null) !== null
                    || ($profile->blood_group_id ?? null) !== null || ($profile->physical_build_id ?? null) !== null;

                return $has ? 'completed' : 'incomplete';
            case 'marriages':
                return ($profile->marital_status_id ?? null) !== null ? 'completed' : 'incomplete';
            case 'education-career':
                $hasEdu = ($profile->highest_education ?? '') !== '' || ($profile->occupation_title ?? '') !== '' || ($profile->annual_income ?? null) !== null;
                $eduCount = DB::table('profile_education')->where('profile_id', $profile->id)->count();
                $careerCount = DB::table('profile_career')->where('profile_id', $profile->id)->count();

                return $hasEdu || $eduCount > 0 || $careerCount > 0 ? 'completed' : 'incomplete';
            case 'family-details':
                $hasFamily = ($profile->father_name ?? '') !== '' || ($profile->mother_name ?? '') !== '' || ($profile->family_type_id ?? null) !== null;

                return $hasFamily ? 'completed' : 'incomplete';
            case 'personal-family':
                return self::sectionPersonalFamilyFilled($profile) ? 'completed' : 'incomplete';
            case 'siblings':
                if (($profile->has_siblings ?? null) === false) {
                    return 'completed';
                }
                $count = DB::table('profile_siblings')->where('profile_id', $profile->id)->count();

                return $count > 0 ? 'completed' : 'incomplete';
            case 'relatives':
                $count = DB::table('profile_relatives')->where('profile_id', $profile->id)->count();

                return $count > 0 ? 'completed' : 'incomplete';
            case 'alliance':
                $count = DB::table('profile_alliance_networks')->where('profile_id', $profile->id)->count();

                return $count > 0 ? 'completed' : 'incomplete';
            case 'location':
                return self::sectionLocationFilled($profile) ? 'completed' : 'incomplete';
            case 'property':
                $summary = DB::table('profile_property_summary')->where('profile_id', $profile->id)->exists();
                $assets = DB::table('profile_property_assets')->where('profile_id', $profile->id)->count();

                return $summary || $assets > 0 ? 'completed' : 'incomplete';
            case 'horoscope':
                $h = DB::table('profile_horoscope_data')->where('profile_id', $profile->id)->first();
                $hasRashi = $h && ($h->rashi_id ?? null) !== null;

                return $hasRashi ? 'completed' : 'incomplete';
            case 'about-preferences':
                return self::sectionAboutPreferencesFilled($profile) ? 'completed' : 'incomplete';
            case 'contacts':
                $selfRelId = DB::table('master_contact_relations')->where('key', 'self')->value('id');
                $has = DB::table('profile_contacts')->where('profile_id', $profile->id)
                    ->where('contact_relation_id', $selfRelId)->exists();

                return $has ? 'completed' : 'warning';
            case 'photo':
                return self::sectionPhotoFilled($profile) ? 'completed' : 'incomplete';
            default:
                return 'incomplete';
        }
    }

    /**
     * @return array<string, string> section_key => status
     */
    public static function getSectionStatuses(?MatrimonyProfile $profile, array $sectionKeys): array
    {
        if (! $profile) {
            return array_fill_keys($sectionKeys, 'incomplete');
        }
        $out = [];
        foreach ($sectionKeys as $key) {
            $out[$key] = self::getSectionStatus($profile, $key);
        }

        return $out;
    }
}
