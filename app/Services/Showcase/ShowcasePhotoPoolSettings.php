<?php

namespace App\Services\Showcase;

use App\Models\AdminSetting;

/**
 * Admin policy when bulk/auto showcase creation cannot assign a strict category photo.
 * Stored as JSON in admin_settings key {@see self::SETTING_KEY}.
 */
class ShowcasePhotoPoolSettings
{
    public const SETTING_KEY = 'showcase_photo_pool_policy';

    public const ACTION_CREATE_WITHOUT_PHOTO = 'create_without_photo';

    public const ACTION_SKIP_PROFILE = 'skip_profile';

    public const MISSING_FOLDER = 'missing_folder';

    public const POOL_EXHAUSTED = 'pool_exhausted';

    public const INVALID_CATEGORY = 'invalid_category';

    /**
     * @return array<string, mixed>
     */
    public static function policy(): array
    {
        $raw = (string) AdminSetting::getValue(self::SETTING_KEY, '');
        if ($raw === '') {
            return self::defaults();
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? self::normalize($decoded) : self::defaults();
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'missing_exact_folder_action' => self::ACTION_CREATE_WITHOUT_PHOTO,
            'pool_exhausted_action' => self::ACTION_CREATE_WITHOUT_PHOTO,
            'allow_reuse_when_bucket_exhausted' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        $defaults = self::defaults();

        return [
            'missing_exact_folder_action' => self::normalizeAction(
                $data['missing_exact_folder_action'] ?? $defaults['missing_exact_folder_action'],
                $defaults['missing_exact_folder_action']
            ),
            'pool_exhausted_action' => self::normalizeAction(
                $data['pool_exhausted_action'] ?? $defaults['pool_exhausted_action'],
                $defaults['pool_exhausted_action']
            ),
            'allow_reuse_when_bucket_exhausted' => filter_var(
                $data['allow_reuse_when_bucket_exhausted'] ?? $defaults['allow_reuse_when_bucket_exhausted'],
                FILTER_VALIDATE_BOOL
            ),
        ];
    }

    public static function shouldSkipProfile(string $reason): bool
    {
        $policy = self::policy();

        return match ($reason) {
            self::MISSING_FOLDER => $policy['missing_exact_folder_action'] === self::ACTION_SKIP_PROFILE,
            self::POOL_EXHAUSTED => $policy['pool_exhausted_action'] === self::ACTION_SKIP_PROFILE,
            default => true,
        };
    }

    public static function allowReuseWhenBucketExhausted(): bool
    {
        return (bool) (self::policy()['allow_reuse_when_bucket_exhausted'] ?? false);
    }

    private static function normalizeAction(mixed $value, string $fallback): string
    {
        return in_array($value, [self::ACTION_CREATE_WITHOUT_PHOTO, self::ACTION_SKIP_PROFILE], true)
            ? (string) $value
            : $fallback;
    }
}
