<?php

namespace App\Services;

use App\Models\MatrimonyProfile;
use Illuminate\Http\Request;

/**
 * Partner Preferences workspace: URL ?pref= slug + sidebar summaries (counts only).
 */
class PartnerPreferenceNavService
{
    public const SLUGS = ['basics', 'community', 'location', 'education', 'lifestyle', 'family'];

    public static function resolveActiveSection(Request $request): string
    {
        $pref = (string) ($request->query('pref') ?? $request->old('pref', 'basics'));
        if ($pref === '') {
            $pref = 'basics';
        }

        return in_array($pref, self::SLUGS, true) ? $pref : 'basics';
    }

    /**
     * @param  array<string, mixed>  $vd  View data from ProfileWizardController::getSectionViewData('about-preferences', ...)
     * @return array<int, array{slug: string, label: string, badge: int|string}>
     */
    public static function navItems(MatrimonyProfile $profile, array $vd): array
    {
        $criteria = $vd['preferenceCriteria'] ?? null;

        $basics = 0;
        $maritalPrefCount = count($vd['preferredMaritalStatusIds'] ?? []);
        if ($criteria !== null) {
            if (! empty($criteria->marriage_type_preference_id)) {
                $basics++;
            }
            if ($maritalPrefCount > 0 || ! empty($criteria->preferred_marital_status_id)) {
                $basics++;
            }
            if (! empty($criteria->partner_profile_with_children)) {
                $basics++;
            }
            if (($criteria->preferred_age_min ?? null) !== null && ($criteria->preferred_age_max ?? null) !== null) {
                $basics++;
            }
            if (($criteria->preferred_height_min_cm ?? null) !== null && ($criteria->preferred_height_max_cm ?? null) !== null) {
                $basics++;
            }
        }

        $rel = count($vd['preferredReligionIds'] ?? []);
        $cast = count($vd['preferredCasteIds'] ?? []);
        $community = $rel + $cast;

        $loc = (int) count($vd['preferredCountryIds'] ?? [])
            + (int) count($vd['preferredStateIds'] ?? [])
            + (int) count($vd['preferredDistrictIds'] ?? [])
            + (int) count($vd['preferredTalukaIds'] ?? []);
        if ($criteria !== null && ! empty($criteria->willing_to_relocate)) {
            $loc++;
        }

        $edu = (int) count($vd['preferredMasterEducationIds'] ?? [])
            + (int) count($vd['preferredWorkingWithTypeIds'] ?? [])
            + (int) count($vd['preferredProfessionIds'] ?? []);
        if ($criteria !== null
            && ($criteria->preferred_income_min ?? null) !== null
            && ($criteria->preferred_income_max ?? null) !== null) {
            $edu++;
        }

        $life = (int) count($vd['preferredDietIds'] ?? []);

        $fam = 0;
        if ($criteria !== null && ! empty($criteria->preferred_profile_managed_by)) {
            $fam++;
        }

        $mk = fn (int|string $n) => $n > 0 ? $n : '—';

        return [
            ['slug' => 'basics', 'label' => 'wizard.partner_pref_nav_basics', 'badge' => $mk($basics)],
            ['slug' => 'community', 'label' => 'wizard.partner_pref_nav_community', 'badge' => $mk($community)],
            ['slug' => 'location', 'label' => 'wizard.partner_pref_nav_location', 'badge' => $mk($loc)],
            ['slug' => 'education', 'label' => 'wizard.partner_pref_nav_education', 'badge' => $mk($edu)],
            ['slug' => 'lifestyle', 'label' => 'wizard.partner_pref_nav_lifestyle', 'badge' => $mk($life)],
            ['slug' => 'family', 'label' => 'wizard.partner_pref_nav_family', 'badge' => $mk($fam)],
        ];
    }

    /**
     * POST may carry hidden `pref`; GET uses query. Used to preserve workspace on redirect.
     *
     * @return array<string, string>
     */
    public static function prefQuery(Request $request): array
    {
        $pref = $request->input('pref') ?? $request->query('pref');
        if ($pref !== null && $pref !== '' && in_array((string) $pref, self::SLUGS, true)) {
            return ['pref' => (string) $pref];
        }

        return [];
    }
}
