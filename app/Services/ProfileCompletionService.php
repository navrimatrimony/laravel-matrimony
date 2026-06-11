<?php

namespace App\Services;

use App\Models\MatrimonyProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5B: Section-based completion percentage for profile wizard.
 * Full SSOT section order follows config/field_catalog.php.
 * Completion % uses five product areas (20% each); detailed section status follows current wizard sections.
 */
class ProfileCompletionService
{
    public const SECTIONS = [
        'basic-info' => 20,
        'physical' => 0,
        'education-career' => 20,
        'family-details' => 0,
        'siblings' => 0,
        'relatives' => 0,
        'alliance' => 0,
        'property' => 0,
        'horoscope' => 0,
        'about-me' => 0,
        'about-preferences' => 20,
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

        // Personal/Family (20%): family/education/career summary or children
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
        $hasCareerCore = trim((string) ($profile->company_name ?? '')) !== ''
            || ($profile->profession_id ?? null)
            || ($profile->occupation_master_id ?? null)
            || ($profile->work_city_id ?? null)
            || trim((string) ($profile->work_location_text ?? '')) !== '';

        return $children > 0 || $hasCareerCore;
    }

    private static function sectionLocationFilled(MatrimonyProfile $profile): bool
    {
        if (($profile->location_id ?? null)) {
            return true;
        }
        $leafCol = Schema::hasColumn('profile_addresses', 'location_id') ? 'location_id' : 'city_id';
        $geo = (new \App\Models\Location)->getTable();

        return DB::table('profile_addresses as pa')
            ->join($geo.' as a', 'a.id', '=', 'pa.'.$leafCol)
            ->where('pa.profile_id', $profile->id)
            ->where('a.type', 'village')
            ->whereNotNull('pa.'.$leafCol)
            ->exists();
    }

    private static function sectionAboutPreferencesFilled(MatrimonyProfile $profile): bool
    {
        $criteria = DB::table('profile_preference_criteria')->where('profile_id', $profile->id)->first();
        if ($criteria && (
            ($criteria->preferred_city_id ?? null) !== null
            || ($criteria->preferred_age_min ?? null) !== null
            || ($criteria->preferred_age_max ?? null) !== null
        )) {
            return true;
        }
        if (Schema::hasTable('profile_preferred_education_degrees')
            && DB::table('profile_preferred_education_degrees')->where('profile_id', $profile->id)->exists()) {
            return true;
        }
        if (Schema::hasTable('profile_preferred_occupation_master')
            && DB::table('profile_preferred_occupation_master')->where('profile_id', $profile->id)->exists()) {
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
            case 'education-career':
                $hasEdu = ($profile->highest_education ?? '') !== '' || ($profile->occupation_title ?? '') !== '' || ($profile->annual_income ?? null) !== null;
                $hasCareer = trim((string) ($profile->company_name ?? '')) !== ''
                    || ($profile->profession_id ?? null)
                    || ($profile->occupation_master_id ?? null)
                    || ($profile->work_city_id ?? null)
                    || trim((string) ($profile->work_location_text ?? '')) !== '';

                return $hasEdu || $hasCareer ? 'completed' : 'incomplete';
            case 'family-details':
                $hasFamily = ($profile->father_name ?? '') !== '' || ($profile->mother_name ?? '') !== '' || ($profile->family_type_id ?? null) !== null;

                return $hasFamily ? 'completed' : 'incomplete';
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
            case 'property':
                return trim((string) ($profile->getAttribute('property_details') ?? '')) !== ''
                    ? 'completed'
                    : 'incomplete';
            case 'horoscope':
                $h = DB::table('profile_horoscope_data')->where('profile_id', $profile->id)->first();
                $hasRashi = $h && ($h->rashi_id ?? null) !== null;

                return $hasRashi ? 'completed' : 'incomplete';
            case 'about-me':
                $ext = DB::table('profile_extended_attributes')->where('profile_id', $profile->id)->first();
                $hasAbout = $ext && (
                    trim((string) ($ext->narrative_about_me ?? '')) !== ''
                    || trim((string) ($ext->narrative_expectations ?? '')) !== ''
                );

                return $hasAbout ? 'completed' : 'incomplete';
            case 'about-preferences':
                return self::sectionAboutPreferencesFilled($profile) ? 'completed' : 'incomplete';
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
