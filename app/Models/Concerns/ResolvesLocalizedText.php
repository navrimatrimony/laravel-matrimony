<?php

namespace App\Models\Concerns;

use App\Support\LocalizedText;

/**
 * Model-side sugar over {@see LocalizedText}.
 *
 * Delegation only. This trait must never grow a locale comparison or a
 * presence check of its own — the moment it does, there are two answers to
 * "is this Marathi value usable" again, which is the exact problem
 * LocalizedText exists to remove.
 */
trait ResolvesLocalizedText
{
    /**
     * @param  list<string>|null  $englishColumns  Ordered fallbacks; defaults to `[$baseColumn]`.
     */
    public function localizedText(string $baseColumn, ?array $englishColumns = null): string
    {
        return LocalizedText::column($this, $baseColumn, $englishColumns);
    }
}
