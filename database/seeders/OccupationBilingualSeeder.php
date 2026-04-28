<?php

namespace Database\Seeders;

use App\Models\OccupationCategory;
use App\Models\OccupationMaster;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds bilingual occupation master from {@see database/seeders/data/occupations_bilingual.tsv}.
 *
 * Dedupes by normalized English occupation title (first row wins for category + Marathi labels).
 * Does not delete existing occupations absent from the file (preserves FK references).
 *
 * After TSV, applies {@see database/seeders/data/occupation_legacy_mr_ch_*.php} for legacy profession MR
 * (workplace categories). Chunk rows are deduped by (category_en, occupation_en).
 */
class OccupationBilingualSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/occupations_bilingual.tsv');
        if (is_file($path)) {
            $parsed = $this->parseTsv($path);
            if ($parsed !== []) {
                DB::transaction(fn () => $this->seedFromTsvRows($parsed));
            }
        } else {
            $this->command?->warn('occupations_bilingual.tsv missing; TSV pass skipped.');
        }

        DB::transaction(fn () => $this->seedLegacyMarathiChunks());
    }

    /**
     * @param  list<array{sr:int,category_en:string,occupation_en:string,category_mr:string,occupation_mr:string}>  $parsed
     */
    private function seedFromTsvRows(array $parsed): void
    {
        $deduped = $this->dedupeOccupations($parsed);
        $categoryMeta = $this->buildCategoryMeta($deduped);

        $categoryIds = [];
        $order = 0;
        foreach ($categoryMeta as $catEn => $meta) {
            $catEn = trim((string) $catEn);
            if ($catEn === '') {
                continue;
            }
            $category = OccupationCategory::query()->firstWhere('name', $catEn);
            $payload = [
                'name' => $catEn,
                'name_mr' => $meta['name_mr'],
                'sort_order' => $order,
            ];
            if ($category) {
                $category->update($payload);
            } else {
                $category = OccupationCategory::query()->create(array_merge($payload, [
                    'legacy_working_with_type_id' => null,
                ]));
            }
            $categoryIds[$catEn] = $category->id;
            $order += 10;
        }

        foreach ($deduped as $row) {
            $catEn = trim((string) $row['category_en']);
            $occEn = trim((string) $row['occupation_en']);
            if ($occEn === '' || ! isset($categoryIds[$catEn])) {
                continue;
            }
            $norm = Str::limit(mb_strtolower($occEn), 160, '');
            $occMr = trim((string) $row['occupation_mr']);
            $occMr = $occMr !== '' ? Str::limit($occMr, 255, '') : null;
            $sr = (int) ($row['sr'] ?? 0);

            $existing = OccupationMaster::query()
                ->where(function ($q) use ($norm, $occEn) {
                    $q->where('normalized_name', $norm)
                        ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($occEn)]);
                })
                ->orderBy('id')
                ->first();

            $data = [
                'name' => Str::limit($occEn, 160, ''),
                'normalized_name' => $norm,
                'name_mr' => $occMr,
                'category_id' => $categoryIds[$catEn],
                'sort_order' => $sr > 0 ? $sr : 0,
            ];

            if ($existing) {
                $existing->update($data);
            } else {
                OccupationMaster::query()->create($data);
            }
        }
    }

    private function seedLegacyMarathiChunks(): void
    {
        $chunkFiles = glob(database_path('seeders/data/occupation_legacy_mr_ch_*.php')) ?: [];
        sort($chunkFiles, SORT_STRING);
        if ($chunkFiles === []) {
            return;
        }

        $seenKeys = [];
        foreach ($chunkFiles as $path) {
            $part = require $path;
            if (! is_array($part)) {
                continue;
            }
            foreach ($part as $row) {
                if (! is_array($row) || count($row) < 3) {
                    continue;
                }
                $catEn = trim((string) $row[0]);
                $occEn = trim((string) $row[1]);
                $occMr = trim((string) $row[2]);
                if ($catEn === '' || $occEn === '' || $occMr === '') {
                    continue;
                }
                $dedupeKey = mb_strtolower($catEn).'|'.mb_strtolower($occEn);
                if (isset($seenKeys[$dedupeKey])) {
                    $this->command?->warn("Duplicate legacy MR row skipped: {$catEn} / {$occEn}");

                    continue;
                }
                $seenKeys[$dedupeKey] = true;

                $category = OccupationCategory::query()->firstWhere('name', $catEn);
                if (! $category) {
                    $this->command?->warn("Legacy MR: occupation category not found: {$catEn}");

                    continue;
                }

                $norm = Str::limit(mb_strtolower($occEn), 160, '');
                $nameMr = Str::limit($occMr, 255, '');

                $scoped = OccupationMaster::query()
                    ->where('category_id', $category->id)
                    ->where(function ($q) use ($norm, $occEn) {
                        $q->where('normalized_name', $norm)
                            ->orWhereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($occEn))]);
                    })
                    ->orderBy('id')
                    ->get();

                if ($scoped->count() === 1) {
                    $scoped->first()->update(['name_mr' => $nameMr]);

                    continue;
                }
                if ($scoped->count() > 1) {
                    $scoped->first()->update(['name_mr' => $nameMr]);
                    $this->command?->warn("Legacy MR: multiple matches in category, first updated: {$catEn} / {$occEn}");

                    continue;
                }

                $global = OccupationMaster::query()
                    ->where(function ($q) use ($norm, $occEn) {
                        $q->where('normalized_name', $norm)
                            ->orWhereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($occEn))]);
                    })
                    ->orderBy('id')
                    ->get();

                if ($global->count() === 1) {
                    $global->first()->update(['name_mr' => $nameMr]);
                } elseif ($global->count() > 1) {
                    $this->command?->warn("Legacy MR: ambiguous occupation name across categories, skipped: {$occEn}");
                } else {
                    $this->command?->warn("Legacy MR: occupation not found: {$catEn} / {$occEn}");
                }
            }
        }
    }

    /**
     * @return list<array{sr:int,category_en:string,occupation_en:string,category_mr:string,occupation_mr:string}>
     */
    private function parseTsv(string $path): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return [];
        }
        $header = fgetcsv($fh, 0, "\t");
        if ($header === false) {
            fclose($fh);

            return [];
        }
        $out = [];
        while (($cols = fgetcsv($fh, 0, "\t")) !== false) {
            if (count($cols) < 5) {
                continue;
            }
            [$sr, $catEn, $occEn, $catMr, $occMr] = [
                (int) trim((string) $cols[0]),
                trim((string) $cols[1]),
                trim((string) $cols[2]),
                trim((string) $cols[3]),
                trim((string) $cols[4]),
            ];
            if ($occEn === '') {
                continue;
            }
            $out[] = [
                'sr' => $sr,
                'category_en' => $catEn,
                'occupation_en' => $occEn,
                'category_mr' => $catMr,
                'occupation_mr' => $occMr,
            ];
        }
        fclose($fh);

        return $out;
    }

    /**
     * @param  list<array{sr:int,category_en:string,occupation_en:string,category_mr:string,occupation_mr:string}>  $rows
     * @return list<array{sr:int,category_en:string,occupation_en:string,category_mr:string,occupation_mr:string}>
     */
    private function dedupeOccupations(array $rows): array
    {
        $seen = [];
        $deduped = [];
        foreach ($rows as $row) {
            $key = mb_strtolower(trim((string) $row['occupation_en']));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $row;
        }

        return $deduped;
    }

    /**
     * @param  list<array{sr:int,category_en:string,occupation_en:string,category_mr:string,occupation_mr:string}>  $rows
     * @return array<string, array{name_mr: ?string}>
     */
    private function buildCategoryMeta(array $rows): array
    {
        $meta = [];
        foreach ($rows as $row) {
            $catEn = trim((string) $row['category_en']);
            if ($catEn === '') {
                continue;
            }
            if (! isset($meta[$catEn])) {
                $mr = trim((string) $row['category_mr']);
                $meta[$catEn] = ['name_mr' => $mr !== '' ? Str::limit($mr, 128, '') : null];
            }
        }

        return $meta;
    }
}
