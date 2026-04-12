<?php

namespace App\Services\Showcase;

use App\Models\AdminSetting;

/**
 * Admin policy for all showcase profile creation (bulk + auto-engine).
 */
class ShowcaseSettings
{
    /**
     * How partner preferences are filled after showcase profile create.
     *
     * - match_searcher: prefer attributes of the searching member (auto-create only; bulk falls back to rules_autofill).
     * - rules_autofill: same deterministic rules as wizard "suggestions" ({@see PartnerPreferenceSuggestionService} + balanced preset).
     * - mixed: rules_autofill base, then overlay religion/caste/age/height/location from searcher when present.
     */
    public static function partnerPrefMode(): string
    {
        $v = strtolower(trim((string) AdminSetting::getValue('showcase_partner_pref_mode', 'rules_autofill')));

        return in_array($v, ['match_searcher', 'rules_autofill', 'mixed'], true) ? $v : 'rules_autofill';
    }
}
