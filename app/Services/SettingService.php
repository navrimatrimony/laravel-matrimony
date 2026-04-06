<?php

namespace App\Services;

use App\Models\AdminSetting;

/**
 * Key-value app settings stored in {@code admin_settings} (see {@see AdminSetting}).
 */
class SettingService
{
    /**
     * @return mixed Raw stored value string, or {@code $default} when the key row is absent.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $row = AdminSetting::query()->where('key', $key)->first();

        return $row ? $row->value : $default;
    }

    public function set(string $key, mixed $value): void
    {
        if (is_bool($value)) {
            AdminSetting::setValue($key, $value ? '1' : '0');

            return;
        }

        AdminSetting::setValue($key, (string) $value);
    }
}
