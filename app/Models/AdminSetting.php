<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/*
|--------------------------------------------------------------------------
| AdminSetting Model
|--------------------------------------------------------------------------
|
| 👉 Stores admin policy settings
| 👉 Key-value pairs for admin configuration
|
*/
class AdminSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Get a setting value by key
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function getValue(string $key, $default = null)
    {
        $setting = self::where('key', $key)->first();
        return $setting ? $setting->value : $default;
    }

    /**
     * Set a setting value by key
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public static function setValue(string $key, $value): void
    {
        self::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    /**
     * Get boolean setting value
     *
     * @param string $key
     * @param bool $default
     * @return bool
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::getValue($key, $default ? '1' : '0');
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
