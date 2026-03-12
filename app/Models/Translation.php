<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Admin-editable translation overrides.
 * Key (e.g. components.options.diet.vegetarian) is read-only; only value is editable per locale.
 * Loaded into Laravel translator so __() uses DB over file.
 */
class Translation extends Model
{
    protected $fillable = ['locale', 'key', 'value'];

    public static function loadIntoTranslator(string $locale): void
    {
        $rows = static::where('locale', $locale)->get(['key', 'value']);
        if ($rows->isEmpty()) {
            return;
        }
        $translator = app('translator');
        $byNamespace = [];
        foreach ($rows as $row) {
            $key = $row->key;
            $pos = strpos($key, '.');
            if ($pos !== false) {
                $ns = substr($key, 0, $pos);
                $item = substr($key, $pos + 1);
                $byNamespace[$ns][$item] = $row->value;
            } else {
                $byNamespace['*'][$key] = $row->value;
            }
        }
        foreach ($byNamespace as $namespace => $lines) {
            $translator->addLines($lines, $locale, $namespace);
        }
    }
}
