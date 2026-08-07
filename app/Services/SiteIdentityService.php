<?php

namespace App\Services;

use App\Models\AdminSetting;
use App\Support\LegalDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The ONE reader for business identity.
 *
 * Every surface that prints who this company is — homepage footer, legal pages,
 * public Suchak pages, page titles, share unfurls — resolves it here, so a fact
 * is changed in one place and moves everywhere at once.
 *
 * There are two kinds of value behind this reader and exactly one rule for each:
 *
 *  1. FACTUAL identity (legal name, statutory registered office, the public
 *     phone, the support email) is owned by `config/legal.php`. It is a
 *     statutory fact, it must show up in a git diff, and it must not be
 *     silently editable from an admin screen.
 *  2. BRAND presentation (site name, tagline, logo, socials, copyright line)
 *     is owned by the DB-backed `site_identity_*` admin settings.
 *
 * Where the two meet, the admin setting is an OVERRIDE and never the origin:
 * an unset or blank admin row falls through to the config value instead of
 * publishing an empty string. `CONFIG_FALLBACKS` is that join, and the keys
 * listed there but absent from `TEXT_KEYS` are read-only by construction —
 * no admin screen can reach them.
 */
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
        // The keys below carry no literal of their own on purpose: whatever is
        // not overridden by an admin comes from config/legal.php via
        // CONFIG_FALLBACKS, so the fact has exactly one home. A blank here means
        // "there is no such fact yet", not "the value is empty".
        'company_name' => '',
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
        // Read-only statutory facts. Deliberately absent from TEXT_KEYS, so
        // setText() refuses them and no admin form can publish a different
        // legal name or registered office than the one in config/legal.php.
        'legal_name' => '',
        'registered_address' => '',
    ];

    /**
     * Identity keys whose fact is owned by config/legal.php.
     *
     * key => config path. An admin override wins only while it is non-blank;
     * clearing it hands the key back to config. A key NOT listed here has no
     * config owner on purpose — there is one real support email, so `sales_email`
     * and `info_email` stay empty until an admin sets them rather than printing
     * the same address three times in a footer, and `secondary_phone` stays
     * empty rather than repeating the one public number.
     */
    public const CONFIG_FALLBACKS = [
        // Overridable — brand-facing presentation of a config-owned fact.
        'company_name' => 'legal.entity.brand_name',
        'support_email' => 'legal.contact.support_email',
        'primary_phone' => 'legal.contact.mobile',
        // The free-form display address, which an admin may still set to a
        // visiting/office address. When they have not, the statutory registered
        // office is published rather than nothing — a real address of the same
        // company, never an invented one.
        'address' => 'legal.entity.registered_address',

        // Read-only — statutory, config always wins (see DEFAULTS above).
        'legal_name' => 'legal.entity.legal_name',
        'registered_address' => 'legal.entity.registered_address',
    ];

    /**
     * The effective identity — what every public surface prints.
     *
     * Resolution order, per key: admin override (only while non-blank) →
     * config/legal.php via CONFIG_FALLBACKS → the literal in DEFAULTS.
     *
     * The blank check is the whole fix: an `admin_settings` row that EXISTS but
     * holds an empty string used to win over the default, which is why the
     * homepage footer rendered no phone, no email and no company address at all
     * while /terms and /grievance printed the real ones from config.
     *
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
                $value = $this->isAdminEditable($key)
                    ? ($rows->get($this->settingKey($key)) ?? $default)
                    : $default;

                if (trim((string) $value) === '') {
                    $value = $this->configuredFallback($key) ?? $value;
                }

                $settings[$key] = $value;
            }

            return $this->normalizeLocalizedSiteNames($settings);
        });
    }

    /**
     * What the admin has actually overridden, exactly as stored — no config
     * fallback, no defaults.
     *
     * The edit form MUST render this and never {@see all()}. Pre-filling a box
     * with an inherited value and pressing Save would copy config/legal.php into
     * `admin_settings` and freeze it there, so the next edit to the config would
     * change nothing — precisely the second owner this service exists to avoid.
     *
     * @return array<string, string>
     */
    public function overrides(): array
    {
        $rows = AdminSetting::query()
            ->whereIn('key', array_map(fn (string $key): string => $this->settingKey($key), self::TEXT_KEYS))
            ->pluck('value', 'key');

        $overrides = [];
        foreach (self::TEXT_KEYS as $key) {
            $overrides[$key] = trim((string) $rows->get($this->settingKey($key), ''));
        }

        return $overrides;
    }

    /**
     * The public phone as a `tel:` target, so no page has to build one by hand.
     *
     * Returns the configured `+91…` form while the published number is still the
     * configured one, and a stripped version of the admin's number once they have
     * overridden it — the href can never point at a different number than the
     * one printed beside it.
     */
    public function primaryPhoneTel(): string
    {
        $phone = trim((string) $this->get('primary_phone', ''));

        if ($phone === '') {
            return '';
        }

        $configuredPhone = (string) ($this->configuredFallback('primary_phone') ?? '');
        $configuredTel = trim((string) config('legal.contact.mobile_tel', ''));

        if ($phone === $configuredPhone && $configuredTel !== '' && ! LegalDocument::isUnfilled($configuredTel)) {
            return $configuredTel;
        }

        return (string) preg_replace('/[^0-9+]/', '', $phone);
    }

    /**
     * True when an admin screen is allowed to store a value for this key at all.
     * A key outside both lists is resolved from config only.
     */
    private function isAdminEditable(string $key): bool
    {
        return in_array($key, self::TEXT_KEYS, true) || in_array($key, self::IMAGE_KEYS, true);
    }

    /**
     * The config/legal.php value behind a key, or null when there is none to use.
     *
     * A value still written as an unfilled `[[TOKEN]]` counts as "none": that
     * marker exists so a missing fact is caught on the legal pages, where an
     * admin-only strip flags it — it must never be published in a marketing
     * footer as though it were a phone number.
     */
    private function configuredFallback(string $key): ?string
    {
        $path = self::CONFIG_FALLBACKS[$key] ?? null;

        if ($path === null) {
            return null;
        }

        $value = trim((string) config($path, ''));

        if ($value === '' || LegalDocument::isUnfilled($value)) {
            return null;
        }

        return $value;
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
