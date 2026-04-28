<?php

namespace Database\Seeders;

use App\Models\Caste;
use App\Models\Religion;
use App\Models\SubCaste;
use App\Support\MasterData\ReligionBilingualCatalog;
use App\Support\MasterData\ReligionCasteSubcasteMultilanguageSeedSync;
use Illuminate\Database\Seeder;

class ReligionCasteSubCasteSeeder extends Seeder
{
    /**
     * Normalized religion → caste → sub_caste hierarchy (firstOrCreate).
     * Then syncs EN/MR labels from {@see database/seeders/data/religions_bilingual.php}.
     * Finally applies EN/MR from {@see database/seeders/data/religion_caste_subcaste_seed_religions.json}
     * and the matching castes/subcastes JSON files when present.
     */
    public function run(): void
    {
        $data = [
            'Hindu' => [
                'Maratha' => ['96 Kuli', 'Kunbi Maratha', 'Deshmukh'],
                'Brahmin' => ['Deshastha', 'Kokanastha', 'Karhade', 'Saraswat'],
                'Mali' => ['Phule Mali', 'Halade Mali', 'Jire Mali'],
                'Dhangar' => ['Hatkar', 'Ahir', 'Khutekar'],
                'Chambhar' => ['Harale', 'Ahirwar', 'Satnami'],
                'Teli' => ['Ekbaili', 'Donbaili', 'Tilwan'],
                'Lohar' => ['Gadi Lohar', 'Panchal Lohar'],
                'Kshatriya' => [],
                'CKP (Chandraseniya Kayastha Prabhu)' => [],
                'Bhandari' => [],
                'Sonar' => [],
                'Sutar' => [],
                'Koli' => [],
                'Lingayat' => [],
                'Rajput' => [],
                'Agri' => [],
                'Gavli' => [],
                'Gura' => [],
            ],
            'Muslim' => [
                'Sunni' => ['Shaikh', 'Sayyad', 'Pathan', 'Hanafi', 'Ansari', 'Qureshi', 'Siddiqui'],
                'Shia' => ['Khoja', 'Bohra', 'Ithna Ashari'],
            ],
            'Buddhist' => [
                'Mahar' => ['Nav-Baudhha', 'Neo Buddhist'],
            ],
            'Jain' => [
                'Digambar' => ['Oswal', 'Khandelwal', 'Porwal'],
                'Shwetambar' => ['Murtipujaka', 'Sthanakvasi'],
            ],
            'Sikh' => [
                'Jat' => ['Ramgarhia', 'Khatri', 'Arora'],
            ],
        ];

        foreach ($data as $religionLabel => $castes) {
            $religion = Religion::firstOrCreate(
                ['key' => $this->slug($religionLabel)],
                ['label' => $religionLabel, 'is_active' => true]
            );

            foreach ($castes as $casteLabel => $subCasteLabels) {
                $casteKey = $this->slug($casteLabel);
                $caste = Caste::firstOrCreate(
                    ['key' => $casteKey],
                    ['religion_id' => $religion->id, 'label' => $casteLabel, 'is_active' => true]
                );

                foreach ($subCasteLabels as $subLabel) {
                    SubCaste::firstOrCreate(
                        [
                            'caste_id' => $caste->id,
                            'key' => $this->slug($subLabel),
                        ],
                        ['label' => $subLabel, 'is_active' => true]
                    );
                }
            }
        }

        $this->syncReligionBilingualCatalog();

        ReligionCasteSubcasteMultilanguageSeedSync::apply();
    }

    /**
     * Upsert religion `label`, `label_en`, `label_mr` from the canonical PHP file (UTF-8).
     */
    private function syncReligionBilingualCatalog(): void
    {
        $catalog = ReligionBilingualCatalog::load();
        if ($catalog === []) {
            return;
        }

        foreach ($catalog as $key => $meta) {
            $key = trim((string) $key);
            if ($key === '' || ! is_array($meta)) {
                continue;
            }
            $labelEn = trim((string) ($meta['label_en'] ?? ''));
            if ($labelEn === '') {
                continue;
            }
            $mr = trim((string) ($meta['label_mr'] ?? ''));
            $labelMr = $mr !== '' ? $mr : null;

            $religion = Religion::query()->firstOrNew(['key' => $key]);
            $religion->label = $labelEn;
            $religion->label_en = $labelEn;
            $religion->label_mr = $labelMr;
            $religion->is_active = true;
            $religion->save();
        }
    }

    private function slug(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '_', trim($value));

        return mb_strtolower($slug, 'UTF-8');
    }
}
