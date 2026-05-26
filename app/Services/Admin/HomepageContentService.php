<?php

namespace App\Services\Admin;

use App\Models\AdminSetting;

class HomepageContentService
{
    public const SETTING_KEY = 'homepage_content_settings';

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
            'hero_badge_mr' => 'विश्वास, कुटुंब आणि जुळणारे स्थळ',
            'hero_badge_en' => 'Trusted Marathi Matrimony',
            'hero_title_mr' => 'सातजन्माच्या गाठी स्वर्गातच बांधलेल्या असतात',
            'hero_title_en' => 'Matches for a lifetime are made in heaven.',
            'hero_subtitle_mr' => 'आम्ही फक्त योग्य भेट घडवतो.',
            'hero_subtitle_en' => 'We simply help the right people meet.',
            'primary_cta_mr' => 'नोंदणी करा',
            'primary_cta_en' => 'Register',
            'secondary_cta_mr' => 'स्थळ शोधा',
            'secondary_cta_en' => 'Search Profiles',
            'assisted_title_mr' => 'सहाय्यक सेवा',
            'assisted_title_en' => 'Assisted Service',
            'assisted_body_mr' => 'कुटुंबांना प्रोफाइल, पसंती आणि संवाद यामध्ये संयमी मदत.',
            'assisted_body_en' => 'Support for families that want a guided, matrimony-focused experience.',
            'success_title_mr' => 'यशोगाथा',
            'success_title_en' => 'Success Stories',
            'success_intro_mr' => 'विश्वासाने सुरू झालेला संवाद आयुष्यभराच्या नात्यात बदलला.',
            'success_intro_en' => 'Real stories can be featured here with consent and admin approval.',
            'final_cta_title_mr' => 'योग्य जोडीदाराचा शोध सुरू करा',
            'final_cta_title_en' => 'Ready to explore matches?',
            'final_cta_body_mr' => 'प्रोफाइल तयार करा किंवा उपलब्ध स्थळे शोधा.',
            'final_cta_body_en' => 'Create your profile or open the search flow with the same filters used inside the platform.',
            'app_title_mr' => 'मोबाइल अ‍ॅप',
            'app_title_en' => 'Download our mobile app',
            'app_body_mr' => 'Android आणि iOS वर शोध, interests आणि संवाद सोपे ठेवा.',
            'app_body_en' => 'Search profiles, manage interests, and chat on Android and iOS.',
            'app_android_url' => '',
            'app_ios_url' => '',
            'app_show_android' => true,
            'app_show_ios' => true,
            'sections' => [
                'trust' => ['enabled' => true, 'sort_order' => 10],
                'how_it_works' => ['enabled' => true, 'sort_order' => 20],
                'assisted_service' => ['enabled' => true, 'sort_order' => 30],
                'success_stories' => ['enabled' => true, 'sort_order' => 40],
                'safety' => ['enabled' => true, 'sort_order' => 50],
                'plans' => ['enabled' => true, 'sort_order' => 60],
                'app_section' => ['enabled' => true, 'sort_order' => 70],
                'retail_outlet' => ['enabled' => true, 'sort_order' => 80],
                'final_cta' => ['enabled' => true, 'sort_order' => 90],
            ],
            'search_fields' => [
                'gender' => true,
                'age' => true,
                'religion' => false,
                'caste' => true,
                'state' => true,
                'district' => true,
                'marital_status' => true,
            ],
            'hero_search_age_control' => 'slider',
            'hero_search_community_mode' => 'caste',
            'hero_search_location_mode' => 'state_district',
            'story_limit' => 6,
            'success_stories_display' => 'slider',
            'success_stories_autoplay' => true,
            'success_stories_autoplay_seconds' => 5,
            'success_stories_slides_mobile' => 1,
            'success_stories_slides_tablet' => 2,
            'success_stories_slides_desktop' => 3,
            'success_stories_show_arrows' => true,
            'success_stories_show_dots' => true,
            'success_stories_pause_on_hover' => true,
            'success_stories_loop' => true,
        ];
    }

    /**
     * @return list<string>
     */
    public function storiesDisplayKeys(): array
    {
        return [
            'story_limit',
            'success_stories_display',
            'success_stories_autoplay',
            'success_stories_autoplay_seconds',
            'success_stories_slides_mobile',
            'success_stories_slides_tablet',
            'success_stories_slides_desktop',
            'success_stories_show_arrows',
            'success_stories_show_dots',
            'success_stories_pause_on_hover',
            'success_stories_loop',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function normalizeStoriesDisplayInput(array $input): array
    {
        return [
            'story_limit' => max(1, min(24, (int) ($input['story_limit'] ?? 6))),
            'success_stories_display' => in_array(($input['success_stories_display'] ?? 'grid'), ['grid', 'slider'], true)
                ? $input['success_stories_display']
                : 'grid',
            'success_stories_autoplay' => filter_var($input['success_stories_autoplay'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'success_stories_autoplay_seconds' => max(2, min(30, (int) ($input['success_stories_autoplay_seconds'] ?? 5))),
            'success_stories_slides_mobile' => max(1, min(2, (int) ($input['success_stories_slides_mobile'] ?? 1))),
            'success_stories_slides_tablet' => max(1, min(3, (int) ($input['success_stories_slides_tablet'] ?? 2))),
            'success_stories_slides_desktop' => max(1, min(4, (int) ($input['success_stories_slides_desktop'] ?? 3))),
            'success_stories_show_arrows' => filter_var($input['success_stories_show_arrows'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'success_stories_show_dots' => filter_var($input['success_stories_show_dots'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'success_stories_pause_on_hover' => filter_var($input['success_stories_pause_on_hover'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'success_stories_loop' => filter_var($input['success_stories_loop'] ?? true, FILTER_VALIDATE_BOOLEAN),
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
