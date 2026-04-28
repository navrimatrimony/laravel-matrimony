<?php

namespace App\Support\MasterData;

/**
 * Canonical religion EN/MR labels for seed + translation import.
 *
 * @see database/seeders/data/religions_bilingual.php
 */
final class ReligionBilingualCatalog
{
    public static function path(): string
    {
        return database_path('seeders/data/religions_bilingual.php');
    }

    /**
     * @return array<string, array{label_en: string, label_mr: ?string}>
     */
    public static function load(): array
    {
        $path = self::path();
        if (! is_file($path)) {
            return [];
        }

        $data = require $path;

        return is_array($data) ? $data : [];
    }
}
