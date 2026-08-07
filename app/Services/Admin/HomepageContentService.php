<?php

namespace App\Services\Admin;

use App\Models\AdminSetting;

/**
 * Owner of the `homepage_content_settings` blob — the small set of homepage
 * decisions that genuinely change per deployment and must not need a deploy.
 *
 * What this service deliberately does NOT own any more:
 *
 *  - Homepage prose. Every visible word now lives in lang/{mr,en}/homepage.php
 *    (overridable at runtime through Admin -> Translations, which writes the
 *    same keys). Copy is the surface a payment-gateway reviewer reads signed
 *    out; it belongs somewhere with a diff and a reviewer. Two owners for one
 *    sentence is what let "Plans are managed from the admin panel" and "Real
 *    stories can be featured here with consent and admin approval" reach real
 *    visitors.
 *  - Homepage section order. It is a fixed editorial decision, held in
 *    {@see self::SECTION_ORDER}. A free-text sort number that silently
 *    reorders the page under a reviewer is not worth the one time somebody
 *    might want to move a block.
 *  - Success-story slider mechanics. Eleven keys were readable by the view and
 *    writable by a route whose form was never included anywhere — configuration
 *    that looked live and was not. The values that everyone has actually been
 *    seeing are now constants in the view.
 *
 * Removing a key from {@see self::defaults()} does not remove it from a
 * database row that already carries it: {@see self::settings()} merges
 * defaults INTO the saved blob, so a stale key survives until the next admin
 * save (which rebuilds the blob from these keys only). That is safe only
 * because no reader looks at the removed keys any more — the view reads lang
 * files and constants for them. Never retire a key here without also retiring
 * its reader, or the change silently does nothing on any site that has saved
 * the setting once.
 */
class HomepageContentService
{
    public const SETTING_KEY = 'homepage_content_settings';

    /**
     * The order homepage blocks appear in, top to bottom. Pricing sits high on
     * purpose: a stranger — including a payment-gateway reviewer — must be able
     * to see what is sold and what it costs without hunting for it.
     *
     * @var list<string>
     */
    public const SECTION_ORDER = [
        'trust',
        'how_it_works',
        'plans',
        'assisted_service',
        'safety',
        'success_stories',
        'app_section',
        'retail_outlet',
        'final_cta',
    ];

    /**
     * Sections with no off switch.
     *
     * `plans` is the only block that answers "what is sold and what does it
     * cost". It is also the only page on this site where a signed-out visitor
     * can see a price at all, because /plans is behind auth. A checkbox that
     * hides every price from the public site is not a setting anyone should be
     * holding — and it had in fact been switched off, so the homepage was
     * showing no price to anyone. It renders whenever at least one active,
     * visible plan exists, and is skipped when none does.
     *
     * These keys are absent from {@see self::defaults()}, so a stale
     * `sections.plans.enabled = false` already in a saved blob is never read
     * and is dropped on the next admin save.
     *
     * @var list<string>
     */
    public const ALWAYS_VISIBLE_SECTIONS = [
        'plans',
    ];

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $raw = AdminSetting::getValue(self::SETTING_KEY, '');
        $saved = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (! is_array($saved)) {
            $saved = [];
        }

        return $this->mergeDefaults($saved, $this->defaults());
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function save(array $settings): void
    {
        AdminSetting::setValue(self::SETTING_KEY, json_encode($this->mergeDefaults($settings, $this->defaults())));
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            // Store links change without a deploy, and one of them being wrong
            // sends every visitor to somebody else's app. Genuinely editable.
            'app_android_url' => '',
            'app_ios_url' => '',
            'app_show_android' => true,
            'app_show_ios' => true,

            // The only per-block control left: show it or hide it. Order is
            // fixed in code (see SECTION_ORDER), and the blocks in
            // ALWAYS_VISIBLE_SECTIONS have no switch at all.
            'sections' => array_fill_keys(
                array_values(array_diff(self::SECTION_ORDER, self::ALWAYS_VISIBLE_SECTIONS)),
                ['enabled' => true],
            ),

            // Hero search form. Each field has exactly ONE owner:
            //  - gender / age / marital_status -> these checkboxes
            //  - religion + caste              -> hero_search_community_mode
            //  - state + district              -> hero_search_location_mode
            // Previously a caste field needed BOTH search_fields.caste and the
            // community mode to say yes, which is one decision with two owners
            // and a failure mode nobody could explain from the admin screen.
            'search_fields' => [
                'gender' => true,
                'age' => true,
                'marital_status' => true,
            ],
            'hero_search_age_control' => 'slider',
            'hero_search_community_mode' => 'caste',
            'hero_search_location_mode' => 'state_district',

            // Read by the homepage route when it loads published stories. Not
            // admin-editable — six is a page-design decision, not a setting.
            'story_limit' => 6,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    private function mergeDefaults(array $input, array $defaults): array
    {
        foreach ($defaults as $key => $defaultValue) {
            if (! array_key_exists($key, $input)) {
                $input[$key] = $defaultValue;
                continue;
            }

            if (is_array($defaultValue) && is_array($input[$key])) {
                $input[$key] = $this->mergeDefaults($input[$key], $defaultValue);
            }
        }

        return $input;
    }
}
