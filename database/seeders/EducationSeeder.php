<?php

namespace Database\Seeders;

use App\Models\EducationCategory;
use App\Models\EducationDegree;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds {@see EducationCategory} and {@see EducationDegree} from bilingual PHP chunks
 * ({@see database/seeders/data/education_ch_*.php}). Replaces all rows (no merge).
 *
 * Legacy JSON format ({@see database/seeders/data/education_hierarchy.json}) is still
 * supported when chunk files are absent.
 */
class EducationSeeder extends Seeder
{
    public function run(): void
    {
        $chunkFiles = glob(database_path('seeders/data/education_ch_*.php')) ?: [];
        sort($chunkFiles, SORT_STRING);

        if ($chunkFiles !== []) {
            $rows = [];
            foreach ($chunkFiles as $path) {
                $part = require $path;
                if (is_array($part)) {
                    $rows = array_merge($rows, $part);
                }
            }
            $this->seedFromBilingualRows($rows);

            return;
        }

        $this->seedFromLegacyJson();
    }

    /**
     * @param  list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:string,7:string}>  $rows
     */
    private function seedFromBilingualRows(array $rows): void
    {
        DB::transaction(function () use ($rows) {
            EducationDegree::query()->delete();
            EducationCategory::query()->delete();

            $categoryOrder = [];
            $grouped = [];
            foreach ($rows as $row) {
                if (count($row) < 8) {
                    continue;
                }
                [$catEn, $catMr, $codeEn, $titleEn, $fullEn, $codeMr, $titleMr, $fullMr] = $row;
                $catEn = trim((string) $catEn);
                if ($catEn === '') {
                    continue;
                }
                if (! isset($grouped[$catEn])) {
                    $grouped[$catEn] = [
                        'name_mr' => trim((string) $catMr) !== '' ? trim((string) $catMr) : null,
                        'degrees' => [],
                    ];
                    $categoryOrder[] = $catEn;
                }
                $codeEn = trim((string) $codeEn);
                $titleEn = trim((string) $titleEn);
                $fullEn = trim((string) $fullEn);
                if ($codeEn === '' && $titleEn === '') {
                    continue;
                }
                if ($codeEn === '') {
                    $codeEn = $titleEn;
                }
                if ($titleEn === '') {
                    $titleEn = $codeEn;
                }
                $grouped[$catEn]['degrees'][] = [
                    'code' => $codeEn,
                    'title' => $titleEn,
                    'full_form' => $fullEn !== '' ? $fullEn : null,
                    'code_mr' => trim((string) $codeMr) !== '' ? trim((string) $codeMr) : null,
                    'title_mr' => trim((string) $titleMr) !== '' ? trim((string) $titleMr) : null,
                    'full_form_mr' => trim((string) $fullMr) !== '' ? trim((string) $fullMr) : null,
                ];
            }

            $sortOrder = 0;
            foreach ($categoryOrder as $catEn) {
                $meta = $grouped[$catEn];
                $baseSlug = Str::slug($catEn);
                if ($baseSlug === '') {
                    $baseSlug = 'category-'.$sortOrder;
                }
                $slug = $baseSlug;
                $suffix = 1;
                while (EducationCategory::query()->where('slug', $slug)->exists()) {
                    $slug = $baseSlug.'-'.$suffix;
                    $suffix++;
                }
                $category = EducationCategory::query()->create([
                    'name' => $catEn,
                    'name_mr' => $meta['name_mr'],
                    'slug' => $slug,
                    'sort_order' => $sortOrder,
                    'is_active' => true,
                ]);
                $sortOrder++;

                $degreeOrder = 0;
                foreach ($meta['degrees'] as $degree) {
                    EducationDegree::query()->create([
                        'category_id' => $category->id,
                        'code' => $degree['code'],
                        'title' => $degree['title'],
                        'full_form' => $degree['full_form'],
                        'code_mr' => $degree['code_mr'],
                        'title_mr' => $degree['title_mr'],
                        'full_form_mr' => $degree['full_form_mr'],
                        'sort_order' => $degreeOrder,
                    ]);
                    $degreeOrder++;
                }
            }
        });
    }

    private function seedFromLegacyJson(): void
    {
        $path = base_path('database/seeders/data/education_hierarchy.json');
        if (! is_readable($path)) {
            $this->command?->warn('Education hierarchy JSON not found at: '.$path);

            return;
        }

        $json = json_decode(file_get_contents($path), true);
        if (! isset($json['data']['education']) || ! is_array($json['data']['education'])) {
            $this->command?->warn('Invalid education hierarchy JSON structure.');

            return;
        }

        DB::transaction(function () use ($json) {
            EducationDegree::query()->delete();
            EducationCategory::query()->delete();

            $sortOrder = 0;
            foreach ($json['data']['education'] as $categoryBlock) {
                if (! is_array($categoryBlock)) {
                    continue;
                }
                foreach ($categoryBlock as $categoryName => $degrees) {
                    if (! is_array($degrees)) {
                        continue;
                    }
                    $baseSlug = Str::slug($categoryName);
                    if ($baseSlug === '') {
                        $baseSlug = 'category-'.$sortOrder;
                    }
                    $slug = $baseSlug;
                    $suffix = 1;
                    while (EducationCategory::query()->where('slug', $slug)->exists()) {
                        $slug = $baseSlug.'-'.$suffix;
                        $suffix++;
                    }
                    $category = EducationCategory::query()->create([
                        'name' => $categoryName,
                        'name_mr' => null,
                        'slug' => $slug,
                        'sort_order' => $sortOrder,
                        'is_active' => true,
                    ]);
                    $sortOrder++;

                    $degreeOrder = 0;
                    foreach ($degrees as $degree) {
                        $code = $degree['id'] ?? $degree['text'] ?? '';
                        $title = $degree['text'] ?? $degree['id'] ?? '';
                        $fullform = $degree['fullform'] ?? null;
                        if ($code === '' && $title === '') {
                            continue;
                        }
                        if ($code === '') {
                            $code = $title;
                        }
                        if ($title === '') {
                            $title = $code;
                        }
                        EducationDegree::query()->create([
                            'category_id' => $category->id,
                            'code' => $code,
                            'title' => $title,
                            'full_form' => $fullform !== '' && $fullform !== null ? $fullform : null,
                            'code_mr' => null,
                            'title_mr' => null,
                            'full_form_mr' => null,
                            'sort_order' => $degreeOrder,
                        ]);
                        $degreeOrder++;
                    }
                }
            }
        });
    }
}
