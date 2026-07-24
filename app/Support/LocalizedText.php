<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * The one place that decides whether a Marathi value is shown.
 *
 * Master data is being translated column by column, so at any moment some rows
 * have Marathi and some do not. The rule is: a Suchak or member who chose
 * Marathi sees Marathi where it exists and English where it does not — never a
 * blank. Because presence is evaluated per request against the live attribute,
 * filling a `*_mr` column later makes Marathi appear on the next request with
 * no code change and no deploy.
 *
 * This generalises {@see \App\Models\Location::localizedName()}, which had the
 * only correct implementation of that rule. The others each re-derived it, and
 * three of them compared against `''` without trimming — so a whitespace-only
 * Marathi value passed the "is it present" test and rendered as an empty label.
 * Presence is now defined once, as Laravel's `filled()`, which trims first and
 * therefore treats null, `''` and `'   '` identically.
 *
 * Nothing else in the application may re-decide either question. A display-time
 * comparison of the locale to `'mr'` anywhere outside this class is a copy of
 * this logic and will drift from it.
 */
final class LocalizedText
{
    /**
     * Whether display should be in Marathi. Exactly `'mr'` — an unrelated
     * locale is not "not English", it is its own language and has no column.
     */
    public static function isMarathi(?string $locale = null): bool
    {
        return ($locale ?? app()->getLocale()) === 'mr';
    }

    /**
     * Like {@see self::isMarathi()} but also accepts a regional tag such as
     * `mr-IN`. For callers migrated off str_starts_with(getLocale(), 'mr'),
     * which matched regional variants; plain isMarathi() is exact and is the
     * right choice everywhere else.
     */
    public static function isMarathiLoose(?string $locale = null): bool
    {
        $value = strtolower($locale ?? app()->getLocale());

        return $value === 'mr' || str_starts_with($value, 'mr-');
    }

    /**
     * Choose between two values already in hand.
     *
     * For call sites that hold loose strings rather than a row. Prefer
     * {@see self::column()} when you have the record — it keeps the column
     * names in one place.
     */
    public static function pick(?string $marathi, ?string $english, ?string $locale = null): string
    {
        if (self::isMarathi($locale) && filled($marathi)) {
            return trim((string) $marathi);
        }

        return trim((string) $english);
    }

    /**
     * Resolve a label from a row, whatever shape the row arrived in.
     *
     * Reads an Eloquent model, a plain array, or the stdClass a query-builder
     * select returns, so a call site does not have to care which layer it is
     * on. By default the Marathi column is `{$baseColumn}_mr`, which is the
     * naming every migration in this codebase already follows.
     *
     * @param  list<string>|null  $englishColumns  Ordered fallbacks, first non-empty wins.
     *                                             Defaults to `[$baseColumn]`. Pass a chain for
     *                                             tables that carry both `label_en` and `label`.
     */
    public static function column(
        object|array|null $row,
        string $baseColumn,
        ?array $englishColumns = null,
        ?string $marathiColumn = null,
        ?string $locale = null,
    ): string {
        if ($row === null) {
            return '';
        }

        $marathiColumn ??= $baseColumn.'_mr';
        $englishColumns ??= [$baseColumn];

        if (self::isMarathi($locale)) {
            $marathi = self::raw($row, $marathiColumn);
            if ($marathi !== '') {
                return $marathi;
            }
        }

        foreach ($englishColumns as $column) {
            $value = self::raw($row, $column);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * A single column's value, normalised: the trimmed text, or null when
     * there is nothing usable there.
     *
     * Not a display choice — this is for payloads that carry both languages as
     * raw keys and let the client pick (`{"name": ..., "name_mr": ...}`).
     * Those builders still need one answer to "is this Marathi value real",
     * and without this they hand-write `filled() ? trim() : null` — or, more
     * often, an untrimmed `?:` that lets `'   '` through as a label and renders
     * blank on the device. Same presence rule as everything else here.
     */
    public static function value(object|array|null $row, string $column): ?string
    {
        if ($row === null) {
            return null;
        }

        $value = self::raw($row, $column);

        return $value === '' ? null : $value;
    }

    /**
     * One trimmed string out of any row shape. Absent, null, empty and
     * whitespace-only all collapse to `''` so callers test one condition.
     */
    private static function raw(object|array $row, string $column): string
    {
        $value = match (true) {
            $row instanceof Model => $row->getAttribute($column),
            is_array($row) => $row[$column] ?? null,
            default => $row->{$column} ?? null,
        };

        return trim((string) ($value ?? ''));
    }
}
