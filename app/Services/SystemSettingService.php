<?php

namespace App\Services;

use App\Models\AdminSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Cached key-value settings backed by {@code admin_settings}.
 */
class SystemSettingService
{
    private const CACHE_MISSING = "\0system_setting_missing\0";

    protected function cacheKey(string $key): string
    {
        return 'system_setting:'.$key;
    }

    /**
     * @return mixed Raw stored string value, or {@code $default} when the row is absent.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $cacheKey = $this->cacheKey($key);
        $wrapped = Cache::get($cacheKey);

        if ($wrapped !== null) {
            return $wrapped === self::CACHE_MISSING ? $default : $wrapped;
        }

        $row = AdminSetting::query()->where('key', $key)->first();
        if ($row === null) {
            Cache::forever($cacheKey, self::CACHE_MISSING);

            return $default;
        }

        Cache::forever($cacheKey, $row->value);

        return $row->value;
    }

    public function set(string $key, mixed $value): void
    {
        if (is_bool($value)) {
            AdminSetting::setValue($key, $value ? '1' : '0');
        } elseif ($value === null) {
            AdminSetting::setValue($key, '');
        } else {
            AdminSetting::setValue($key, is_scalar($value) ? (string) $value : json_encode($value));
        }

        Cache::forget($this->cacheKey($key));
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $raw = $this->get($key, $default ? '1' : '0');

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }
}
