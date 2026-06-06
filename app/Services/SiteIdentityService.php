<?php

namespace App\Services;

use App\Models\AdminSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SiteIdentityService
{
    public const CACHE_KEY = 'site_identity_settings';

    public const TEXT_KEYS = [
        'site_name',
        'site_name_mr',
        'site_name_en',
        'site_tagline',
        'footer_copyright_text',
        'company_name',
        'support_email',
        'sales_email',
        'info_email',
        'primary_phone',
        'secondary_phone',
        'address',
        'google_maps_embed_link',
        'facebook_url',
        'instagram_url',
        'youtube_url',
        'linkedin_url',
        'x_url',
    ];

    public const IMAGE_KEYS = [
        'logo_light',
        'logo_dark',
        'favicon',
        'admin_panel_logo',
        'default_seo_image',
        'auth_background_image',
    ];

    public const DEFAULTS = [
        'site_name' => 'नवरी मिळे नवऱ्याला',
        'site_name_mr' => 'नवरी मिळे नवऱ्याला',
        'site_name_en' => 'Navri Mile Navryala',
        'site_tagline' => 'Navri Mile Navryala | Marathi Matrimony',
        'logo_light' => 'images/my-logo-light-mode.png',
        'logo_dark' => 'images/my-logo.png',
        'favicon' => 'favicon.ico',
        'admin_panel_logo' => null,
        'default_seo_image' => null,
        'auth_background_image' => null,
        'footer_copyright_text' => '© {year} Navri Mile Navryala. All rights reserved.',
        'company_name' => 'Navri Mile Navryala',
        'support_email' => '',
        'sales_email' => '',
        'info_email' => '',
        'primary_phone' => '',
        'secondary_phone' => '',
        'address' => '',
        'google_maps_embed_link' => '',
        'facebook_url' => '',
        'instagram_url' => '',
        'youtube_url' => '',
        'linkedin_url' => '',
        'x_url' => '',
    ];

    /**
     * @return array<string, string|null>
     */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, 300, function (): array {
            $rows = AdminSetting::query()
                ->whereIn('key', array_map(fn (string $key): string => $this->settingKey($key), array_keys(self::DEFAULTS)))
                ->pluck('value', 'key');

            $settings = [];
            foreach (self::DEFAULTS as $key => $default) {
                $settings[$key] = $rows->get($this->settingKey($key), $default);
            }

            return $this->normalizeLocalizedSiteNames($settings);
        });
    }

    /**
     * Site display name for the active (or given) app locale — used in referral share copy, etc.
     */
    public function siteNameForLocale(?string $locale = null): string
    {
        $locale = strtolower((string) ($locale ?? app()->getLocale()));
        $useEnglish = str_starts_with($locale, 'en');

        if ($useEnglish) {
            $en = trim((string) $this->get('site_name_en', ''));
            if ($en !== '') {
                return $en;
            }

            $company = trim((string) $this->get('company_name', ''));
            if ($company !== '') {
                return $company;
            }

            return self::DEFAULTS['site_name_en'];
        }

        $mr = trim((string) $this->get('site_name_mr', ''));
        if ($mr !== '') {
            return $mr;
        }

        $legacy = trim((string) $this->get('site_name', ''));
        if ($legacy !== '') {
            return $legacy;
        }

        return self::DEFAULTS['site_name_mr'];
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $settings = $this->all();

        return $settings[$key] ?? $default;
    }

    public function assetUrl(string $key): ?string
    {
        $path = $this->get($key);

        return filled($path) ? asset($path) : null;
    }

    public function copyrightText(): string
    {
        $text = (string) $this->get('footer_copyright_text', self::DEFAULTS['footer_copyright_text']);

        return str_replace('{year}', date('Y'), $text);
    }

    public function setText(string $key, ?string $value): void
    {
        if (! in_array($key, self::TEXT_KEYS, true)) {
            return;
        }

        $value = trim((string) $value);
        AdminSetting::setValue($this->settingKey($key), $value);

        if ($key === 'site_name_mr') {
            AdminSetting::setValue($this->settingKey('site_name'), $value);
        }

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @param  array<string, string|null>  $settings
     * @return array<string, string|null>
     */
    private function normalizeLocalizedSiteNames(array $settings): array
    {
        $legacy = trim((string) ($settings['site_name'] ?? ''));
        $mr = trim((string) ($settings['site_name_mr'] ?? ''));
        $en = trim((string) ($settings['site_name_en'] ?? ''));

        if ($mr === '' && $legacy !== '') {
            $settings['site_name_mr'] = $legacy;
            $mr = $legacy;
        }

        if ($en === '') {
            $settings['site_name_en'] = self::DEFAULTS['site_name_en'];
        }

        if ($legacy === '' && $mr !== '') {
            $settings['site_name'] = $mr;
        }

        return $settings;
    }

    public function setImage(string $key, UploadedFile $file): string
    {
        if (! in_array($key, self::IMAGE_KEYS, true)) {
            throw new \InvalidArgumentException("Unsupported site identity image key: {$key}");
        }

        $directory = public_path('images/branding');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: 'png');
        $filename = Str::slug(str_replace('_', '-', $key)).'-'.time().'.'.$extension;
        $path = 'images/branding/'.$filename;

        $file->move($directory, $filename);
        AdminSetting::setValue($this->settingKey($key), $path);
        Cache::forget(self::CACHE_KEY);

        return $path;
    }

    private function settingKey(string $key): string
    {
        return 'site_identity_'.$key;
    }
}
