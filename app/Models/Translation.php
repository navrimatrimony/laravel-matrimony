<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-editable translation overrides.
 * Key (e.g. components.options.diet.vegetarian) is read-only; only value is editable per locale.
 * Loaded into Laravel translator so __() uses DB over file.
 */
class Translation extends Model
{
    protected $fillable = ['locale', 'key', 'value'];

    /**
     * Put this locale's admin overrides in front of the lang-file values.
     *
     * ── WHY THIS IS NOT A SPLIT-ON-THE-FIRST-DOT ─────────────────────────────
     *
     * It used to be, and that is why no override ever took effect. A key like
     * `suchak.api.errors.suchak_account_required` was cut at the first dot and
     * loaded as namespace `suchak` + key `api.errors.…`, which lands in
     * `loaded['suchak'][…]`. But `__('suchak.api.errors.…')` has no `::` in it,
     * so Laravel resolves it in the DEFAULT namespace and looks in
     * `loaded['*']['suchak'][…]` — a different bucket entirely. Every row was
     * written somewhere nothing reads, on the web group as well as everywhere
     * else, so "translations live in the database" was true of the table and
     * false of the application.
     *
     * The leading segment of these keys is the lang FILE (`suchak.php`), which
     * Laravel calls the GROUP, not a namespace. Namespaces are the `vendor::`
     * prefix and nothing else — so that is the one case still split out here,
     * and everything else goes through with its key intact.
     */
    public static function loadIntoTranslator(string $locale): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        $rows = static::where('locale', $locale)->get(['key', 'value']);
        if ($rows->isEmpty()) {
            return;
        }

        $translator = app('translator');
        $byNamespace = [];

        foreach ($rows as $row) {
            $key = (string) $row->key;

            // A key with no group at all ("welcome") cannot be addressed by
            // Laravel's group loader, so it is skipped rather than silently
            // dropped into a bucket nothing reads — which is the whole defect
            // this method used to have.
            if (! str_contains(str_replace('::', '', $key), '.')) {
                continue;
            }

            if (str_contains($key, '::')) {
                [$namespace, $item] = explode('::', $key, 2);
                $byNamespace[$namespace][$item] = $row->value;

                continue;
            }

            $byNamespace['*'][$key] = $row->value;
        }

        foreach ($byNamespace as $namespace => $lines) {
            $translator->addLines($lines, $locale, $namespace);
        }
    }
}
