<?php

namespace App\Support\MasterData;

use App\Models\Caste;
use App\Models\Religion;
use App\Models\SubCaste;

/**
 * Applies EN/MR labels during {@see \Database\Seeders\ReligionCasteSubCasteSeeder}.
 *
 * Version-controlled JSON under {@see database/seeders/data}:
 * - religion_caste_subcaste_seed_religions.json
 * - religion_caste_subcaste_seed_castes.json
 * - religion_caste_subcaste_seed_subcastes.json
 */
final class ReligionCasteSubcasteMultilanguageSeedSync
{
    private static function canonicalDataDir(): string
    {
        return database_path('seeders'.DIRECTORY_SEPARATOR.'data');
    }

    public static function apply(): void
    {
        $dir = self::canonicalDataDir();
        $religionsPath = $dir.DIRECTORY_SEPARATOR.'religion_caste_subcaste_seed_religions.json';
        $castesPath = $dir.DIRECTORY_SEPARATOR.'religion_caste_subcaste_seed_castes.json';
        $subcastesPath = $dir.DIRECTORY_SEPARATOR.'religion_caste_subcaste_seed_subcastes.json';

        if (
            ! is_file($religionsPath)
            && ! is_file($castesPath)
            && ! is_file($subcastesPath)
        ) {
            return;
        }

        if (is_file($religionsPath) && is_readable($religionsPath)) {
            self::syncReligions($religionsPath);
        }

        $legacyCasteMap = [];
        if (is_file($castesPath) && is_readable($castesPath)) {
            $legacyCasteMap = self::buildLegacyCasteMap($castesPath);
            self::syncCastes($castesPath);
        }

        if (is_file($subcastesPath) && is_readable($subcastesPath)) {
            self::syncSubCastes($subcastesPath, $legacyCasteMap);
        }
    }

    private static function syncReligions(string $path): void
    {
        $rows = self::decodeJsonArray($path);
        if ($rows === null) {
            return;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = self::trimString($row['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $labelEn = self::trimString($row['label_en'] ?? '');
            if ($labelEn === '') {
                continue;
            }
            $labelMr = self::nullableTrim($row['label_mr'] ?? null);

            $religion = Religion::query()->firstOrNew(['key' => $key]);
            $religion->label = $labelEn;
            $religion->label_en = $labelEn;
            $religion->label_mr = $labelMr;
            if (isset($row['is_active'])) {
                $religion->is_active = (bool) $row['is_active'];
            } elseif (! $religion->exists) {
                $religion->is_active = true;
            }
            $religion->save();
        }
    }

    /**
     * @return array<int, array{religion_key: string, caste_key: string}>
     */
    private static function buildLegacyCasteMap(string $path): array
    {
        $rows = self::decodeJsonArray($path);
        if ($rows === null) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = self::intOrNull($row['ID'] ?? $row['id'] ?? null);
            $religionKey = self::trimString($row['religion_key'] ?? '');
            $casteKey = self::trimString($row['key'] ?? '');
            if ($id === null || $religionKey === '' || $casteKey === '') {
                continue;
            }
            $map[$id] = ['religion_key' => $religionKey, 'caste_key' => $casteKey];
        }

        return $map;
    }

    private static function syncCastes(string $path): void
    {
        $rows = self::decodeJsonArray($path);
        if ($rows === null) {
            return;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $religionKey = self::trimString($row['religion_key'] ?? '');
            $casteKey = self::trimString($row['key'] ?? '');
            if ($religionKey === '' || $casteKey === '') {
                continue;
            }
            $labelEn = self::trimString($row['label_en'] ?? $row['Label (legacy)'] ?? '');
            if ($labelEn === '') {
                continue;
            }
            $labelMr = self::nullableTrim($row['label_mr'] ?? null);

            $religion = Religion::query()->where('key', $religionKey)->first();
            if ($religion === null) {
                continue;
            }

            $caste = self::findCasteByExportKey($religion->id, $casteKey);
            if ($caste === null) {
                continue;
            }

            $caste->label = $labelEn;
            $caste->label_en = $labelEn;
            $caste->label_mr = $labelMr;
            $caste->save();
        }
    }

    /**
     * @param  array<int, array{religion_key: string, caste_key: string}>  $legacyCasteMap
     */
    private static function syncSubCastes(string $path, array $legacyCasteMap): void
    {
        $rows = self::decodeJsonArray($path);
        if ($rows === null) {
            return;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $legacyCasteId = self::intOrNull($row['caste_id'] ?? null);
            $subKey = self::trimString($row['key'] ?? '');
            if ($legacyCasteId === null || $subKey === '') {
                continue;
            }
            if (! isset($legacyCasteMap[$legacyCasteId])) {
                continue;
            }
            $meta = $legacyCasteMap[$legacyCasteId];
            $religion = Religion::query()->where('key', $meta['religion_key'])->first();
            if ($religion === null) {
                continue;
            }
            $caste = self::findCasteByExportKey($religion->id, $meta['caste_key']);
            if ($caste === null) {
                continue;
            }

            $labelEn = self::trimString($row['label_en'] ?? $row['label'] ?? '');
            if ($labelEn === '') {
                continue;
            }
            $labelMr = self::nullableTrim($row['label_mr'] ?? null);

            $sub = self::findSubCasteByExportKey($caste->id, $subKey);
            if ($sub === null) {
                continue;
            }

            $sub->label = $labelEn;
            $sub->label_en = $labelEn;
            $sub->label_mr = $labelMr;
            $sub->save();
        }
    }

    private static function findCasteByExportKey(int $religionId, string $exportKey): ?Caste
    {
        $variants = self::keyVariants($exportKey);

        return Caste::query()
            ->where('religion_id', $religionId)
            ->whereIn('key', $variants)
            ->first();
    }

    private static function findSubCasteByExportKey(int $casteId, string $exportKey): ?SubCaste
    {
        $variants = self::keyVariants($exportKey);

        return SubCaste::query()
            ->where('caste_id', $casteId)
            ->whereIn('key', $variants)
            ->first();
    }

    /**
     * @return list<string>
     */
    private static function keyVariants(string $key): array
    {
        $key = trim($key);
        $u = str_replace('_', '-', $key);
        $s = str_replace('-', '_', $key);

        $out = array_values(array_unique(array_filter([$key, $u, $s])));

        // TSV / slug edge: "Bhavasar" vs "Bhavsar" (Hindu caste vs Kshatriya subcaste spelling drift).
        if ($key === 'bhavasar' || $key === 'bhavsar') {
            $out = array_merge($out, ['bhavasar', 'bhavsar']);
        }

        return array_values(array_unique(array_filter($out)));
    }

    private static function decodeJsonArray(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private static function trimString(mixed $v): string
    {
        return trim((string) $v);
    }

    private static function nullableTrim(mixed $v): ?string
    {
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    private static function intOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '' || $v === '\\N') {
            return null;
        }
        if (is_int($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v;
        }

        return null;
    }
}
