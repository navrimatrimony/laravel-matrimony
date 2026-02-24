<?php

namespace App\Services;

use App\Models\MatrimonyProfile;
use Illuminate\Support\Facades\DB;

/**
 * Phase-5B: Section-based completion percentage for profile wizard.
 * Full SSOT section order: basic-info, personal-family, location, property, horoscope, legal, about-preferences, contacts, photo.
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
        'location' => 20,
        'property' => 0,
        'horoscope' => 0,
        'legal' => 0,
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
        if (($profile->city_id ?? null)) {
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
        $pref = DB::table('profile_preferences')->where('profile_id', $profile->id)->first();
        if ($pref && (
            ($pref->preferred_city ?? '') !== '' || ($pref->preferred_age_min ?? null) !== null
            || ($pref->preferred_education ?? '') !== ''
        )) {
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
}
