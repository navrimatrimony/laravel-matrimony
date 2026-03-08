<?php

namespace Database\Seeders;

use App\Models\EducationCategory;
use App\Models\EducationDegree;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shaadi.com-style education hierarchy.
 * Reads JSON from database/seeders/data/education_hierarchy.json.
 * Master-data replacement: truncates tables then inserts exactly what is in the JSON (no merge, no legacy rows).
 */
class EducationSeeder extends Seeder
{
    public function run(): void
    {
        $path = base_path('database/seeders/data/education_hierarchy.json');
        if (! is_readable($path)) {
            $this->command->warn('Education hierarchy JSON not found at: ' . $path);

            return;
        }

        $json = json_decode(file_get_contents($path), true);
        if (! isset($json['data']['education']) || ! is_array($json['data']['education'])) {
            $this->command->warn('Invalid education hierarchy JSON structure.');

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
                    $slug = Str::slug($categoryName);
                    $category = EducationCategory::create([
                        'name' => $categoryName,
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
                        EducationDegree::create([
                            'category_id' => $category->id,
                            'code' => $code,
                            'title' => $title,
                            'full_form' => $fullform !== '' && $fullform !== null ? $fullform : null,
                            'sort_order' => $degreeOrder,
                        ]);
                        $degreeOrder++;
                    }
                }
            }
        });
    }
}
